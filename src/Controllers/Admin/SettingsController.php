<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\AuditService;
use RB\Services\CommissionSettings;
use RB\Services\RankingService;
use RB\Services\SettingsService;
use RB\Support\Money;
use RB\Support\Request;

final class SettingsController extends AdminController
{
    public function index(): void
    {
        $this->render('admin/settings/index', [
            'title' => 'تنظیمات سیستم',
            'settings' => SettingsService::all(),
            'miniappActive' => SettingsService::miniappActive(),
            'commission' => CommissionSettings::current(db(), true),
            'metrics' => RankingService::METRICS,
        ]);
    }

    public function saveGeneral(): void
    {
        $appName = trim((string) ($_POST['app_name'] ?? ''));
        if ($appName !== '') {
            SettingsService::set('app_name', mb_substr($appName, 0, 60), 'general');
        }

        $this->respond(function () {
            AuditService::log('settings.updated', 'system', null, ['group' => 'general'], current_admin_id());
            return [];
        }, 'تنظیمات عمومی ذخیره شد.', 'admin/settings');
    }

    public function saveWithdrawal(): void
    {
        $min = Money::parseToman($_POST['min_withdraw_amount'] ?? null);
        if ($min === null || $min < 1000) {
            $this->flash('error', 'حداقل مبلغ برداشت باید عددی بزرگ‌تر از ۱٬۰۰۰ تومان باشد.');
            Request::redirect(url('admin/settings') . '#withdrawal');
            return;
        }
        $maxOpen = (int) ($_POST['max_open_withdrawals'] ?? 1);
        $daily = Money::parseToman($_POST['max_daily_withdrawal'] ?? null) ?? 0;

        SettingsService::set('min_withdraw_amount', (string) $min, 'withdrawal');
        SettingsService::set('withdrawal_enabled', !empty($_POST['withdrawal_enabled']) ? '1' : '0', 'withdrawal');
        SettingsService::set('max_open_withdrawals', (string) max(0, $maxOpen), 'withdrawal');
        SettingsService::set('max_daily_withdrawal', (string) $daily, 'withdrawal');

        AuditService::log('settings.withdrawal_updated', 'system', null, [
            'min_withdraw_amount' => $min,
            'enabled' => !empty($_POST['withdrawal_enabled']),
            'max_open' => $maxOpen,
            'daily' => $daily,
        ], current_admin_id());

        $this->respond(fn() => [], 'تنظیمات برداشت ذخیره شد.', 'admin/settings');
    }

    public function saveStartMessage(): void
    {
        $fields = [
            'start_message_new' => 'start_message_new',
            'start_message_returning' => 'start_message_returning',
            'start_rules_text' => 'start_rules_text',
            'guide_text' => 'guide_text',
        ];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                SettingsService::set($key, mb_substr((string) $_POST[$key], 0, 4000), 'messages');
            }
        }
        SettingsService::set('show_rules_in_start', !empty($_POST['show_rules_in_start']) ? '1' : '0', 'messages');
        AuditService::log('settings.updated', 'system', null, ['group' => 'messages'], current_admin_id());
        $this->respond(fn() => [], 'پیام‌های ربات ذخیره شد.', 'admin/settings#messages');
    }

    public function saveGates(): void
    {
        SettingsService::set('force_channel_membership', !empty($_POST['force_channel_membership']) ? '1' : '0', 'telegram');
        SettingsService::set('contact_required', !empty($_POST['contact_required']) ? '1' : '0', 'telegram');

        // پنل کاربری (Mini App): به‌صورت پیش‌فرض خاموش، چون به HTTPS معتبر نیاز دارد.
        // حالت‌ها: auto (خودکار بر اساس https)، on (اجباری)، off
        $mode = (string) ($_POST['miniapp_mode'] ?? 'auto');
        if (!in_array($mode, ['auto', 'on', 'off'], true)) {
            $mode = 'auto';
        }
        SettingsService::set('miniapp_mode', $mode, 'telegram');
        $miniappUrl = trim((string) ($_POST['miniapp_url'] ?? ''));
        if ($miniappUrl !== '') {
            if (!preg_match('~^https?://~i', $miniappUrl)) {
                $miniappUrl = 'https://' . $miniappUrl;
            }
            SettingsService::set('miniapp_url', mb_substr(rtrim($miniappUrl, '/'), 0, 255), 'telegram');
        }
        if (isset($_POST['contact_request_message'])) {
            SettingsService::set('contact_request_message', mb_substr((string) $_POST['contact_request_message'], 0, 600), 'telegram');
        }
        if (isset($_POST['join_channels_message'])) {
            SettingsService::set('join_channels_message', mb_substr((string) $_POST['join_channels_message'], 0, 600), 'telegram');
        }
        AuditService::log('settings.updated', 'system', null, [
            'group' => 'gates',
            'force_channel' => !empty($_POST['force_channel_membership']),
            'contact_required' => !empty($_POST['contact_required']),
        ], current_admin_id());
        $this->respond(fn() => [], 'تنظیمات شرایط ورود ذخیره شد.', 'admin/settings#gates');
    }

    public function saveRanking(): void
    {
        $enabled = !empty($_POST['ranking_enabled']);
        $metric = (string) ($_POST['ranking_metric'] ?? 'direct_ref_count');
        if (!array_key_exists($metric, RankingService::METRICS)) {
            $metric = 'direct_ref_count';
        }
        SettingsService::set('ranking_enabled', $enabled ? '1' : '0', 'ranking');
        SettingsService::set('ranking_metric', $metric, 'ranking');
        $this->respond(fn() => [], 'تنظیمات رتبه‌بندی ذخیره شد.', 'admin/settings#ranking');
    }
}
