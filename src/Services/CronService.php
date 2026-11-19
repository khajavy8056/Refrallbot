<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Bot\BotHandler;
use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Support\Database;
use RB\Support\ErrorHandler;

/**
 * وظایف زمان‌بندی‌شده (Cron خط فرمان و Cron وب) با پشتیبانی Polling چندپیام‌رسانی ایزوله
 */
final class CronService
{
    public const TASKS = ['all', 'messengers', 'broadcast', 'campaigns', 'maintenance', 'security', 'backup'];

    private Database $db;

    /** @var callable(string):void */
    private $log;

    public function __construct(?Database $db = null, ?callable $log = null)
    {
        $this->db = $db ?? Database::instance();
        $this->log = $log ?? static function (string $m): void {
        };
    }

    /**
     * @return array{task:string,ok:bool,output:array<int,string>,ms:int}
     */
    public function run(string $task = 'all'): array
    {
        $started = microtime(true);
        $output = [];
        $db = $this->db;

        $emit = function (string $message) use (&$output): void {
            $output[] = $message;
            ($this->log)($message);
        };

        if (!in_array($task, self::TASKS, true)) {
            return ['task' => $task, 'ok' => false, 'output' => ['وظیفه ناشناخته است: ' . $task], 'ms' => 0];
        }

        $ok = true;
        try {
            // ۱) دریافت دوره‌ای آپدیت پیام‌رسان‌هایی که Polling برایشان فعال شده (ایزوله در برابر خطای هر پلتفرم)
            if (in_array($task, ['messengers', 'all'], true) && $db->tableExists('messenger_connections')) {
                $registry = new MessengerRegistry($db);
                $registry->ensureSeeded();
                $pollingRows = $db->all(
                    'SELECT platform, polling_offset FROM messenger_connections WHERE is_enabled = 1 AND polling_enabled = 1 AND bot_token_enc IS NOT NULL'
                );
                foreach ($pollingRows as $pRow) {
                    $plat = (string) $pRow['platform'];
                    if (!MessengerCapabilities::supports($plat, 'polling')) {
                        continue;
                    }
                    try {
                        $driver = $registry->driver($plat);
                        $fetched = $driver->fetchUpdates($pRow['polling_offset'] !== null ? (string) $pRow['polling_offset'] : null, 40);
                        if ($fetched['ok']) {
                            $handler = new BotHandler($db, $driver);
                            $processed = 0;
                            foreach ($fetched['updates'] as $upd) {
                                $dedupe = $registry->beginUpdate($plat, $upd->updateKey);
                                if (!$dedupe['proceed']) {
                                    continue;
                                }
                                try {
                                    $handler->handleNormalized($upd);
                                    $registry->finishUpdate($plat, (int) $dedupe['id'], true);
                                    $processed++;
                                } catch (\Throwable $ue) {
                                    $registry->finishUpdate($plat, (int) $dedupe['id'], false);
                                    ErrorHandler::write('POLL', $plat . ' update error: ' . ErrorHandler::redact($ue->getMessage()));
                                }
                            }
                            if (($fetched['next_offset'] ?? null) !== ($pRow['polling_offset'] ?? null)) {
                                $db->update(
                                    'messenger_connections',
                                    ['polling_offset' => $fetched['next_offset'], 'updated_at' => now()],
                                    'platform = :p',
                                    ['p' => $plat]
                                );
                            }
                            if ($processed > 0) {
                                $emit('دریافت پیام ' . MessengerCapabilities::label($plat) . ': ' . $processed . ' آپدیت پردازش شد.');
                            }
                        }
                    } catch (\Throwable $pe) {
                        // خطای یک پیام‌رسان هرگز روی پیام‌رسان‌های دیگر یا بقیه Cron اثر نمی‌گذارد
                        $emit('هشدار Polling ' . MessengerCapabilities::label($plat) . ': ' . ErrorHandler::redact($pe->getMessage()));
                    }
                }
            }

            // ۲) ارسال همگانی چندپیام‌رسانی
            if (in_array($task, ['broadcast', 'all'], true)) {
                $service = new BroadcastService($db);
                $res = $service->processQueue(25);
                $sentCount = (int) ($res['sent'] ?? 0);
                $emit('ارسال همگانی: ' . $sentCount . ' پیام ارسال شد.');

                $failedCampaigns = $db->all(
                    "SELECT id FROM broadcast_campaigns WHERE status IN ('sending','failed') ORDER BY id DESC LIMIT 5"
                );
                foreach ($failedCampaigns as $row) {
                    $retried = $service->retryFailed((int) $row['id']);
                    if ($retried > 0) {
                        $db->update(
                            'broadcast_campaigns',
                            ['status' => 'queued', 'updated_at' => now()],
                            'id = :id',
                            ['id' => (int) $row['id']]
                        );
                        $emit('تلاش مجدد برای ' . $retried . ' پیام ناموفق کمپین #' . $row['id']);
                    }
                }
            }

            // ۳) جوایز کمپین‌ها
            if (in_array($task, ['campaigns', 'all'], true)) {
                $granted = (new CampaignService($db))->processRewards();
                $emit('جوایز کمپین‌ها: ' . $granted . ' جایزه پرداخت شد.');
            }

            // ۴) نگهداری و پاکسازی
            if (in_array($task, ['maintenance', 'all'], true)) {
                $db->run('DELETE FROM bot_sessions WHERE expires_at < :now', ['now' => now()]);

                if ($db->tableExists('user_link_tokens')) {
                    $cutoff = date('Y-m-d H:i:s', time() - 7 * 86400);
                    $db->run('DELETE FROM user_link_tokens WHERE expires_at < :c', ['c' => $cutoff]);
                }
                if ($db->tableExists('messenger_processed_updates')) {
                    $cutoff = date('Y-m-d H:i:s', time() - 14 * 86400);
                    $db->run("DELETE FROM messenger_processed_updates WHERE created_at < :c AND status <> 'processing'", ['c' => $cutoff]);
                }
                (new UpdateDeduplicator($db))->prune();

                $rows = $db->all("SELECT id, `value` FROM system_settings WHERE `key` LIKE 'magic_login:%'");
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row['value'], true) ?: [];
                    if ((int) ($payload['expires'] ?? 0) < time()) {
                        $db->delete('system_settings', 'id = :id', ['id' => (int) $row['id']]);
                    }
                }

                (new UserService($db))->refreshNetworkCounters();
                $emit('نگهداری: پاکسازی و بازسازی شمارنده‌ها انجام شد.');
            }

            // ۵) پشتیبان روزانه
            if (in_array($task, ['backup', 'all'], true)) {
                $dir = RB_STORAGE . '/backups';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $file = $dir . '/refrallbot-db-' . date('Ymd') . '.sql';
                if (!is_file($file)) {
                    $handle = @fopen($file, 'wb');
                    if ($handle !== false) {
                        fwrite($handle, '-- RefrallBot automatic backup ' . date('c') . "\n");
                        fwrite($handle, '-- driver: ' . $db->driver() . "\n\n");
                        foreach ($db->listTables() as $table) {
                            $qTable = $db->quoteIdent($table);
                            $rows = $db->all('SELECT * FROM ' . $qTable);
                            fwrite($handle, "\n-- table: {$table} (" . count($rows) . " rows)\n");
                            foreach ($rows as $row) {
                                $cols = array_map([$db, 'quoteIdent'], array_keys($row));
                                $vals = array_map(static fn(mixed $v): string => $v === null ? 'NULL' : $db->quote((string) $v), array_values($row));
                                fwrite($handle, 'INSERT INTO ' . $qTable . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ");\n");
                            }
                        }
                        fclose($handle);
                        @chmod($file, 0640);
                        $emit('پشتیبان روزانه ساخته شد: ' . basename($file));
                    }
                }
                $files = glob($dir . '/refrallbot-db-*.sql') ?: [];
                sort($files);
                while (count($files) > 14) {
                    @unlink((string) array_shift($files));
                }
            }

            // ۶) ارزیابی امنیتی
            if (in_array($task, ['security', 'all'], true)) {
                $rows = $db->all(
                    "SELECT id FROM users WHERE (status = 'suspicious' OR risk_score >= 20) AND status <> 'blocked' LIMIT 200"
                );
                foreach ($rows as $row) {
                    SecurityService::evaluateUser((int) $row['id']);
                }
                $emit('امنیت: ' . count($rows) . ' کاربر ارزیابی شد.');
            }
        } catch (\Throwable $e) {
            $ok = false;
            $emit('خطا: ' . ErrorHandler::redact($e->getMessage()));
        }

        return [
            'task' => $task,
            'ok' => $ok,
            'output' => $output,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    public static function webToken(bool $create = false): string
    {
        $token = (string) (SettingsService::get('cron_token', '') ?? '');
        if ($token === '' && $create) {
            $token = bin2hex(random_bytes(24));
            SettingsService::set('cron_token', $token, 'system');
        }
        return $token;
    }
}
