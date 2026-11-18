<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\AuditService;
use RB\Services\MenuService;
use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Support\Crypto;
use RB\Support\Database;
use RB\Support\Request;

final class TelegramController extends AdminController
{
    public function index(): void
    {
        $db = Database::instance();
        $settings = TelegramService::settings($db);
        $tg = new TelegramService(null, $db);
        $webhookInfo = null;

        if ($tg->isConfigured() && !empty($_GET['refresh'])) {
            $info = $tg->getWebhookInfo();
            if ($info['ok'] ?? false) {
                $webhookInfo = $info['result'];
                $db->update('telegram_settings', [
                    'webhook_info' => json_encode($webhookInfo, JSON_UNESCAPED_UNICODE),
                    'webhook_active' => !empty($webhookInfo['url']),
                    'webhook_url' => $webhookInfo['url'] ?? null,
                    'updated_at' => now(),
                ], 'id = :id', ['id' => (int) $settings['id']]);
                TelegramService::forgetSettingsCache();
                $settings = TelegramService::settings($db);
            }
        } elseif (!empty($settings['webhook_info'])) {
            $webhookInfo = json_decode((string) $settings['webhook_info'], true);
        }

        $this->render('admin/telegram/index', [
            'title' => 'اتصال به ربات تلگرام',
            'settings' => $settings,
            // فقط نسخهٔ ماسک‌شده در دسترس View قرار می‌گیرد (توکن کامل هرگز به خروجی نمی‌رود)
            'maskedToken' => $tg->isConfigured() ? $tg->maskedToken() : null,
            'suggestedWebhook' => $tg->suggestedWebhookUrl(),
            'webhookInfo' => $webhookInfo,
            'menus' => $db->all('SELECT * FROM bot_menus ORDER BY sort_order, id'),
            'counts' => [
                'messages_sent' => (int) ($settings['messages_sent'] ?? 0),
                'messages_failed' => (int) ($settings['messages_failed'] ?? 0),
                'updates' => (int) ($settings['updates_count'] ?? 0),
            ],
            'lastMessages' => $db->all('SELECT * FROM telegram_messages ORDER BY id DESC LIMIT 12'),
            // کرون وب برای هاست‌هایی که crontab نمی‌دهند (توکن در اولین بازدید ساخته می‌شود)
            'cronToken' => \RB\Services\CronService::webToken(true),
        ]);
    }

    public function saveToken(): void
    {
        $token = trim((string) ($_POST['bot_token'] ?? ''));
        if ($token === '') {
            $this->fail('توکن ربات را وارد کنید.');
            return;
        }
        if (!preg_match('~^\d{6,12}:[A-Za-z0-9_-]{30,50}$~', $token)) {
            $this->fail('قالب توکن معتبر نیست. توکن باید شبیه 123456789:AAExxxxxxxxxxxxxxxxxxxxxxxxxxx باشد.');
            return;
        }

        try {
            $tg = new TelegramService($token, db());
            $result = $tg->testAndStore($token, current_admin_id());
            if (!$result['ok']) {
                AuditService::log('telegram.token_saved', 'telegram', null, ['result' => 'failed'], current_admin_id());
                $this->fail('توکن نامعتبر است: ' . ($result['error'] ?? 'خطای نامشخص'));
                return;
            }
            $bot = $result['bot'];

            // ذخیره آدرس Webhook و Mini App پیشنهادی
            $base = rtrim((string) ($_POST['base_url'] ?? ''), '/');
            if ($base !== '') {
                if (!preg_match('~^https?://~i', $base)) {
                    $base = 'https://' . $base;
                }
                SettingsService::set('webhook_base_url', $base, 'telegram');
                SettingsService::set('panel_url', $base, 'telegram');
                // تلگرام Mini App را فقط روی https می‌پذیرد
                if (str_starts_with($base, 'https://')) {
                    SettingsService::set('miniapp_url', $base . url('app'), 'telegram');
                }
            }

            $db = db();
            $settingsId = (int) $db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
            if (!$db->value('SELECT webhook_secret FROM telegram_settings WHERE id = :id', ['id' => $settingsId])) {
                $db->update('telegram_settings', [
                    'webhook_secret' => bin2hex(random_bytes(24)),
                ], 'id = :id', ['id' => $settingsId]);
            }
            TelegramService::forgetSettingsCache();
            MenuService::syncDefaults($db);

            AuditService::log('telegram.token_saved', 'telegram', $settingsId, [
                'bot_id' => $bot['id'] ?? null,
                'username' => $bot['username'] ?? null,
            ], current_admin_id());

            $this->ok('ربات با موفقیت متصل شد: @' . ($bot['username'] ?? ''), [
                'bot' => [
                    'id' => $bot['id'] ?? null,
                    'username' => $bot['username'] ?? null,
                    'name' => $bot['first_name'] ?? null,
                ],
                'reload' => true,
            ]);
        } catch (\Throwable $e) {
            $this->fail('خطا: ' . $e->getMessage());
        }
    }

    public function test(): void
    {
        try {
            $tg = new TelegramService();
            if (!$tg->isConfigured()) {
                $this->fail('ابتدا توکن ربات را ذخیره کنید.');
                return;
            }
            $result = $tg->testAndStore(null, current_admin_id());
            if (!$result['ok']) {
                $this->fail('تست اتصال ناموفق: ' . ($result['error'] ?? 'خطای نامشخص'));
                return;
            }
            $bot = $result['bot'];
            $this->ok('اتصال برقرار است: @' . ($bot['username'] ?? '') . ' (' . ($bot['first_name'] ?? '') . ')', [
                'bot' => $bot,
                'reload' => true,
            ]);
        } catch (\Throwable $e) {
            $this->fail('خطا: ' . $e->getMessage());
        }
    }

    public function disconnect(): void
    {
        $db = db();
        $settings = TelegramService::settings($db);
        $webhookNote = '';
        $webhookDeleted = true;
        if (!empty($settings['bot_token_enc'])) {
            try {
                $del = (new TelegramService())->deleteWebhook();
                $webhookDeleted = (bool) ($del['ok'] ?? false);
                if (!$webhookDeleted) {
                    $webhookNote = ' تلگرام حذف Webhook را تأیید نکرد: ' . (string) ($del['description'] ?? 'خطای نامشخص');
                }
            } catch (\Throwable $e) {
                $webhookDeleted = false;
                $webhookNote = ' حذف Webhook ممکن نشد: ' . $e->getMessage();
            }
        }
        $db->update('telegram_settings', [
            'bot_token_enc' => null,
            'bot_id' => null,
            'bot_username' => null,
            'bot_name' => null,
            'connection_status' => 'disconnected',
            'webhook_active' => 0,
            'webhook_url' => null,
            'webhook_info' => null,
            'menu_synced' => 0,
            'updated_at' => now(),
        ], 'id = :id', ['id' => (int) ($settings['id'] ?? 0)]);
        TelegramService::forgetSettingsCache();
        AuditService::log('telegram.token_removed', 'telegram', (int) ($settings['id'] ?? 0), [
            'webhook_deleted' => $webhookDeleted,
        ], current_admin_id());
        if (!$webhookDeleted) {
            // توکن پاک شده اما Webhook در تلگرام ممکن است هنوز فعال باشد.
            $this->ok(
                'توکن ربات از پنل حذف شد اما حذف Webhook تأیید نشد.' . $webhookNote
                    . ' تا زمانی که در BotFather توکن را باطل نکنید، تلگرام به آدرس قبلی آپدیت می‌فرستد.',
                ['reload' => true, 'warnings' => ['حذف Webhook تأیید نشد']]
            );
            return;
        }
        $this->ok('اتصال ربات قطع و توکن حذف شد.', ['reload' => true]);
    }

    public function setWebhook(): void
    {
        $tg = new TelegramService();
        if (!$tg->isConfigured()) {
            $this->fail('ابتدا توکن ربات را ذخیره کنید.');
            return;
        }
        $url = trim((string) ($_POST['webhook_url'] ?? ''));
        if ($url === '') {
            $url = $tg->suggestedWebhookUrl();
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->fail('آدرس Webhook معتبر نیست.');
            return;
        }
        if (!str_starts_with($url, 'https://')) {
            $this->fail('آدرس Webhook باید با HTTPS شروع شود. تلگرام آدرس‌های HTTP را نمی‌پذیرد.');
            return;
        }
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        if ($host === '' || !str_contains($host, '.')) {
            $this->fail('دامنهٔ Webhook معتبر نیست.');
            return;
        }
        // آدرس‌های محلی/خصوصی برای تلگرام قابل دسترسی نیستند
        $isPrivate = (bool) preg_match(
            '~^(localhost|127\.|0\.0\.0\.0|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|\[?::1\]?)~i',
            $host
        );
        if ($isPrivate) {
            $this->fail('آدرس محلی/خصوصی برای تلگرام قابل دسترسی نیست؛ از دامنهٔ عمومی با HTTPS استفاده کنید.');
            return;
        }

        $settings = TelegramService::settings();
        $secret = (string) ($settings['webhook_secret'] ?? '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(24));
        }

        $result = $tg->setWebhook($url, ['secret_token' => $secret]);
        if (!($result['ok'] ?? false)) {
            $description = (string) ($result['description'] ?? 'خطای نامشخص');
            $dbErr = db();
            $dbErr->update('telegram_settings', [
                'last_error' => mb_substr('تنظیم Webhook ناموفق: ' . $description, 0, 900),
                'updated_at' => now(),
            ], 'id = :id', ['id' => (int) ($settings['id'] ?? 0)]);
            TelegramService::forgetSettingsCache();
            AuditService::log('telegram.webhook_failed', 'telegram', (int) ($settings['id'] ?? 0), ['error' => $description], current_admin_id());
            $this->fail('تلگرام درخواست را نپذیرفت: ' . $description, 502);
            return;
        }

        // تأیید واقعی: getWebhookInfo باید همان آدرس را برگرداند
        $verify = $tg->getWebhookInfo();
        $actualUrl = (string) ($verify['result']['url'] ?? '');
        if (!($verify['ok'] ?? false) || $actualUrl !== $url) {
            $dbV = db();
            $dbV->update('telegram_settings', [
                'webhook_info' => ($verify['ok'] ?? false) ? json_encode($verify['result'], JSON_UNESCAPED_UNICODE) : null,
                'last_error' => mb_substr('Webhook تنظیم شد اما تأیید نشد (آدرس فعلی تلگرام: ' . ($actualUrl !== '' ? $actualUrl : 'خالی') . ')', 0, 900),
                'updated_at' => now(),
            ], 'id = :id', ['id' => (int) ($settings['id'] ?? 0)]);
            TelegramService::forgetSettingsCache();
            $this->fail(
                'تلگرام تنظیم Webhook را تأیید نکرد. آدرس فعلی در تلگرام: ' . ($actualUrl !== '' ? $actualUrl : 'خالی')
                    . ' — لطفاً دسترسی عمومی دامنه و گواهی HTTPS را بررسی کنید.',
                502
            );
            return;
        }

        $db = db();
        $db->update('telegram_settings', [
            'webhook_url' => $url,
            'webhook_secret' => $secret,
            'webhook_active' => 1,
            'webhook_set_at' => now(),
            'updated_at' => now(),
        ], 'id = :id', ['id' => (int) ($settings['id'] ?? 0)]);
        TelegramService::forgetSettingsCache();

        // ذخیره دامنه برای ساخت لینک‌ها
        $parts = parse_url($url);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            SettingsService::set('webhook_base_url', $origin, 'telegram');
            SettingsService::set('panel_url', $origin, 'telegram');

            // آدرس پنل کاربری فقط وقتی نوشته می‌شود که HTTPS باشد؛
            // تلگرام web_app را روی http رد می‌کند.
            if (str_starts_with($origin, 'https://')) {
                if ((string) (SettingsService::get('miniapp_url', '') ?: '') === '') {
                    SettingsService::set('miniapp_url', $origin . url('app'), 'telegram');
                }
            } elseif ((string) (SettingsService::get('miniapp_url', '') ?: '') !== '') {
                SettingsService::set('miniapp_url', '', 'telegram');
            }
        }

        AuditService::log('telegram.webhook_set', 'telegram', (int) ($settings['id'] ?? 0), ['url' => $url], current_admin_id());
        $this->ok('Webhook با موفقیت تنظیم شد.', ['url' => $url, 'reload' => true]);
    }

    public function deleteWebhook(): void
    {
        $tg = new TelegramService();
        if (!$tg->isConfigured()) {
            $this->fail('ابتدا توکن ربات را ذخیره کنید.');
            return;
        }

        $db = db();
        $settings = TelegramService::settings($db);
        $settingsId = (int) ($settings['id'] ?? 0);
        $result = $tg->deleteWebhook();

        if (!($result['ok'] ?? false)) {
            // حذف واقعی انجام نشده است؛ وضعیت قبلی نباید دست بخورد.
            $description = (string) ($result['description'] ?? 'خطای نامشخص');
            $db->update('telegram_settings', [
                'last_error' => mb_substr('حذف Webhook ناموفق: ' . $description, 0, 900),
                'updated_at' => now(),
            ], 'id = :id', ['id' => $settingsId]);
            TelegramService::forgetSettingsCache();
            AuditService::log('telegram.webhook_delete_failed', 'telegram', $settingsId, ['error' => $description], current_admin_id());
            $this->fail('حذف Webhook ناموفق بود: ' . $description . ' — وضعیت قبلی حفظ شد.', 502);
            return;
        }

        // فقط پس از تأیید تلگرام، وضعیت محلی را پاک می‌کنیم.
        $db->update('telegram_settings', [
            'webhook_active' => 0,
            'webhook_url' => null,
            'webhook_info' => null,
            'webhook_set_at' => null,
            'last_error' => null,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $settingsId]);
        TelegramService::forgetSettingsCache();
        AuditService::log('telegram.webhook_deleted', 'telegram', $settingsId, [], current_admin_id());
        $this->ok('Webhook در تلگرام حذف شد. اکنون می‌توانید Polling را فعال کنید.', ['reload' => true]);
    }

    public function syncMenu(): void
    {
        $tg = new TelegramService();
        if (!$tg->isConfigured()) {
            $this->fail('ابتدا توکن ربات را ذخیره کنید.');
            return;
        }
        MenuService::syncDefaults();

        $commands = [
            ['command' => 'start', 'description' => 'شروع و منوی اصلی'],
            ['command' => 'menu', 'description' => 'نمایش منو'],
            ['command' => 'help', 'description' => 'راهنمای استفاده'],
        ];
        $commandsResult = $tg->setMyCommands($commands);

        // دکمه منوی Mini App — فقط اگر فعال باشد و آدرسش HTTPS معتبر باشد.
        // تلگرام web_app را روی http نمی‌پذیرد و setChatMenuButton خطا می‌دهد.
        $miniappUrl = (string) SettingsService::get('miniapp_url', '');
        $miniappMode = (string) (SettingsService::get('miniapp_mode', 'auto') ?? 'auto');
        $miniappEnabled = SettingsService::miniappActive();
        $menuButtonResult = ['ok' => true, 'skipped' => true];
        $menuButtonNote = '';
        if ($miniappMode === 'off') {
            $menuButtonNote = 'پنل وب (Mini App) خاموش است؛ دکمه منو تغییر نکرد.';
        } elseif ($miniappUrl === '') {
            $menuButtonNote = 'آدرس پنل کاربری ثبت نشده است.';
        } elseif (!str_starts_with($miniappUrl, 'https://')) {
            $menuButtonNote = 'دکمه Mini App فقط با آدرس https کار می‌کند؛ فعلاً ست نشد.';
        } else {
            $menuButtonResult = $tg->setChatMenuButton([
                'menu_button' => [
                    'type' => 'web_app',
                    'text' => 'پنل کاربری',
                    'web_app' => ['url' => $miniappUrl],
                ],
            ]);
        }

        $commandsOk = (bool) ($commandsResult['ok'] ?? false);
        $menuButtonOk = !empty($menuButtonResult['skipped']) || (bool) ($menuButtonResult['ok'] ?? false);
        $allOk = $commandsOk && $menuButtonOk;

        $db = db();
        // «همگام‌شده» فقط وقتی ثبت می‌شود که همهٔ بخش‌ها واقعاً موفق بوده باشند.
        $db->update('telegram_settings', [
            'menu_synced' => $allOk ? 1 : 0,
            'menu_synced_at' => $allOk ? now() : null,
            'last_error' => $allOk ? null : mb_substr(implode(' | ', array_filter([
                !$commandsOk ? 'setMyCommands: ' . (string) ($commandsResult['description'] ?? 'خطا') : '',
                (!$menuButtonOk && empty($menuButtonResult['skipped'])) ? 'setChatMenuButton: ' . (string) ($menuButtonResult['description'] ?? 'خطا') : '',
            ])), 0, 900),
            'updated_at' => now(),
        ], 'id = :id', ['id' => (int) (TelegramService::settings()['id'] ?? 0)]);
        TelegramService::forgetSettingsCache();

        AuditService::log('telegram.menu_synced', 'telegram', null, [
            'commands_ok' => $commandsResult['ok'] ?? false,
            'menu_button_ok' => $menuButtonResult['ok'] ?? false,
            'menu_button_skipped' => !empty($menuButtonResult['skipped']),
            'miniapp_enabled' => $miniappEnabled,
        ], current_admin_id());

        $warnings = [];
        $notes = [];
        if (!($commandsResult['ok'] ?? false)) {
            $warnings[] = 'ثبت دستورات: ' . ($commandsResult['description'] ?? 'خطا');
        }
        if (!empty($menuButtonResult['skipped'])) {
            $notes[] = $menuButtonNote;
        } elseif (!($menuButtonResult['ok'] ?? false)) {
            $warnings[] = 'دکمه Mini App: ' . ($menuButtonResult['description'] ?? 'خطا (آدرس HTTPS لازم است)');
        }

        if ($warnings !== []) {
            // موفقیت جزئی هرگز نباید «کامل» گزارش شود.
            $partial = 'همگام‌سازی کامل نشد — ' . implode(' | ', $warnings);
            if ($notes !== []) {
                $partial .= ' (' . implode(' ', $notes) . ')';
            }
            $this->fail($partial, 502, [
                'partial' => true,
                'reload' => true,
                'warnings' => $warnings,
                'notes' => $notes,
                'commands_ok' => $commandsOk,
                'menu_button_ok' => $menuButtonOk,
            ]);
            return;
        }

        $message = 'دستورات ربات با موفقیت همگام شد.';
        if ($notes) {
            $message .= ' ' . implode(' ', $notes);
        }

        $this->ok($message, [
            'reload' => true,
            'warnings' => [],
            'notes' => $notes,
            'commands_ok' => $commandsOk,
            'menu_button_ok' => $menuButtonOk,
        ]);
    }

    public function menus(): void
    {
        $db = db();
        $this->render('admin/telegram/menus', [
            'title' => 'مدیریت منوهای ربات',
            'menus' => $db->all('SELECT * FROM bot_menus ORDER BY sort_order, id'),
        ]);
    }

    public function saveMenus(): void
    {
        $db = db();
        $items = $_POST['menus'] ?? [];
        if (!is_array($items)) {
            $this->fail('داده نامعتبر است.');
            return;
        }
        foreach ($items as $id => $data) {
            $db->update('bot_menus', [
                'title' => mb_substr((string) ($data['title'] ?? ''), 0, 78),
                'is_active' => !empty($data['is_active']) ? 1 : 0,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'updated_at' => now(),
            ], 'id = :id', ['id' => (int) $id]);
        }
        AuditService::log('telegram.menu_synced', 'telegram', null, ['action' => 'save'], current_admin_id());
        $this->ok('منوها ذخیره شد. برای اعمال در ربات، دکمه «همگام‌سازی» را بزنید.', ['reload' => true]);
    }
}
