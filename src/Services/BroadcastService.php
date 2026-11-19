<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Support\Config;
use RB\Support\Database;
use RB\Support\ErrorHandler;

/**
 * سرویس ارسال پیام همگانی مرحله‌ای (Batch) در تمام پیام‌رسان‌های فعال
 * (پشتیبانی از ارسال به همه پیام‌رسان‌ها یا فیلتر یک پیام‌رسان مشخص با ایزوله‌سازی کامل خطا)
 */
final class BroadcastService
{
    public const SEGMENTS = [
        'all' => 'همه کاربران',
        'active' => 'کاربران فعال',
        'with_phone' => 'کاربران دارای شماره تماس',
        'with_refs' => 'کاربران دارای زیرمجموعه',
        'no_refs' => 'کاربران بدون زیرمجموعه',
        'with_balance' => 'کاربران دارای موجودی',
        'suspicious' => 'کاربران مشکوک',
    ];

    public function __construct(private ?Database $db = null)
    {
        $this->db ??= Database::instance();
    }

    /** ساخت کمپین و ثبت فهرست مخاطبان */
    public function createCampaign(array $data, ?int $adminId = null): array
    {
        $registry = new MessengerRegistry($this->db);
        $targetPlatform = strtolower(trim((string) ($data['target_platform'] ?? 'all')));
        if ($targetPlatform !== 'all' && !MessengerCapabilities::isSupported($targetPlatform)) {
            $targetPlatform = 'all';
        }

        $activePlatforms = $registry->activePlatforms();
        if ($activePlatforms === [] && !TelegramService::configured()) {
            throw new \RuntimeException('ابتدا حداقل یک پیام‌رسان را در بخش «مدیریت پیام‌رسان‌ها» متصل و فعال کنید.');
        }
        if ($targetPlatform !== 'all' && !isset($activePlatforms[$targetPlatform])) {
            throw new \RuntimeException('پیام‌رسان «' . MessengerCapabilities::label($targetPlatform) . '» فعال یا متصل نیست.');
        }

        $segment = (string) ($data['segment'] ?? 'all');
        if (!array_key_exists($segment, self::SEGMENTS)) {
            throw new \RuntimeException('گروه مخاطبان نامعتبر است.');
        }
        $params = (array) ($data['segment_params'] ?? []);
        $params['target_platform'] = $targetPlatform;
        $targets = $this->resolveTargets($segment, $params);
        if ($targets === []) {
            throw new \RuntimeException('هیچ مخاطب فعالی برای این گروه و پیام‌رسان یافت نشد.');
        }

        $scheduledAt = null;
        if (!empty($data['scheduled_at'])) {
            $ts = strtotime((string) $data['scheduled_at']);
            if ($ts !== false && $ts > time()) {
                $scheduledAt = date('Y-m-d H:i:s', $ts);
            }
        }

        return $this->db->transaction(function () use ($data, $segment, $params, $targets, $adminId, $scheduledAt, $targetPlatform) {
            $campRow = [
                'title' => (string) ($data['title'] ?? 'پیام همگانی'),
                'message_text' => (string) $data['message_text'],
                'media_type' => $data['media_type'] ?? null,
                'media_file_id' => $data['media_file_id'] ?? null,
                'buttons' => !empty($data['buttons']) ? json_encode($data['buttons'], JSON_UNESCAPED_UNICODE) : null,
                'segment' => $segment,
                'segment_params' => json_encode($params, JSON_UNESCAPED_UNICODE),
                'status' => $scheduledAt !== null ? 'queued' : 'sending',
                'scheduled_at' => $scheduledAt,
                'total_targets' => count($targets),
                'created_by' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($this->db->columnExists('broadcast_campaigns', 'target_platform')) {
                $campRow['target_platform'] = $targetPlatform;
            }
            $campaignId = $this->db->insert('broadcast_campaigns', $campRow);

            foreach ($targets as $target) {
                $tid = (int) ($target['telegram_id'] ?? 0);
                $this->db->insert('broadcast_targets', [
                    'campaign_id' => $campaignId,
                    'user_id' => (int) $target['id'],
                    'telegram_id' => $tid,
                    'status' => 'pending',
                ]);
            }

            return $this->db->first('SELECT * FROM broadcast_campaigns WHERE id = :id', ['id' => $campaignId]) ?? [];
        });
    }

    /** @return array<int,array{id:int,telegram_id:int}> */
    public function resolveTargets(string $segment, array $params = []): array
    {
        $targetPlatform = strtolower(trim((string) ($params['target_platform'] ?? 'all')));
        $hasMessengerTable = $this->db->tableExists('user_messenger_accounts');

        $where = ["u.status <> 'blocked'"];
        $bind = [];

        if ($targetPlatform !== 'all' && MessengerCapabilities::isSupported($targetPlatform)) {
            if ($hasMessengerTable) {
                $where[] = 'EXISTS (SELECT 1 FROM user_messenger_accounts ma WHERE ma.user_id = u.id AND ma.platform = :tplat AND ma.is_blocked = 0 AND ma.can_send_message = 1)';
                $bind['tplat'] = $targetPlatform;
            } elseif ($targetPlatform === MessengerCapabilities::TELEGRAM) {
                $where[] = 'u.telegram_id > 0';
            } else {
                return [];
            }
        }

        switch ($segment) {
            case 'active':
                $where[] = "u.status = 'active'";
                break;
            case 'suspicious':
                $where = ["u.status = 'suspicious'"];
                if (isset($bind['tplat'])) {
                    $where[] = 'EXISTS (SELECT 1 FROM user_messenger_accounts ma WHERE ma.user_id = u.id AND ma.platform = :tplat AND ma.is_blocked = 0)';
                }
                break;
            case 'with_phone':
                $where[] = "u.phone IS NOT NULL AND u.phone <> ''";
                break;
            case 'with_refs':
                $where[] = 'u.direct_ref_count > 0';
                break;
            case 'no_refs':
                $where[] = 'u.direct_ref_count = 0';
                break;
            case 'with_balance':
                $where[] = 'u.wallet_balance > 0';
                break;
            case 'level':
                $level = max(0, min(8, (int) ($params['level'] ?? 0)));
                $sql = 'SELECT DISTINCT u.id, u.telegram_id FROM users u
                        JOIN referral_relations rr ON rr.descendant_id = u.id
                        WHERE rr.depth = :lvl AND ' . implode(' AND ', $where);
                $bind['lvl'] = $level + 1;
                try {
                    return $this->db->all($sql, $bind);
                } catch (\Throwable $e) {
                    ErrorHandler::logException($e);
                    return [];
                }
            case 'all':
            default:
                break;
        }

        $sql = 'SELECT u.id, u.telegram_id FROM users u WHERE ' . implode(' AND ', $where);
        try {
            return $this->db->all($sql, $bind);
        } catch (\Throwable $e) {
            ErrorHandler::logException($e);
            return [];
        }
    }

    public function countTargets(string $segment, array $params = []): int
    {
        return count($this->resolveTargets($segment, $params));
    }

    /** @return array<string,int> */
    public function segmentCounts(): array
    {
        $out = [];
        foreach (self::SEGMENTS as $key => $label) {
            $out[$key] = $this->countTargets($key);
        }
        return $out;
    }

    public static function renderPreview(string $text): string
    {
        $escaped = e($text);
        $escaped = preg_replace('~\*\*(.+?)\*\*~s', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('~__(.+?)__~s', '<em>$1</em>', $escaped) ?? $escaped;
        return nl2br($escaped);
    }

    /**
     * پردازش یک دسته از کمپین‌های همگانی در تمام پیام‌رسان‌ها
     * @return array{campaigns:int,sent:int,failed:int,blocked:int,error?:string}
     */
    public function processQueue(int $batchSize = 25, int $maxPerRun = 500): array
    {
        $batchSize = max(1, min(50, $batchSize));
        $maxPerRun = max($batchSize, $maxPerRun);
        $sent = 0;
        $failed = 0;
        $blocked = 0;
        $campaignsTouched = 0;

        $registry = new MessengerRegistry($this->db);
        if ($registry->activePlatforms() === [] && !TelegramService::configured()) {
            return ['campaigns' => 0, 'sent' => 0, 'failed' => 0, 'blocked' => 0, 'error' => 'هیچ پیام‌رسانی متصل نیست'];
        }

        $campaigns = $this->db->all(
            "SELECT * FROM broadcast_campaigns
             WHERE status IN ('sending','queued')
               AND (scheduled_at IS NULL OR scheduled_at <= :now)
             ORDER BY id ASC LIMIT 5",
            ['now' => now()]
        );

        foreach ($campaigns as $campaign) {
            if ($sent + $failed >= $maxPerRun) {
                break;
            }
            $campaignsTouched++;
            if ($campaign['status'] === 'queued') {
                $this->db->update('broadcast_campaigns', [
                    'status' => 'sending',
                    'started_at' => now(),
                    'updated_at' => now(),
                ], 'id = :id', ['id' => (int) $campaign['id']]);
            } elseif (empty($campaign['started_at'])) {
                $this->db->update('broadcast_campaigns', ['started_at' => now()], 'id = :id', ['id' => (int) $campaign['id']]);
            }

            $targetPlatform = strtolower((string) ($campaign['target_platform'] ?? 'all'));
            $targets = $this->db->all(
                "SELECT * FROM broadcast_targets WHERE campaign_id = :c AND status = 'pending' ORDER BY id LIMIT " . $batchSize,
                ['c' => (int) $campaign['id']]
            );

            foreach ($targets as $target) {
                if ($sent + $failed >= $maxPerRun) {
                    break;
                }
                $result = $this->sendToTarget($registry, $campaign, $target, $targetPlatform);
                if ($result === 'sent') {
                    $this->db->update('broadcast_targets', [
                        'status' => 'sent', 'sent_at' => now(), 'error' => null,
                    ], 'id = :id', ['id' => (int) $target['id']]);
                    $sent++;
                } elseif ($result === 'blocked') {
                    $this->db->update('broadcast_targets', [
                        'status' => 'blocked', 'sent_at' => now(), 'error' => 'کاربر ربات را مسدود کرده است',
                    ], 'id = :id', ['id' => (int) $target['id']]);
                    $blocked++;
                } else {
                    $this->db->update('broadcast_targets', [
                        'status' => 'failed', 'sent_at' => now(), 'error' => mb_substr((string) $result, 0, 400),
                    ], 'id = :id', ['id' => (int) $target['id']]);
                    $failed++;
                }
                $delay = (int) Config::get('services.broadcast_delay_ms', 60);
                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }

            $remaining = (int) $this->db->value(
                "SELECT COUNT(*) FROM broadcast_targets WHERE campaign_id = :c AND status = 'pending'",
                ['c' => (int) $campaign['id']]
            );
            $counts = $this->db->first(
                "SELECT
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS s,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS f,
                    SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) AS b
                 FROM broadcast_targets WHERE campaign_id = :c",
                ['c' => (int) $campaign['id']]
            );
            $this->db->update('broadcast_campaigns', [
                'sent_count' => (int) ($counts['s'] ?? 0),
                'failed_count' => (int) ($counts['f'] ?? 0),
                'blocked_count' => (int) ($counts['b'] ?? 0),
                'status' => $remaining > 0 ? 'sending' : 'sent',
                'finished_at' => $remaining > 0 ? null : now(),
                'updated_at' => now(),
            ], 'id = :id', ['id' => (int) $campaign['id']]);
        }

        return [
            'campaigns' => $campaignsTouched,
            'sent' => $sent,
            'failed' => $failed,
            'blocked' => $blocked,
        ];
    }

    /** ارسال ایزوله یک پیام کمپین به حساب پیام‌رسان مخاطب */
    private function sendToTarget(MessengerRegistry $registry, array $campaign, array $target, string $targetPlatform): string
    {
        $userId = (int) ($target['user_id'] ?? 0);
        $telegramId = (int) ($target['telegram_id'] ?? 0);
        $text = (string) ($campaign['message_text'] ?? '');
        $buttons = !empty($campaign['buttons']) ? json_decode((string) $campaign['buttons'], true) : null;
        $options = ['parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if (is_array($buttons) && $buttons !== []) {
            $options['inline_rows'] = $buttons;
        }

        // انتخاب بهترین پیام‌رسان فعال کاربر (بدون ارسال تکراری به یک نفر)
        $accounts = [];
        if ($userId > 0 && $this->db->tableExists('user_messenger_accounts')) {
            if ($targetPlatform !== 'all' && MessengerCapabilities::isSupported($targetPlatform)) {
                $accounts = $this->db->all(
                    'SELECT * FROM user_messenger_accounts WHERE user_id = :u AND platform = :p AND is_blocked = 0 AND can_send_message = 1 LIMIT 1',
                    ['u' => $userId, 'p' => $targetPlatform]
                );
            } else {
                $accounts = $this->db->all(
                    'SELECT * FROM user_messenger_accounts WHERE user_id = :u AND is_blocked = 0 AND can_send_message = 1 ORDER BY is_primary DESC, last_seen_at DESC',
                    ['u' => $userId]
                );
            }
        }

        if ($accounts === [] && $telegramId > 0 && ($targetPlatform === 'all' || $targetPlatform === MessengerCapabilities::TELEGRAM)) {
            $accounts = [[
                'platform' => MessengerCapabilities::TELEGRAM,
                'external_user_id' => (string) $telegramId,
                'external_chat_id' => (string) $telegramId,
            ]];
        }

        $lastErr = 'هیچ پیام‌رسان فعالی برای این کاربر در دسترس نیست.';
        foreach ($accounts as $acc) {
            $p = (string) $acc['platform'];
            $chatId = (string) ($acc['external_chat_id'] ?: $acc['external_user_id']);
            try {
                $driver = $registry->driver($p);
                if (!$driver->isEnabled() && !($p === MessengerCapabilities::TELEGRAM && $driver->isConfigured())) {
                    continue;
                }
                if (!empty($campaign['media_file_id']) && ($campaign['media_type'] ?? '') === 'photo' && $p === MessengerCapabilities::TELEGRAM) {
                    $res = $driver->sendPhoto($chatId, (string) $campaign['media_file_id'], $text, $options);
                } else {
                    $res = $driver->sendMessage($chatId, $text, $options);
                }
                if (!empty($res['ok'])) {
                    return 'sent';
                }
                $desc = (string) ($res['description'] ?? 'خطای ارسال');
                if (stripos($desc, 'blocked') !== false || stripos($desc, 'مسدود') !== false || stripos($desc, 'deactivated') !== false) {
                    (new UserService($this->db))->markMessengerBlocked($p, (string) $acc['external_user_id'], true);
                    $lastErr = 'blocked';
                    continue;
                }
                $lastErr = MessengerCapabilities::label($p) . ': ' . $desc;
            } catch (\Throwable $e) {
                $lastErr = MessengerCapabilities::label($p) . ': ' . ErrorHandler::redact($e->getMessage());
            }
        }

        return $lastErr;
    }

    public function sendTestToAdmin(int $adminId, string $text): bool
    {
        $admin = $this->db->first('SELECT telegram_user_id FROM admins WHERE id = :id', ['id' => $adminId]);
        if (empty($admin['telegram_user_id'])) {
            throw new \RuntimeException('شناسه تلگرام برای حساب مدیر ثبت نشده است. آن را در صفحه «مدیران» وارد کنید.');
        }
        $tg = new TelegramService();
        $result = $tg->sendMessage((int) $admin['telegram_user_id'], "🧪 <b>پیام آزمایشی</b>\n\n" . $text);
        return (bool) ($result['ok'] ?? false);
    }

    public function retryFailed(int $campaignId): int
    {
        return $this->db->run(
            "UPDATE broadcast_targets SET status = 'pending', error = NULL WHERE campaign_id = :c AND status = 'failed'",
            ['c' => $campaignId]
        )->rowCount();
    }
}
