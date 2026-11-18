<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;

/**
 * مدیریت متمرکز منوهای ربات
 * منوهای پیش‌فرض در کد تعریف شده‌اند؛ مدیر می‌تواند فعال/غیرفعال و ترتیب آن‌ها را تغییر دهد.
 */
final class MenuService
{
    /** ساختار پیش‌فرض منوها — منبع واحد حقیقت */
    public const DEFAULTS = [
        ['action' => 'account', 'title' => '👤 حساب کاربری من', 'perm' => null],
        ['action' => 'invite', 'title' => '🔗 لینک دعوت من', 'perm' => null],
        ['action' => 'referrals', 'title' => '👥 زیرمجموعه‌های من', 'perm' => null],
        ['action' => 'income', 'title' => '💰 درآمد من', 'perm' => null],
        ['action' => 'wallet', 'title' => '👛 کیف پول', 'perm' => null],
        ['action' => 'withdraw', 'title' => '🏦 درخواست برداشت', 'perm' => null],
        ['action' => 'history', 'title' => '🧾 تاریخچه تراکنش‌ها', 'perm' => null],
        ['action' => 'campaigns', 'title' => '🎁 جشنواره‌ها و جوایز', 'perm' => null],
        ['action' => 'ranking', 'title' => '🏆 رتبه‌بندی', 'perm' => 'ranking'],
        ['action' => 'notifications', 'title' => '🔔 اعلان‌ها', 'perm' => null],
        ['action' => 'support', 'title' => '☎️ پشتیبانی', 'perm' => null],
        ['action' => 'guide', 'title' => '📖 راهنمای استفاده', 'perm' => null],
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
     * ساختار Reply Keyboard بر اساس حالت انتخابی مدیر:
     *   - miniapp : دکمه بزرگ بازکردن Mini App + دسترسی سریع لینک دعوت و پشتیبانی
     *   - hybrid  : دکمه Mini App در سطر اول + تمام دکمه‌های ۲ ستونه ربات
     *   - buttons : فقط دکمه‌های ۲ ستونه ربات (بدون هیچ دکمه web_app)
     */
    public static function replyKeyboardRows(?array $user = null): array
    {
        $uiMode = SettingsService::botUiMode();
        $miniappUrl = SettingsService::resolvedMiniappUrl();
        $hasHttpsApp = SettingsService::miniappActive() && str_starts_with($miniappUrl, 'https://');

        if ($uiMode === 'miniapp' && $hasHttpsApp) {
            return [
                [['text' => '📱 ورود به پنل کاربری', 'web_app' => ['url' => $miniappUrl]]],
                [self::actionTitle('invite'), self::actionTitle('support')],
            ];
        }

        $rows = [];
        if ($uiMode === 'hybrid' && $hasHttpsApp) {
            $rows[] = [['text' => '📱 پنل کاربری (مینی‌اپ)', 'web_app' => ['url' => $miniappUrl]]];
        }

        $current = [];
        foreach (self::active() as $menu) {
            $current[] = $menu['title'];
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

    /** دکمه‌های شیشه‌ای اصلی (برای پیام‌ها) */
    public static function inlineMainRows(?array $user = null): array
    {
        $rows = [];
        $row = [];
        foreach (self::active() as $menu) {
            $row[] = ['text' => $menu['title'], 'callback_data' => 'menu:' . $menu['action']];
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
        $base = rtrim((string) SettingsService::get('webhook_base_url', ''), '/');
        if ($base === '') {
            $base = '';
        }
        return [
            [
                ['text' => '🛠 پنل مدیریت', 'callback_data' => 'admin:panel'],
                ['text' => '📊 آمار سریع', 'callback_data' => 'admin:stats'],
            ],
        ];
    }

    public static function actionTitle(string $action): string
    {
        foreach (self::DEFAULTS as $row) {
            if ($row['action'] === $action) {
                return $row['title'];
            }
        }
        return $action;
    }

    /** تشخیص دکمه بر اساس متن ارسالی کاربر */
    public static function actionByTitle(string $text): ?string
    {
        $text = trim($text);
        foreach (self::DEFAULTS as $row) {
            if (trim($row['title']) === $text) {
                return $row['action'];
            }
        }
        // پشتیبانی از تنظیمات سفارشی مدیر
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
