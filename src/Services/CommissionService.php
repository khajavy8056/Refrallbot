<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;
use RB\Support\Money;

/**
 * موتور پورسانت چندلایه
 *
 * قواعد کلیدی:
 * ۱) هر رویداد پاداش یک event_key یکتا دارد؛ تکرار همان رویداد پاداش دوباره ایجاد نمی‌کند.
 * ۲) برای هر (event, beneficiary, level, type) فقط یک کمیسیون قابل ثبت است (کلید یکتای دیتابیس).
 * ۳) محاسبه در یک تراکنش دیتابیس انجام می‌شود و تنظیمات معتبر در زمان محاسبه
 *    روی خود تراکنش مالی ذخیره می‌گردد.
 * ۴) درصدها با ریاضیات عدد صحیح محاسبه می‌شوند تا خطای اعشاری رخ ندهد.
 * ۵) مجموع پرداختی هرگز از سقف تعیین‌شده بیشتر نمی‌شود.
 */
final class CommissionService
{
    private Database $db;
    private CommissionSettings $settings;
    private WalletService $wallet;

    public function __construct(?Database $db = null, ?CommissionSettings $settings = null)
    {
        $this->db = $db ?? Database::instance();
        $this->settings = $settings ?? CommissionSettings::current($this->db);
        $this->wallet = new WalletService($this->db);
    }

    public function settings(): CommissionSettings
    {
        return $this->settings;
    }

    /**
     * پردازش پاداش برای یک رویداد واجد شرایط (idempotent).
     *
     * @return array{event_id:int, commissions:array, total:int, duplicated:bool}
     */
    public function processEvent(array $options): array
    {
        $eventKey = (string) ($options['event_key'] ?? '');
        if ($eventKey === '') {
            throw new \InvalidArgumentException('event_key الزامی است.');
        }
        $type = (string) ($options['type'] ?? 'referral_qualified');
        $userId = (int) ($options['user_id'] ?? 0);
        $actorId = isset($options['actor_id']) && $options['actor_id'] ? (int) $options['actor_id'] : null;
        $baseAmount = isset($options['base_amount'])
            ? (int) $options['base_amount']
            : $this->settings->directReward;
        $appliesDirect = (bool) ($options['applies_direct'] ?? true);
        $payload = $options['payload'] ?? [];

        return $this->db->transaction(function () use ($eventKey, $type, $userId, $actorId, $baseAmount, $appliesDirect, $payload) {
            // ---- محافظت در برابر رویداد تکراری
            $existing = $this->db->first(
                'SELECT * FROM referral_events WHERE event_key = :k LIMIT 1',
                ['k' => $eventKey]
            );
            if ($existing !== null) {
                return [
                    'event_id' => (int) $existing['id'],
                    'commissions' => [],
                    'total' => 0,
                    'duplicated' => true,
                ];
            }

            $eventId = $this->db->insert('referral_events', [
                'event_key' => $eventKey,
                'type' => $type,
                'user_id' => $userId,
                'actor_id' => $actorId,
                'source_user_id' => $userId,
                'base_amount' => $baseAmount,
                'status' => 'processed',
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            $commissions = [];

            // ---- ۱) پاداش معرفی مستقیم
            if ($appliesDirect && $this->settings->directEnabled && $actorId && $baseAmount > 0) {
                $commissions[] = $this->credit($eventId, $actorId, 0, 'direct', $baseAmount, '100', $baseAmount, $userId);
            }

            // ---- ۲) پورسانت چندلایه
            if ($this->settings->multiLevelEnabled) {
                $base = $this->settings->percentBaseAmount($baseAmount);
                $ancestors = $this->ancestors($userId, $this->settings->maxLevels);

                foreach ($ancestors as $depth => $ancestorId) {
                    $level = $depth + 1; // depth 0 = معرف مستقیم = لایه ۱
                    $config = $this->settings->level($level);
                    if ($config === null || !$config['enabled'] || (float) $config['percent'] <= 0) {
                        continue; // سطح غیرفعال: سهم آن به کسی منتقل نمی‌شود
                    }
                    $amount = Money::percentOf($base, (string) $config['percent'], $this->settings->rounding);
                    if ($amount <= 0) {
                        continue;
                    }
                    $commissions[] = $this->credit(
                        $eventId,
                        (int) $ancestorId,
                        $level,
                        'level',
                        $amount,
                        (string) $config['percent'],
                        $base,
                        $userId
                    );
                }
            }

            // ---- ۳) کنترل سقف کل پرداخت برای این رویداد
            $paid = array_values(array_filter($commissions, static fn($c) => ($c['amount'] ?? 0) > 0));
            if ($this->settings->maxPayoutPerEvent > 0) {
                $total = array_sum(array_map(static fn($c) => $c['amount'], $paid));
                if ($total > $this->settings->maxPayoutPerEvent) {
                    $this->trimToCap($paid, $this->settings->maxPayoutPerEvent);
                }
            }

            $total = array_sum(array_map(static fn($c) => $c['amount'], $paid));

            return [
                'event_id' => $eventId,
                'commissions' => $commissions,
                'total' => $total,
                'duplicated' => false,
            ];
        });
    }

    /**
     * ثبت یک کمیسیون برای ذی‌نفع و واریز به کیف پول
     */
    private function credit(
        int $eventId,
        int $beneficiaryId,
        int $level,
        string $type,
        int $amount,
        string $percent,
        int $baseAmount,
        int $sourceUserId
    ): array {
        if ($beneficiaryId === $sourceUserId) {
            return ['beneficiary_id' => $beneficiaryId, 'amount' => 0, 'level' => $level, 'status' => 'self_referral'];
        }

        $beneficiary = $this->db->first('SELECT id, status FROM users WHERE id = :id', ['id' => $beneficiaryId]);
        if ($beneficiary === null) {
            return ['beneficiary_id' => $beneficiaryId, 'amount' => 0, 'level' => $level, 'status' => 'user_missing'];
        }
        // کاربر مسدود پورسانت دریافت نمی‌کند (طبق تنظیمات)
        if ($this->settings->blockedUsersNoCommission && $beneficiary['status'] === 'blocked') {
            return ['beneficiary_id' => $beneficiaryId, 'amount' => 0, 'level' => $level, 'status' => 'blocked_beneficiary'];
        }

        // جلوگیری از ثبت تکراری
        $exists = $this->db->value(
            'SELECT id FROM referral_commissions WHERE event_id = :e AND beneficiary_id = :b AND level = :l AND type = :t',
            ['e' => $eventId, 'b' => $beneficiaryId, 'l' => $level, 't' => $type]
        );
        if ($exists) {
            return ['beneficiary_id' => $beneficiaryId, 'amount' => 0, 'level' => $level, 'status' => 'duplicate'];
        }

        $commissionId = $this->db->insert('referral_commissions', [
            'event_id' => $eventId,
            'beneficiary_id' => $beneficiaryId,
            'source_user_id' => $sourceUserId,
            'level' => $level,
            'type' => $type,
            'base_amount' => $baseAmount,
            'percent' => $percent,
            'amount' => $amount,
            'settings_version_id' => $this->settings->versionId,
            'status' => 'approved',
            'meta' => json_encode([
                'settings_version' => $this->settings->version,
                'percent_base' => $this->settings->percentBase,
                'max_levels' => $this->settings->maxLevels,
                'multi_level_enabled' => $this->settings->multiLevelEnabled,
                'direct_reward' => $this->settings->directReward,
                'calculated_at' => now(),
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);

        $walletType = match ($type) {
            'direct' => 'commission_direct',
            'campaign' => 'campaign_reward',
            default => 'commission_level',
        };

        $txId = $this->wallet->credit($beneficiaryId, $walletType, $amount, [
            'source_type' => 'referral_commission',
            'source_id' => $commissionId,
            'event_id' => $eventId,
            'level' => $level,
            'related_user_id' => $sourceUserId,
            'description' => $type === 'direct' ? 'پاداش معرفی مستقیم' : 'پورسانت لایه ' . fa_digits($level),
            'idempotency_key' => 'commission:' . $commissionId,
            'meta' => [
                'settings_version' => $this->settings->version,
                'percent_base' => $this->settings->percentBase,
                'percent' => $percent,
                'level' => $level,
                'type' => $type,
            ],
        ]);

        if ($txId === null) {
            return ['beneficiary_id' => $beneficiaryId, 'amount' => 0, 'level' => $level, 'status' => 'duplicate_tx'];
        }

        $column = match ($type) {
            'direct' => 'direct_earned',
            'campaign' => 'campaign_earned',
            default => 'layer_earned',
        };
        $this->db->run(
            "UPDATE users SET {$column} = {$column} + :a1, total_earned = total_earned + :a2, updated_at = :now WHERE id = :id",
            ['a1' => $amount, 'a2' => $amount, 'now' => now(), 'id' => $beneficiaryId]
        );

        return [
            'commission_id' => $commissionId,
            'transaction_id' => $txId,
            'beneficiary_id' => $beneficiaryId,
            'amount' => $amount,
            'level' => $level,
            'type' => $type,
            'percent' => $percent,
            'status' => 'paid',
        ];
    }

    /**
     * کوتاه کردن پرداخت‌ها تا سقف مجاز (ابتدا از عمیق‌ترین لایه‌ها)
     */
    private function trimToCap(array &$commissions, int $cap): void
    {
        $total = array_sum(array_map(static fn($c) => (int) $c['amount'], $commissions));
        $excess = $total - $cap;
        for ($i = count($commissions) - 1; $i >= 0 && $excess > 0; $i--) {
            $take = min($excess, (int) $commissions[$i]['amount']);
            $commissions[$i]['amount'] -= $take;
            $excess -= $take;
        }
        foreach ($commissions as $c) {
            if (!isset($c['commission_id'])) {
                continue;
            }
            $this->db->run(
                'UPDATE referral_commissions SET amount = :a WHERE id = :id',
                ['a' => (int) $c['amount'], 'id' => (int) $c['commission_id']]
            );
            $this->db->run(
                'UPDATE wallet_transactions SET amount = :a WHERE source_id = :sid AND source_type = :st AND user_id = :u',
                ['a' => (int) $c['amount'], 'sid' => (int) $c['commission_id'], 'st' => 'referral_commission', 'u' => (int) $c['beneficiary_id']]
            );
            // همگام‌سازی موجودی کیف پول و آمار کاربر
            $this->db->run(
                'UPDATE wallets SET balance = COALESCE((SELECT SUM(CASE WHEN direction = :cr THEN amount ELSE -amount END) FROM wallet_transactions wt WHERE wt.user_id = wallets.user_id AND wt.status = :ok), 0) WHERE user_id = :u2',
                ['cr' => 'credit', 'ok' => 'completed', 'u2' => (int) $c['beneficiary_id']]
            );
            $this->db->run(
                'UPDATE users SET total_earned = (SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE user_id = :u3 AND status = :ok2 AND direction = :cr2 AND type IN (:t1, :t2, :t3)),
                 direct_earned = (SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE user_id = :u4 AND direction = :cr3 AND status = :ok3 AND type = :td),
                 layer_earned = (SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE user_id = :u5 AND direction = :cr4 AND status = :ok4 AND type = :tl)
                 WHERE id = :u6',
                [
                    'u3' => (int) $c['beneficiary_id'], 'ok2' => 'completed', 'cr2' => 'credit',
                    't1' => 'commission_direct', 't2' => 'commission_level', 't3' => 'campaign_reward',
                    'u4' => (int) $c['beneficiary_id'], 'cr3' => 'credit', 'ok3' => 'completed', 'td' => 'commission_direct',
                    'u5' => (int) $c['beneficiary_id'], 'cr4' => 'credit', 'ok4' => 'completed', 'tl' => 'commission_level',
                    'u6' => (int) $c['beneficiary_id'],
                ]
            );
        }
    }

    /**
     * زنجیره معرف‌ها از نزدیک‌ترین به دورترین
     * @return array<int,int> [depth(0-based) => user_id]
     */
    public function ancestors(int $userId, int $maxLevels = 8): array
    {
        $rows = $this->db->all(
            'SELECT ancestor_id, depth FROM referral_relations WHERE descendant_id = :d AND depth <= :m ORDER BY depth ASC',
            ['d' => $userId, 'm' => $maxLevels]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['depth'] - 1] = (int) $row['ancestor_id'];
        }
        ksort($out);
        return $out;
    }

    /**
     * بازگردانی مالی یک رویداد (برای تقلب/لغو) — سوابق حذف نمی‌شوند
     */
    public function revertEvent(int $eventId, string $reason, ?int $adminId = null): int
    {
        return $this->db->transaction(function () use ($eventId, $reason, $adminId) {
            $event = $this->db->first('SELECT * FROM referral_events WHERE id = :id', ['id' => $eventId]);
            if ($event === null) {
                throw new \RuntimeException('رویداد یافت نشد.');
            }
            if ($event['status'] === 'reverted') {
                return 0;
            }
            $commissions = $this->db->all(
                'SELECT * FROM referral_commissions WHERE event_id = :e AND status = :s',
                ['e' => $eventId, 's' => 'approved']
            );
            $reverted = 0;
            foreach ($commissions as $commission) {
                $tx = $this->db->first(
                    'SELECT * FROM wallet_transactions WHERE source_type = :st AND source_id = :sid AND user_id = :u AND direction = :d LIMIT 1',
                    ['st' => 'referral_commission', 'sid' => (int) $commission['id'], 'u' => (int) $commission['beneficiary_id'], 'd' => 'credit']
                );
                if ($tx === null) {
                    continue;
                }
                $this->wallet->debit((int) $commission['beneficiary_id'], 'reversal', (int) $commission['amount'], [
                    'source_type' => 'reversal',
                    'source_id' => (int) $commission['id'],
                    'related_user_id' => (int) $event['user_id'],
                    'description' => 'برگشت پورسانت: ' . $reason,
                    'idempotency_key' => 'reversal:commission:' . $commission['id'],
                    'created_by_admin_id' => $adminId,
                    'level' => (int) $commission['level'],
                    'meta' => ['reason' => $reason, 'original_tx' => (int) $tx['id'], 'event_id' => $eventId],
                ], allowNegative: true);
                $this->db->update('referral_commissions', ['status' => 'reverted'], 'id = :id', ['id' => (int) $commission['id']]);
                $this->db->update('wallet_transactions', ['status' => 'reverted'], 'id = :id', ['id' => (int) $tx['id']]);
                $reverted++;
            }
            $this->db->update('referral_events', ['status' => 'reverted'], 'id = :id', ['id' => $eventId]);
            return $reverted;
        });
    }
}
