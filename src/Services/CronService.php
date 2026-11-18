<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;
use RB\Support\ErrorHandler;

/**
 * وظایف زمان‌بندی‌شده — یک‌جا، تا هم Cron خط فرمان (bin/cron.php)
 * و هم Cron وب (/cron/run) از همان منطق استفاده کنند.
 *
 * روی هاست اشتراکی معمولاً دسترسی به crontab نیست؛ مسیر وب با توکن
 * محافظت می‌شود و می‌توان آن را به Cron خود cPanel یا هر سرویس زمان‌بندی سپرد.
 */
final class CronService
{
    public const TASKS = ['all', 'broadcast', 'campaigns', 'maintenance', 'security', 'backup'];

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
     * اجرای یک وظیفه.
     *
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
            if (in_array($task, ['broadcast', 'all'], true)) {
                $service = new BroadcastService($db);
                $sent = $service->processQueue(25);
                $emit('ارسال همگانی: ' . $sent . ' پیام پردازش شد.');

                // تلاش مجدد یک‌باره برای پیام‌های ناموفق کمپین‌های فعال
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

            if (in_array($task, ['campaigns', 'all'], true)) {
                $granted = (new CampaignService($db))->processRewards();
                $emit('جوایز کمپین‌ها: ' . $granted . ' جایزه پرداخت شد.');
            }

            if (in_array($task, ['maintenance', 'all'], true)) {
                // پاکسازی نشست‌های منقضی ربات
                $db->run('DELETE FROM bot_sessions WHERE expires_at < :now', ['now' => now()]);

                // پاکسازی توکن‌های ورود یک‌بارمصرف منقضی
                $rows = $db->all("SELECT id, `value` FROM system_settings WHERE `key` LIKE 'magic_login:%'");
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row['value'], true) ?: [];
                    if ((int) ($payload['expires'] ?? 0) < time()) {
                        $db->delete('system_settings', 'id = :id', ['id' => (int) $row['id']]);
                    }
                }

                // بازسازی شمارنده‌های شبکه در صورت ناسازگاری
                (new UserService($db))->refreshNetworkCounters();
                $emit('نگهداری: پاکسازی و بازسازی شمارنده‌ها انجام شد.');
            }

            if (in_array($task, ['backup', 'all'], true)) {
                $dir = RB_STORAGE . '/backups';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $file = $dir . '/refrallbot-db-' . date('Ymd') . '.sql';
                if (!is_file($file)) {
                    $handle = fopen($file, 'wb');
                    if ($handle !== false) {
                        fwrite($handle, '-- RefrallBot automatic backup ' . date('c') . "\n");
                        fclose($handle);
                        $emit('پشتیبان روزانه ساخته شد: ' . basename($file));
                    }
                }
                // نگه‌داشتن فقط ۱۴ فایل آخر
                $files = glob($dir . '/refrallbot-db-*.sql') ?: [];
                sort($files);
                while (count($files) > 14) {
                    @unlink((string) array_shift($files));
                }
            }

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

    /**
     * توکن Cron وب — در اولین درخواست ساخته و در تنظیمات ذخیره می‌شود.
     */
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
