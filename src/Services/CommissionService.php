<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;

/**
 * موتور مرکزی پورسانت و درآمد لایه‌ای (مشترک در تمام پیام‌رسان‌ها)
 *
 * ساختار دقیق و ریاضی لایه‌ها بر اساس زنجیره واقعی دعوت:
 *   فرض کنید A → B → C → D:
 *   - هنگام عضویت و تأیید B (دعوت‌شده توسط A):
 *       * A در عمق ۱ (depth=1) نسبت به B قرار دارد ⇒ فقط پاداش «لایه ۰ (دعوت مستقیم)» می‌گیرد.
 *       * هیچ پاداش لایه ۱ به A تعلق نمی‌گیرد (رفع کامل مشکل محاسبه دوگانه).
 *       * در صورت فعال بودن پاداش خوش‌آمدگویی، B پاداش «welcome» خود را دریافت می‌کند.
 *   - هنگام عضویت و تأیید C (دعوت‌شده توسط B):
 *       * B در عمق ۱ (depth=1) ⇒ پاداش «لایه ۰ (دعوت مستقیم)» می‌گیرد.
 *       * A در عمق ۲ (depth=2) ⇒ پاداش «لایه ۱ (دعوت غیرمستقیم)» می‌گیرد.
 *   - هنگام عضویت و تأیید D (دعوت‌شده توسط C):
 *       * C در عمق ۱ ⇒ «لایه ۰ (مستقیم)»
 *       * B در عمق ۲ ⇒ «لایه ۱ (غیرمستقیم)»
 *       * A در عمق ۳ ⇒ «لایه ۲ (غیرمستقیم)»
 *   - به طور کلی: لایه k غیرمستقیم (k ≥ 1) دقیقاً معادل ancestor با depth = k + 1 در referral_relations است.
 *
 * قواعد ایمنی مالی:
 *   ۱) هر رویداد یک event_key یکتا دارد (ضدتکرار سطح رویداد).
 *   ۲) برای هر (event_id, beneficiary_id, level, type) کلید یکتا در دیتابیس وجود دارد.
 *   ۳) یک ذی‌نفع در یک رویداد معرفی هرگز هم پاداش مستقیم (لایه ۰) و هم پاداش لایه‌ای نمی‌گیرد.
 *   ۴) هر لایه غیرمستقیم می‌تواند مبلغ ثابت (تومان) یا درصدی (از مبنای شفاف) + سقف پرداخت اختصاصی داشته باشد.
 *   ۵) تغییر تنظیمات فقط نسخه جدید می‌سازد و تراکنش‌های گذشته با تنظیمات زمان خود دست‌نخورده می‌مانند.
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
     * پردازش پاداش برای یک رویداد واجد شرایط (اتمیک و idempotent).
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
            ? max(0, (int) $options['base_amount'])
            : $this->settings->directReward;
        $inviteeAmount = isset($options['invitee_amount'])
            ? max(0, (int) $options['invitee_amount'])
            : $this->settings->inviteeReward;
        $appliesDirect = (bool) ($options['applies_direct'] ?? true);
        $appliesInvitee = (bool) ($options['applies_invitee'] ?? ($type === 'referral_qualified' && $appliesDirect));
        $platform = isset($options['platform']) && is_string($options['platform']) && $options['platform'] !== ''
            ? strtolower(trim($options['platform']))
            : null;
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        return $this->db->transaction(function () use (
            $eventKey, $type, $userId, $actorId, $baseAmount, $inviteeAmount,
            $appliesDirect, $appliesInvitee, $platform, $payload
        ) {
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

            // تشخیص خودکار پیام‌رسان رویداد از روی حساب کاربر در صورت ارسال نشدن
            $resolvedPlatform = $platform;
            if ($resolvedPlatform === null && $userId > 0 && $this->db->columnExists('users', 'entry_platform')) {
                $uRow = $this->db->first(
                    'SELECT entry_platform, referral_source_platform FROM users WHERE id = :id',
                    ['id' => $userId]
                );
                if ($uRow !== null) {
                    $resolvedPlatform = (string) ($uRow['referral_source_platform'] ?: $uRow['entry_platform'] ?: 'telegram');
                }
            }
            if ($resolvedPlatform !== null) {
                $payload['platform'] = $resolvedPlatform;
            }

            $eventRow = [
                'event_key' => $eventKey,
                'type' => $type,
                'user_id' => $userId,
                'actor_id' => $actorId,
                'source_user_id' => $userId,
                'base_amount' => $baseAmount,
                'status' => 'processed',
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ];
            if ($resolvedPlatform !== null && $this->db->columnExists('referral_events', 'platform')) {
                $eventRow['platform'] = $resolvedPlatform;
            }
            $eventId = $this->db->insert('referral_events', $eventRow);

            // بررسی معرف مستقیم واقعی در زنجیره (depth=1) برای جلوگیری قطعی از پرداخت دوگانه
            $directReferrerId = $actorId ?: (int) ($this->db->value(
                'SELECT ancestor_id FROM referral_relations WHERE descendant_id = :d AND depth = 1 LIMIT 1',
                ['d' => $userId]
            ) ?? 0);

            $commissions = [];

            // ---- ۱) لایه ۰: پاداش معرفی مستقیم (دعوت‌کننده مستقیم — همیشه مبلغ ثابت تومان)
            if ($appliesDirect && $this->settings->directEnabled && $directReferrerId > 0 && $baseAmount > 0) {
                $commissions[] = $this->credit(
                    $eventId,
                    $directReferrerId,
                    0,
                    'direct',
                    $baseAmount,
                    '100',
                    $baseAmount,
                    $userId,
                    [
                        'calc_type' => 'fixed',
                        'fixed_amount' => $baseAmount,
                        'max_amount' => 0,
                        'cap_applied' => false,
                        'platform' => $resolvedPlatform ?? 'telegram',
                    ]
                );
            }

            // ---- ۲) پاداش خوش‌آمدگویی کاربر دعوت‌شده (سیستم پاداش دوطرفه — مستقل از لایه‌ها)
            if (
                $appliesInvitee
                && $this->settings->inviteeEnabled
                && $directReferrerId > 0
                && $userId > 0
                && $directReferrerId !== $userId
                && $inviteeAmount > 0
            ) {
                $commissions[] = $this->credit(
                    $eventId,
                    $userId,
                    0,
                    'welcome',
                    $inviteeAmount,
                    '100',
                    $inviteeAmount,
                    $directReferrerId,
                    [
                        'calc_type' => 'fixed',
                        'fixed_amount' => $inviteeAmount,
                        'max_amount' => 0,
                        'cap_applied' => false,
                        'platform' => $resolvedPlatform ?? 'telegram',
                    ]
                );
            }

            // ---- ۳) درآمد لایه‌های غیرمستقیم (لایه ۱ تا maxLevels = عمق ۲ تا maxLevels+1 در زنجیره واقعی دعوت)
            //         معرف مستقیم (depth=1) فقط در لایه ۰ محاسبه می‌شود و هرگز وارد لایه ۱ نمی‌گردد.
            if ($this->settings->multiLevelEnabled) {
                $indirectChain = $this->indirectAncestors($userId, $this->settings->maxLevels);

                foreach ($indirectChain as $level => $ancestorId) {
                    // محافظت مضاعف: معرف مستقیم و خود کاربر هرگز در لایه‌های غیرمستقیم پورسانت نگیرند
                    if ($ancestorId <= 0 || $ancestorId === $userId || ($directReferrerId > 0 && $ancestorId === $directReferrerId)) {
                        continue;
                    }

                    $calc = $this->settings->calculateLevelPayout($level, $baseAmount);
                    if ($calc === null || $calc['amount'] <= 0) {
                        continue; // لایه غیرفعال یا مقدار صفر: سهم آن به لایه دیگر منتقل نمی‌شود
                    }

                    $commissions[] = $this->credit(
                        $eventId,
                        $ancestorId,
                        $level,
                        'level',
                        $calc['amount'],
                        $calc['percent'],
                        $calc['base_amount'],
                        $userId,
                        [
                            'calc_type' => $calc['calc_type'],
                            'fixed_amount' => $calc['fixed_amount'],
                            'raw_amount' => $calc['raw_amount'],
                            'max_amount' => $calc['max_amount'],
                            'cap_applied' => $calc['cap_applied'],
                            'chain_depth' => $level + 1,
                            'platform' => $resolvedPlatform ?? 'telegram',
                        ]
                    );
                }
            }

            // ---- ۴) کنترل سقف کل پرداخت هر رویداد و سقف روزانه
            $paid = array_values(array_filter($commissions, static fn($c) => ($c['amount'] ?? 0) > 0));
            $effectiveCap = $this->settings->maxPayoutPerEvent;

            if ($this->settings->dailyPayoutCap > 0) {
                $todayPaidBefore = (int) $this->db->value(
                    "SELECT COALESCE(SUM(amount), 0) FROM referral_commissions
                     WHERE status = 'approved' AND event_id <> :e AND created_at >= :t",
                    ['e' => $eventId, 't' => today_start()]
                );
                $remainingDaily = max(0, $this->settings->dailyPayoutCap - $todayPaidBefore);
                $effectiveCap = $effectiveCap > 0 ? min($effectiveCap, $remainingDaily) : $remainingDaily;
            }

            if ($effectiveCap > 0 || $this->settings->dailyPayoutCap > 0) {
                $totalNow = array_sum(array_map(static fn($c) => (int) $c['amount'], $paid));
                if ($totalNow > $effectiveCap) {
                    $this->trimToCap($paid, $effectiveCap);
                    // همگام‌سازی مبالغ تعدیل‌شده در خروجی $commissions
                    $byId = [];
                    foreach ($paid as $p) {
                        if (isset($p['commission_id'])) {
                            $byId[(int) $p['commission_id']] = (int) $p['amount'];
                        }
                    }
                    foreach ($commissions as &$cItem) {
                        if (isset($cItem['commission_id'], $byId[(int) $cItem['commission_id']])) {
                            $cItem['amount'] = $byId[(int) $cItem['commission_id']];
                        }
                    }
                    unset($cItem);
                }
            }

            $total = array_sum(array_map(static fn($c) => (int) ($c['amount'] ?? 0), $paid));

            return [
                'event_id' => $eventId,
                'commissions' => $commissions,
                'total' => $total,
                'duplicated' => false,
            ];
        });
    }

    /**
     * ثبت یک کمیسیون برای ذی‌نفع و واریز به کیف پول مرکزی او
     */
    private function credit(
        int $eventId,
        int $beneficiaryId,
        int $level,
        string $type,
        int $amount,
        string $percent,
        int $baseAmount,
        int $sourceUserId,
        array $extraMeta = []
    ): array {
        if ($beneficiaryId === $sourceUserId) {
            return ['beneficiary_id' => $beneficiaryId, 'amount' => 0, 'level' => $level, 'status' => 'self_referral'];
        }

        $beneficiary = $this->db->first('SELECT id, status FROM users WHERE id = :id', ['id' => $beneficiaryId]);
        if ($beneficiary === null) {
            return ['beneficiary_id' => $beneficiaryId, 'amount' => 0, 'level' => $level, 'status' => 'user_missing'];
        }
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

        // محافظت ضد پرداخت دوگانه: ذی‌نفعی که در این رویداد پاداش مستقیم لایه ۰ گرفته، نباید پورسانت لایه‌ای بگیرد
        if ($type === 'level') {
            $hasDirect = $this->db->value(
                "SELECT id FROM referral_commissions WHERE event_id = :e AND beneficiary_id = :b AND type = 'direct' LIMIT 1",
                ['e' => $eventId, 'b' => $beneficiaryId]
            );
            if ($hasDirect) {
                return ['beneficiary_id' => $beneficiaryId, 'amount' => 0, 'level' => $level, 'status' => 'already_direct'];
            }
        }

        $calcType = (string) ($extraMeta['calc_type'] ?? ($type === 'level' ? 'percent' : 'fixed'));
        $metaSnapshot = array_merge([
            'settings_version' => $this->settings->version,
            'calc_type' => $calcType,
            'percent_base' => $this->settings->percentBase,
            'percent' => $percent,
            'base_amount' => $baseAmount,
            'max_levels' => $this->settings->maxLevels,
            'multi_level_enabled' => $this->settings->multiLevelEnabled,
            'direct_reward' => $this->settings->directReward,
            'invitee_reward' => $this->settings->inviteeReward,
            'calculated_at' => now(),
        ], $extraMeta);

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
            'meta' => json_encode($metaSnapshot, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);

        $walletType = match ($type) {
            'direct'   => 'commission_direct',
            'welcome'  => 'commission_welcome',
            'campaign' => 'campaign_reward',
            default    => 'commission_level',
        };

        $description = match ($type) {
            'direct'   => 'پاداش دعوت مستقیم (لایه ۰)',
            'welcome'  => 'پاداش خوش‌آمدگویی عضویت با لینک دعوت',
            'campaign' => 'جایزه کمپین',
            default    => 'درآمد لایه ' . fa_digits($level) . ' (دعوت غیرمستقیم)',
        };

        $txId = $this->wallet->credit($beneficiaryId, $walletType, $amount, [
            'source_type' => 'referral_commission',
            'source_id' => $commissionId,
            'event_id' => $eventId,
            'level' => $level,
            'related_user_id' => $sourceUserId,
            'description' => $description,
            'idempotency_key' => 'commission:' . $commissionId,
            'meta' => [
                'settings_version' => $this->settings->version,
                'calc_type' => $calcType,
                'percent_base' => $this->settings->percentBase,
                'percent' => $percent,
                'fixed_amount' => (int) ($extraMeta['fixed_amount'] ?? 0),
                'max_amount' => (int) ($extraMeta['max_amount'] ?? 0),
                'cap_applied' => !empty($extraMeta['cap_applied']),
                'level' => $level,
                'type' => $type,
                'direct_reward' => $this->settings->directReward,
                'invitee_reward' => $this->settings->inviteeReward,
                'platform' => (string) ($extraMeta['platform'] ?? 'telegram'),
            ],
        ]);

        if ($txId === null) {
            return ['beneficiary_id' => $beneficiaryId, 'amount' => 0, 'level' => $level, 'status' => 'duplicate_tx'];
        }

        $hasWelcomeCol = $type !== 'welcome' || $this->db->columnExists('users', 'welcome_earned');
        $column = match ($type) {
            'direct'   => 'direct_earned',
            'welcome'  => $hasWelcomeCol ? 'welcome_earned' : null,
            'campaign' => 'campaign_earned',
            default    => 'layer_earned',
        };
        if ($column !== null) {
            $this->db->run(
                "UPDATE users SET {$column} = {$column} + :a1, total_earned = total_earned + :a2, updated_at = :now WHERE id = :id",
                ['a1' => $amount, 'a2' => $amount, 'now' => now(), 'id' => $beneficiaryId]
            );
        } else {
            $this->db->run(
                'UPDATE users SET total_earned = total_earned + :a, updated_at = :now WHERE id = :id',
                ['a' => $amount, 'now' => now(), 'id' => $beneficiaryId]
            );
        }

        return [
            'commission_id' => $commissionId,
            'transaction_id' => $txId,
            'beneficiary_id' => $beneficiaryId,
            'amount' => $amount,
            'level' => $level,
            'type' => $type,
            'calc_type' => $calcType,
            'percent' => $percent,
            'cap_applied' => !empty($extraMeta['cap_applied']),
            'status' => 'paid',
        ];
    }

    /**
     * کوتاه کردن پرداخت‌ها تا سقف مجاز (ابتدا از عمیق‌ترین لایه‌ها)
     */
    private function trimToCap(array &$commissions, int $cap): void
    {
        $cap = max(0, $cap);
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
            $this->syncBeneficiaryFromLedger((int) $c['beneficiary_id']);
        }
    }

    /**
     * همگام‌سازی دقیق کیف پول و ستون‌های درآمدی کاربر از روی دفتر کل تراکنش‌ها
     */
    private function syncBeneficiaryFromLedger(int $userId): void
    {
        $this->wallet->rebuildBalance($userId);

        $totals = $this->db->first(
            "SELECT
                COALESCE(SUM(CASE WHEN type IN ('commission_direct','commission_welcome','commission_level','campaign_reward') THEN amount ELSE 0 END), 0) AS total_earned,
                COALESCE(SUM(CASE WHEN type = 'commission_direct' THEN amount ELSE 0 END), 0) AS direct_earned,
                COALESCE(SUM(CASE WHEN type = 'commission_welcome' THEN amount ELSE 0 END), 0) AS welcome_earned,
                COALESCE(SUM(CASE WHEN type = 'commission_level' THEN amount ELSE 0 END), 0) AS layer_earned,
                COALESCE(SUM(CASE WHEN type = 'campaign_reward' THEN amount ELSE 0 END), 0) AS campaign_earned
             FROM wallet_transactions
             WHERE user_id = :u AND direction = 'credit' AND status = 'completed'",
            ['u' => $userId]
        ) ?? [];

        $update = [
            'total_earned' => (int) ($totals['total_earned'] ?? 0),
            'direct_earned' => (int) ($totals['direct_earned'] ?? 0),
            'layer_earned' => (int) ($totals['layer_earned'] ?? 0),
            'campaign_earned' => (int) ($totals['campaign_earned'] ?? 0),
            'updated_at' => now(),
        ];
        if ($this->db->columnExists('users', 'welcome_earned')) {
            $update['welcome_earned'] = (int) ($totals['welcome_earned'] ?? 0);
        }
        $this->db->update('users', $update, 'id = :id', ['id' => $userId]);
    }

    /**
     * زنجیره معرف‌های غیرمستقیم برای محاسبه درآمد لایه‌ای:
     *   - depth = 1 در referral_relations معرف مستقیم (لایه ۰) است و اینجا برگردانده نمی‌شود.
     *   - depth = 2 ⇒ لایه ۱ غیرمستقیم
     *   - depth = k + 1 ⇒ لایه k غیرمستقیم (تا k = $maxLevels)
     *
     * @return array<int,int> [level(1..maxLevels) => ancestor_user_id]
     */
    public function indirectAncestors(int $userId, int $maxLevels = 8): array
    {
        $maxLevels = max(1, min(CommissionSettings::MAX_SUPPORTED_LEVELS, $maxLevels));
        $maxDepth = $maxLevels + 1; // depth=2..maxLevels+1 ⇒ level=1..maxLevels
        $rows = $this->db->all(
            'SELECT ancestor_id, depth FROM referral_relations
             WHERE descendant_id = :d AND depth >= 2 AND depth <= :m
             ORDER BY depth ASC',
            ['d' => $userId, 'm' => $maxDepth]
        );
        $out = [];
        foreach ($rows as $row) {
            $level = (int) $row['depth'] - 1;
            if ($level >= 1 && $level <= $maxLevels) {
                $out[$level] = (int) $row['ancestor_id'];
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * زنجیره کامل اجداد کاربر بر حسب شماره لایه واقعی (۰ = معرف مستقیم، ۱..۸ = لایه‌های غیرمستقیم)
     *
     * @return array<int,int> [layer(0..maxLevels) => user_id]
     */
    public function ancestors(int $userId, int $maxLevels = 8): array
    {
        $rows = $this->db->all(
            'SELECT ancestor_id, depth FROM referral_relations WHERE descendant_id = :d AND depth <= :m ORDER BY depth ASC',
            ['d' => $userId, 'm' => $maxLevels + 1]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['depth'] - 1] = (int) $row['ancestor_id'];
        }
        ksort($out);
        return $out;
    }

    /**
     * بازگردانی مالی یک رویداد (برای تقلب/لغو) — سوابق در دفتر کل Append-Only حفظ می‌شوند
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
                $revDesc = match ((string) ($commission['type'] ?? '')) {
                    'welcome' => 'برگشت پاداش خوش‌آمدگویی: ' . $reason,
                    'direct'  => 'برگشت پاداش لایه ۰ (دعوت مستقیم): ' . $reason,
                    default   => 'برگشت درآمد لایه ' . fa_digits((int) $commission['level']) . ': ' . $reason,
                };
                $this->wallet->debit((int) $commission['beneficiary_id'], 'reversal', (int) $commission['amount'], [
                    'source_type' => 'reversal',
                    'source_id' => (int) $commission['id'],
                    'related_user_id' => (int) $event['user_id'],
                    'description' => $revDesc,
                    'idempotency_key' => 'reversal:commission:' . $commission['id'],
                    'created_by_admin_id' => $adminId,
                    'reversal_of_id' => (int) $tx['id'],
                    'level' => (int) $commission['level'],
                    'meta' => [
                        'reason' => $reason,
                        'original_tx' => (int) $tx['id'],
                        'event_id' => $eventId,
                        'commission_type' => $commission['type'] ?? '',
                    ],
                ], allowNegative: true);

                $this->db->update('referral_commissions', ['status' => 'reverted'], 'id = :id', ['id' => (int) $commission['id']]);

                $amt = (int) $commission['amount'];
                $ctype = (string) ($commission['type'] ?? '');
                $earnCol = match ($ctype) {
                    'direct'   => 'direct_earned',
                    'welcome'  => $this->db->columnExists('users', 'welcome_earned') ? 'welcome_earned' : null,
                    'campaign' => 'campaign_earned',
                    default    => 'layer_earned',
                };
                $uRow = $this->db->first('SELECT * FROM users WHERE id = :id', ['id' => (int) $commission['beneficiary_id']]);
                if ($uRow !== null) {
                    $upd = [
                        'total_earned' => max(0, (int) ($uRow['total_earned'] ?? 0) - $amt),
                        'updated_at' => now(),
                    ];
                    if ($earnCol !== null && array_key_exists($earnCol, $uRow)) {
                        $upd[$earnCol] = max(0, (int) ($uRow[$earnCol] ?? 0) - $amt);
                    }
                    $this->db->update('users', $upd, 'id = :id', ['id' => (int) $commission['beneficiary_id']]);
                }
                $reverted++;
            }
            $this->db->update('referral_events', ['status' => 'reverted'], 'id = :id', ['id' => $eventId]);
            return $reverted;
        });
    }
}
