<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;

/**
 * تنظیمات سیستم (کلید/مقدار) با کش در حافظه
 */
final class SettingsService
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            // `key` در MySQL کلمهٔ رزروشده است و بدون backtick خطای سینتکس
            // می‌دهد؛ چون خطا در catch بلعیده می‌شد، همهٔ تنظیمات بی‌صدا خالی
            // می‌شدند (نام سامانه، کمیسیون‌ها، توکن کرون، حداقل برداشت...).
            $rows = Database::instance()->all('SELECT `key`, `value` FROM system_settings');
        } catch (\Throwable) {
            return self::$cache = [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[$row['key']] = $row['value'];
        }
        return self::$cache = $out;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    public static function set(string $key, mixed $value, string $group = 'general'): void
    {
        $db = Database::instance();
        $stringValue = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
        $exists = $db->value('SELECT id FROM system_settings WHERE `key` = :k', ['k' => $key]);
        if ($exists) {
            $db->update('system_settings', [
                'value' => $stringValue,
                'group_name' => $group,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => (int) $exists]);
        } else {
            $db->insert('system_settings', [
                'key' => $key,
                'value' => $stringValue,
                'group_name' => $group,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        self::$cache = null;
    }

    /** پاک‌کردن کش درون‌حافظه (پس از تغییر مستقیم دیتابیس) */
    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function setMany(array $values, string $group = 'general'): void
    {
        foreach ($values as $key => $value) {
            self::set($key, $value, $group);
        }
    }

    /** گروه هر کلید — برای نمایش در صفحه تنظیمات */
    public static function groupOf(string $key): string
    {
        try {
            $group = Database::instance()->value(
                'SELECT group_name FROM system_settings WHERE `key` = :k',
                ['k' => $key]
            );
        } catch (\Throwable) {
            return 'general';
        }
        return (string) ($group ?: 'general');
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    /** حالت‌های قابل انتخاب رابط کاربری ربات در تلگرام */
    public const BOT_UI_MODES = [
        'miniapp' => 'حالت مینی‌اپ (وب‌اپ تلگرام)',
        'buttons' => 'حالت دکمه‌ای (منوی ربات)',
        'hybrid'  => 'حالت ترکیبی (مینی‌اپ + دکمه‌ها)',
    ];

    /**
     * آیا پنل وب (Mini App) باید فعال باشد؟
     *
     * تلگرام برای Mini App فقط https معتبر می‌پذیرد. بنابراین:
     *   miniapp_mode=off یا bot_ui_mode=buttons → همیشه خاموش
     *   miniapp_mode=on  → فقط اگر آدرس https باشد
     *   miniapp_mode=auto → (پیش‌فرض) فقط اگر آدرس https باشد
     *
     * روی هاست بدون SSL همه‌چیز از دکمه‌های خود ربات کار می‌کند.
     */
    public static function miniappActive(): bool
    {
        $mode = (string) (self::get('miniapp_mode', 'auto') ?? 'auto');
        if ($mode === 'off') {
            return false;
        }
        $uiMode = (string) (self::get('bot_ui_mode', '') ?? '');
        if ($uiMode === 'buttons') {
            return false;
        }
        $url = self::resolvedMiniappUrl();
        return $url !== '' && str_starts_with($url, 'https://');
    }

    /** آدرس تمیزشدهٔ Mini App (خالی اگر تنظیم نشده باشد) */
    public static function resolvedMiniappUrl(): string
    {
        $url = trim((string) (self::get('miniapp_url', '') ?: ''));
        return $url;
    }

    /** حالت ذخیره‌شده توسط مدیر: buttons | miniapp | hybrid */
    public static function configuredBotUiMode(): string
    {
        $miniMode = (string) (self::get('miniapp_mode', 'auto') ?? 'auto');
        if ($miniMode === 'off') {
            return 'buttons';
        }
        $uiMode = (string) (self::get('bot_ui_mode', '') ?? '');
        if (in_array($uiMode, ['buttons', 'miniapp', 'hybrid'], true)) {
            return $uiMode;
        }
        return 'hybrid';
    }

    /**
     * حالت مؤثر رابط ربات هنگام ارسال پیام به کاربر:
     *   - buttons : تمام امکانات فقط از طریق دکمه‌های خود ربات (بدون Mini App)
     *   - miniapp : تمرکز روی مینی‌اپ تلگرام (فقط وقتی HTTPS معتبر فعال است؛ در غیر این صورت بازگشت ایمن به دکمه‌ها)
     *   - hybrid  : هم دکمه‌های کامل ربات و هم دسترسی مستقیم به مینی‌اپ
     */
    public static function botUiMode(): string
    {
        $configured = self::configuredBotUiMode();
        if ($configured === 'buttons') {
            return 'buttons';
        }
        if ($configured === 'miniapp') {
            return self::miniappActive() ? 'miniapp' : 'buttons';
        }
        return self::miniappActive() ? 'hybrid' : 'buttons';
    }

    /** برچسب فارسی حالت فعلی رابط ربات */
    public static function botUiModeLabel(?string $mode = null): string
    {
        $m = $mode ?? self::botUiMode();
        return self::BOT_UI_MODES[$m] ?? self::BOT_UI_MODES['hybrid'];
    }

    /**
     * ذخیرهٔ حالت رابط ربات (دکمه‌ای / مینی‌اپ / ترکیبی) و همگام‌سازی فوری دکمه منوی تلگرام
     *
     * @return array{mode:string,effective:string,miniapp_active:bool,miniapp_url:string,menu_synced:bool,note:string}
     */
    public static function applyBotUiMode(string $requestedMode, ?string $miniappUrlInput = null, ?TelegramService $tg = null): array
    {
        $mode = in_array($requestedMode, ['buttons', 'miniapp', 'hybrid'], true) ? $requestedMode : 'hybrid';

        if ($miniappUrlInput !== null) {
            $clean = (string) (preg_replace('~[\s\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+~u', '', $miniappUrlInput) ?? trim($miniappUrlInput));
            if ($clean !== '') {
                if (!preg_match('~^https?://~i', $clean)) {
                    $clean = 'https://' . $clean;
                }
                self::set('miniapp_url', mb_substr(rtrim($clean, '/'), 0, 255), 'telegram');
            }
        }

        // اگر مدیر حالت مینی‌اپ یا ترکیبی را انتخاب کرده و آدرس خالی است، از دامنه HTTPS ذخیره شده بسازیم
        $currentUrl = self::resolvedMiniappUrl();
        if ($mode !== 'buttons' && ($currentUrl === '' || !str_starts_with($currentUrl, 'https://'))) {
            $base = rtrim((string) (self::get('webhook_base_url', '') ?: \RB\Support\Request::origin()), '/');
            $appPath = rtrim((string) \RB\Support\Config::get('app.base_path', ''), '/');
            if ($appPath !== '' && str_ends_with($base, $appPath)) {
                $base = substr($base, 0, -strlen($appPath));
            }
            if (str_starts_with(strtolower($base), 'https://')) {
                $autoUrl = rtrim($base, '/') . url('app');
                self::set('miniapp_url', mb_substr($autoUrl, 0, 255), 'telegram');
            }
        }

        $miniMode = match ($mode) {
            'buttons' => 'off',
            'miniapp' => 'on',
            default   => 'auto',
        };
        self::set('bot_ui_mode', $mode, 'telegram');
        self::set('miniapp_mode', $miniMode, 'telegram');
        self::flush();

        $sync = self::syncTelegramMenuButton($tg);

        return [
            'mode' => $mode,
            'effective' => self::botUiMode(),
            'miniapp_active' => self::miniappActive(),
            'miniapp_url' => self::resolvedMiniappUrl(),
            'menu_synced' => (bool) ($sync['synced'] ?? false),
            'note' => (string) ($sync['note'] ?? ''),
        ];
    }

    /**
     * همگام‌سازی خودکار دکمه منوی پایین چت تلگرام با حالت فعلی ربات:
     *   - مینی‌اپ / ترکیبی (با HTTPS) → web_app
     *   - دکمه‌ای (خاموش) → commands (حذف دکمه Mini App و بازگشت به فهرست دستورات)
     *
     * @return array{ok:bool,synced:bool,skipped:bool,note:string,description?:string}
     */
    public static function syncTelegramMenuButton(?TelegramService $tg = null): array
    {
        try {
            $tg ??= new TelegramService();
            if (!$tg->isConfigured()) {
                return ['ok' => true, 'synced' => false, 'skipped' => true, 'note' => 'توکن ربات هنوز ثبت نشده است.'];
            }

            $configured = self::configuredBotUiMode();
            $miniappUrl = self::resolvedMiniappUrl();

            if ($configured === 'buttons') {
                $res = $tg->setChatMenuButton([
                    'menu_button' => ['type' => 'commands'],
                ]);
                $ok = (bool) ($res['ok'] ?? false);
                return [
                    'ok' => $ok,
                    'synced' => $ok,
                    'skipped' => false,
                    'note' => $ok ? 'دکمه منوی ربات به حالت «دکمه‌ای / دستورات» تغییر یافت.' : '',
                    'description' => (string) ($res['description'] ?? ''),
                ];
            }

            if ($miniappUrl === '') {
                return ['ok' => true, 'synced' => false, 'skipped' => true, 'note' => 'آدرس پنل کاربری (Mini App) ثبت نشده است.'];
            }
            if (!str_starts_with($miniappUrl, 'https://')) {
                return ['ok' => true, 'synced' => false, 'skipped' => true, 'note' => 'دکمه Mini App فقط با آدرس HTTPS کار می‌کند؛ ربات موقتاً در حالت دکمه‌ای فعال ماند.'];
            }

            $res = $tg->setChatMenuButton([
                'menu_button' => [
                    'type' => 'web_app',
                    'text' => 'پنل کاربری',
                    'web_app' => ['url' => $miniappUrl],
                ],
            ]);
            $ok = (bool) ($res['ok'] ?? false);
            return [
                'ok' => $ok,
                'synced' => $ok,
                'skipped' => false,
                'note' => $ok ? 'دکمه مینی‌اپ در تلگرام فعال و همگام‌سازی شد.' : '',
                'description' => (string) ($res['description'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'synced' => false, 'skipped' => true, 'note' => '', 'description' => $e->getMessage()];
        }
    }

}
