<?php
declare(strict_types=1);
/**
 * تست سناریو ۲۳: ارسال فرم‌های پنل مدیریت و Mini App با هر سه قالب
 * application/json، application/x-www-form-urlencoded و multipart/form-data
 * (رفع باگ «توکن ربات را وارد کنید» هنگام ذخیرهٔ توکن و سایر فرم‌های data-ajax).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Http\Router;
use RB\Services\AdminAuth;
use RB\Services\CommissionSettings;
use RB\Services\MenuService;
use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Services\UserService;
use RB\Support\Csrf;
use RB\Support\Database;
use RB\Support\Request;

T::suite('سناریو ۲۳: ذخیرهٔ توکن ربات و تمام فرم‌های AJAX پنل مدیریت و Mini App');

$db = Database::instance();
$roleId = (int) ($db->value("SELECT id FROM admin_roles WHERE slug = 'superadmin'") ?: $db->insert('admin_roles', [
    'name' => 'مدیر کل',
    'slug' => 'superadmin',
    'permissions' => json_encode(['*']),
    'description' => 'دسترسی کامل',
    'created_at' => now(),
    'updated_at' => now(),
]));
$adminId = $db->insert('admins', [
    'name' => 'مدیر تست',
    'username' => 'superadmin_form',
    'password_hash' => password_hash('Str0ngPass99', PASSWORD_DEFAULT),
    'role_id' => $roleId,
    'is_active' => 1,
    'must_change_password' => 0,
    'failed_attempts' => 0,
    'totp_recovery_codes' => '',
    'created_at' => now(),
    'updated_at' => now(),
]);
file_put_contents(RB_STORAGE . '/installed.lock', '{}');
MenuService::syncDefaults($db);

// شبیه‌ساز ترنسپورت تلگرام برای getMe و setWebhook
TelegramService::$fakeTransport = static function (string $method, array $payload): array {
    if ($method === 'getMe') {
        return [
            'ok' => true,
            'result' => ['id' => 9876543210, 'is_bot' => true, 'first_name' => 'ربات تست', 'username' => 'refrall_ok_bot'],
        ];
    }
    if ($method === 'getWebhookInfo') {
        return [
            'ok' => true,
            'result' => ['url' => 'https://bot.example.com/webhook/telegram', 'pending_update_count' => 0],
        ];
    }
    return ['ok' => true, 'result' => true];
};

// ابزار ارسال درخواست POST با بدنهٔ JSON و $_POST کاملاً خالی (بازتولید دقیق رفتار مرورگر)
$dispatchJsonPost = static function (string $uri, array $body) use ($adminId): array {
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['admin_last_seen'] = time();
    $csrf = Csrf::token();

    $_GET = [];
    $_POST = []; // عمداً خالی — دقیقاً مثل زمانی که PHP درخواست application/json دریافت می‌کند
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['CONTENT_TYPE'] = 'application/json';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;
    Request::setRawBody((string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $router = new Router();
    $register = require RB_ROOT . '/src/routes.php';
    $register($router);

    ob_start();
    $router->dispatch();
    $raw = (string) ob_get_clean();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['ok' => false, '_raw' => $raw];
};

// ۱) ذخیرهٔ توکن ربات با JSON و $_POST خالی (همراه با کاراکتر نامرئی LRM/RLM کپی‌شده از BotFather و شناسه ۱۰ رقمی)
$fakeToken = '7123456789:' . str_repeat('A', 35);
$resToken = $dispatchJsonPost('/admin/telegram/save', [
    'bot_token' => "\u{200E} " . $fakeToken . " \u{200F}",
    'base_url' => 'https://bot.example.com',
]);
T::eq(true, $resToken['ok'] ?? false, 'ذخیرهٔ توکن ربات با درخواست JSON (و $_POST خالی) موفق شد');
T::eq('refrall_ok_bot', $resToken['bot']['username'] ?? '', 'نام کاربری ربات برگشت داده شد');
T::eq('https://bot.example.com', (string) SettingsService::get('webhook_base_url', ''), 'آدرس پایهٔ وبهوک ذخیره شد');
T::eq(true, (new TelegramService())->isConfigured(), 'توکن رمزنگاری‌شده در دیتابیس ذخیره شد');

// ۲) تنظیم Webhook با JSON و $_POST خالی
$resHook = $dispatchJsonPost('/admin/telegram/webhook/set', [
    'webhook_url' => 'https://bot.example.com/webhook/telegram',
]);
T::eq(true, $resHook['ok'] ?? false, 'تنظیم Webhook با درخواست JSON موفق شد');

// ۳) ذخیرهٔ تنظیمات عمومی با JSON و $_POST خالی (کنترلری که مستقیماً $_POST می‌خواند)
$resGen = $dispatchJsonPost('/admin/settings/general', [
    'app_name' => 'باشگاه مشتریان آزمایشی',
]);
T::eq(true, $resGen['ok'] ?? false, 'ذخیرهٔ تنظیمات عمومی با JSON موفق شد');
T::eq('باشگاه مشتریان آزمایشی', (string) SettingsService::get('app_name', ''), 'نام سامانه در دیتابیس به‌روز شد');

// ۴) ذخیرهٔ تنظیمات پورسانت با کلیدهای براکت‌دار HTML (levels[1][percent] و conditions[])
$resCom = $dispatchJsonPost('/admin/commissions', [
    'direct_reward' => '15,000',
    'direct_enabled' => '1',
    'multi_level_enabled' => '1',
    'max_levels' => '2',
    'levels[1][percent]' => '25',
    'levels[1][enabled]' => '1',
    'levels[2][percent]' => '10',
    'levels[2][enabled]' => '1',
    'percent_base' => 'direct_reward',
    'conditions' => ['joined', 'contact'],
    'blocked_users_no_commission' => '1',
]);
T::eq(true, $resCom['ok'] ?? false, 'ذخیرهٔ تنظیمات پورسانت با کلیدهای براکت‌دار موفق شد');
$curCom = CommissionSettings::current($db, true);
T::eq(15000, $curCom->directReward, 'مبلغ پاداش مستقیم ۱۵٬۰۰۰ تومان ثبت شد');
T::eq(['joined', 'contact'], $curCom->qualifyConditions, 'شرایط واجد شرایط شدن ثبت شد');

// ۵) ویرایش منوهای ربات با کلیدهای براکت‌دار menus[id][title]
$firstMenu = $db->first('SELECT * FROM bot_menus ORDER BY id LIMIT 1');
$menuId = (int) ($firstMenu['id'] ?? 0);
$resMenus = $dispatchJsonPost('/admin/telegram/menus/save', [
    "menus[{$menuId}][title]" => '👤 پروفایل من',
    "menus[{$menuId}][is_active]" => '1',
    "menus[{$menuId}][sort_order]" => '10',
]);
T::eq(true, $resMenus['ok'] ?? false, 'ذخیرهٔ منوهای ربات با کلیدهای براکت‌دار موفق شد');
T::eq('👤 پروفایل من', (string) $db->value('SELECT title FROM bot_menus WHERE id = :id', ['id' => $menuId]), 'عنوان منو به‌روز شد');

// ۶) ذخیرهٔ پروفایل در Mini App (که miniapp.js همیشه JSON می‌فرستد)
$regUser = (new UserService($db))->registerFromTelegram(['id' => 444555666, 'first_name' => 'سارا', 'username' => 'sara_t']);
$userId = (int) ($regUser['user']['id'] ?? 0);
$_SESSION['miniapp_user_id'] = $userId;
$_SESSION['miniapp_auth_at'] = time();
$resProfile = $dispatchJsonPost('/app/api/profile', [
    'first_name' => 'سارا جدید',
    'last_name' => 'محمدی',
]);
T::eq(true, $resProfile['ok'] ?? false, 'ویرایش پروفایل Mini App با JSON موفق شد');
T::eq('سارا جدید', (string) $db->value('SELECT first_name FROM users WHERE id = :id', ['id' => $userId]), 'نام کاربر Mini App ذخیره شد');

// ۷) ذخیرهٔ توکن با فرم استاندارد x-www-form-urlencoded ($_POST پر و بدنهٔ JSON خالی)
$_GET = [];
$_POST = ['bot_token' => $fakeToken, 'base_url' => 'https://panel.example.com'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/admin/telegram/save';
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded; charset=UTF-8';
$_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();
Request::setRawBody('');
$router2 = new Router();
$reg2 = require RB_ROOT . '/src/routes.php';
$reg2($router2);
ob_start();
$router2->dispatch();
$resForm = json_decode((string) ob_get_clean(), true);
T::eq(true, $resForm['ok'] ?? false, 'ذخیرهٔ توکن ربات با x-www-form-urlencoded هم موفق شد');
T::eq('https://panel.example.com', (string) SettingsService::get('webhook_base_url', ''), 'آدرس پایه با فرم معمولی هم به‌روز شد');

// ۸) بررسی expandBracketKeys روی آرایه‌های چندمقداری
$expanded = Request::expandBracketKeys([
    'conditions[]' => ['joined', 'channel'],
    'order[5]' => '20',
    'simple' => 'ok',
]);
T::eq(['joined', 'channel'], $expanded['conditions'] ?? null, 'expandBracketKeys آرایهٔ [] را درست می‌سازد');
T::eq('20', $expanded['order']['5'] ?? null, 'expandBracketKeys کلید عددی را درست می‌سازد');
T::eq('ok', $expanded['simple'] ?? null, 'expandBracketKeys کلید ساده را حفظ می‌کند');

TelegramService::$fakeTransport = null;
Request::setRawBody('');
$_POST = [];
@unlink(RB_STORAGE . '/installed.lock');

exit(T::summary());
