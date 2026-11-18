<?php
/**
 * تست جامع نسخه v1.2.0:
 *  ۱) رفع قطعی خطای 500 وبهوک روی MySQL (Error 1093 در refreshNetworkCounters + ستون admins.name در /admin)
 *  ۲) سوییچ دوطرفه و آنی حالت ربات (مینی‌اپ ↔ دکمه‌ای ↔ ترکیبی) + همگام‌سازی setChatMenuButton
 *  ۳) دارک‌مود پیش‌فرض پنل مدیریت + نمودارهای چندگانه رشد اعضا در StatsService و داشبورد
 *  ۴) دارک‌مود پیش‌فرض مینی‌اپ + آمار و نمودارهای حرفه‌ای دعوت کاربر در پروفایل
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Bot\BotHandler;
use RB\Controllers\MiniApp\DashboardApiController;
use RB\Services\MenuService;
use RB\Services\SettingsService;
use RB\Services\StatsService;
use RB\Services\TelegramService;
use RB\Services\UserService;
use RB\Support\Crypto;
use RB\Support\Csrf;
use RB\Support\Database;
use RB\Support\Session;

T::suite('نسخه v1.2.0 — رفع ۵۰۰ دعوت MySQL، سوییچ مینی‌اپ/دکمه‌ای، دارک‌مود و نمودارها');

$db = Database::instance();

// ------------------------------------------------------------------ ۱) نگهبان استاتیک سازگاری MySQL
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(RB_ROOT . '/src'));
$mysql1093Hits = [];
$badAdminColHits = [];
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $code = (string) file_get_contents($path);
    if (preg_match('~\b(?:UPDATE|DELETE\s+FROM)\s+`?([a-z_]+)`?\b[^;]{0,500}\bFROM\s+`?\1`?\b~is', $code, $m)) {
        $mysql1093Hits[] = basename($path) . ': ' . substr($m[0], 0, 80);
    }
    if (str_contains($code, 'full_name')) {
        $badAdminColHits[] = basename($path);
    }
}
T::eq([], $mysql1093Hits, 'هیچ کوئری ناسازگار با MySQL Error 1093 (UPDATE/DELETE با زیرکوئری هم‌جدول) در src/ وجود ندارد');
T::eq([], $badAdminColHits, 'هیچ ارجاعی به ستون ناموجود full_name در src/ وجود ندارد');

// ------------------------------------------------------------------ ۲) شبیه‌سازی کامل دعوت رفرال روی Webhook و /admin
$tgCalls = [];
TelegramService::$fakeTransport = static function (string $method, array $params) use (&$tgCalls): array {
    $tgCalls[] = ['method' => $method, 'params' => $params];
    return ['ok' => true, 'result' => ['message_id' => count($tgCalls), 'chat' => ['id' => $params['chat_id'] ?? 1]]];
};

$webhookSecret = bin2hex(random_bytes(16));
$fakeToken = '123456789:AAFakeTokenForV120Testing1234567890';
$settingsId = (int) $db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
$tgRow = [
    'bot_token_enc' => Crypto::encrypt($fakeToken),
    'bot_id' => 123456789,
    'bot_username' => 'refrall_v120_bot',
    'bot_name' => 'RefrallBot v1.2',
    'connection_status' => 'connected',
    'webhook_active' => 1,
    'webhook_url' => 'https://example.test/webhook/telegram',
    'webhook_secret' => $webhookSecret,
    'last_error' => 'خطای پردازش آپدیت: خطای قدیمی تست',
    'updated_at' => now(),
];
if ($settingsId > 0) {
    $db->update('telegram_settings', $tgRow, 'id = :id', ['id' => $settingsId]);
} else {
    $settingsId = $db->insert('telegram_settings', $tgRow + ['created_at' => now()]);
}
TelegramService::forgetSettingsCache();
SettingsService::set('force_channel_membership', '0', 'telegram');
SettingsService::set('contact_required', '0', 'telegram');
SettingsService::set('referral_join_notify_enabled', '1', 'messages');
SettingsService::flush();

// ثبت کاربر معرف (A)
$userSvc = new UserService($db);
$refReg = $userSvc->registerFromTelegram([
    'id' => 920001001,
    'first_name' => 'سارا',
    'last_name' => 'احمدی',
    'username' => 'sara_inviter',
    'language_code' => 'fa',
]);
$inviter = $refReg['user'];
$refCode = (string) $inviter['referral_code'];
T::ok($refCode !== '', 'کد دعوت معرف تولید شد');

// ارسال آپدیت واقعی /start <ref_code> برای کاربر دعوت‌شده (B) — شامل language_code طولانی برای تست MySQL strict mode
$tgCalls = [];
$handler = new BotHandler($db);
$handler->handle([
    'update_id' => 9200001,
    'message' => [
        'message_id' => 101,
        'date' => time(),
        'chat' => ['id' => 920001002, 'type' => 'private'],
        'from' => [
            'id' => 920001002,
            'is_bot' => false,
            'first_name' => 'امیررضا',
            'last_name' => 'محمدی',
            'username' => 'amirreza_new',
            'language_code' => 'en-GB-oxendict', // ۱۴ کاراکتر > VARCHAR(10)
        ],
        'text' => '/start ' . $refCode,
    ],
]);

$invitedB = $userSvc->findByTelegramId(920001002);
T::ok($invitedB !== null, 'کاربر دعوت‌شده B بدون خطای ۵۰۰ ثبت شد');
T::eq((int) $inviter['id'], (int) ($invitedB['referred_by'] ?? 0), 'معرف کاربر B به‌درستی ثبت شد');
T::ok(mb_strlen((string) ($invitedB['language'] ?? '')) <= 10, 'کد زبان طولانی برای سازگاری با VARCHAR(10) کوتاه شد');

// بررسی ارسال پاسخ خوش‌آمدگویی به دعوت‌شده + پیام دعوت موفق به معرف
$sentToInvited = false;
$sentToInviter = false;
foreach ($tgCalls as $c) {
    if ($c['method'] !== 'sendMessage') {
        continue;
    }
    if ((int) ($c['params']['chat_id'] ?? 0) === 920001002) {
        $sentToInvited = true;
    }
    if ((int) ($c['params']['chat_id'] ?? 0) === 920001001) {
        $sentToInviter = true;
    }
}
T::ok($sentToInvited, 'ربات به کاربر دعوت‌شده B پاسخ خوش‌آمدگویی و منو ارسال کرد');
T::ok($sentToInviter, 'ربات به معرف A اعلان دعوت موفق ارسال کرد');

// دعوت لایه دوم (B -> C) و بازشماری سراسری شمارنده‌ها
$handler->handle([
    'update_id' => 9200002,
    'message' => [
        'message_id' => 102,
        'date' => time(),
        'chat' => ['id' => 920001003, 'type' => 'private'],
        'from' => ['id' => 920001003, 'is_bot' => false, 'first_name' => 'مینا', 'username' => 'mina_lvl2', 'language_code' => 'fa'],
        'text' => '/start ' . $invitedB['referral_code'],
    ],
]);

$inviterFresh = $userSvc->find((int) $inviter['id']);
T::eq(1, (int) ($inviterFresh['direct_ref_count'] ?? 0), 'شمارنده معرفی مستقیم A دقیقاً ۱ است');
T::eq(2, (int) ($inviterFresh['total_ref_count'] ?? 0), 'شمارنده کل شبکه A (لایه ۱ + ۲) دقیقاً ۲ است');

// بازشماری کامل (بدون آرگومان — مسیر Cron و changeReferrer)
$userSvc->refreshNetworkCounters([]);
$inviterAfterFull = $userSvc->find((int) $inviter['id']);
$invitedBAfterFull = $userSvc->find((int) $invitedB['id']);
T::eq(1, (int) ($inviterAfterFull['direct_ref_count'] ?? 0), 'refreshNetworkCounters([]) شمارنده مستقیم A را دقیق نگه داشت');
T::eq(2, (int) ($inviterAfterFull['total_ref_count'] ?? 0), 'refreshNetworkCounters([]) شمارنده کل شبکه A را دقیق نگه داشت');
T::eq(1, (int) ($invitedBAfterFull['direct_ref_count'] ?? 0), 'refreshNetworkCounters([]) شمارنده مستقیم B را دقیق نگه داشت');

// تست دستور /admin برای ادمین متصل به تلگرام
$roleId = (int) ($db->value('SELECT id FROM admin_roles ORDER BY id LIMIT 1') ?? 0);
if ($roleId === 0) {
    $roleId = $db->insert('admin_roles', ['name' => 'مدیر ارشد', 'slug' => 'superadmin', 'permissions' => '["*"]', 'created_at' => now()]);
}
$adminId = (int) ($db->value('SELECT id FROM admins ORDER BY id LIMIT 1') ?? 0);
if ($adminId === 0) {
    $adminId = $db->insert('admins', [
        'role_id' => $roleId,
        'name' => 'مدیر آزمایشی',
        'username' => 'v120admin',
        'password_hash' => password_hash('SecretPass123!', PASSWORD_DEFAULT),
        'telegram_user_id' => 920009999,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
} else {
    $db->update('admins', ['telegram_user_id' => 920009999, 'name' => 'مدیر آزمایشی', 'is_active' => 1], 'id = :id', ['id' => $adminId]);
}

$tgCalls = [];
$handler->handle([
    'update_id' => 9200003,
    'message' => [
        'message_id' => 103,
        'date' => time(),
        'chat' => ['id' => 920009999, 'type' => 'private'],
        'from' => ['id' => 920009999, 'is_bot' => false, 'first_name' => 'Admin'],
        'text' => '/admin',
    ],
]);
$adminReply = (string) ($tgCalls[0]['params']['text'] ?? '');
T::ok(str_contains($adminReply, 'مدیر آزمایشی'), 'دستور /admin ستون admins.name را بدون خطای SQL خواند');

// ------------------------------------------------------------------ ۳) سوییچ دوطرفه مینی‌اپ ↔ دکمه‌ای ↔ ترکیبی
$tgCalls = [];
$resMini = SettingsService::applyBotUiMode('miniapp', 'https://panel.example.test/app');
T::eq('miniapp', $resMini['effective'], 'حالت مینی‌اپ با HTTPS فعال شد');
T::ok(SettingsService::miniappActive(), 'miniappActive در حالت مینی‌اپ روشن است');
$menuBtnCall = array_values(array_filter($tgCalls, static fn($c) => $c['method'] === 'setChatMenuButton'));
T::ok(!empty($menuBtnCall), 'setChatMenuButton هنگام سوییچ به مینی‌اپ فراخوانی شد');
T::eq('web_app', (string) ($menuBtnCall[0]['params']['menu_button']['type'] ?? ''), 'دکمه منوی تلگرام روی web_app تنظیم شد');

$miniKb = TelegramService::replyKeyboard(MenuService::replyKeyboardRows());
$miniKbJson = (string) json_encode($miniKb, JSON_UNESCAPED_UNICODE);
T::ok(str_contains($miniKbJson, '"web_app"'), 'کیبورد حالت مینی‌اپ دکمه web_app دارد');
T::eq(2, count($miniKb['keyboard']), 'کیبورد حالت مینی‌اپ جمع‌وجور (۲ ردیف: ورود به پنل + دعوت/پشتیبانی) است');

// سوییچ به حالت دکمه‌ای
$tgCalls = [];
$resBtn = SettingsService::applyBotUiMode('buttons');
T::eq('buttons', $resBtn['effective'], 'سوییچ به حالت دکمه‌ای انجام شد');
T::ok(!SettingsService::miniappActive(), 'در حالت دکمه‌ای مینی‌اپ خاموش است');
$cmdBtnCall = array_values(array_filter($tgCalls, static fn($c) => $c['method'] === 'setChatMenuButton'));
T::ok(!empty($cmdBtnCall), 'setChatMenuButton هنگام سوییچ به حالت دکمه‌ای فراخوانی شد');
T::eq('commands', (string) ($cmdBtnCall[0]['params']['menu_button']['type'] ?? ''), 'دکمه منوی تلگرام به commands برگشت');

$btnKb = TelegramService::replyKeyboard(MenuService::replyKeyboardRows());
$btnKbJson = (string) json_encode($btnKb, JSON_UNESCAPED_UNICODE);
T::ok(!str_contains($btnKbJson, 'web_app'), 'کیبورد حالت دکمه‌ای هیچ web_app ندارد');
T::ok(count($btnKb['keyboard']) >= 5, 'کیبورد حالت دکمه‌ای همه دکمه‌های ربات را نمایش می‌دهد');

// سوییچ مجدد از دکمه‌ای به ترکیبی و سپس دوباره مینی‌اپ (بررسی برگشت‌پذیری کامل)
$resHyb = SettingsService::applyBotUiMode('hybrid');
T::eq('hybrid', $resHyb['effective'], 'سوییچ از دکمه‌ای به حالت ترکیبی انجام شد');
T::ok(SettingsService::miniappActive(), 'آدرس قبلی مینی‌اپ حفظ و مجدداً فعال شد');
$hybKb = TelegramService::replyKeyboard(MenuService::replyKeyboardRows());
T::ok(isset($hybKb['keyboard'][0][0]['web_app']) && count($hybKb['keyboard']) >= 6, 'حالت ترکیبی هم دکمه مینی‌اپ و هم تمام دکمه‌های ربات را دارد');

$resBackMini = SettingsService::applyBotUiMode('miniapp');
T::eq('miniapp', $resBackMini['effective'], 'برگشت مجدد به حالت مینی‌اپ با موفقیت انجام شد');

// ------------------------------------------------------------------ ۴) دارک‌مود پیش‌فرض و نمودارهای رشد اعضای ادمین
$adminLayout = (string) file_get_contents(RB_ROOT . '/views/layouts/app.php');
$authLayout = (string) file_get_contents(RB_ROOT . '/views/layouts/auth.php');
$miniLayout = (string) file_get_contents(RB_ROOT . '/views/layouts/miniapp.php');
$appCss = (string) file_get_contents(RB_ROOT . '/public/assets/css/app.css');
$dashView = (string) file_get_contents(RB_ROOT . '/views/admin/dashboard.php');
$miniHome = (string) file_get_contents(RB_ROOT . '/views/miniapp/home.php');

T::ok(str_contains($adminLayout, 'data-theme="dark"') && str_contains($adminLayout, 'data-theme-toggle'), 'لی‌اوت ادمین پیش‌فرض روی دارک‌مود است و کلید تغییر تم دارد');
T::ok(str_contains($authLayout, 'data-theme="dark"'), 'صفحه ورود ادمین پیش‌فرض روی دارک‌مود است');
T::ok(str_contains($miniLayout, 'data-theme="dark"') && str_contains($miniLayout, 'data-theme-scope="miniapp"'), 'مینی‌اپ پیش‌فرض روی دارک‌مود است');
T::ok(str_contains($appCss, 'html[data-theme="dark"]') && str_contains($appCss, '.mode-switcher-grid'), 'متغیرهای کامل دارک‌مود و سوییچر حالت در CSS تعریف شده‌اند');

$growth14 = StatsService::memberGrowthBreakdown(14);
T::eq(14, count($growth14['labels']), 'نمودار رشد ۱۴ روزه ادمین ۱۴ برچسب تاریخ شمسی دارد');
T::eq(14, count($growth14['cumulative']), 'نمودار تجمعی ادمین ۱۴ نقطه دارد');
T::ok($growth14['summary']['end_total'] >= 3, 'مجموع نهایی نمودار تجمعی کاربران ثبت‌شده را شامل می‌شود');
T::ok($growth14['summary']['period_referred'] >= 2, 'تعداد جذب رفرالی دوره دقیق محاسبه شد');

$comp = StatsService::memberComposition();
T::eq(4, count($comp), 'نمودار دونات ترکیب اعضا ۴ بخش دارد');
$levels = StatsService::networkByLevel();
T::ok(count($levels) >= 2, 'توزیع لایه‌های شبکه حداقل ۲ سطح را برگرداند');

foreach (['chart-users', 'chart-cumulative', 'chart-composition', 'chart-daily-refs', 'chart-referrals', 'chart-withdrawals'] as $cid) {
    T::ok(str_contains($dashView, 'id="' . $cid . '"'), 'داشبورد ادمین شامل نمودار ' . $cid . ' است');
}

// ------------------------------------------------------------------ ۵) آمار و نمودارهای پروفایل کاربر در Mini App
foreach (['data-tab="profile"', 'mini-chart-home', 'mini-chart-invites', 'mini-chart-quality', 'mini-chart-earnings', 'mini-chart-refs-bar'] as $needle) {
    T::ok(str_contains($miniHome, $needle), 'مینی‌اپ شامل بخش/نمودار ' . $needle . ' است');
}

Session::start();
$_SESSION['miniapp_user_id'] = (int) $inviter['id'];
$_SESSION['miniapp_tg_id'] = (int) $inviter['telegram_id'];
$_SESSION['miniapp_auth_at'] = time();
$_SERVER['HTTP_ACCEPT'] = 'application/json';

ob_start();
(new DashboardApiController())->dashboard();
$dashJson = json_decode((string) ob_get_clean(), true);

T::ok((bool) ($dashJson['ok'] ?? false), 'API داشبورد مینی‌اپ با موفقیت اجرا شد');
T::ok(isset($dashJson['analytics']), 'فیلد analytics برای نمودارهای پروفایل کاربر ارسال شد');
T::eq(14, count($dashJson['analytics']['labels_14'] ?? []), 'سری ۱۴ روزه دعوت کاربر دقیق است');
T::eq(30, count($dashJson['analytics']['labels_30'] ?? []), 'سری ۳۰ روزه دعوت کاربر دقیق است');
T::eq(1, (int) ($dashJson['analytics']['kpis']['today'] ?? 0), 'دعوت امروز کاربر A در پروفایل برابر ۱ است');
T::eq(1, (int) (end($dashJson['analytics']['cumulative_30']) ?: 0), 'مقدار پایانی نمودار تجمعی دعوت کاربر A برابر ۱ است');
T::ok(!empty($dashJson['analytics']['tier']['name']), 'سطح انگیزشی دعوت‌کننده در پروفایل کاربر محاسبه شد');

// پاکسازی حالت به پیش‌فرض
SettingsService::set('miniapp_mode', 'auto', 'telegram');
SettingsService::set('bot_ui_mode', 'hybrid', 'telegram');
SettingsService::set('miniapp_url', '', 'telegram');
SettingsService::flush();
TelegramService::$fakeTransport = null;

exit(T::summary());
