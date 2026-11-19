<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\AuditService;
use RB\Services\CommissionSettings;
use RB\Services\CommissionSettingsService;
use RB\Support\Database;
use RB\Support\Money;
use RB\Support\Request;

final class CommissionController extends AdminController
{
    public function index(): void
    {
        $db = Database::instance();
        $settings = CommissionSettings::current($db, true);
        $versions = $db->all('SELECT * FROM commission_setting_versions ORDER BY version DESC LIMIT 15');

        $this->render('admin/commissions/index', [
            'title' => 'تنظیمات پورسانت و درآمد لایه‌ای',
            'settings' => $settings,
            'versions' => $versions,
            'percentBases' => CommissionSettings::PERCENT_BASES,
            'stats' => [
                'total_commissions' => (int) $db->value("SELECT COALESCE(SUM(amount),0) FROM referral_commissions WHERE status IN ('approved','credited')"),
                'direct_paid' => (int) $db->value("SELECT COALESCE(SUM(amount),0) FROM referral_commissions WHERE status IN ('approved','credited') AND type = 'direct'"),
                'indirect_paid' => (int) $db->value("SELECT COALESCE(SUM(amount),0) FROM referral_commissions WHERE status IN ('approved','credited') AND type = 'level' AND level >= 1"),
                'welcome_paid' => (int) $db->value("SELECT COALESCE(SUM(amount),0) FROM referral_commissions WHERE status IN ('approved','credited') AND type = 'welcome'"),
                'by_level' => $db->all("SELECT level, COUNT(*) c, COALESCE(SUM(amount),0) s FROM referral_commissions WHERE status IN ('approved','credited') AND type = 'level' AND level >= 1 GROUP BY level ORDER BY level"),
                'events' => (int) $db->value('SELECT COUNT(*) FROM referral_events'),
            ],
        ]);
    }

    public function save(): void
    {
        $levels = [];
        $postedLevels = is_array($_POST['levels'] ?? null) ? $_POST['levels'] : [];
        $maxLevels = max(1, min(CommissionSettings::MAX_SUPPORTED_LEVELS, (int) en_digits((string) ($_POST['max_levels'] ?? '1'))));

        for ($level = 1; $level <= CommissionSettings::MAX_SUPPORTED_LEVELS; $level++) {
            if ($level > $maxLevels) {
                continue;
            }
            $row = is_array($postedLevels[$level] ?? null) ? $postedLevels[$level] : [];
            $enabled = !empty($row['enabled']);
            $calcType = strtolower(trim((string) ($row['calc_type'] ?? 'percent')));
            if (!in_array($calcType, ['fixed', 'percent'], true)) {
                $calcType = 'percent';
            }

            $rawPercent = trim(en_digits((string) ($row['percent'] ?? '0')));
            $percent = str_replace(['٪', '%', ' ', '٬', '،', ','], '', str_replace('٫', '.', $rawPercent));
            if ($percent === '') {
                $percent = '0';
            }
            if (!is_numeric($percent) || (float) $percent < 0 || (float) $percent > 100) {
                $errMsg = 'درصد لایه ' . fa_digits($level) . ' باید عددی بین ۰ تا ۱۰۰ باشد.';
                $this->abortSave($errMsg);
                return;
            }

            $amount = Money::parseToman($row['amount'] ?? null) ?? 0;
            $maxAmount = Money::parseToman($row['max_amount'] ?? null) ?? 0;

            $levels[] = [
                'level' => $level,
                'enabled' => $enabled,
                'calc_type' => $calcType,
                'amount' => max(0, $amount),
                'percent' => Money::fromMicro(Money::toMicro($percent)),
                'max_amount' => max(0, $maxAmount),
            ];
        }

        $conditions = array_values(array_filter((array) ($_POST['conditions'] ?? []), static fn($c) => is_string($c)));

        $data = [
            'direct_calc_type' => (string) ($_POST['direct_calc_type'] ?? 'fixed'),
            'direct_reward' => Money::parseToman($_POST['direct_reward'] ?? null) ?? 0,
            'direct_enabled' => !empty($_POST['direct_enabled']),
            'invitee_reward' => Money::parseToman($_POST['invitee_reward'] ?? null) ?? 0,
            'invitee_enabled' => !empty($_POST['invitee_enabled']),
            'multi_level_enabled' => !empty($_POST['multi_level_enabled']),
            'max_levels' => $maxLevels,
            'levels' => $levels,
            'percent_base' => (string) ($_POST['percent_base'] ?? 'direct_reward'),
            'fixed_base_amount' => Money::parseToman($_POST['fixed_base_amount'] ?? null) ?? 0,
            'max_payout_per_event' => Money::parseToman($_POST['max_payout_per_event'] ?? null) ?? 0,
            'daily_payout_cap' => Money::parseToman($_POST['daily_payout_cap'] ?? null) ?? 0,
            'qualify_conditions' => $conditions ?: ['joined'],
            'blocked_users_no_commission' => !empty($_POST['blocked_users_no_commission']),
        ];

        [$valid, $errors] = CommissionSettingsService::validate($data);
        if (!$valid) {
            if ($this->wantsJson()) {
                $this->fail(implode(' ', $errors), 422, ['errors' => $errors]);
                return;
            }
            $this->flash('error', implode(' ', $errors));
            Request::redirect(url('admin/commissions'));
            return;
        }

        try {
            $version = CommissionSettings::saveVersion($data, current_admin_id());
            AuditService::log('settings.commission_updated', 'commission_setting', $version->versionId, [
                'version' => $version->version,
                'direct_reward' => $data['direct_reward'],
                'direct_enabled' => $data['direct_enabled'],
                'invitee_reward' => $data['invitee_reward'],
                'invitee_enabled' => $data['invitee_enabled'],
                'multi_level_enabled' => $data['multi_level_enabled'],
                'max_levels' => $data['max_levels'],
                'percent_base' => $data['percent_base'],
                'levels' => $levels,
            ], current_admin_id());
            $message = 'تنظیمات پورسانت به‌صورت نسخه ' . fa_digits($version->version) . ' ذخیره شد. محاسبات گذشته دست‌نخورده و غیرقابل‌تغییر باقی می‌مانند.';
        } catch (\Throwable $e) {
            $this->abortSave('خطا در ذخیره: ' . $e->getMessage());
            return;
        }

        if ($this->wantsJson()) {
            $this->ok($message, [
                'version' => (int) $version->version,
                'direct_reward' => (int) $data['direct_reward'],
                'direct_enabled' => (bool) $data['direct_enabled'],
                'invitee_reward' => (int) $data['invitee_reward'],
                'invitee_enabled' => (bool) $data['invitee_enabled'],
                'multi_level_enabled' => (bool) $data['multi_level_enabled'],
                'max_levels' => (int) $data['max_levels'],
            ]);
            return;
        }
        $this->flash('success', $message);
        Request::redirect(url('admin/commissions'));
    }

    private function abortSave(string $errMsg): void
    {
        if ($this->wantsJson()) {
            $this->fail($errMsg);
            return;
        }
        $this->flash('error', $errMsg);
        Request::redirect(url('admin/commissions'));
    }
}
