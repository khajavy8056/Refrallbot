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
               AND type IN ('commission_direct','commission_level','campaign_reward')"
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
        $webhookOk = !empty($settings['webhook_active']);

        return [
            'total_users' => $totalUsers,
            'new_today' => $newToday,
            'new_week' => $newWeek,
            'active_users' => $activeUsers,
            'blocked_users' => $blockedUsers,
            'suspicious_users' => $suspiciousUsers,
            'total_referrals' => $totalReferrals,
            'qualified_referrals' => $qualifiedReferrals,
            'total_commissions' => $totalCommissions,
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
            'bot_connected' => $botStatus === 'connected',
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
             WHERE type IN ('commission_direct','commission_level') AND created_at >= :s GROUP BY DATE(created_at)",
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

    public static function topUsers(int $limit = 10): array
    {
        return Database::instance()->all(
            'SELECT id, first_name, last_name, username, direct_ref_count, total_earned
             FROM users ORDER BY total_earned DESC, direct_ref_count DESC LIMIT ' . (int) $limit
        );
    }

    public static function commissionByLevel(): array
    {
        return Database::instance()->all(
            "SELECT level, COUNT(*) AS c, COALESCE(SUM(amount),0) AS s
             FROM referral_commissions WHERE status = 'approved' GROUP BY level ORDER BY level"
        );
    }

    public static function recentActivity(int $limit = 10): array
    {
        return Database::instance()->all(
            'SELECT al.* FROM admin_activity_logs al ORDER BY al.id DESC LIMIT ' . (int) $limit
        );
    }

    public static function recentUsers(int $limit = 8): array
    {
        return Database::instance()->all(
            'SELECT id, first_name, last_name, username, telegram_id, joined_at, status, referred_by
             FROM users ORDER BY id DESC LIMIT ' . (int) $limit
        );
    }
}
