<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\CommissionSettings;
use RB\Services\StatsService;
use RB\Services\TelegramService;
use RB\Services\WithdrawalService;
use RB\Support\Database;

final class DashboardController extends AdminController
{
    public function index(): void
    {
        $overview = StatsService::overview();
        $days = (int) ($_GET['days'] ?? 30);
        if (!in_array($days, [7, 14, 30, 90], true)) {
            $days = 30;
        }

        $growth = StatsService::userGrowth($days);
        $series = StatsService::referralSeries($days);

        $labels = array_map(
            static fn($row) => fa_digits(substr($row['date'], 8, 2)) . '/' . fa_digits(substr($row['date'], 5, 2)),
            $growth
        );

        $this->render('admin/dashboard', [
            'title' => 'داشبورد',
            'overview' => $overview,
            'days' => $days,
            'labels' => $labels,
            'growth' => array_column($growth, 'value'),
            'referrals' => array_column($series, 'referrals'),
            'payouts' => array_column($series, 'payouts'),
            'withdrawalSeries' => array_column($series, 'withdrawals'),
            'topUsers' => StatsService::topUsers(6),
            'commissionLevels' => StatsService::commissionByLevel(),
            'recentUsers' => StatsService::recentUsers(6),
            'activity' => StatsService::recentActivity(8),
            'settings' => CommissionSettings::current(),
            'telegram' => TelegramService::settings(),
        ]);
    }

    public function chart(): void
    {
        $days = (int) ($_GET['days'] ?? 30);
        if (!in_array($days, [7, 14, 30, 90], true)) {
            $days = 30;
        }
        $growth = StatsService::userGrowth($days);
        $series = StatsService::referralSeries($days);
        $this->json([
            'ok' => true,
            'labels' => array_map(static fn($r) => fa_digits(substr($r['date'], 8, 2)), $growth),
            'users' => array_column($growth, 'value'),
            'referrals' => array_column($series, 'referrals'),
            'payouts' => array_column($series, 'payouts'),
        ]);
    }
}
