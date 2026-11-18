<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;

/**
 * کیف پول و دفتر ثبت مالی (Ledger)
 *
 * قوانین:
 *  - هر تغییر موجودی یک ردیف در wallet_transactions ثبت می‌کند (append-only).
 *  - هیچ تراکنشی ویرایش یا حذف نمی‌شود؛ اصلاحات با تراکنش معکوس ثبت می‌گردد.
 *  - idempotency_key یکتا مانع از ثبت دوباره‌ی یک عملیات مالی می‌شود.
 *  - موجودی از جمع تراکنش‌های completed قابل بازسازی و تطبیق است.
 */
final class WalletService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    public function ensureWallet(int $userId): array
    {
        $wallet = $this->db->first('SELECT * FROM wallets WHERE user_id = :u', ['u' => $userId]);
        if ($wallet === null) {
            $this->db->insert('wallets', [
                'user_id' => $userId,
                'balance' => 0,
                'pending' => 0,
                'total_in' => 0,
                'total_out' => 0,
                'currency' => 'IRT',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $wallet = $this->db->first('SELECT * FROM wallets WHERE user_id = :u', ['u' => $userId]);
        }
        return $wallet;
    }

    /**
     * واریز به کیف پول
     * @return int|null شناسه تراکنش یا null اگر تکراری باشد
     */
    public function credit(int $userId, string $type, int $amount, array $options = []): ?int
    {
        if ($amount <= 0) {
            return null;
        }
        return $this->db->transaction(function () use ($userId, $type, $amount, $options) {
            $idempotencyKey = $options['idempotency_key'] ?? null;
            if ($idempotencyKey !== null) {
                $duplicate = $this->db->value(
                    'SELECT id FROM wallet_transactions WHERE idempotency_key = :k',
                    ['k' => $idempotencyKey]
                );
                if ($duplicate) {
                    return null;
                }
            }

            $this->ensureWallet($userId);
            $wallet = $this->db->lockRow('wallets', (int) $this->db->value(
                'SELECT id FROM wallets WHERE user_id = :u',
                ['u' => $userId]
            ));
            $balanceAfter = (int) $wallet['balance'] + $amount;

            $txId = $this->db->insert('wallet_transactions', [
                'tx_code' => self::generateCode('TX'),
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'direction' => 'credit',
                'balance_after' => $balanceAfter,
                'status' => $options['status'] ?? 'completed',
                'source_type' => $options['source_type'] ?? null,
                'source_id' => $options['source_id'] ?? null,
                'related_user_id' => $options['related_user_id'] ?? null,
                'level' => $options['level'] ?? null,
                'event_id' => $options['event_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'description' => isset($options['description']) ? mb_substr((string) $options['description'], 0, 240) : null,
                'meta' => isset($options['meta']) ? json_encode($options['meta'], JSON_UNESCAPED_UNICODE) : null,
                'created_by_admin_id' => $options['created_by_admin_id'] ?? null,
                'created_at' => now(),
            ]);

            $this->db->run(
                'UPDATE wallets SET balance = balance + :a, total_in = total_in + :a2, updated_at = :now WHERE user_id = :u',
                ['a' => $amount, 'a2' => $amount, 'now' => now(), 'u' => $userId]
            );
            $this->db->run(
                'UPDATE users SET wallet_balance = wallet_balance + :a, updated_at = :now WHERE id = :u',
                ['a' => $amount, 'now' => now(), 'u' => $userId]
            );

            return $txId;
        });
    }

    /**
     * برداشت از کیف پول (برای برگشت پورسانت، پاداش و اصلاحات)
     * @return int|null
     */
    public function debit(int $userId, string $type, int $amount, array $options = [], bool $allowNegative = false): ?int
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ برداشت باید بزرگ‌تر از صفر باشد.');
        }
        return $this->db->transaction(function () use ($userId, $type, $amount, $options, $allowNegative) {
            $idempotencyKey = $options['idempotency_key'] ?? null;
            if ($idempotencyKey !== null) {
                $duplicate = $this->db->value(
                    'SELECT id FROM wallet_transactions WHERE idempotency_key = :k',
                    ['k' => $idempotencyKey]
                );
                if ($duplicate) {
                    return null;
                }
            }

            $this->ensureWallet($userId);
            $walletId = (int) $this->db->value('SELECT id FROM wallets WHERE user_id = :u', ['u' => $userId]);
            $wallet = $this->db->lockRow('wallets', $walletId);
            $balance = (int) $wallet['balance'];

            if (!$allowNegative && $balance < $amount) {
                throw new \RuntimeException('موجودی کیف پول کافی نیست.');
            }
            $balanceAfter = $balance - $amount;

            $txId = $this->db->insert('wallet_transactions', [
                'tx_code' => self::generateCode('TX'),
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'direction' => 'debit',
                'balance_after' => $balanceAfter,
                'status' => $options['status'] ?? 'completed',
                'source_type' => $options['source_type'] ?? null,
                'source_id' => $options['source_id'] ?? null,
                'related_user_id' => $options['related_user_id'] ?? null,
                'level' => $options['level'] ?? null,
                'event_id' => $options['event_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'description' => isset($options['description']) ? mb_substr((string) $options['description'], 0, 240) : null,
                'meta' => isset($options['meta']) ? json_encode($options['meta'], JSON_UNESCAPED_UNICODE) : null,
                'created_by_admin_id' => $options['created_by_admin_id'] ?? null,
                'reversal_of_id' => $options['reversal_of_id'] ?? null,
                'created_at' => now(),
            ]);

            $this->db->run(
                'UPDATE wallets SET balance = balance - :a, total_out = total_out + :a2, updated_at = :now WHERE user_id = :u',
                ['a' => $amount, 'a2' => $amount, 'now' => now(), 'u' => $userId]
            );
            $this->db->run(
                'UPDATE users SET wallet_balance = wallet_balance - :a, updated_at = :now WHERE id = :u',
                ['a' => $amount, 'now' => now(), 'u' => $userId]
            );

            return $txId;
        });
    }

    /**
     * رزرو مبلغ برای درخواست برداشت: از موجودی کم و به pending اضافه می‌شود.
     */
    public function reserve(int $userId, int $amount, string $reserveKey, array $options = []): int
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ درخواست باید بزرگ‌تر از صفر باشد.');
        }
        return $this->db->transaction(function () use ($userId, $amount, $reserveKey, $options) {
            $duplicate = $this->db->value(
                'SELECT id FROM wallet_transactions WHERE idempotency_key = :k',
                ['k' => $reserveKey]
            );
            if ($duplicate) {
                throw new \RuntimeException('این درخواست برداشت قبلاً ثبت شده است.');
            }

            $this->ensureWallet($userId);
            $walletId = (int) $this->db->value('SELECT id FROM wallets WHERE user_id = :u', ['u' => $userId]);
            $wallet = $this->db->lockRow('wallets', $walletId);
            $balance = (int) $wallet['balance'];

            if ($balance < $amount) {
                throw new \RuntimeException('موجودی قابل برداشت کافی نیست.');
            }

            $txId = $this->db->insert('wallet_transactions', [
                'tx_code' => self::generateCode('TX'),
                'user_id' => $userId,
                'type' => 'withdrawal_reserve',
                'amount' => $amount,
                'direction' => 'debit',
                'balance_after' => $balance - $amount,
                'status' => 'completed',
                'source_type' => $options['source_type'] ?? 'withdrawal',
                'source_id' => $options['source_id'] ?? null,
                'idempotency_key' => $reserveKey,
                'description' => $options['description'] ?? 'رزرو مبلغ برای درخواست برداشت',
                'meta' => isset($options['meta']) ? json_encode($options['meta'], JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now(),
            ]);

            $this->db->run(
                'UPDATE wallets SET balance = balance - :a, pending = pending + :a2, updated_at = :now WHERE user_id = :u',
                ['a' => $amount, 'a2' => $amount, 'now' => now(), 'u' => $userId]
            );
            $this->db->run(
                'UPDATE users SET wallet_balance = wallet_balance - :a, pending_balance = pending_balance + :a2, updated_at = :now WHERE id = :u',
                ['a' => $amount, 'a2' => $amount, 'now' => now(), 'u' => $userId]
            );

            return $txId;
        });
    }

    /**
     * نهایی‌سازی پرداخت: مبلغ رزرو‌شده از pending کسر و در مجموع برداشت ثبت می‌شود.
     */
    public function settleReserved(int $userId, int $amount, int $reserveTxId, string $idempotencyKey, int $withdrawalId, ?int $adminId = null): int
    {
        return $this->db->transaction(function () use ($userId, $amount, $reserveTxId, $idempotencyKey, $withdrawalId, $adminId) {
            $duplicate = $this->db->value('SELECT id FROM wallet_transactions WHERE idempotency_key = :k', ['k' => $idempotencyKey]);
            if ($duplicate) {
                return (int) $duplicate;
            }

            $walletId = (int) $this->db->value('SELECT id FROM wallets WHERE user_id = :u', ['u' => $userId]);
            $wallet = $this->db->lockRow('wallets', $walletId);
            $pending = (int) $wallet['pending'];
            if ($pending < $amount) {
                throw new \RuntimeException('مبلغ رزرو‌شده کافی نیست.');
            }

            // توجه: مبلغ در زمان رزرو از موجودی خارج شده است؛ بنابراین در تسویه
            // هیچ حرکت مالی جدیدی در دفتر ثبت نمی‌شود و فقط رکورد اطلاع‌رسانی
            // با وضعیت info (خارج از محاسبه مانده) درج می‌گردد.
            $txId = $this->db->insert('wallet_transactions', [
                'tx_code' => self::generateCode('TX'),
                'user_id' => $userId,
                'type' => 'withdrawal_paid',
                'amount' => $amount,
                'direction' => 'debit',
                'balance_after' => (int) $wallet['balance'],
                'status' => 'info',
                'source_type' => 'withdrawal',
                'source_id' => $withdrawalId,
                'idempotency_key' => $idempotencyKey,
                'description' => 'پرداخت درخواست برداشت (رزرو‌شده در ' . $reserveTxId . ')',
                'meta' => json_encode(['reserve_tx' => $reserveTxId], JSON_UNESCAPED_UNICODE),
                'created_by_admin_id' => $adminId,
                'created_at' => now(),
            ]);

            $this->db->run(
                'UPDATE wallets SET pending = pending - :a, total_out = total_out + :a2, updated_at = :now WHERE user_id = :u',
                ['a' => $amount, 'a2' => $amount, 'now' => now(), 'u' => $userId]
            );
            $this->db->run(
                'UPDATE users SET pending_balance = pending_balance - :a, total_withdrawn = total_withdrawn + :a2, updated_at = :now WHERE id = :u',
                ['a' => $amount, 'a2' => $amount, 'now' => now(), 'u' => $userId]
            );

            return $txId;
        });
    }

    /**
     * آزادسازی مبلغ رزرو‌شده (رد درخواست یا لغو)
     */
    public function releaseReserved(int $userId, int $amount, int $reserveTxId, string $idempotencyKey, int $withdrawalId, string $reason, ?int $adminId = null): int
    {
        return $this->db->transaction(function () use ($userId, $amount, $reserveTxId, $idempotencyKey, $withdrawalId, $reason, $adminId) {
            $duplicate = $this->db->value('SELECT id FROM wallet_transactions WHERE idempotency_key = :k', ['k' => $idempotencyKey]);
            if ($duplicate) {
                return (int) $duplicate;
            }

            $walletId = (int) $this->db->value('SELECT id FROM wallets WHERE user_id = :u', ['u' => $userId]);
            $wallet = $this->db->lockRow('wallets', $walletId);

            $txId = $this->db->insert('wallet_transactions', [
                'tx_code' => self::generateCode('TX'),
                'user_id' => $userId,
                'type' => 'withdrawal_release',
                'amount' => $amount,
                'direction' => 'credit',
                'balance_after' => (int) $wallet['balance'] + $amount,
                'status' => 'completed',
                'source_type' => 'withdrawal',
                'source_id' => $withdrawalId,
                'idempotency_key' => $idempotencyKey,
                'description' => 'بازگشت مبلغ درخواست برداشت: ' . mb_substr($reason, 0, 120),
                'meta' => json_encode(['reserve_tx' => $reserveTxId, 'reason' => $reason], JSON_UNESCAPED_UNICODE),
                'created_by_admin_id' => $adminId,
                'reversal_of_id' => $reserveTxId,
                'created_at' => now(),
            ]);

            $this->db->run(
                'UPDATE wallets SET pending = MAX(0, pending - :a), balance = balance + :a2, updated_at = :now WHERE user_id = :u',
                ['a' => $amount, 'a2' => $amount, 'now' => now(), 'u' => $userId]
            );
            $this->db->run(
                'UPDATE users SET pending_balance = MAX(0, pending_balance - :a), wallet_balance = wallet_balance + :a2, updated_at = :now WHERE id = :u',
                ['a' => $amount, 'a2' => $amount, 'now' => now(), 'u' => $userId]
            );

            return $txId;
        });
    }

    /**
     * تطبیق موجودی: محاسبه مانده از دفتر مالی
     * @return array{ledger:int, wallet:int, user:int, match:bool}
     */
    public function reconcile(int $userId): array
    {
        $ledger = (int) $this->db->value(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0)
             FROM wallet_transactions WHERE user_id = :u AND status = 'completed'",
            ['u' => $userId]
        );
        $wallet = (int) $this->db->value('SELECT COALESCE(balance, 0) FROM wallets WHERE user_id = :u', ['u' => $userId], 0);
        $user = (int) $this->db->value('SELECT COALESCE(wallet_balance, 0) FROM users WHERE id = :u', ['u' => $userId], 0);
        return [
            'ledger' => $ledger,
            'wallet' => $wallet,
            'user' => $user,
            'match' => $ledger === $wallet && $ledger === $user,
        ];
    }

    /**
     * بازسازی موجودی از دفتر مالی (ابزار نگهداری)
     */
    public function rebuildBalance(int $userId): int
    {
        return $this->db->transaction(function () use ($userId) {
            $net = (int) $this->db->value(
                "SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0)
                 FROM wallet_transactions WHERE user_id = :u AND status = 'completed'",
                ['u' => $userId]
            );
            $pending = (int) $this->db->value(
                "SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests
                 WHERE user_id = :u AND status IN ('new','reviewing','approved','paying')",
                ['u' => $userId]
            );
            $totalIn = (int) $this->db->value(
                "SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE user_id = :u AND status = 'completed' AND direction = 'credit' AND type IN ('commission_direct','commission_level','campaign_reward','adjustment','withdrawal_release')",
                ['u' => $userId]
            );
            $totalOut = (int) $this->db->value(
                "SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests WHERE user_id = :u AND status = 'paid'",
                ['u' => $userId]
            );
            $this->ensureWallet($userId);
            $this->db->run(
                'UPDATE wallets SET balance = :b, pending = :p, total_in = :ti, total_out = :to, updated_at = :now WHERE user_id = :u',
                ['b' => $net, 'p' => $pending, 'ti' => $totalIn, 'to' => $totalOut, 'now' => now(), 'u' => $userId]
            );
            $this->db->run(
                'UPDATE users SET wallet_balance = :b, pending_balance = :p, updated_at = :now WHERE id = :u',
                ['b' => $net, 'p' => $pending, 'now' => now(), 'u' => $userId]
            );
            return $net;
        });
    }

    public static function generateCode(string $prefix): string
    {
        return $prefix . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /** @return array{0:array,1:int} تراکنش‌ها و تعداد کل */
    public function transactions(int $userId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['user_id = :u'];
        $params = ['u' => $userId];
        if (!empty($filters['type'])) {
            $where[] = 'type = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['direction'])) {
            $where[] = 'direction = :dir';
            $params['dir'] = $filters['direction'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'created_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'created_at <= :to';
            $params['to'] = $filters['to'] . ' 23:59:59';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM wallet_transactions WHERE {$whereSql}", $params);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->all(
            "SELECT * FROM wallet_transactions WHERE {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        return [$rows, $total];
    }

    public function summary(int $userId): array
    {
        $u = $this->db->first('SELECT wallet_balance, pending_balance, total_earned, total_withdrawn, direct_earned, layer_earned, campaign_earned, withdrawal_count FROM users WHERE id = :id', ['id' => $userId]) ?? [];
        $pendingRequests = (int) $this->db->value(
            "SELECT COUNT(*) FROM withdrawal_requests WHERE user_id = :u AND status IN ('new','reviewing','approved','paying')",
            ['u' => $userId]
        );
        return [
            'balance' => (int) ($u['wallet_balance'] ?? 0),
            'pending' => (int) ($u['pending_balance'] ?? 0),
            'total_earned' => (int) ($u['total_earned'] ?? 0),
            'total_withdrawn' => (int) ($u['total_withdrawn'] ?? 0),
            'direct_earned' => (int) ($u['direct_earned'] ?? 0),
            'layer_earned' => (int) ($u['layer_earned'] ?? 0),
            'campaign_earned' => (int) ($u['campaign_earned'] ?? 0),
            'withdrawal_count' => (int) ($u['withdrawal_count'] ?? 0),
            'pending_requests' => $pendingRequests,
        ];
    }
}
