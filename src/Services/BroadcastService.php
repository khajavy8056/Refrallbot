<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Config;
use RB\Support\Database;
use RB\Support\ErrorHandler;

/**
 * ارسال پیام همگانی به‌صورت مرحله‌ای (Batch) برای رعایت محدودیت نرخ تلگرام
 *
 * چرا مرحله‌ای؟ هاست اشتراکی معمولاً محدودیت زمان اجرا دارد و تلگرام نیز
 * حدود ۳۰ پیام در ثانیه مجاز می‌داند. هر اجرای Cron فقط یک دسته را ارسال می‌کند.
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
        if (!TelegramService::configured()) {
            throw new \RuntimeException('ابتدا توکن ربات را تنظیم کنید.');
        }
        $segment = (string) ($data['segment'] ?? 'all');
        if (!array_key_exists($segment, self::SEGMENTS)) {
            throw new \RuntimeException('گروه مخاطبان نامعتبر است.');
        }
        $params = $data['segment_params'] ?? [];
        $targets = $this->resolveTargets($segment, $params);
        if ($targets === []) {
            throw new \RuntimeException('هیچ مخاطبی برای این گروه یافت نشد.');
        }

        $scheduledAt = null;
        if (!empty($data['scheduled_at'])) {
            $ts = strtotime((string) $data['scheduled_at']);
            if ($ts !== false && $ts > time()) {
                $scheduledAt = date('Y-m-d H:i:s', $ts);
            }
        }

        return $this->db->transaction(function () use ($data, $segment, $params, $targets, $adminId, $scheduledAt) {
            $campaignId = $this->db->insert('broadcast_campaigns', [
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
            ]);

            foreach ($targets as $target) {
                $this->db->insert('broadcast_targets', [
                    'campaign_id' => $campaignId,
                    'user_id' => $target['id'],
                    'telegram_id' => $target['telegram_id'],
                    'status' => 'pending',
                ]);
            }

            return $this->db->first('SELECT * FROM broadcast_campaigns WHERE id = :id', ['id' => $campaignId]) ?? [];
        });
    }

    /** @return array<int,array{id:int,telegram_id:int}> */
    public function resolveTargets(string $segment, array $params = []): array
    {
        $sql = 'SELECT id, telegram_id FROM users WHERE telegram_id IS NOT NULL';
        $bind = [];
        switch ($segment) {
            case 'active':
                $sql .= " AND status = 'active'";
                break;
            case 'suspicious':
                $sql .= " AND status = 'suspicious'";
                break;
            case 'with_phone':
                $sql .= " AND phone IS NOT NULL AND phone <> ''";
                break;
            case 'with_refs':
                $sql .= ' AND direct_ref_count > 0';
                break;
            case 'no_refs':
                $sql .= ' AND direct_ref_count = 0';
                break;
            case 'with_balance':
                $sql .= ' AND wallet_balance > 0';
                break;
            case 'level':
                $level = max(1, min(8, (int) ($params['level'] ?? 1)));
                $sql = 'SELECT DISTINCT u.id, u.telegram_id FROM users u
                        JOIN referral_relations rr ON rr.descendant_id = u.id
                        WHERE rr.depth = :lvl';
                $bind['lvl'] = $level;
                break;
            case 'user_ids':
                $ids = array_map('intval', (array) ($params['ids'] ?? []));
                if ($ids === []) {
                    return [];
                }
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sql .= " AND id IN ({$placeholders})";
                foreach ($ids as $i => $id) {
                    $bind[$i + 1] = $id;
                }
                break;
            case 'campaign':
                $campaignId = (int) ($params['campaign_id'] ?? 0);
                if ($campaignId <= 0) {
                    return [];
                }
                $sql = 'SELECT DISTINCT u.id, u.telegram_id FROM users u
                        JOIN campaign_participants cp ON cp.user_id = u.id
                        WHERE cp.campaign_id = :cid';
                $bind['cid'] = $campaignId;
                break;
            case 'all':
            default:
                break;
        }
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
     * پردازش یک دسته از کمپین‌های در حال ارسال
     * @return array{campaigns:int,sent:int,failed:int,blocked:int}
     */
    public function processQueue(int $batchSize = 25, int $maxPerRun = 500): array
    {
        $batchSize = max(1, min(50, $batchSize));
        $maxPerRun = max($batchSize, $maxPerRun);
        $sent = 0;
        $failed = 0;
        $blocked = 0;
        $campaignsTouched = 0;

        $campaigns = $this->db->all(
            "SELECT * FROM broadcast_campaigns
             WHERE status IN ('sending','queued')
               AND (scheduled_at IS NULL OR scheduled_at <= :now)
             ORDER BY id ASC LIMIT 5",
            ['now' => now()]
        );

        $tg = new TelegramService();
        if (!$tg->isConfigured()) {
            return ['campaigns' => 0, 'sent' => 0, 'failed' => 0, 'blocked' => 0, 'error' => 'ربات متصل نیست'];
        }

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

            $targets = $this->db->all(
                "SELECT * FROM broadcast_targets WHERE campaign_id = :c AND status = 'pending' ORDER BY id LIMIT " . $batchSize,
                ['c' => (int) $campaign['id']]
            );

            foreach ($targets as $target) {
                if ($sent + $failed >= $maxPerRun) {
                    break;
                }
                $result = $this->sendOne($tg, $campaign, (int) $target['telegram_id']);
                if ($result === 'sent') {
                    $this->db->update('broadcast_targets', [
                        'status' => 'sent', 'sent_at' => now(), 'error' => null,
                    ], 'id = :id', ['id' => (int) $target['id']]);
                    $sent++;
                } elseif ($result === 'blocked') {
                    $this->db->update('broadcast_targets', [
                        'status' => 'blocked', 'sent_at' => now(), 'error' => 'کاربر ربات را بلاک کرده است',
                    ], 'id = :id', ['id' => (int) $target['id']]);
                    $blocked++;
                } else {
                    $this->db->update('broadcast_targets', [
                        'status' => 'failed', 'sent_at' => now(), 'error' => mb_substr((string) $result, 0, 400),
                    ], 'id = :id', ['id' => (int) $target['id']]);
                    $failed++;
                }
                // رعایت فاصله بین ارسال‌ها
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

    /** @return string 'sent' | 'blocked' | string(خطا) */
    private function sendOne(TelegramService $tg, array $campaign, int $telegramId): string
    {
        $options = ['parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        $buttons = $campaign['buttons'] ? json_decode((string) $campaign['buttons'], true) : null;
        if (is_array($buttons) && $buttons !== []) {
            $options['reply_markup'] = ['inline_keyboard' => $buttons];
        }

        $text = (string) $campaign['message_text'];

        if (!empty($campaign['media_file_id'])) {
            $method = match ($campaign['media_type']) {
                'photo' => 'sendPhoto',
                'video' => 'sendVideo',
                'document' => 'sendDocument',
                default => 'sendMessage',
            };
            $field = match ($campaign['media_type']) {
                'photo' => 'photo',
                'video' => 'video',
                'document' => 'document',
                default => null,
            };
            if ($field !== null) {
                $params = ['chat_id' => $telegramId, $field => $campaign['media_file_id']];
                if (trim($text) !== '') {
                    $params['caption'] = $text;
                    $params['parse_mode'] = 'HTML';
                }
                if (isset($options['reply_markup'])) {
                    $params['reply_markup'] = $options['reply_markup'];
                }
                $result = $tg->call($method, $params);
                return $this->classify($result);
            }
        }

        return $this->classify($tg->sendMessage($telegramId, $text, $options));
    }

    private function classify(array $result): string
    {
        if ($result['ok'] ?? false) {
            return 'sent';
        }
        $code = (int) ($result['error_code'] ?? 0);
        $description = (string) ($result['description'] ?? 'خطای نامشخص');
        if ($code === 403 || stripos($description, 'blocked') !== false || stripos($description, 'deactivated') !== false) {
            return 'blocked';
        }
        if ($code === 429) {
            $retry = (int) ($result['parameters']['retry_after'] ?? 2);
            sleep(min(5, max(1, $retry)));
            return '429: محدودیت نرخ ارسال — تلاش بعدی در اجرای بعدی';
        }
        return $description;
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

    /** تلاش مجدد ارسال‌های ناموفق */
    public function retryFailed(int $campaignId): int
    {
        return $this->db->run(
            "UPDATE broadcast_targets SET status = 'pending', error = NULL WHERE campaign_id = :c AND status = 'failed'",
            ['c' => $campaignId]
        )->rowCount();
    }
}
