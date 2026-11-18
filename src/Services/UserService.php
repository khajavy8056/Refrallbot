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
                    $this->db->update('users', [
                        'username' => $tgUser['username'] ?? $existing['username'],
                        'first_name' => $tgUser['first_name'] ?? $existing['first_name'],
                        'last_name' => $tgUser['last_name'] ?? $existing['last_name'],
                        'language' => $tgUser['language_code'] ?? $existing['language'],
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
                    'language' => $tgUser['language_code'] ?? 'fa',
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
     * @param int[] $userIds
     */
    public function refreshNetworkCounters(array $userIds = []): void
    {
        if ($userIds === []) {
            $this->db->run(
                'UPDATE users SET direct_ref_count = (SELECT COUNT(*) FROM users c WHERE c.referred_by = users.id)'
            );
            $this->db->run(
                'UPDATE users SET total_ref_count = (SELECT COUNT(*) FROM referral_relations rr WHERE rr.ancestor_id = users.id)'
            );
            return;
        }
        foreach (array_unique($userIds) as $id) {
            $this->db->run(
                'UPDATE users SET direct_ref_count = (SELECT COUNT(*) FROM users c WHERE c.referred_by = users.id),
                                  total_ref_count = (SELECT COUNT(*) FROM referral_relations rr WHERE rr.ancestor_id = users.id)
                 WHERE id = :id',
                ['id' => (int) $id]
            );
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
     * بررسی شرایط واجد شرایط شدن معرفی و ثبت پورسانت
     */
    public function maybeQualify(array $user, bool $force = false): bool
    {
        if (empty($user['referred_by'])) {
            return false;
        }
        if (!empty($user['qualified_at']) && !$force) {
            return false;
        }
        if ($user['status'] === 'blocked') {
            return false;
        }

        $settings = CommissionSettings::current($this->db, true);
        $conditions = $settings->qualifyConditions ?: ['joined'];
        if (!$this->meetsConditions($user, $conditions)) {
            return false;
        }

        // علامت‌گذاری واجد شرایط
        $this->db->run(
            'UPDATE users SET qualified_at = :now, updated_at = :now2 WHERE id = :id AND qualified_at IS NULL',
            ['now' => now(), 'now2' => now(), 'id' => (int) $user['id']]
        );

        $service = new CommissionService($this->db, $settings);
        $result = $service->processEvent([
            'event_key' => 'referral_qualified:' . (int) $user['id'],
            'type' => 'referral_qualified',
            'user_id' => (int) $user['id'],
            'actor_id' => (int) $user['referred_by'],
            'base_amount' => $settings->directReward,
            'applies_direct' => true,
            'payload' => [
                'referred_by' => (int) $user['referred_by'],
                'conditions' => $conditions,
                'telegram_id' => (int) $user['telegram_id'],
            ],
        ]);

        if (!$result['duplicated'] && $result['total'] > 0) {
            Notifier::referralCommission($user, $result, $settings);
        }
        SecurityService::evaluateUser((int) $user['id']);
        return true;
    }

    public function meetsConditions(array $user, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $ok = match ($condition) {
                'joined' => true,
                'profile_complete' => !empty($user['first_name']),
                'phone' => !empty($user['phone']),
                'channels' => (bool) ($user['channels_verified'] ?? false),
                'manual_approval' => !empty($user['qualified_at']),
                'activity' => $this->hasActivity((int) $user['id']),
                default => true,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
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
