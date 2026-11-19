<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;

/**
 * آمار و نمودارهای داشبورد مدیر
 */
final class StatsService
{
    public static function overview(): array
    {
        $db = Database::instance();
        $today = today_start();

        $totalUsers = (int) $db->value('SELECT COUNT(*) FROM users');
        $newToday = (int) $db->value('SELECT COUNT(*) FROM users WHERE joined_at >= :t', ['t' => $today]);
        $newWeek = (int) $db->value('SELECT COUNT(*) FROM users WHERE joined_at >= :t', ['t' => date('Y-m-d H:i:s', time() - 7 * 86400)]);
        $activeUsers = (int) $db->value('SELECT COUNT(*) FROM users WHERE status = :s', ['s' => 'active']);
        $blockedUsers = (int) $db->value('SELECT COUNT(*) FROM users WHERE status = :s', ['s' => 'blocked']);
        $suspiciousUsers = (int) $db->value('SELECT COUNT(*) FROM users WHERE status = :s', ['s' => 'suspicious']);

        $totalReferrals = (int) $db->value('SELECT COUNT(*) FROM users WHERE referred_by IS NOT NULL');
        $qualifiedReferrals = (int) $db->value('SELECT COUNT(*) FROM users WHERE qualified_at IS NOT NULL');

        $totalCommissions = (int) $db->value(
            "SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions
             WHERE status = 'completed' AND direction = 'credit'
               AND type IN ('commission_direct','commission_welcome','commission_level','campaign_reward')"
        );
        $totalEarned = (int) $db->value('SELECT COALESCE(SUM(total_earned), 0) FROM users');
        $totalWithdrawn = (int) $db->value('SELECT COALESCE(SUM(total_withdrawn), 0) FROM users');

        $withdrawals = (new WithdrawalService($db))->stats();
        $activeCampaigns = (int) $db->value(
            'SELECT COUNT(*) FROM campaigns WHERE is_active = 1 AND (ends_at IS NULL OR ends_at >= :now)',
            ['now' => now()]
        );

        $settings = TelegramService::settings($db);
        $botStatus = ($settings['connection_status'] ?? 'disconnected');
        $webhookOk = !empty($settings['webhook_active']) || (($settings['webhook_status'] ?? '') === 'active');

        $activeMessengersCount = 0;
        if ($db->tableExists('messenger_connections')) {
            $activeMessengersCount = (int) $db->value(
                'SELECT COUNT(*) FROM messenger_connections WHERE is_enabled = 1 AND bot_token_enc IS NOT NULL'
            );
        }
        if ($activeMessengersCount === 0 && $botStatus === 'connected') {
            $activeMessengersCount = 1;
        }

        $indirectLinksCount = (int) $db->value('SELECT COUNT(*) FROM referral_relations WHERE depth >= 2');
        $pendingCommissionsAmount = (int) $db->value(
            "SELECT COALESCE(SUM(amount), 0) FROM referral_commissions WHERE status = 'pending'"
        );
        $pendingCommissionsCount = (int) $db->value(
            "SELECT COUNT(*) FROM referral_commissions WHERE status = 'pending'"
        );

        return [
            'total_users' => $totalUsers,
            'new_today' => $newToday,
            'new_week' => $newWeek,
            'active_users' => $activeUsers,
            'blocked_users' => $blockedUsers,
            'suspicious_users' => $suspiciousUsers,
            'total_referrals' => $totalReferrals,
            'indirect_referrals' => $indirectLinksCount,
            'qualified_referrals' => $qualifiedReferrals,
            'total_commissions' => $totalCommissions,
            'pending_commissions_amount' => $pendingCommissionsAmount,
            'pending_commissions_count' => $pendingCommissionsCount,
            'total_earned' => $totalEarned,
            'total_withdrawn' => $totalWithdrawn,
            'pending_withdrawals_amount' => $withdrawals['pending_amount'],
            'pending_withdrawals_count' => array_sum(array_map(
                static fn($s) => $withdrawals['counts'][$s] ?? 0,
                WithdrawalService::OPEN_STATUSES
            )),
            'paid_withdrawals_amount' => $withdrawals['paid_amount'],
            'active_campaigns' => $activeCampaigns,
            'bot_status' => $botStatus,
            'bot_connected' => $botStatus === 'connected' || $activeMessengersCount > 0,
            'active_messengers_count' => $activeMessengersCount,
            'webhook_active' => $webhookOk,
            'wallet_total_balance' => (int) $db->value('SELECT COALESCE(SUM(balance), 0) FROM wallets'),
            'pending_security_events' => (int) $db->value("SELECT COUNT(*) FROM security_events WHERE status = 'open'"),
        ];
    }

    /** رشد کاربران در بازه روزانه */
    public static function userGrowth(int $days = 30): array
    {
        $db = Database::instance();
        $start = date('Y-m-d', time() - ($days - 1) * 86400);
        $rows = $db->all(
            'SELECT DATE(joined_at) AS d, COUNT(*) AS c FROM users WHERE joined_at >= :s GROUP BY DATE(joined_at)',
            ['s' => $start . ' 00:00:00']
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['d']] = (int) $row['c'];
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', time() - $i * 86400);
            $out[] = ['date' => $date, 'value' => $map[$date] ?? 0];
        }
        return $out;
    }

    /** دعوت‌ها و پورسانت روزانه */
    public static function referralSeries(int $days = 30): array
    {
        $db = Database::instance();
        $start = date('Y-m-d', time() - ($days - 1) * 86400) . ' 00:00:00';
        $refs = $db->all(
            'SELECT DATE(created_at) AS d, COUNT(*) AS c FROM users WHERE referred_by IS NOT NULL AND created_at >= :s GROUP BY DATE(created_at)',
            ['s' => $start]
        );
        $payouts = $db->all(
            "SELECT DATE(created_at) AS d, COALESCE(SUM(amount),0) AS s FROM wallet_transactions
             WHERE type IN ('commission_direct','commission_welcome','commission_level') AND status = 'completed' AND direction = 'credit' AND created_at >= :s GROUP BY DATE(created_at)",
            ['s' => $start]
        );
        $withdrawals = $db->all(
            'SELECT DATE(created_at) AS d, COALESCE(SUM(amount),0) AS s FROM withdrawal_requests WHERE created_at >= :s GROUP BY DATE(created_at)',
            ['s' => $start]
        );
        $byDate = static function (array $rows, string $field) {
            $map = [];
            foreach ($rows as $row) {
                $map[(string) $row['d']] = (int) $row[$field];
            }
            return $map;
        };
        $refMap = $byDate($refs, 'c');
        $payMap = $byDate($payouts, 's');
        $wdMap = $byDate($withdrawals, 's');

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', time() - $i * 86400);
            $out[] = [
                'date' => $date,
                'referrals' => $refMap[$date] ?? 0,
                'payouts' => $payMap[$date] ?? 0,
                'withdrawals' => $wdMap[$date] ?? 0,
            ];
        }
        return $out;
    }

    /**
     * تحلیل کامل رشد اعضا (روزانه، تجمعی، تفکیک دعوت‌شده/مستقیم، واجد شرایط و نرخ رشد دوره)
     * کاملاً سازگار با MySQL 5.7/8.x (ONLY_FULL_GROUP_BY) و SQLite.
     */
    public static function memberGrowthBreakdown(int $days = 30): array
    {
        $db = Database::instance();
        $days = max(1, min(365, $days));
        $startDate = date('Y-m-d', time() - ($days - 1) * 86400);
        $startTs = $startDate . ' 00:00:00';
        $prevStartTs = date('Y-m-d', time() - (2 * $days - 1) * 86400) . ' 00:00:00';

        $baseline = (int) $db->value('SELECT COUNT(*) FROM users WHERE joined_at < :s', ['s' => $startTs]);
        $prevPeriodNew = (int) $db->value(
            'SELECT COUNT(*) FROM users WHERE joined_at >= :ps AND joined_at < :s',
            ['ps' => $prevStartTs, 's' => $startTs]
        );

        $allRows = $db->all(
            'SELECT DATE(joined_at) AS d, COUNT(*) AS c FROM users WHERE joined_at >= :s GROUP BY DATE(joined_at)',
            ['s' => $startTs]
        );
        $refRows = $db->all(
            'SELECT DATE(joined_at) AS d, COUNT(*) AS c FROM users WHERE referred_by IS NOT NULL AND joined_at >= :s GROUP BY DATE(joined_at)',
            ['s' => $startTs]
        );
        $qualRows = $db->all(
            'SELECT DATE(qualified_at) AS d, COUNT(*) AS c FROM users WHERE qualified_at IS NOT NULL AND qualified_at >= :s GROUP BY DATE(qualified_at)',
            ['s' => $startTs]
        );

        $toMap = static function (array $rows): array {
            $map = [];
            foreach ($rows as $row) {
                $map[(string) $row['d']] = (int) $row['c'];
            }
            return $map;
        };
        $allMap = $toMap($allRows);
        $refMap = $toMap($refRows);
        $qualMap = $toMap($qualRows);

        $labels = [];
        $dailyTotal = [];
        $dailyReferred = [];
        $dailyDirect = [];
        $dailyQualified = [];
        $cumulative = [];

        $running = $baseline;
        $periodNew = 0;
        $periodReferred = 0;
        $periodDirect = 0;
        $periodQualified = 0;
        $peakCount = 0;
        $peakLabel = '—';

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', time() - $i * 86400);
            $fullJalali = fa_date($date . ' 12:00:00', false); // ۱۴۰۵/۰۸/۲۸
            $parts = explode('/', $fullJalali);
            $shortLabel = count($parts) === 3 ? ($parts[1] . '/' . $parts[2]) : fa_digits(substr($date, 5));

            $tot = $allMap[$date] ?? 0;
            $ref = min($tot, $refMap[$date] ?? 0);
            $dir = max(0, $tot - $ref);
            $qual = $qualMap[$date] ?? 0;
            $running += $tot;

            $labels[] = $shortLabel;
            $dailyTotal[] = $tot;
            $dailyReferred[] = $ref;
            $dailyDirect[] = $dir;
            $dailyQualified[] = $qual;
            $cumulative[] = $running;

            $periodNew += $tot;
            $periodReferred += $ref;
            $periodDirect += $dir;
            $periodQualified += $qual;
            if ($tot >= $peakCount && $tot > 0) {
                $peakCount = $tot;
                $peakLabel = $shortLabel;
            }
        }

        $growthPct = $prevPeriodNew > 0
            ? (int) round((($periodNew - $prevPeriodNew) / $prevPeriodNew) * 100)
            : ($periodNew > 0 ? 100 : 0);

        $totalUsersAll = (int) $db->value('SELECT COUNT(*) FROM users');
        $totalRefAll = (int) $db->value('SELECT COUNT(*) FROM users WHERE referred_by IS NOT NULL');
        $totalQualRefAll = (int) $db->value('SELECT COUNT(*) FROM users WHERE referred_by IS NOT NULL AND qualified_at IS NOT NULL');
        $activeAll = (int) $db->value("SELECT COUNT(*) FROM users WHERE status = 'active'");

        return [
            'labels' => $labels,
            'daily_total' => $dailyTotal,
            'daily_referred' => $dailyReferred,
            'daily_direct' => $dailyDirect,
            'daily_qualified' => $dailyQualified,
            'cumulative' => $cumulative,
            'summary' => [
                'baseline' => $baseline,
                'end_total' => $running,
                'period_new' => $periodNew,
                'prev_period_new' => $prevPeriodNew,
                'growth_pct' => $growthPct,
                'period_referred' => $periodReferred,
                'period_direct' => $periodDirect,
                'period_qualified' => $periodQualified,
                'daily_avg' => round($periodNew / $days, 1),
                'peak_count' => $peakCount,
                'peak_label' => $peakLabel,
                'referral_share_pct' => $totalUsersAll > 0 ? (int) round(($totalRefAll / $totalUsersAll) * 100) : 0,
                'qualification_rate_pct' => $totalRefAll > 0 ? (int) round(($totalQualRefAll / $totalRefAll) * 100) : 0,
                'active_rate_pct' => $totalUsersAll > 0 ? (int) round(($activeAll / $totalUsersAll) * 100) : 0,
            ],
        ];
    }

    /** ترکیب کل اعضای ربات برای نمودار دونات */
    public static function memberComposition(): array
    {
        $db = Database::instance();
        $refQualified = (int) $db->value(
            "SELECT COUNT(*) FROM users WHERE status = 'active' AND referred_by IS NOT NULL AND qualified_at IS NOT NULL"
        );
        $refPending = (int) $db->value(
            "SELECT COUNT(*) FROM users WHERE status = 'active' AND referred_by IS NOT NULL AND qualified_at IS NULL"
        );
        $organic = (int) $db->value(
            "SELECT COUNT(*) FROM users WHERE status = 'active' AND referred_by IS NULL"
        );
        $flagged = (int) $db->value(
            "SELECT COUNT(*) FROM users WHERE status <> 'active'"
        );
        return [
            ['label' => 'دعوت‌شده (واجد شرایط)', 'value' => $refQualified, 'color' => '#10b981'],
            ['label' => 'دعوت‌شده (در انتظار)', 'value' => $refPending, 'color' => '#06b6d4'],
            ['label' => 'عضویت مستقیم', 'value' => $organic, 'color' => '#6366f1'],
            ['label' => 'مشکوک / مسدود', 'value' => $flagged, 'color' => '#f59e0b'],
        ];
    }

    /** توزیع زیرمجموعه‌ها در لایه‌های واقعی شبکه (لایه ۰ مستقیم = depth 1، لایه ۱+ غیرمستقیم = depth >= 2) */
    public static function networkByLevel(): array
    {
        return Database::instance()->all(
            'SELECT (depth - 1) AS layer, depth AS level, COUNT(*) AS c FROM referral_relations GROUP BY depth ORDER BY depth'
        );
    }

    public static function topUsers(int $limit = 10): array
    {
        $db = Database::instance();
        $platCol = $db->columnExists('users', 'entry_platform') ? 'entry_platform,' : "'telegram' AS entry_platform,";
        return $db->all(
            "SELECT id, first_name, last_name, username, {$platCol} direct_ref_count, total_ref_count, total_earned
             FROM users ORDER BY total_earned DESC, direct_ref_count DESC LIMIT " . (int) $limit
        );
    }

    public static function commissionByLevel(): array
    {
        return Database::instance()->all(
            "SELECT level,
                    COUNT(*) AS c,
                    COALESCE(SUM(CASE WHEN status IN ('approved','credited') THEN amount ELSE 0 END), 0) AS s,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_sum
             FROM referral_commissions
             WHERE type IN ('direct', 'level') AND status IN ('approved', 'credited', 'pending')
             GROUP BY level ORDER BY level"
        );
    }

    /**
     * گزارش جامع و شفاف درآمد لایه‌ای کل سامانه:
     * تعداد دعوت مستقیم (لایه ۰)، تعداد غیرمستقیم (لایه‌های ۱+)، تعداد کاربر و تراکنش در هر لایه،
     * درآمد پرداخت‌شده (واریزشده به کیف پول)، درآمد در انتظار تأیید، پاداش خوش‌آمدگویی و جمع کل.
     */
    public static function layerFinancialReport(?Database $db = null): array
    {
        $db ??= Database::instance();
        $cs = CommissionSettings::current($db);

        $relRows = $db->all(
            'SELECT rr.depth,
                    COUNT(*) AS users_count,
                    SUM(CASE WHEN u.qualified_at IS NOT NULL THEN 1 ELSE 0 END) AS qualified_count
             FROM referral_relations rr
             JOIN users u ON u.id = rr.descendant_id
             GROUP BY rr.depth
             ORDER BY rr.depth ASC'
        );
        $relMap = [];
        $directUsers = 0;
        $indirectUsers = 0;
        foreach ($relRows as $r) {
            $layer = max(0, (int) $r['depth'] - 1);
            $cnt = (int) $r['users_count'];
            $relMap[$layer] = [
                'users_count' => $cnt,
                'qualified_count' => (int) ($r['qualified_count'] ?? 0),
            ];
            if ($layer === 0) {
                $directUsers = $cnt;
            } else {
                $indirectUsers += $cnt;
            }
        }

        $commRows = $db->all(
            "SELECT level, type,
                    COUNT(*) AS tx_count,
                    COALESCE(SUM(CASE WHEN status IN ('approved','credited') THEN amount ELSE 0 END), 0) AS paid_amount,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount,
                    COALESCE(SUM(CASE WHEN status = 'rejected' THEN amount ELSE 0 END), 0) AS rejected_amount
             FROM referral_commissions
             GROUP BY level, type
             ORDER BY level ASC"
        );

        $maxShownLayer = max($cs->maxLevels, $relMap ? max(array_keys($relMap)) : 0);
        $layers = [];
        for ($l = 0; $l <= $maxShownLayer; $l++) {
            $rule = $l === 0 ? null : $cs->level($l);
            $layers[$l] = [
                'layer' => $l,
                'label' => $l === 0 ? 'لایه ۰ (دعوت مستقیم)' : ('لایه ' . fa_digits($l) . ' (غیرمستقیم)'),
                'is_direct' => $l === 0,
                'rule_enabled' => $l === 0 ? ($cs->effectiveDirectReward() > 0) : ($cs->multiLevelEnabled && !empty($rule['enabled'])),
                'rule_summary' => $l === 0
                    ? ('مبلغ ثابت: ' . wtoman($cs->effectiveDirectReward()))
                    : ($rule === null ? '—' : (
                        ($rule['calc_type'] ?? 'percent') === 'fixed'
                            ? ('مبلغ ثابت: ' . wtoman((int) $rule['amount']))
                            : (fa_digits((string) $rule['percent']) . '٪' . ((int) ($rule['max_amount'] ?? 0) > 0 ? ' (سقف ' . wtoman((int) $rule['max_amount']) . ')' : ''))
                    )),
                'users_count' => $relMap[$l]['users_count'] ?? 0,
                'qualified_count' => $relMap[$l]['qualified_count'] ?? 0,
                'tx_count' => 0,
                'paid_amount' => 0,
                'pending_amount' => 0,
                'rejected_amount' => 0,
                'total_amount' => 0,
            ];
        }

        $welcome = ['tx_count' => 0, 'paid_amount' => 0, 'pending_amount' => 0, 'total_amount' => 0];
        $totalPaid = 0;
        $totalPending = 0;
        $directPaid = 0;
        $indirectPaid = 0;

        foreach ($commRows as $c) {
            $type = (string) $c['type'];
            $paid = (int) $c['paid_amount'];
            $pend = (int) $c['pending_amount'];
            $rej = (int) $c['rejected_amount'];
            $cnt = (int) $c['tx_count'];

            $totalPaid += $paid;
            $totalPending += $pend;

            if ($type === 'welcome') {
                $welcome['tx_count'] += $cnt;
                $welcome['paid_amount'] += $paid;
                $welcome['pending_amount'] += $pend;
                $welcome['total_amount'] = $welcome['paid_amount'] + $welcome['pending_amount'];
                continue;
            }

            $lk = $type === 'direct' ? 0 : max(1, (int) $c['level']);
            if (!isset($layers[$lk])) {
                $layers[$lk] = [
                    'layer' => $lk,
                    'label' => 'لایه ' . fa_digits($lk) . ' (غیرمستقیم)',
                    'is_direct' => false,
                    'rule_enabled' => false,
                    'rule_summary' => 'تاریخی',
                    'users_count' => $relMap[$lk]['users_count'] ?? 0,
                    'qualified_count' => $relMap[$lk]['qualified_count'] ?? 0,
                    'tx_count' => 0,
                    'paid_amount' => 0,
                    'pending_amount' => 0,
                    'rejected_amount' => 0,
                    'total_amount' => 0,
                ];
            }
            $layers[$lk]['tx_count'] += $cnt;
            $layers[$lk]['paid_amount'] += $paid;
            $layers[$lk]['pending_amount'] += $pend;
            $layers[$lk]['rejected_amount'] += $rej;
            $layers[$lk]['total_amount'] = $layers[$lk]['paid_amount'] + $layers[$lk]['pending_amount'];

            if ($lk === 0) {
                $directPaid += $paid;
            } else {
                $indirectPaid += $paid;
            }
        }
        ksort($layers);

        $wd = (new WithdrawalService($db))->stats();

        return [
            'direct_users' => $directUsers,
            'indirect_users' => $indirectUsers,
            'total_network_links' => $directUsers + $indirectUsers,
            'direct_paid' => $directPaid,
            'indirect_paid' => $indirectPaid,
            'welcome' => $welcome,
            'total_paid' => $totalPaid,
            'total_pending' => $totalPending,
            'total_income' => $totalPaid + $totalPending,
            'withdrawals_paid' => (int) ($wd['paid_amount'] ?? 0),
            'withdrawals_pending' => (int) ($wd['pending_amount'] ?? 0),
            'wallet_balance' => (int) $db->value('SELECT COALESCE(SUM(balance), 0) FROM wallets'),
            'layers' => array_values($layers),
        ];
    }

    /**
     * گزارش عملکرد و درآمد مستقل به تفکیک هر پیام‌رسان (شامل کاربران، ثبت‌نام، رفرال مستقیم/غیرمستقیم،
     * پاداش‌ها، عضویت کانال‌ها، وضعیت ربات، پیام‌های ارسالی و خطاهای ارتباطی)
     */
    public static function messengerPerformanceReport(?Database $db = null, bool $onlyActiveConnected = false): array
    {
        $db ??= Database::instance();
        $registry = new \RB\Messengers\MessengerRegistry($db);
        $connections = $registry->allConnections();

        $hasEntry = $db->columnExists('users', 'entry_platform');
        $hasSource = $db->columnExists('users', 'referral_source_platform');
        $hasAcc = $db->tableExists('user_messenger_accounts');
        $hasEvPlat = $db->columnExists('referral_events', 'platform');
        $hasRel = $db->tableExists('referral_relations');
        $hasChPlat = $db->columnExists('telegram_channels', 'platform');
        $hasChMem = $db->tableExists('user_channel_memberships');
        $hasMsgPlat = $db->columnExists('telegram_messages', 'platform');
        $hasBcPlat = $db->columnExists('broadcast_campaigns', 'target_platform');
        $hasErrLog = $db->tableExists('messenger_error_logs');

        $todayTs = today_start();
        $weekTs = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $monthTs = date('Y-m-d 00:00:00', strtotime('-29 days'));
        $last24h = date('Y-m-d H:i:s', time() - 86400);

        $usersByPlat = [];
        if ($hasEntry) {
            foreach ($db->all(
                "SELECT COALESCE(entry_platform, 'telegram') AS p,
                        COUNT(*) AS total_users,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_users,
                        SUM(CASE WHEN joined_at >= :today THEN 1 ELSE 0 END) AS today_users,
                        SUM(CASE WHEN joined_at >= :week THEN 1 ELSE 0 END) AS week_users,
                        SUM(CASE WHEN joined_at >= :month THEN 1 ELSE 0 END) AS month_users
                 FROM users GROUP BY COALESCE(entry_platform, 'telegram')",
                ['today' => $todayTs, 'week' => $weekTs, 'month' => $monthTs]
            ) as $r) {
                $usersByPlat[(string) $r['p']] = [
                    'total_users' => (int) $r['total_users'],
                    'active_users' => (int) $r['active_users'],
                    'today_users' => (int) $r['today_users'],
                    'week_users' => (int) $r['week_users'],
                    'month_users' => (int) $r['month_users'],
                ];
            }
        }

        $refsByPlat = [];
        if ($hasSource) {
            foreach ($db->all(
                "SELECT COALESCE(referral_source_platform, entry_platform, 'telegram') AS p,
                        COUNT(*) AS direct_refs,
                        SUM(CASE WHEN qualified_at IS NOT NULL THEN 1 ELSE 0 END) AS qualified_refs,
                        SUM(CASE WHEN qualified_at IS NULL THEN 1 ELSE 0 END) AS pending_refs
                 FROM users WHERE referred_by IS NOT NULL
                 GROUP BY COALESCE(referral_source_platform, entry_platform, 'telegram')"
            ) as $r) {
                $refsByPlat[(string) $r['p']] = [
                    'direct_refs' => (int) $r['direct_refs'],
                    'qualified_refs' => (int) $r['qualified_refs'],
                    'pending_refs' => (int) $r['pending_refs'],
                ];
            }
        }

        // دعوت غیرمستقیم (عمق ۲ به بعد در referral_relations = لایه‌های ۱ تا ۸ غیرمستقیم)
        $indirectByPlat = [];
        if ($hasRel && $hasEntry) {
            $platExpr = $hasSource
                ? "COALESCE(u.referral_source_platform, u.entry_platform, 'telegram')"
                : "COALESCE(u.entry_platform, 'telegram')";
            foreach ($db->all(
                "SELECT {$platExpr} AS p, COUNT(*) AS indirect_refs
                 FROM referral_relations rr
                 JOIN users u ON u.id = rr.descendant_id
                 WHERE rr.depth >= 2
                 GROUP BY {$platExpr}"
            ) as $r) {
                $indirectByPlat[(string) $r['p']] = (int) $r['indirect_refs'];
            }
        }

        $linkedByPlat = [];
        if ($hasAcc) {
            foreach ($db->all('SELECT platform, COUNT(*) AS c FROM user_messenger_accounts GROUP BY platform') as $r) {
                $linkedByPlat[(string) $r['platform']] = (int) $r['c'];
            }
        }

        $incomeByPlat = [];
        if ($hasEvPlat) {
            foreach ($db->all(
                "SELECT COALESCE(e.platform, 'telegram') AS p,
                        COALESCE(SUM(CASE WHEN c.type = 'direct' AND c.status IN ('approved','credited') THEN c.amount ELSE 0 END), 0) AS direct_paid,
                        COALESCE(SUM(CASE WHEN c.type = 'level' AND c.status IN ('approved','credited') THEN c.amount ELSE 0 END), 0) AS indirect_paid,
                        COALESCE(SUM(CASE WHEN c.type = 'welcome' AND c.status IN ('approved','credited') THEN c.amount ELSE 0 END), 0) AS welcome_paid,
                        COALESCE(SUM(CASE WHEN c.status IN ('approved','credited') THEN c.amount ELSE 0 END), 0) AS total_paid,
                        COALESCE(SUM(CASE WHEN c.status = 'pending' THEN c.amount ELSE 0 END), 0) AS total_pending
                 FROM referral_commissions c
                 JOIN referral_events e ON e.id = c.event_id
                 GROUP BY COALESCE(e.platform, 'telegram')"
            ) as $r) {
                $incomeByPlat[(string) $r['p']] = [
                    'direct_paid' => (int) $r['direct_paid'],
                    'indirect_paid' => (int) $r['indirect_paid'],
                    'welcome_paid' => (int) $r['welcome_paid'],
                    'total_paid' => (int) $r['total_paid'],
                    'total_pending' => (int) $r['total_pending'],
                ];
            }
        }

        // آمار کانال‌های فعال و عضویت‌های تأییدشده به تفکیک پیام‌رسان میزبان
        $channelsByPlat = [];
        if ($db->tableExists('telegram_channels')) {
            if ($hasChPlat) {
                foreach ($db->all("SELECT COALESCE(platform, 'telegram') AS p, COUNT(*) AS c FROM telegram_channels WHERE is_active = 1 GROUP BY COALESCE(platform, 'telegram')") as $r) {
                    $channelsByPlat[(string) $r['p']] = (int) $r['c'];
                }
            } else {
                $channelsByPlat['telegram'] = (int) $db->value('SELECT COUNT(*) FROM telegram_channels WHERE is_active = 1');
            }
        }

        $chMembersByPlat = [];
        if ($hasChMem) {
            foreach ($db->all(
                "SELECT channel_platform AS p,
                        COUNT(*) AS total_m,
                        SUM(CASE WHEN verify_method = 'api_direct' THEN 1 ELSE 0 END) AS direct_m,
                        SUM(CASE WHEN verify_method = 'api_cross_linked' THEN 1 ELSE 0 END) AS cross_m,
                        SUM(CASE WHEN verify_method = 'self_declared' THEN 1 ELSE 0 END) AS self_m
                 FROM user_channel_memberships
                 WHERE status = 'verified'
                 GROUP BY channel_platform"
            ) as $r) {
                $chMembersByPlat[(string) $r['p']] = [
                    'total' => (int) $r['total_m'],
                    'direct' => (int) $r['direct_m'],
                    'cross' => (int) $r['cross_m'],
                    'self' => (int) $r['self_m'],
                ];
            }
        }

        // آمار پیام‌های ثبت‌شده در telegram_messages و پیام‌های همگانی
        $msgByPlat = [];
        if ($hasMsgPlat && $db->tableExists('telegram_messages')) {
            foreach ($db->all(
                "SELECT COALESCE(platform, 'telegram') AS p,
                        SUM(CASE WHEN direction = 'out' THEN 1 ELSE 0 END) AS out_cnt,
                        SUM(CASE WHEN direction = 'in' THEN 1 ELSE 0 END) AS in_cnt
                 FROM telegram_messages GROUP BY COALESCE(platform, 'telegram')"
            ) as $r) {
                $msgByPlat[(string) $r['p']] = [
                    'out' => (int) $r['out_cnt'],
                    'in' => (int) $r['in_cnt'],
                ];
            }
        }

        $bcByPlat = [];
        if ($hasBcPlat && $db->tableExists('broadcast_campaigns')) {
            foreach ($db->all(
                "SELECT target_platform AS p, COALESCE(SUM(sent_count), 0) AS sent, COALESCE(SUM(failed_count), 0) AS failed
                 FROM broadcast_campaigns WHERE target_platform <> 'all' GROUP BY target_platform"
            ) as $r) {
                $bcByPlat[(string) $r['p']] = [
                    'sent' => (int) $r['sent'],
                    'failed' => (int) $r['failed'],
                ];
            }
        }

        // آمار خطاهای ارتباطی ثبت‌شده
        $errByPlat = [];
        if ($hasErrLog) {
            foreach ($db->all(
                'SELECT platform AS p, COUNT(*) AS total_err,
                        SUM(CASE WHEN created_at >= :d THEN 1 ELSE 0 END) AS err_24h
                 FROM messenger_error_logs GROUP BY platform',
                ['d' => $last24h]
            ) as $r) {
                $errByPlat[(string) $r['p']] = [
                    'total' => (int) $r['total_err'],
                    'last_24h' => (int) $r['err_24h'],
                ];
            }
        }

        $out = [];
        foreach (\RB\Messengers\MessengerCapabilities::platforms() as $p) {
            $conn = $connections[$p] ?? [];
            $isActiveConnected = !empty($conn['is_active_connected']);
            if ($onlyActiveConnected && !$isActiveConnected) {
                continue;
            }
            $meta = \RB\Messengers\MessengerCapabilities::get($p);
            $uStat = $usersByPlat[$p] ?? ['total_users' => 0, 'active_users' => 0, 'today_users' => 0, 'week_users' => 0, 'month_users' => 0];
            $rStat = $refsByPlat[$p] ?? ['direct_refs' => 0, 'qualified_refs' => 0, 'pending_refs' => 0];
            $indirectRefs = $indirectByPlat[$p] ?? 0;
            $inc = $incomeByPlat[$p] ?? ['direct_paid' => 0, 'indirect_paid' => 0, 'welcome_paid' => 0, 'total_paid' => 0, 'total_pending' => 0];
            $cm = $chMembersByPlat[$p] ?? ['total' => 0, 'direct' => 0, 'cross' => 0, 'self' => 0];
            $ms = $msgByPlat[$p] ?? ['out' => 0, 'in' => 0];
            $bc = $bcByPlat[$p] ?? ['sent' => 0, 'failed' => 0];
            $er = $errByPlat[$p] ?? ['total' => 0, 'last_24h' => 0];

            $connSent = (int) ($conn['messages_sent'] ?? 0);
            $effectiveSent = max($connSent, $ms['out']);

            // اگر کاربران قبلی channels_verified داشتند اما هنوز رکوردی در user_channel_memberships نیست
            $channelVerifiedTotal = $cm['total'];
            if ($channelVerifiedTotal === 0 && ($channelsByPlat[$p] ?? 0) > 0 && $hasEntry) {
                $channelVerifiedTotal = (int) $db->value(
                    "SELECT COUNT(*) FROM users WHERE COALESCE(entry_platform, 'telegram') = :p AND channels_verified = 1",
                    ['p' => $p]
                );
            }

            // حساب‌های یکتای متصل به این پیام‌رسان (شامل کاربران ثبت‌نامی مستقیم + کاربرانی که حسابشان را لینک کرده‌اند)
            $linkedCount = $linkedByPlat[$p] ?? 0;
            $independentUsers = max($uStat['total_users'], $linkedCount);

            $out[$p] = [
                'platform' => $p,
                'name' => (string) $meta['name'],
                'icon' => (string) $meta['icon'],
                'badge' => \RB\Messengers\MessengerCapabilities::badge($p),
                'users_label' => \RB\Messengers\MessengerCapabilities::usersLabel($p),
                'color' => (string) $meta['color'],
                'is_enabled' => !empty($conn['is_enabled']),
                'has_token' => !empty($conn['has_token']),
                'is_active_connected' => $isActiveConnected,
                'bot_username' => (string) ($conn['bot_username'] ?? ''),
                'bot_name' => (string) ($conn['bot_name'] ?? ''),
                'webhook_status' => (string) ($conn['webhook_status'] ?? 'not_set'),
                'polling_enabled' => !empty($conn['polling_enabled']),
                'last_update_at' => $conn['last_update_at'] ?? null,
                'last_error' => (string) ($conn['last_error'] ?? ''),
                'status_badge' => $conn['status_badge'] ?? ['label' => 'پیکربندی‌نشده', 'class' => 'badge-muted'],
                'ui_mode' => $conn['ui_mode'] ?? [],
                // آمار کاربران و ثبت‌نام
                'registered_users' => $uStat['total_users'],
                'independent_users' => $independentUsers,
                'active_users' => $uStat['active_users'],
                'today_users' => $uStat['today_users'],
                'week_users' => $uStat['week_users'],
                'month_users' => $uStat['month_users'],
                'linked_accounts' => $linkedCount > 0 ? $linkedCount : $uStat['total_users'],
                // آمار رفرال و دعوت مستقیم/غیرمستقیم
                'direct_referrals' => $rStat['direct_refs'],
                'indirect_referrals' => $indirectRefs,
                'total_referrals' => $rStat['direct_refs'] + $indirectRefs,
                'qualified_referrals' => $rStat['qualified_refs'],
                'pending_referrals' => $rStat['pending_refs'],
                // آمار پاداش‌ها
                'direct_paid' => $inc['direct_paid'],
                'indirect_paid' => $inc['indirect_paid'],
                'welcome_paid' => $inc['welcome_paid'],
                'total_paid' => $inc['total_paid'],
                'total_pending' => $inc['total_pending'],
                'total_income' => $inc['total_paid'] + $inc['total_pending'],
                // آمار عضویت در کانال‌ها
                'active_channels' => $channelsByPlat[$p] ?? 0,
                'channel_members_total' => $channelVerifiedTotal,
                'channel_members_direct' => $cm['direct'],
                'channel_members_cross' => $cm['cross'],
                'channel_members_self' => $cm['self'],
                // آمار پیام‌های ارسالی و خطاهای ارتباطی
                'updates_received' => max((int) ($conn['updates_received'] ?? 0), $ms['in']),
                'messages_sent' => $effectiveSent,
                'messages_failed' => (int) ($conn['messages_failed'] ?? 0) + $bc['failed'],
                'broadcasts_sent' => $bc['sent'],
                'error_count' => $er['total'],
                'errors_24h' => $er['last_24h'],
            ];
        }
        return $out;
    }

    /**
     * خلاصه تفکیکی هوشمند برای پنل مدیریت:
     *   - active : فقط پیام‌رسان‌های فعال و با موفقیت متصل‌شده (بدون نمایش آمار صفر برای پیام‌رسان غیرفعال)
     *   - totals : مجموع کل واقعی سیستم (همیشه مستقل از فعال/غیرفعال بودن هر پیام‌رسان حفظ می‌شود)
     *
     * @return array{active:array<string,array<string,mixed>>,totals:array<string,int>}
     */
    public static function segmentedMessengerSummary(?Database $db = null): array
    {
        $db ??= Database::instance();
        $all = self::messengerPerformanceReport($db, false);
        $active = [];
        foreach ($all as $p => $row) {
            if (!empty($row['is_active_connected'])) {
                $active[$p] = $row;
            }
        }

        $todayTs = today_start();
        $weekTs = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $monthTs = date('Y-m-d 00:00:00', strtotime('-29 days'));

        $directRefs = (int) $db->value('SELECT COUNT(*) FROM users WHERE referred_by IS NOT NULL');
        $indirectRefs = $db->tableExists('referral_relations')
            ? (int) $db->value('SELECT COUNT(*) FROM referral_relations WHERE depth >= 2')
            : 0;
        $chMemTotal = $db->tableExists('user_channel_memberships')
            ? (int) $db->value("SELECT COUNT(*) FROM user_channel_memberships WHERE status = 'verified'")
            : 0;
        if ($chMemTotal === 0) {
            $chMemTotal = (int) $db->value('SELECT COUNT(*) FROM users WHERE channels_verified = 1');
        }

        $totals = [
            'connected_bots' => count($active),
            'total_users' => (int) $db->value('SELECT COUNT(*) FROM users'),
            'active_users' => (int) $db->value("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'today_users' => (int) $db->value('SELECT COUNT(*) FROM users WHERE joined_at >= :t', ['t' => $todayTs]),
            'week_users' => (int) $db->value('SELECT COUNT(*) FROM users WHERE joined_at >= :t', ['t' => $weekTs]),
            'month_users' => (int) $db->value('SELECT COUNT(*) FROM users WHERE joined_at >= :t', ['t' => $monthTs]),
            'direct_referrals' => $directRefs,
            'indirect_referrals' => $indirectRefs,
            'total_referrals' => $directRefs + $indirectRefs,
            'qualified_referrals' => (int) $db->value('SELECT COUNT(*) FROM users WHERE referred_by IS NOT NULL AND qualified_at IS NOT NULL'),
            'pending_referrals' => (int) $db->value('SELECT COUNT(*) FROM users WHERE referred_by IS NOT NULL AND qualified_at IS NULL'),
            'direct_paid' => (int) $db->value("SELECT COALESCE(SUM(amount), 0) FROM referral_commissions WHERE type = 'direct' AND status IN ('approved','credited')"),
            'indirect_paid' => (int) $db->value("SELECT COALESCE(SUM(amount), 0) FROM referral_commissions WHERE type = 'level' AND status IN ('approved','credited')"),
            'welcome_paid' => (int) $db->value("SELECT COALESCE(SUM(amount), 0) FROM referral_commissions WHERE type = 'welcome' AND status IN ('approved','credited')"),
            'total_paid' => (int) $db->value("SELECT COALESCE(SUM(amount), 0) FROM referral_commissions WHERE status IN ('approved','credited')"),
            'total_pending' => (int) $db->value("SELECT COALESCE(SUM(amount), 0) FROM referral_commissions WHERE status = 'pending'"),
            'active_channels' => $db->tableExists('telegram_channels') ? (int) $db->value('SELECT COUNT(*) FROM telegram_channels WHERE is_active = 1') : 0,
            'channel_members_total' => $chMemTotal,
            'messages_sent' => array_sum(array_column($all, 'messages_sent')),
            'messages_failed' => array_sum(array_column($all, 'messages_failed')),
            'updates_received' => array_sum(array_column($all, 'updates_received')),
            'broadcasts_sent' => $db->tableExists('broadcast_campaigns') ? (int) $db->value('SELECT COALESCE(SUM(sent_count), 0) FROM broadcast_campaigns') : 0,
            'error_count' => $db->tableExists('messenger_error_logs') ? (int) $db->value('SELECT COUNT(*) FROM messenger_error_logs') : 0,
            'errors_24h' => array_sum(array_column($active, 'errors_24h')),
        ];

        return [
            'active' => $active,
            'totals' => $totals,
        ];
    }

    public static function recentActivity(int $limit = 10): array
    {
        return Database::instance()->all(
            'SELECT al.* FROM admin_activity_logs al ORDER BY al.id DESC LIMIT ' . (int) $limit
        );
    }

    public static function recentUsers(int $limit = 8): array
    {
        $db = Database::instance();
        $platCol = $db->columnExists('users', 'entry_platform') ? 'entry_platform,' : "'telegram' AS entry_platform,";
        return $db->all(
            "SELECT id, first_name, last_name, username, telegram_id, {$platCol} joined_at, status, referred_by
             FROM users ORDER BY id DESC LIMIT " . (int) $limit
        );
    }
}
