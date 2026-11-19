<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Support\Database;

/**
 * مدیریت متمرکز منوهای ربات در تمام پیام‌رسان‌ها با تطبیق هوشمند ۳ حالت نمایش:
 *   - miniapp : فقط Mini App (در پیام‌رسان‌های فاقد مینی‌اپ مانند روبیکا به‌صورت خودکار دکمه‌های کامل نمایش داده می‌شود)
 *   - buttons : فقط دکمه‌های معمولی (بدون هیچ دکمه web_app در چت)
 *   - hybrid  : هر دو حالت (Mini App + دکمه‌های کامل در تلگرام و بله؛ دکمه‌های کامل در روبیکا؛ برنامک در ایتا)
 */
final class MenuService
{
    /** ساختار پیش‌فرض منوها — منبع واحد حقیقت */
    public const DEFAULTS = [
        ['action' => 'account',       'title' => '👤 حساب کاربری من',    'perm' => null],
        ['action' => 'invite',        'title' => '🔗 دعوت و بنر تبلیغاتی', 'perm' => null],
        ['action' => 'referrals',     'title' => '👥 زیرمجموعه‌های من',   'perm' => null],
        ['action' => 'income',        'title' => '💰 درآمد لایه‌ای من',   'perm' => null],
        ['action' => 'wallet',        'title' => '👛 کیف پول',           'perm' => null],
        ['action' => 'withdraw',      'title' => '🏦 درخواست برداشت',     'perm' => null],
        ['action' => 'history',       'title' => '🧾 تاریخچه تراکنش‌ها',   'perm' => null],
        ['action' => 'link_account',  'title' => '🔄 اتصال پیام‌رسان‌ها',   'perm' => null],
        ['action' => 'campaigns',     'title' => '🎁 جشنواره‌ها و جوایز',  'perm' => null],
        ['action' => 'ranking',       'title' => '🏆 رتبه‌بندی',          'perm' => 'ranking'],
        ['action' => 'notifications', 'title' => '🔔 اعلان‌ها',           'perm' => null],
        ['action' => 'support',       'title' => '☎️ پشتیبانی',          'perm' => null],
        ['action' => 'guide',         'title' => '📖 راهنمای استفاده',     'perm' => null],
    ];

    /** نگاشت عنوان‌های نسخه قبلی برای سازگاری ۱۰۰٪ با منوهای موجود دیتابیس */
    private const LEGACY_TITLES = [
        '🔗 لینک دعوت من' => 'invite',
        '💰 درآمد من'     => 'income',
    ];

    public static function syncDefaults(?Database $db = null): void
    {
        $db ??= Database::instance();
        $existing = $db->all('SELECT * FROM bot_menus');
        $byAction = [];
        foreach ($existing as $row) {
            $byAction[$row['action']] = $row;
        }
        $order = 0;
        foreach (self::DEFAULTS as $default) {
            $order += 10;
            if (isset($byAction[$default['action']])) {
                continue;
            }
            $db->insert('bot_menus', [
                'title' => $default['title'],
                'action' => $default['action'],
                'type' => 'reply',
                'is_active' => 1,
                'sort_order' => $order,
                'meta' => json_encode(['perm' => $default['perm']], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return array<int,array> */
    public static function active(?Database $db = null): array
    {
        $db ??= Database::instance();
        if (!$db->tableExists('bot_menus')) {
            return [];
        }
        self::syncDefaults($db);
        $rows = $db->all('SELECT * FROM bot_menus WHERE is_active = 1 ORDER BY sort_order, id');
        $rankingEnabled = SettingsService::bool('ranking_enabled', true);
        return array_values(array_filter($rows, static function ($row) use ($rankingEnabled) {
            $meta = json_decode((string) ($row['meta'] ?? '{}'), true) ?: [];
            if (($meta['perm'] ?? null) === 'ranking' && !$rankingEnabled) {
                return false;
            }
            return true;
        }));
    }

    /**
     * ساختار کیبورد معمولی با رعایت دقیق قابلیت واقعی هر پیام‌رسان:
     *   - اگر پیام‌رسان Mini App ندارد (مثلاً روبیکا): حتی در حالت miniapp دکمه‌های کامل نمایش داده می‌شود (هیچ دکمه خرابی ساخته نمی‌شود).
     *   - در حالت miniapp روی تلگرام/بله با HTTPS: دکمه ورود به Mini App (بدون شلوغی دکمه‌های اضافی) + دعوت سریع و پشتیبانی.
     *   - در حالت hybrid روی تلگرام/بله با HTTPS: هم دکمه Mini App و هم تمام دکمه‌های معمولی.
     *   - در حالت buttons: فقط دکمه‌های معمولی در تمام پیام‌رسان‌های دکمه‌دار.
     */
    public static function replyKeyboardRows(?array $user = null, string $platform = MessengerCapabilities::TELEGRAM): array
    {
        if (!MessengerCapabilities::supports($platform, 'reply_keyboard')) {
            return [];
        }

        $resolved = SettingsService::resolveUiModeFor($platform);
        $baseUrl = SettingsService::resolvedMiniappUrl();
        $appUrl = '';
        if ($resolved['show_miniapp'] && $baseUrl !== '') {
            $appUrl = (new MessengerRegistry())->driver($platform)->buildMiniAppUrl($baseUrl);
        }

        if ($resolved['effective'] === 'miniapp' && $appUrl !== '') {
            return [
                [['text' => '📱 ورود به پنل کاربری (Mini App)', 'web_app' => ['url' => $appUrl]]],
                [self::actionTitle('invite'), self::actionTitle('support')],
            ];
        }

        $rows = [];
        if ($resolved['effective'] === 'hybrid' && $appUrl !== '') {
            $rows[] = [['text' => '📱 پنل کاربری (مینی‌اپ)', 'web_app' => ['url' => $appUrl]]];
        }

        $current = [];
        foreach (self::active() as $menu) {
            $current[] = (string) $menu['title'];
            if (count($current) === 2) {
                $rows[] = $current;
                $current = [];
            }
        }
        if ($current) {
            $rows[] = $current;
        }
        return $rows;
    }

    /** دکمه‌های شیشه‌ای اصلی متناسب با حالت نمایش و قابلیت پیام‌رسان */
    public static function inlineMainRows(?array $user = null, string $platform = MessengerCapabilities::TELEGRAM): array
    {
        if (!MessengerCapabilities::supports($platform, 'inline_keyboard')) {
            return [];
        }

        $resolved = SettingsService::resolveUiModeFor($platform);
        $baseUrl = SettingsService::resolvedMiniappUrl();
        $appUrl = ($resolved['show_miniapp'] && $baseUrl !== '')
            ? (new MessengerRegistry())->driver($platform)->buildMiniAppUrl($baseUrl)
            : '';

        if ($resolved['effective'] === 'miniapp' && $appUrl !== '') {
            return [
                [['text' => '🚀 باز کردن پنل کاربری (Mini App)', 'web_app' => ['url' => $appUrl]]],
                [['text' => '🎁 استودیو دعوت و بنر', 'callback_data' => 'menu:invite']],
            ];
        }

        $rows = [];
        if ($resolved['effective'] === 'hybrid' && $appUrl !== '') {
            $rows[] = [['text' => '🚀 باز کردن مینی‌اپ', 'web_app' => ['url' => $appUrl]]];
        }

        $row = [];
        foreach (self::active() as $menu) {
            $row[] = ['text' => (string) $menu['title'], 'callback_data' => 'menu:' . $menu['action']];
            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** دکمه‌های مدیریتی (فقط برای ادمین‌های ربات) */
    public static function adminRows(): array
    {
        return [
            [
                ['text' => '🛠 پنل مدیریت', 'callback_data' => 'admin:panel'],
                ['text' => '📊 آمار سریع', 'callback_data' => 'admin:stats'],
            ],
        ];
    }

    public static function actionTitle(string $action): string
    {
        $db = Database::instance();
        if ($db->tableExists('bot_menus')) {
            $saved = $db->value('SELECT title FROM bot_menus WHERE action = :a LIMIT 1', ['a' => $action]);
            if (is_string($saved) && $saved !== '') {
                return $saved;
            }
        }
        foreach (self::DEFAULTS as $row) {
            if ($row['action'] === $action) {
                return $row['title'];
            }
        }
        return $action;
    }

    /** تشخیص عملیات دکمه بر اساس متن ارسالی کاربر */
    public static function actionByTitle(string $text): ?string
    {
        $text = trim($text);
        if (isset(self::LEGACY_TITLES[$text])) {
            return self::LEGACY_TITLES[$text];
        }
        foreach (self::DEFAULTS as $row) {
            if (trim($row['title']) === $text) {
                return $row['action'];
            }
        }
        $db = Database::instance();
        if ($db->tableExists('bot_menus')) {
            $action = $db->value('SELECT action FROM bot_menus WHERE title = :t LIMIT 1', ['t' => $text]);
            if ($action) {
                return (string) $action;
            }
        }
        return null;
    }
}
