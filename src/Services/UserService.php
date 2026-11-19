<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Config;
use RB\Support\Database;
use RB\Support\Request;

/**
 * مدیریت کاربران و شبکه معرفی
 */
final class UserService
{
    private Database $db;
    private int $maxDepth;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
        $this->maxDepth = (int) Config::get('app.max_referral_depth', 8);
    }

    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    public function findByTelegramId(int|string $telegramId): ?array
    {
        return $this->db->first('SELECT * FROM users WHERE telegram_id = :t', ['t' => (int) $telegramId]);
    }

    public function findByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        return $this->db->first('SELECT * FROM users WHERE referral_code = :c', ['c' => $code]);
    }

    public function exists(int $id): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM users WHERE id = :id', ['id' => $id]);
    }

    /**
     * ثبت/به‌روزرسانی کاربر هنگام Start ربات
     * @return array{user:array,is_new:bool}
     */
    public function registerFromTelegram(array $tgUser, ?string $startPayload = null, array $context = []): array
    {
        $telegramId = (int) ($tgUser['id'] ?? 0);
        if ($telegramId <= 0) {
            throw new \InvalidArgumentException('شناسه تلگرام نامعتبر است.');
        }

        try {
            $out = $this->db->transaction(function () use ($tgUser, $telegramId, $startPayload, $context) {
                $existing = $this->findByTelegramId($telegramId);
                $isNew = $existing === null;

                if (!$isNew) {
                    $nextUsername = array_key_exists('username', $tgUser) && $tgUser['username'] !== null
                        ? mb_substr((string) $tgUser['username'], 0, 64)
                        : $existing['username'];
                    $nextFirst = array_key_exists('first_name', $tgUser) && $tgUser['first_name'] !== null
                        ? mb_substr((string) $tgUser['first_name'], 0, 120)
                        : $existing['first_name'];
                    $nextLast = array_key_exists('last_name', $tgUser) && $tgUser['last_name'] !== null
                        ? mb_substr((string) $tgUser['last_name'], 0, 120)
                        : $existing['last_name'];
                    $nextLang = !empty($tgUser['language_code'])
                        ? mb_substr((string) $tgUser['language_code'], 0, 10)
                        : ($existing['language'] ?? 'fa');

                    $this->db->update('users', [
                        'username' => $nextUsername,
                        'first_name' => $nextFirst,
                        'last_name' => $nextLast,
                        'language' => $nextLang,
                        'last_activity_at' => now(),
                        'last_ip' => $context['ip'] ?? $existing['last_ip'],
                        'updated_at' => now(),
                    ], 'id = :id', ['id' => (int) $existing['id']]);
                    $user = $this->find((int) $existing['id']);
                    if ($user !== null) {
                        $this->maybeQualify($user);
                    }
                    return ['user' => $user ?? $existing, 'is_new' => false];
                }

                $referrer = null;
                if ($startPayload !== null && $startPayload !== '') {
                    $referrer = $this->findByCode($this->normalizeCode($startPayload));
                    // جلوگیری از خودمعرفی (بر اساس شناسه تلگرام)
                    if ($referrer !== null && (int) ($referrer['telegram_id'] ?? 0) === $telegramId) {
                        $referrer = null;
                    }
                }

                $userId = $this->db->insert('users', [
                    'telegram_id' => $telegramId,
                    'username' => isset($tgUser['username']) ? mb_substr((string) $tgUser['username'], 0, 64) : null,
                    'first_name' => isset($tgUser['first_name']) ? mb_substr((string) $tgUser['first_name'], 0, 120) : null,
                    'last_name' => isset($tgUser['last_name']) ? mb_substr((string) $tgUser['last_name'], 0, 120) : null,
                    'language' => mb_substr((string) ($tgUser['language_code'] ?? 'fa'), 0, 10) ?: 'fa',
                    'referral_code' => $this->generateUniqueCode(),
                    'status' => 'active',
                    'verify_status' => 'pending',
                    'joined_at' => now(),
                    'last_activity_at' => now(),
                    'signup_ip' => $context['ip'] ?? Request::ip(),
                    'last_ip' => $context['ip'] ?? Request::ip(),
                    'meta' => json_encode([
                        'start_payload' => $startPayload,
                        'photo_url' => $tgUser['photo_url'] ?? null,
                        'is_premium' => $tgUser['is_premium'] ?? false,
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                (new WalletService($this->db))->ensureWallet($userId);

                if ($referrer !== null && (int) $referrer['id'] !== $userId) {
                    $this->attachReferral($userId, (int) $referrer['id']);
                }

                // ثبت رویداد ورود (idempotent)
                $this->db->insertIgnore('referral_events', [
                    'event_key' => 'user_joined:' . $userId,
                    'type' => 'user_joined',
                    'user_id' => $userId,
                    'actor_id' => $referrer['id'] ?? null,
                    'source_user_id' => $userId,
                    'base_amount' => 0,
                    'status' => 'processed',
                    'payload' => json_encode(['telegram_id' => $telegramId], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ], 'event_key');

                $user = $this->find($userId);
                if ($user !== null) {
                    $this->maybeQualify($user);
                }
                return ['user' => $this->find($userId) ?? $user, 'is_new' => true];
            });
        } catch (\Throwable $e) {
            if (!$this->db->inTransaction()) {
                Notifier::clearDeferred();
            }
            throw $e;
        }

        if (!$this->db->inTransaction()) {
            Notifier::flushDeferred();
        }
        return $out;
    }

    /**
     * اتصال زیرمجموعه به معرف و ساخت زنجیره تا ۸ سطح
     */
    public function attachReferral(int $userId, int $referrerId, ?int $adminId = null): void
    {
        if ($userId === $referrerId) {
            throw new \RuntimeException('کاربر نمی‌تواند معرف خودش باشد.');
        }
        try {
            $this->db->transaction(function () use ($userId, $referrerId, $adminId) {
                $user = $this->find($userId);
                if ($user === null) {
                    throw new \RuntimeException('کاربر یافت نشد.');
                }
                if (!empty($user['referred_by'])) {
                    // معرف قبلاً ثبت شده — تغییر فقط از پنل مدیریت مجاز است
                    throw new \RuntimeException('معرف این کاربر قبلاً ثبت شده است.');
                }
                if (!$this->exists($referrerId)) {
                    throw new \RuntimeException('معرف یافت نشد.');
                }
                if ($this->isDescendant($referrerId, $userId)) {
                    throw new \RuntimeException('این ارتباط یک حلقه در شبکه ایجاد می‌کند.');
                }

                $this->db->update('users', [
                    'referred_by' => $referrerId,
                    'referred_at' => now(),
                    'updated_at' => now(),
                ], 'id = :id', ['id' => $userId]);

                $this->rebuildRelations($userId);
                $this->refreshNetworkCounters([$referrerId, $userId]);

                // اطلاع‌رسانی «دعوت موفق» به معرف (حتی اگر پاداش معرفی صفر باشد)
                if ($adminId === null) {
                    $freshReferrer = $this->find($referrerId);
                    $freshUser = $this->find($userId) ?? $user;
                    if ($freshReferrer !== null) {
                        Notifier::referralJoined(
                            $referrerId,
                            $freshUser,
                            (int) ($freshReferrer['direct_ref_count'] ?? 1),
                            (int) ($freshReferrer['total_ref_count'] ?? 1)
                        );
                    }
                }
            });
        } catch (\Throwable $e) {
            if (!$this->db->inTransaction()) {
                Notifier::clearDeferred();
            }
            throw $e;
        }

        if (!$this->db->inTransaction()) {
            Notifier::flushDeferred();
        }
    }

    /**
     * بازسازی کامل زنجیره‌ی اجداد یک کاربر بر اساس referred_by
     * (منبع واحد حقیقت: ستون referred_by در جدول users)
     */
    public function rebuildRelations(int $userId): int
    {
        $this->db->delete('referral_relations', 'descendant_id = :d', ['d' => $userId]);

        $chain = [];
        $current = (int) ($this->db->value('SELECT referred_by FROM users WHERE id = :id', ['id' => $userId]) ?? 0);
        $seen = [$userId => true];
        $depth = 1;
        while ($current > 0 && $depth <= $this->maxDepth && !isset($seen[$current])) {
            $seen[$current] = true;
            $chain[$depth] = $current;
            $current = (int) ($this->db->value('SELECT referred_by FROM users WHERE id = :id', ['id' => $current]) ?? 0);
            $depth++;
        }

        foreach ($chain as $d => $ancestorId) {
            $this->db->insert('referral_relations', [
                'ancestor_id' => (int) $ancestorId,
                'descendant_id' => $userId,
                'depth' => (int) $d,
                'created_at' => now(),
            ]);
        }
        $this->refreshNetworkCounters(array_values($chain));
        return count($chain);
    }

    /**
     * به‌روزرسانی شمارنده‌های شبکه برای کاربران داده‌شده
     *
     * نکته سازگاری MySQL:
     * در MySQL خطای 1093 رخ می‌دهد اگر جدول هدف UPDATE (اینجا `users`) مستقیماً در
     * زیرکوئری FROM همان UPDATE ظاهر شود. به همین دلیل شمارش مستقیم ابتدا جداگانه
     * خوانده و سپس با UPDATE ساده و استاندارد اعمال می‌شود (سازگار با MySQL 5.7/8.x و SQLite).
     *
     * @param int[] $userIds
     */
    public function refreshNetworkCounters(array $userIds = []): void
    {
        if ($userIds === []) {
            $this->db->run('UPDATE users SET direct_ref_count = 0, total_ref_count = 0');

            $directRows = $this->db->all(
                'SELECT referred_by AS uid, COUNT(*) AS cnt FROM users WHERE referred_by IS NOT NULL GROUP BY referred_by'
            );
            foreach ($directRows as $row) {
                $uid = (int) ($row['uid'] ?? 0);
                if ($uid > 0) {
                    $this->db->update('users', ['direct_ref_count' => (int) $row['cnt']], 'id = :id', ['id' => $uid]);
                }
            }

            $totalRows = $this->db->all(
                'SELECT ancestor_id AS uid, COUNT(*) AS cnt FROM referral_relations GROUP BY ancestor_id'
            );
            foreach ($totalRows as $row) {
                $uid = (int) ($row['uid'] ?? 0);
                if ($uid > 0) {
                    $this->db->update('users', ['total_ref_count' => (int) $row['cnt']], 'id = :id', ['id' => $uid]);
                }
            }
            return;
        }

        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $direct = (int) $this->db->value(
                'SELECT COUNT(*) FROM users WHERE referred_by = :id',
                ['id' => $uid]
            );
            $total = (int) $this->db->value(
                'SELECT COUNT(*) FROM referral_relations WHERE ancestor_id = :id',
                ['id' => $uid]
            );
            $this->db->update('users', [
                'direct_ref_count' => $direct,
                'total_ref_count' => $total,
            ], 'id = :id', ['id' => $uid]);
        }
    }

    /**
     * @return array<int,int> [depth => ancestor_id] با depth از ۱
     */
    public function ancestors(int $userId, int $maxDepth = 8): array
    {
        $rows = $this->db->all(
            'SELECT ancestor_id, depth FROM referral_relations WHERE descendant_id = :d AND depth <= :m ORDER BY depth',
            ['d' => $userId, 'm' => $maxDepth]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['depth']] = (int) $row['ancestor_id'];
        }
        return $out;
    }

    public function isDescendant(int $candidate, int $ancestor): bool
    {
        return (bool) $this->db->value(
            'SELECT 1 FROM referral_relations WHERE ancestor_id = :a AND descendant_id = :d LIMIT 1',
            ['a' => $ancestor, 'd' => $candidate]
        );
    }

    /** درخت زیرمجموعه‌ها تا عمق مشخص */
    public function descendants(int $userId, int $maxDepth = 8, int $limit = 2000): array
    {
        $rows = $this->db->all(
            'SELECT rr.depth, rr.descendant_id, u.telegram_id, u.username, u.first_name, u.last_name,
                    u.status, u.joined_at, u.last_activity_at, u.total_earned, u.total_ref_count, u.verify_status, u.referred_by
             FROM referral_relations rr
             JOIN users u ON u.id = rr.descendant_id
             WHERE rr.ancestor_id = :a AND rr.depth <= :m
             ORDER BY rr.depth ASC, rr.descendant_id ASC
             LIMIT ' . (int) $limit,
            ['a' => $userId, 'm' => $maxDepth]
        );
        return $rows;
    }

    /** زیرمجموعه‌های مستقیم */
    public function directReferrals(int $userId, int $limit = 500): array
    {
        return $this->db->all(
            'SELECT * FROM users WHERE referred_by = :r ORDER BY joined_at DESC LIMIT ' . (int) $limit,
            ['r' => $userId]
        );
    }

    /**
     * فهرست مؤثر شرایط لازم پیش از پرداخت پاداش دعوت (هم برای دعوت‌کننده و هم دعوت‌شده).
     * علاوه بر شرایط انتخاب‌شده در تنظیمات پورسانت، در صورت فعال بودن دروازه‌های ورود ربات
     * (تأیید شماره تماس یا عضویت اجباری در کانال‌های فعال) آن‌ها نیز به‌صورت خودکار الزام می‌شوند
     * تا هیچ پاداشی پیش از تکمیل کامل احراز پرداخت نگردد.
     *
     * @return string[]
     */
    public function effectiveConditions(?CommissionSettings $settings = null): array
    {
        $settings ??= CommissionSettings::current($this->db);
        $raw = $settings->qualifyConditions ?: ['joined'];
        $normalized = [];
        foreach ($raw as $cond) {
            $key = match ((string) $cond) {
                'contact', 'phone' => 'phone',
                'channel', 'channels' => 'channels',
                'profile' => 'profile',
                'profile_complete' => 'profile_complete',
                'approved', 'manual_approval' => 'approved',
                'activity' => 'activity',
                'joined' => 'joined',
                default => (string) $cond,
            };
            if ($key !== '' && !in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }
        if ($normalized === []) {
            $normalized[] = 'joined';
        }

        // همگام‌سازی با دروازه‌های اجباری ورود به ربات
        if (SettingsService::bool('contact_required', false) && !in_array('phone', $normalized, true)) {
            $normalized[] = 'phone';
        }
        if (SettingsService::bool('force_channel_membership', false) && !in_array('channels', $normalized, true)) {
            $membership = new MembershipService($this->db);
            $tg = new TelegramService(null, $this->db);
            if ($tg->isConfigured() && $membership->activeChannels() !== []) {
                $normalized[] = 'channels';
            }
        }

        return $normalized;
    }

    /**
     * چک‌لیست پویای شرایط تکمیل ثبت‌نام و دریافت پاداش دعوت برای نمایش در پیام‌ها و رابط کاربری
     *
     * @return array<int,array{key:string,label:string,done:bool}>
     */
    public function qualificationChecklist(array $user, ?CommissionSettings $settings = null): array
    {
        $settings ??= CommissionSettings::current($this->db);
        $conditions = $this->effectiveConditions($settings);
        $membership = new MembershipService($this->db);
        $activeChannels = $membership->activeChannels();
        $channelNames = array_values(array_filter(array_map(
            static fn(array $c): string => trim((string) ($c['title'] ?? '')),
            $activeChannels
        )));
        $minMsgs = max(0, (int) SettingsService::get('qualify_min_activity_messages', '0'));

        $items = [];
        foreach ($conditions as $cond) {
            $done = $this->meetsConditions($user, [$cond]);
            $label = match ($cond) {
                'joined' => 'شروع ربات و ثبت اولیه دعوت',
                'profile' => 'تکمیل پروفایل کاربری (نام و نام خانوادگی)',
                'profile_complete' => 'تکمیل نام پروفایل کاربری',
                'phone', 'contact' => 'ثبت و تأیید شماره تماس',
                'channels', 'channel' => $channelNames !== []
                    ? 'عضویت در کانال اجباری (' . implode('، ', array_slice($channelNames, 0, 3)) . (count($channelNames) > 3 ? ' و ...' : '') . ') و زدن دکمه بررسی عضویت'
                    : 'عضویت در کانال‌های اجباری ربات و تأیید عضویت',
                'approved', 'manual_approval' => 'بررسی و تأیید عضویت توسط مدیریت',
                'activity' => $minMsgs > 0
                    ? 'ثبت حداقل ' . fa_digits($minMsgs) . ' پیام فعالیت در ربات'
                    : 'فعالیت در ربات',
                default => '',
            };
            if ($label !== '') {
                $items[] = ['key' => $cond, 'label' => $label, 'done' => $done];
            }
        }
        return $items;
    }

    /**
     * فقط شرایط باقی‌مانده (تکمیل‌نشده) کاربر برای دریافت پاداش دعوت
     *
     * @return array<int,array{key:string,label:string,done:bool}>
     */
    public function pendingRequirements(array $user, ?CommissionSettings $settings = null): array
    {
        return array_values(array_filter(
            $this->qualificationChecklist($user, $settings),
            static fn(array $item): bool => !$item['done']
        ));
    }

    /**
     * بررسی شرایط واجد شرایط شدن معرفی و ثبت پاداش دوطرفه (دعوت‌کننده + دعوت‌شده) و چندلایه.
     * هیچ پاداشی به هیچ‌یک از دو طرف پیش از تکمیل و تأیید همهٔ شرایط پرداخت نمی‌شود.
     */
    public function maybeQualify(array $user, bool $force = false): bool
    {
        if (empty($user['referred_by'])) {
            return false;
        }
        $userId = (int) $user['id'];
        $referrerId = (int) $user['referred_by'];
        if ($userId <= 0 || $referrerId <= 0 || $userId === $referrerId) {
            return false;
        }
        if (!empty($user['qualified_at']) && !$force) {
            return false;
        }
        if (($user['status'] ?? 'active') === 'blocked') {
            return false;
        }

        $settings = CommissionSettings::current($this->db, true);
        $conditions = $this->effectiveConditions($settings);
        if (!$this->meetsConditions($user, $conditions)) {
            return false;
        }

        // علامت‌گذاری اتمیک واجد شرایط (جلوگیری از اجرای همزمان دو درخواست)
        $stmt = $this->db->run(
            'UPDATE users SET qualified_at = :now, updated_at = :now2 WHERE id = :id AND qualified_at IS NULL',
            ['now' => now(), 'now2' => now(), 'id' => $userId]
        );
        if (!$force && $stmt->rowCount() === 0) {
            return false;
        }

        $service = new CommissionService($this->db, $settings);
        $result = $service->processEvent([
            'event_key' => 'referral_qualified:' . $userId,
            'type' => 'referral_qualified',
            'user_id' => $userId,
            'actor_id' => $referrerId,
            'base_amount' => $settings->directReward,
            'invitee_amount' => $settings->inviteeReward,
            'applies_direct' => true,
            'applies_invitee' => true,
            'payload' => [
                'referred_by' => $referrerId,
                'conditions' => $conditions,
                'direct_reward' => $settings->effectiveDirectReward(),
                'invitee_reward' => $settings->effectiveInviteeReward(),
                'telegram_id' => (int) ($user['telegram_id'] ?? 0),
            ],
        ]);

        if (!$result['duplicated'] && $result['total'] > 0) {
            Notifier::referralCommission($user, $result, $settings);
        }
        SecurityService::evaluateUser($userId);
        return true;
    }

    public function meetsConditions(array $user, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $ok = match ((string) $condition) {
                'joined' => true,
                'profile' => trim((string) ($user['first_name'] ?? '')) !== '' && trim((string) ($user['last_name'] ?? '')) !== '',
                'profile_complete' => trim((string) ($user['first_name'] ?? '')) !== '',
                'phone', 'contact' => trim((string) ($user['phone'] ?? '')) !== '',
                'channels', 'channel' => (bool) ($user['channels_verified'] ?? false),
                'approved', 'manual_approval' => (string) ($user['verify_status'] ?? '') === 'verified',
                'activity' => $this->hasActivity((int) ($user['id'] ?? 0)),
                default => true,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /** تأیید دستی عضویت کاربر توسط مدیر و بررسی خودکار پرداخت پاداش دعوت */
    public function approveVerification(int $userId, ?int $adminId = null): bool
    {
        $user = $this->find($userId);
        if ($user === null) {
            throw new \InvalidArgumentException('کاربر یافت نشد.');
        }
        $this->db->update('users', [
            'verify_status' => 'verified',
            'updated_at' => now(),
        ], 'id = :id', ['id' => $userId]);
        AuditService::log('user.verified', 'user', $userId, [
            'previous' => $user['verify_status'] ?? 'pending',
            'to' => 'verified',
        ], $adminId);

        $fresh = $this->find($userId);
        $qualified = false;
        if ($fresh !== null) {
            $qualified = $this->maybeQualify($fresh);
            if (!$this->db->inTransaction()) {
                Notifier::flushDeferred();
            }
        }
        return $qualified;
    }

    /** تکمیل/ویرایش نام و نام خانوادگی پروفایل کاربر و بررسی خودکار شرایط پاداش */
    public function updateProfile(int $userId, string $firstName, string $lastName): bool
    {
        $firstName = mb_substr(trim($firstName), 0, 120);
        $lastName = mb_substr(trim($lastName), 0, 120);
        if ($firstName === '') {
            throw new \InvalidArgumentException('وارد کردن نام الزامی است.');
        }
        $this->db->update('users', [
            'first_name' => $firstName,
            'last_name' => $lastName !== '' ? $lastName : null,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $userId]);

        $fresh = $this->find($userId);
        $qualified = false;
        if ($fresh !== null) {
            $qualified = $this->maybeQualify($fresh);
            if (!$this->db->inTransaction()) {
                Notifier::flushDeferred();
            }
        }
        return $qualified;
    }

    private function hasActivity(int $userId): bool
    {
        $minMessages = (int) \RB\Services\SettingsService::get('qualify_min_activity_messages', '0');
        if ($minMessages <= 0) {
            return true;
        }
        $count = (int) $this->db->value(
            "SELECT COUNT(*) FROM telegram_messages WHERE user_id = :u AND direction = 'in'",
            ['u' => $userId]
        );
        return $count >= $minMessages;
    }

    public function generateUniqueCode(): string
    {
        for ($i = 0; $i < 12; $i++) {
            $code = strtoupper(substr(str_replace(['0', 'O', 'I', '1'], ['A', 'B', 'C', 'D'], bin2hex(random_bytes(4))), 0, 8));
            $exists = $this->db->value('SELECT 1 FROM users WHERE referral_code = :c', ['c' => $code]);
            if (!$exists) {
                return $code;
            }
        }
        return strtoupper(bin2hex(random_bytes(6)));
    }

    private function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        // پشتیبانی از فرمت ref_XXXX
        if (str_starts_with($code, 'REF_')) {
            $code = substr($code, 4);
        }
        return $code;
    }

    public function touchActivity(int $userId, ?string $ip = null): void
    {
        $this->db->run(
            'UPDATE users SET last_activity_at = :now, last_ip = COALESCE(:ip, last_ip) WHERE id = :id',
            ['now' => now(), 'ip' => $ip, 'id' => $userId]
        );
    }

    public function setStatus(int $userId, string $status, ?int $adminId = null, string $reason = ''): void
    {
        if (!in_array($status, ['active', 'blocked', 'suspicious'], true)) {
            throw new \InvalidArgumentException('وضعیت نامعتبر است.');
        }
        $before = $this->find($userId);
        $this->db->update('users', ['status' => $status, 'updated_at' => now()], 'id = :id', ['id' => $userId]);
        AuditService::log('user.status_changed', 'user', $userId, [
            'from' => $before['status'] ?? null,
            'to' => $status,
            'reason' => $reason,
        ], $adminId);
    }

    public function setPhone(int $userId, string $phone): void
    {
        $this->db->update('users', [
            'phone' => $phone,
            'contact_verified' => 1,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $userId]);
        $user = $this->find($userId);
        if ($user) {
            $this->maybeQualify($user);
            if (!$this->db->inTransaction()) {
                Notifier::flushDeferred();
            }
        }
    }

    public function markChannelsVerified(int $userId): void
    {
        $this->db->update('users', ['channels_verified' => 1, 'updated_at' => now()], 'id = :id', ['id' => $userId]);
        $user = $this->find($userId);
        if ($user) {
            $this->maybeQualify($user);
            if (!$this->db->inTransaction()) {
                Notifier::flushDeferred();
            }
        }
    }

    /** آمار کلی کاربر برای پنل */
    public function stats(int $userId): array
    {
        $user = $this->find($userId);
        if ($user === null) {
            return [];
        }
        $wallet = (new WalletService($this->db))->summary($userId);
        $suspicious = (new CommissionSettingsService($this->db))->isSuspicious($userId);
        return [
            'user' => $user,
            'wallet' => $wallet,
            'direct_refs' => (int) $user['direct_ref_count'],
            'total_refs' => (int) $user['total_ref_count'],
            'level' => $this->depthFromRoot($userId),
            'rank' => RankingService::rankOf($userId),
            'suspicious' => $suspicious,
        ];
    }

    public function depthFromRoot(int $userId, int $max = 8): int
    {
        $row = $this->db->first(
            'SELECT MAX(depth) AS d FROM referral_relations WHERE descendant_id = :u',
            ['u' => $userId]
        );
        return (int) ($row['d'] ?? 0);
    }

    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['q'])) {
            $q = '%' . trim((string) $filters['q']) . '%';
            $where[] = '(u.first_name LIKE :q1 OR u.last_name LIKE :q2 OR u.username LIKE :q3 OR u.phone LIKE :q4 OR CAST(u.telegram_id AS CHAR) LIKE :q5 OR u.referral_code LIKE :q6)';
            $params += ['q1' => $q, 'q2' => $q, 'q3' => $q, 'q4' => $q, 'q5' => $q, 'q6' => $q];
        }
        if (!empty($filters['status'])) {
            $where[] = 'u.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'u.joined_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'u.joined_at <= :to';
            $params['to'] = $filters['to'] . ' 23:59:59';
        }
        if (!empty($filters['has_refs'])) {
            $where[] = 'u.direct_ref_count > 0';
        }
        if (!empty($filters['has_income'])) {
            $where[] = 'u.total_earned > 0';
        }
        if (!empty($filters['with_phone'])) {
            $where[] = "u.phone IS NOT NULL AND u.phone <> ''";
        }
        if (!empty($filters['referred_by'])) {
            $where[] = 'u.referred_by = :rb';
            $params['rb'] = (int) $filters['referred_by'];
        }
        if (!empty($filters['suspicious'])) {
            $where[] = 'u.status = :susp';
            $params['susp'] = 'suspicious';
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $this->db->value("SELECT COUNT(*) FROM users u {$whereSql}", $params);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->all(
            "SELECT u.*, r.first_name AS referrer_first_name, r.username AS referrer_username, r.referral_code AS referrer_code
             FROM users u LEFT JOIN users r ON r.id = u.referred_by
             {$whereSql}
             ORDER BY u.id DESC LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** تغییر معرف با ثبت سابقه (فقط مدیر) */
    public function changeReferrer(int $userId, ?int $newReferrerId, string $reason, ?int $adminId = null): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new \RuntimeException('برای تغییر معرف، ثبت دلیل (حداقل ۵ کاراکتر) الزامی است.');
        }
        $this->db->transaction(function () use ($userId, $newReferrerId, $reason, $adminId) {
            $user = $this->find($userId);
            if ($user === null) {
                throw new \RuntimeException('کاربر یافت نشد.');
            }
            $oldReferrer = $user['referred_by'] ? (int) $user['referred_by'] : null;
            if ($newReferrerId !== null) {
                if ($newReferrerId === $userId) {
                    throw new \RuntimeException('کاربر نمی‌تواند معرف خودش باشد.');
                }
                if (!$this->exists($newReferrerId)) {
                    throw new \RuntimeException('معرف جدید یافت نشد.');
                }
                if ($this->isDescendant($newReferrerId, $userId)) {
                    throw new \RuntimeException('این تغییر یک حلقه در شبکه ایجاد می‌کند.');
                }
            }

            $this->db->update('users', [
                'referred_by' => $newReferrerId,
                'referred_at' => $newReferrerId ? now() : null,
                'updated_at' => now(),
            ], 'id = :id', ['id' => $userId]);

            $this->rebuildRelations($userId);
            // بازسازی زنجیره‌ی تمام زیرمجموعه‌های این کاربر
            foreach ($this->db->column(
                'SELECT descendant_id FROM referral_relations WHERE ancestor_id = :a',
                ['a' => $userId]
            ) as $descendantId) {
                $this->rebuildRelations((int) $descendantId);
            }
            $this->refreshNetworkCounters();

            AuditService::log('user.referrer_changed', 'user', $userId, [
                'old_referrer' => $oldReferrer,
                'new_referrer' => $newReferrerId,
                'reason' => $reason,
            ], $adminId);
        });
    }
}
