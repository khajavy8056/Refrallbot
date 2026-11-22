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
     * @return array{campaigns:int,sent:int,failed:int,blocked:int,scheduled:int,locked?:bool,error?:string}
     */

    public static function log(string $level, string $message, array $context = []): void
    {
        $dir = defined('RB_STORAGE') ? (string) RB_STORAGE : dirname(__DIR__, 2) . '/storage';
        $logDir = $dir . '/logs';
        if (!is_dir($logDir) && !@mkdir($logDir, 0775, true) && !is_dir($logDir)) {
            return;
        }
        $logFile = $logDir . '/broadcast.log';
        $ctxStr = $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $line = sprintf(
            "[%s UTC | %s] [%s] %s%s
",
            gmdate('Y-m-d H:i:s'),
            fa_date(now()),
            strtoupper($level),
            $message,
            $ctxStr
        );
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    public static function getLogFilePath(): string
    {
        $dir = defined('RB_STORAGE') ? (string) RB_STORAGE : dirname(__DIR__, 2) . '/storage';
        return $dir . '/logs/broadcast.log';
    }

    public static function recentLogs(int $lines = 100): string
    {
        $file = self::getLogFilePath();
        if (!is_file($file)) {
            return 'هنوز لاگی برای ارسال همگانی ثبت نشده است.';
        }
        $content = @file_get_contents($file);
        if ($content === false || trim($content) === '') {
            return 'فایل لاگ خالی است.';
        }
        $all = explode("
", trim($content));
        $slice = array_slice($all, -$lines);
        return implode("
", $slice);
    }

    public static function clearLog(): bool
    {
        $file = self::getLogFilePath();
        if (is_file($file)) {
            return @file_put_contents($file, '') !== false;
        }
        return true;
    }

    /**
     * اجرای فرصت‌طلبانه (Opportunistic) صف در پس‌زمینه با بودجه زمانی اندک
     * جهت اطمینان از ارسال تدریجی مداوم حتی در صورت عدم تنظیم یا تأخیر کرون هاست
     */
    public static function triggerOpportunisticRun(?Database $db = null, float $timeBudgetSeconds = 3.0): void
    {
        $db ??= Database::instance();
        if (!$db->tableExists('broadcast_campaigns') || !$db->tableExists('broadcast_targets')) {
            return;
        }

        // بررسی سریع: آیا کمپینی با وضعیت queued یا sending و زمان سررسیدشده وجود دارد؟
        $hasPending = (int) $db->value(
            "SELECT 1 FROM broadcast_campaigns
             WHERE (
                 status IN ('sending', 'queued')
                 OR (status = 'failed' AND EXISTS (SELECT 1 FROM broadcast_targets bt WHERE bt.campaign_id = broadcast_campaigns.id AND bt.status = 'pending'))
             )
               AND (scheduled_at IS NULL OR scheduled_at <= :now)
             LIMIT 1",
            ['now' => now()]
        );
        if ($hasPending <= 0) {
            return;
        }

        // جلوگیری از اجرای بیش از حد متوالی در وب (فاصله حداقل ۱۰ ثانیه‌ای بین درخواست‌های وب)
        $dir = defined('RB_STORAGE') ? (string) RB_STORAGE : dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $stampFile = $dir . '/broadcast-last-web-run.txt';
        $now = time();
        if (is_file($stampFile)) {
            $lastRun = (int) @file_get_contents($stampFile);
            if ($now - $lastRun < 10) {
                return;
            }
        }
        @file_put_contents($stampFile, (string) $now, LOCK_EX);

        try {
            (new self($db))->processQueue(15, 30, $timeBudgetSeconds);
        } catch (\Throwable) {
            // نادیده‌گیری خطا برای حفظ سرعت پاسخ وب
        }
    }

    /**
     * همگام‌سازی و بازیابی خودکار کمپین‌های ایجادشده قبل از آپدیت یا معلق‌مانده
     * @return int تعداد کمپین‌های بازیابی‌شده
     */
    public function healStaleCampaigns(): int
    {
        $healed = 0;

        // ۱) کمپین‌هایی که به اشتباه failed یا sent شده‌اند اما هنوز هدف pending دارند
        $staleWithPending = $this->db->all(
            "SELECT c.id, c.title, c.status, COUNT(t.id) as pending_cnt
             FROM broadcast_campaigns c
             JOIN broadcast_targets t ON t.campaign_id = c.id
             WHERE t.status = 'pending' AND c.status IN ('failed', 'sent')
             GROUP BY c.id"
        );
        foreach ($staleWithPending as $row) {
            $this->db->update('broadcast_campaigns', [
                'status' => 'queued',
                'finished_at' => null,
                'updated_at' => now(),
            ], 'id = :id', ['id' => (int) $row['id']]);
            self::log('INFO', "کمپین #{$row['id']} («{$row['title']}») به دلیل داشتن {$row['pending_cnt']} مخاطب معلق پیشین، به صف ارسال بازگردانده شد.");
            $healed++;
        }

        // ۲) کمپین‌های sending یا queued که هیچ هدف pending ندارند و کارشان تمام شده
        $staleDone = $this->db->all(
            "SELECT c.id, c.title
             FROM broadcast_campaigns c
             WHERE c.status IN ('sending', 'queued')
               AND NOT EXISTS (SELECT 1 FROM broadcast_targets t WHERE t.campaign_id = c.id AND t.status = 'pending')"
        );
        foreach ($staleDone as $row) {
            $counts = $this->db->first(
                "SELECT
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS s,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS f,
                    SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) AS b
                 FROM broadcast_targets WHERE campaign_id = :c",
                ['c' => (int) $row['id']]
            );
            $this->db->update('broadcast_campaigns', [
                'sent_count' => (int) ($counts['s'] ?? 0),
                'failed_count' => (int) ($counts['f'] ?? 0),
                'blocked_count' => (int) ($counts['b'] ?? 0),
                'status' => 'sent',
                'finished_at' => now(),
                'updated_at' => now(),
            ], 'id = :id', ['id' => (int) $row['id']]);
            self::log('INFO', "کمپین #{$row['id']} («{$row['title']}») بدون مخاطب معلق بود؛ وضعیت آن به پایان‌یافته تغییر یافت.");
            $healed++;
        }

        return $healed;
    }

    public function processQueue(int $batchSize = 25, int $maxPerRun = 500, ?float $timeBudgetSeconds = null): array
    {
        $startMicro = microtime(true);
        $batchSize = max(1, min(50, $batchSize));
        $maxPerRun = max($batchSize, $maxPerRun);
        $sent = 0;
        $failed = 0;
        $blocked = 0;
        $scheduled = 0;
        $campaignsTouched = 0;
        $deadline = $this->queueDeadline($timeBudgetSeconds);

        $lock = $this->acquireQueueLock();
        if ($lock === null) {
            self::log('WARN', 'پردازش صف ارسال همگانی رد شد: قفل صف توسط فرآیند دیگری در دست اجراست.');
            return [
                'campaigns' => 0,
                'sent' => 0,
                'failed' => 0,
                'blocked' => 0,
                'scheduled' => 0,
                'locked' => true,
                'error' => 'ارسال همگانی هم‌اکنون توسط یک فرایند دیگر در حال اجراست.',
            ];
        }

        try {

        // بازیابی خودکار کمپین‌های معلق یا قدیمی
        $this->healStaleCampaigns();

        $registry = new MessengerRegistry($this->db);
        if ($registry->activePlatforms() === [] && !TelegramService::configured()) {
            self::log('ERROR', 'پردازش صف متوقف شد: هیچ پیام‌رسانی فعال یا متصل نیست.');
            return ['campaigns' => 0, 'sent' => 0, 'failed' => 0, 'blocked' => 0, 'scheduled' => 0, 'error' => 'هیچ پیام‌رسانی متصل نیست'];
        }

        $currentTime = now();
        $campaigns = $this->db->all(
            "SELECT * FROM broadcast_campaigns
             WHERE (
                 status IN ('sending','queued')
                 OR (status = 'failed' AND EXISTS (SELECT 1 FROM broadcast_targets bt WHERE bt.campaign_id = broadcast_campaigns.id AND bt.status = 'pending'))
             )
               AND (scheduled_at IS NULL OR scheduled_at <= :now)
             ORDER BY COALESCE(scheduled_at, created_at) ASC, id ASC LIMIT 5",
            ['now' => $currentTime]
        );

        if ($campaigns === []) {
            self::log('INFO', 'هیچ کمپینی در صف ارسال وجود ندارد.');
            return [
                'campaigns' => 0,
                'sent' => 0,
                'failed' => 0,
                'blocked' => 0,
                'scheduled' => 0,
            ];
        }

        $cIds = array_map(static fn(array $c): int => (int) $c['id'], $campaigns);
        self::log('INFO', "شروع پردازش صف ارسال: " . count($campaigns) . " کمپین در صف [" . implode(', ', $cIds) . "] با دسته {$batchSize} تایی.");

        foreach ($campaigns as $campaign) {
            if ($sent + $failed + $blocked + $scheduled >= $maxPerRun || microtime(true) >= $deadline) {
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
            if ($this->db->columnExists('broadcast_targets', 'next_attempt_at')) {
                $targets = $this->db->all(
                    "SELECT * FROM broadcast_targets
                     WHERE campaign_id = :c
                       AND status = 'pending'
                       AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
                     ORDER BY COALESCE(next_attempt_at, '1970-01-01 00:00:00') ASC, id ASC
                     LIMIT " . $batchSize,
                    ['c' => (int) $campaign['id'], 'now' => $currentTime]
                );
            } else {
                // محافظت برای نصب‌هایی که migration صف بازیابی را هنوز اجرا نکرده‌اند
                $targets = $this->db->all(
                    "SELECT * FROM broadcast_targets WHERE campaign_id = :c AND status = 'pending' ORDER BY id LIMIT " . $batchSize,
                    ['c' => (int) $campaign['id']]
                );
            }

            foreach ($targets as $target) {
                if ($sent + $failed + $blocked + $scheduled >= $maxPerRun || microtime(true) >= $deadline) {
                    break;
                }
                $attempt = max(0, (int) ($target['attempts'] ?? 0)) + 1;
                $attemptAt = now();
                $result = $this->sendToTarget($registry, $campaign, $target, $targetPlatform);
                $attemptColumns = $this->db->columnExists('broadcast_targets', 'attempts')
                    ? ['attempts' => $attempt, 'last_attempt_at' => $attemptAt]
                    : [];

                if ($result['result'] === 'sent') {
                    $this->db->update('broadcast_targets', array_merge([
                        'status' => 'sent', 'sent_at' => $attemptAt, 'error' => null,
                    ], $attemptColumns, $this->nextAttemptColumns(null)), 'id = :id', ['id' => (int) $target['id']]);
                    $sent++;
                    self::log('INFO', "[کمپین #{$campaign['id']}] هدف #{$target['id']} (کاربر #{$target['user_id']}) با موفقیت ارسال شد.");
                } elseif ($result['result'] === 'blocked') {
                    $this->db->update('broadcast_targets', array_merge([
                        'status' => 'blocked', 'sent_at' => $attemptAt, 'error' => 'کاربر ربات را مسدود کرده است',
                    ], $attemptColumns, $this->nextAttemptColumns(null)), 'id = :id', ['id' => (int) $target['id']]);
                    $blocked++;
                    self::log('WARN', "[کمپین #{$campaign['id']}] هدف #{$target['id']} (کاربر #{$target['user_id']}) ربات را مسدود کرده است.");
                } else {
                    $maxAttempts = max(1, (int) Config::get('services.broadcast_max_attempts', 3));
                    if (!empty($result['retryable']) && $attempt < $maxAttempts) {
                        $nextAttempt = date('Y-m-d H:i:s', time() + $this->retryDelaySeconds($attempt));
                        $this->db->update('broadcast_targets', array_merge([
                            'status' => 'pending',
                            'sent_at' => null,
                            'error' => mb_substr((string) $result['error'], 0, 400),
                        ], $attemptColumns, $this->nextAttemptColumns($nextAttempt)), 'id = :id', ['id' => (int) $target['id']]);
                        $scheduled++;
                        self::log('WARN', "[کمپین #{$campaign['id']}] هدف #{$target['id']} (کاربر #{$target['user_id']}) خطای گذرا دریافت کرد ({$result['error']})؛ تلاش {$attempt}/{$maxAttempts}، تکرار بعدی در {$nextAttempt}.");
                    } else {
                        $this->db->update('broadcast_targets', array_merge([
                            'status' => 'failed',
                            'sent_at' => $attemptAt,
                            'error' => mb_substr((string) $result['error'], 0, 400),
                        ], $attemptColumns, $this->nextAttemptColumns(null)), 'id = :id', ['id' => (int) $target['id']]);
                        $failed++;
                        self::log('ERROR', "[کمپین #{$campaign['id']}] هدف #{$target['id']} (کاربر #{$target['user_id']}) شکست قطعی: {$result['error']}.");
                    }
                }
                $delay = (int) Config::get('services.broadcast_delay_ms', 60);
                if ($delay > 0 && microtime(true) < $deadline) {
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

            if ($remaining === 0) {
                self::log('SUCCESS', "کمپین #{$campaign['id']} («{$campaign['title']}») به پایان رسید. ارسال‌شده: {$counts['s']}، ناموفق: {$counts['f']}، مسدود: {$counts['b']}.");
            } else {
                self::log('INFO', "کمپین #{$campaign['id']} («{$campaign['title']}»): {$remaining} مخاطب معلق دیگر در صف باقی مانده است.");
            }
        }

        $elapsedMs = (int) round((microtime(true) - $startMicro) * 1000);
        self::log('INFO', "پایان چرخه ارسال صف: {$sent} ارسال شد، {$failed} ناموفق، {$blocked} مسدود، {$scheduled} زمان‌بندی مجدد. مدت زمان: {$elapsedMs} میلی‌ثانیه.");

        return [
            'campaigns' => $campaignsTouched,
            'sent' => $sent,
            'failed' => $failed,
            'blocked' => $blocked,
            'scheduled' => $scheduled,
        ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** ارسال ایزوله یک پیام کمپین به حساب پیام‌رسان مخاطب */
    private function sendToTarget(MessengerRegistry $registry, array $campaign, array $target, string $targetPlatform): array
    {
        $userId = (int) ($target['user_id'] ?? 0);
        $telegramId = (int) ($target['telegram_id'] ?? 0);
        $userRow = $userId > 0 ? $this->db->first('SELECT * FROM users WHERE id = :id', ['id' => $userId]) : null;
        if ($telegramId <= 0 && $userRow && !empty($userRow['telegram_id'])) {
            $telegramId = (int) $userRow['telegram_id'];
        }
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

        $userRow = $userId > 0 ? $this->db->first('SELECT * FROM users WHERE id = :id', ['id' => $userId]) : null;
        $renderedByPlatform = [];

        $lastErr = 'هیچ پیام‌رسان فعالی برای این کاربر در دسترس نیست.';
        $lastRetryable = false;
        $blockedAny = false;
        foreach ($accounts as $acc) {
            $p = (string) $acc['platform'];
            $chatId = (string) ($acc['external_chat_id'] ?: $acc['external_user_id']);
            $text = $renderedByPlatform[$p] ??= $this->renderBroadcastText((string) ($campaign['message_text'] ?? ''), $userRow, $p);
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
                    return ['result' => 'sent', 'error' => '', 'retryable' => false];
                }
                $desc = (string) ($res['description'] ?? 'خطای ارسال');
                if (stripos($desc, 'blocked') !== false || stripos($desc, 'مسدود') !== false || stripos($desc, 'deactivated') !== false) {
                    (new UserService($this->db))->markMessengerBlocked($p, (string) $acc['external_user_id'], true);
                    $blockedAny = true;
                    $lastErr = 'blocked';
                    $lastRetryable = false;
                    continue;
                }
                $lastErr = MessengerCapabilities::label($p) . ': ' . $desc;
                $lastRetryable = self::isRetryableError($lastErr);
            } catch (\Throwable $e) {
                $lastErr = MessengerCapabilities::label($p) . ': ' . ErrorHandler::redact($e->getMessage());
                $lastRetryable = self::isRetryableError($lastErr);
            }
        }

        // اگر یک خطای گذرا وجود داشته باشد، تلاش مجدد محدود از معلق ماندن صف بهتر است.
        if ($lastRetryable) {
            return ['result' => 'failed', 'error' => $lastErr, 'retryable' => true];
        }
        if ($blockedAny) {
            return ['result' => 'blocked', 'error' => 'blocked', 'retryable' => false];
        }
        return ['result' => 'failed', 'error' => $lastErr, 'retryable' => false];
    }

    /** جایگذاری متغیرهای مستندشدهٔ پیام همگانی برای هر مخاطب */
    private function renderBroadcastText(string $template, ?array $user, string $platform): string
    {
        $rawName = $user ? trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) : '';
        $username = $user ? trim((string) ($user['username'] ?? '')) : '';
        $name = $rawName !== '' ? $rawName : ($username !== '' ? ltrim($username, '@') : 'کاربر');
        $balance = $user ? (int) ($user['wallet_balance'] ?? 0) : 0;
        $inviteLink = '';
        if ($user && !empty($user['referral_code'])) {
            try {
                $inviteLink = (new MessengerRegistry($this->db))->buildReferralLink(
                    $platform,
                    (string) $user['referral_code']
                );
            } catch (\Throwable) {
                $inviteLink = '';
            }
        }

        return strtr($template, [
            '{name}' => e($name),
            '{username}' => e($username !== '' ? '@' . ltrim($username, '@') : $name),
            '{balance}' => number_format($balance),
            '{invite_link}' => e($inviteLink),
        ]);
    }

    private static function isRetryableError(string $message): bool
    {
        $lower = mb_strtolower($message, 'UTF-8');
        if (preg_match('~\b(429|5\d\d)\b~', $lower)) {
            return true;
        }
        foreach ([
            'too many', 'rate limit', 'rate', 'timeout', 'timed out', 'time out',
            'temporar', 'unavailable', 'network', 'connection', 'connect',
            'could not resolve', 'dns', 'reset by peer', 'bad gateway', 'gateway',
            'بیش از حد', 'محدودیت', 'زمان انتظار', 'ارتباط شبکه', 'اتصال شبکه',
            'موقتی', 'در دسترس نیست', 'عدم دریافت پاسخ', 'سرور',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function retryDelaySeconds(int $attempt): int
    {
        return min(900, 120 * (2 ** max(0, $attempt - 1)));
    }

    /** @return array<string,string|null> */
    private function nextAttemptColumns(?string $nextAttemptAt): array
    {
        if (!$this->db->columnExists('broadcast_targets', 'next_attempt_at')) {
            return [];
        }
        return ['next_attempt_at' => $nextAttemptAt];
    }

    private function queueDeadline(?float $timeBudgetSeconds): float
    {
        if ($timeBudgetSeconds !== null && $timeBudgetSeconds > 0) {
            return microtime(true) + max(1.0, min(120.0, $timeBudgetSeconds));
        }
        $maxExecution = (int) ini_get('max_execution_time');
        if ($maxExecution > 0) {
            return microtime(true) + max(2.0, min(120.0, (float) $maxExecution - 2.0));
        }
        $configured = (int) Config::get('services.broadcast_cron_budget_seconds', 20);
        return microtime(true) + max(2.0, min(120.0, (float) $configured));
    }

    /** @return resource|null */
    private function acquireQueueLock()
    {
        $dir = defined('RB_STORAGE') ? (string) RB_STORAGE : dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $handle = @fopen($dir . '/broadcast-queue.lock', 'c+');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
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
        $sql = "UPDATE broadcast_targets SET status = 'pending', error = NULL, sent_at = NULL";
        if ($this->db->columnExists('broadcast_targets', 'attempts')) {
            $sql .= ", attempts = 0, last_attempt_at = NULL";
        }
        if ($this->db->columnExists('broadcast_targets', 'next_attempt_at')) {
            $sql .= ", next_attempt_at = NULL";
        }
        $sql .= " WHERE campaign_id = :c AND status = 'failed'";
        return $this->db->run($sql, ['c' => $campaignId])->rowCount();
    }
}
