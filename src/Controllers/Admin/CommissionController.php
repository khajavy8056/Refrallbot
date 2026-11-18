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
        $settings = CommissionSettings::current(db(), true);
        $versions = $db->all(
            'SELECT * FROM commission_setting_versions ORDER BY version DESC LIMIT 15'
        );

        $this->render('admin/commissions/index', [
            'title' => 'تنظیمات پورسانت',
            'settings' => $settings,
            'versions' => $versions,
            'stats' => [
                'total_commissions' => (int) $db->value("SELECT COALESCE(SUM(amount),0) FROM referral_commissions WHERE status = 'approved'"),
                'by_level' => $db->all("SELECT level, COUNT(*) c, COALESCE(SUM(amount),0) s FROM referral_commissions WHERE status='approved' GROUP BY level ORDER BY level"),
                'events' => (int) $db->value('SELECT COUNT(*) FROM referral_events'),
            ],
        ]);
    }

    public function save(): void
    {
        $levels = [];
        $postedLevels = $_POST['levels'] ?? [];
        $maxLevels = max(1, min(8, (int) en_digits((string) ($_POST['max_levels'] ?? '1'))));
        // فقط لایه‌های داخل محدوده انتخاب‌شده ذخیره می‌شوند
        for ($level = 1; $level <= 8; $level++) {
            $rawPercent = trim(en_digits((string) ($postedLevels[$level]['percent'] ?? '0')));
            $percent = str_replace(['٪', '%', ' ', '٬', '،', ','], '', str_replace('٫', '.', $rawPercent));
            if ($percent === '') {
                $percent = '0';
            }
            $enabled = !empty($postedLevels[$level]['enabled']) && $level <= $maxLevels;
            if (!is_numeric($percent) || (float) $percent < 0 || (float) $percent > 100) {
                $errMsg = 'درصد لایه ' . fa_digits($level) . ' نامعتبر است.';
                if ($this->wantsJson()) {
                    $this->fail($errMsg);
                    return;
                }
                $this->flash('error', $errMsg);
                Request::redirect(url('admin/commissions'));
                return;
            }
            if ($level <= $maxLevels) {
                $levels[] = ['level' => $level, 'percent' => Money::fromMicro(Money::toMicro($percent)), 'enabled' => $enabled];
            }
        }

        $conditions = array_values(array_filter((array) ($_POST['conditions'] ?? []), static fn($c) => is_string($c)));

        $data = [
            'direct_reward' => Money::parseToman($_POST['direct_reward'] ?? null) ?? 0,
            'direct_enabled' => !empty($_POST['direct_enabled']),
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
                'max_levels' => $data['max_levels'],
                'percent_base' => $data['percent_base'],
            ], current_admin_id());
            $message = 'تنظیمات پورسانت به‌صورت نسخه ' . fa_digits($version->version) . ' ذخیره شد. تراکنش‌های قبلی تغییری نمی‌کنند.';
        } catch (\Throwable $e) {
            if ($this->wantsJson()) {
                $this->fail('خطا در ذخیره: ' . $e->getMessage());
                return;
            }
            $this->flash('error', 'خطا در ذخیره: ' . $e->getMessage());
            Request::redirect(url('admin/commissions'));
            return;
        }

        if ($this->wantsJson()) {
            $this->ok($message, [
                'version' => (int) $version->version,
                'direct_reward' => (int) $data['direct_reward'],
                'max_levels' => (int) $data['max_levels'],
            ]);
            return;
        }
        $this->flash('success', $message);
        Request::redirect(url('admin/commissions'));
    }
}
