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
            'platformUiModes' => SettingsService::activePlatformUiModes(),
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
            $errMsg = 'حداقل مبلغ برداشت باید عددی بزرگ‌تر از ۱٬۰۰۰ تومان باشد.';
            if ($this->wantsJson()) {
                $this->fail($errMsg);
                return;
            }
            $this->flash('error', $errMsg);
            Request::redirect(url('admin/settings') . '#withdrawal');
            return;
        }
        $maxOpen = (int) en_digits((string) ($_POST['max_open_withdrawals'] ?? '1'));
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
            'invite_message_text',
            'invite_share_text',
            'start_message_referred',
            'referral_joined_message',
            'start_message_new',
            'start_message_returning',
            'start_rules_text',
            'guide_text',
        ];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                SettingsService::set($key, mb_substr((string) $_POST[$key], 0, 4000), 'messages');
            }
        }
        SettingsService::set('show_rules_in_start', !empty($_POST['show_rules_in_start']) ? '1' : '0', 'messages');
        if (isset($_POST['referred_welcome_append_checklist_present'])) {
            SettingsService::set('referred_welcome_append_checklist', !empty($_POST['referred_welcome_append_checklist']) ? '1' : '0', 'messages');
        }
        if (isset($_POST['notify_referral_joined_present'])) {
            SettingsService::set('notify_referral_joined', !empty($_POST['notify_referral_joined']) ? '1' : '0', 'messages');
        }
        AuditService::log('settings.updated', 'system', null, ['group' => 'messages'], current_admin_id());
        $this->respond(fn() => [], 'پیام‌های ربات و دعوت دوستان با موفقیت ذخیره شد.', 'admin/settings#messages');
    }

    public function saveGates(): void
    {
        SettingsService::set('force_channel_membership', !empty($_POST['force_channel_membership']) ? '1' : '0', 'telegram');
        SettingsService::set('contact_required', !empty($_POST['contact_required']) ? '1' : '0', 'telegram');

        // حالت نمایش ربات (buttons / miniapp / hybrid) یا miniapp_mode قدیمی (off / on / auto)
        $miniappUrlInput = isset($_POST['miniapp_url']) ? trim((string) $_POST['miniapp_url']) : null;
        if (isset($_POST['bot_ui_mode']) && array_key_exists((string) $_POST['bot_ui_mode'], SettingsService::BOT_UI_MODES)) {
            SettingsService::applyBotUiMode((string) $_POST['bot_ui_mode'], $miniappUrlInput);
        } else {
            $mode = (string) ($_POST['miniapp_mode'] ?? 'auto');
            if (!in_array($mode, ['auto', 'on', 'off'], true)) {
                $mode = 'auto';
            }
            $mappedUi = $mode === 'off' ? 'buttons' : 'hybrid';
            SettingsService::set('miniapp_mode', $mode, 'telegram');
            SettingsService::set('bot_ui_mode', $mappedUi, 'telegram');
            if ($miniappUrlInput !== null && $miniappUrlInput !== '') {
                if (!preg_match('~^https?://~i', $miniappUrlInput)) {
                    $miniappUrlInput = 'https://' . $miniappUrlInput;
                }
                SettingsService::set('miniapp_url', mb_substr(rtrim($miniappUrlInput, '/'), 0, 255), 'telegram');
            }
            SettingsService::flush();
            SettingsService::syncTelegramMenuButton();
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
