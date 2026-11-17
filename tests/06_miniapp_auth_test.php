<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\TelegramService;
use RB\Services\TelegramWebApp;
use RB\Services\UserService;
use RB\Support\Crypto;
use RB\Support\Database;
use RB\Support\Request;

T::suite('پنل کاربری Mini App — احراز هویت و API');

$db = Database::instance();

// آماده‌سازی ربات
$botToken = '555000111:' . str_repeat('Z', 35);
$settingsId = (int) $db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
if ($settingsId === 0) {
    $settingsId = $db->insert('telegram_settings', [
        'connection_status' => 'connected', 'webhook_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}
$db->update('telegram_settings', [
    'bot_token_enc' => Crypto::encrypt($botToken),
    'bot_username' => 'miniapp_test_bot',
    'connection_status' => 'connected',
    'webhook_active' => 1,
    'webhook_secret' => 'secret-123',
    'updated_at' => now(),
], 'id = :id', ['id' => $settingsId]);
TelegramService::forgetSettingsCache();
T::eq($botToken, TelegramWebApp::botToken(), 'توکن ربات برای بررسی امضا قابل خواندن است');

// کاربر باید قبلاً با /start در دیتابیس باشد
$users = new UserService($db);
$tgUser = ['id' => 700000001, 'is_bot' => false, 'first_name' => 'مریم', 'last_name' => 'احمدی', 'username' => 'maryam_t'];
$created = $users->registerFromTelegram($tgUser);
$user = $created['user'];
T::ok((int) $user['id'] > 0, 'کاربر Mini App آماده شد');

// ---------- امضای معتبر
$initData = TelegramWebApp::buildInitData($tgUser, $botToken);
$verified = TelegramWebApp::verify($initData);
T::ok($verified['ok'] === true, 'initData با امضای معتبر پذیرفته شد');
T::eq(700000001, (int) $verified['user']['id'], 'شناسه کاربر از initData استخراج شد');

// ---------- امضای دستکاری‌شده
$tampered = str_replace('maryam_t', 'hacker', $initData);
$bad = TelegramWebApp::verify($tampered);
T::eq(false, $bad['ok'], 'داده دستکاری‌شده رد شد');
T::ok(str_contains((string) $bad['error'], 'امضا') || str_contains((string) $bad['error'], 'معتبر'), 'پیام خطای امضا واضح است');

// ---------- initData خالی
$empty = TelegramWebApp::verify('');
T::eq(false, $empty['ok'], 'initData خالی رد شد');

// ---------- داده منقضی (بیش از ۲۴ ساعت)
$oldInit = TelegramWebApp::buildInitData($tgUser, $botToken, time() - 90000);
$expired = TelegramWebApp::verify($oldInit);
T::eq(false, $expired['ok'], 'initData منقضی رد شد');
T::ok(str_contains((string) $expired['error'], 'منقضی'), 'پیام خطا به انقضا اشاره می‌کند');

// ---------- توکن دیگر (امضای جعلی)
$wrongToken = TelegramWebApp::buildInitData($tgUser, '999999999:' . str_repeat('Q', 35));
T::eq(false, TelegramWebApp::verify($wrongToken)['ok'], 'امضا با توکن اشتباه رد شد');

// ---------- احراز هویت کامل (ساخت سشن)
$_SESSION = [];
$auth = TelegramWebApp::authenticate($initData);
T::ok($auth['ok'] === true, 'احراز هویت کامل موفق بود');
T::eq((int) $user['id'], (int) ($_SESSION['miniapp_user_id'] ?? 0), 'شناسه کاربر در سشن ذخیره شد');

$current = TelegramWebApp::currentUser();
T::ok($current !== null && (int) $current['id'] === (int) $user['id'], 'کاربر جاری Mini App بازیابی می‌شود');

// ---------- خروج
TelegramWebApp::logout();
T::eq(null, TelegramWebApp::currentUser(), 'پس از خروج، کاربر جاری وجود ندارد');

// ---------- کاربر مسدود نمی‌تواند وارد شود
$db->update('users', ['status' => 'blocked'], 'id = :id', ['id' => (int) $user['id']]);
$_SESSION = [];
$blocked = TelegramWebApp::authenticate($initData);
T::eq(false, $blocked['ok'], 'کاربر مسدود از ورود منع شد');
$db->update('users', ['status' => 'active'], 'id = :id', ['id' => (int) $user['id']]);

// ---------- کاربر ناشناس (بدون /start)
$stranger = ['id' => 700009999, 'is_bot' => false, 'first_name' => 'ناشناس'];
$strangerData = TelegramWebApp::buildInitData($stranger, $botToken);
$_SESSION = [];
$noAccount = TelegramWebApp::authenticate($strangerData);
T::eq(false, $noAccount['ok'], 'کاربر بدون حساب در ربات رد می‌شود');
T::ok(str_contains((string) $noAccount['error'], 'start'), 'پیام خطا کاربر را به /start هدایت می‌کند');

// ---------- API: پاسخ بدون سشن
$_SESSION = [];
$api = new RB\Controllers\MiniApp\DashboardApiController();
ob_start();
try {
    $api->dashboard();
} catch (Throwable $e) {
    // در محیط تست خروج رخ نمی‌دهد؛ خطا هم قابل قبول است
}
$output = ob_get_clean();
$decoded = json_decode($output, true);
T::ok(is_array($decoded) && ($decoded['ok'] ?? true) === false, 'API بدون احراز هویت، ok=false برمی‌گرداند');
T::ok(($decoded['need_auth'] ?? false) === true, 'پاسخ شامل need_auth است تا فرانت صفحه ورود را نشان دهد');

// ---------- API: داده داشبورد با سشن معتبر
$_SESSION = [];
TelegramWebApp::authenticate($initData);
$wallet = new RB\Services\WalletService($db);
$wallet->credit((int) $user['id'], 'commission_direct', 50000, ['idempotency_key' => 'miniapp-test:' . $user['id'], 'description' => 'تست واریز']);

ob_start();
(new RB\Controllers\MiniApp\DashboardApiController())->dashboard();
$dash = json_decode((string) ob_get_clean(), true);
T::ok(($dash['ok'] ?? false) === true, 'داشبورد Mini App داده برمی‌گرداند');
T::eq(50000, (int) ($dash['wallet']['balance'] ?? 0), 'موجودی کیف پول در داشبورد درست است');
T::ok(!empty($dash['user']['invite_link']), 'لینک دعوت اختصاصی در پاسخ هست');
T::ok(str_contains((string) $dash['user']['invite_link'], '?start=' . $user['referral_code']), 'لینک دعوت شامل کد دعوت است');
T::ok(isset($dash['withdraw']['min_amount']), 'حداقل برداشت از تنظیمات خوانده می‌شود (بدون مقدار ثابت در کد)');
T::ok(count($dash['recent_transactions']) >= 1, 'تراکنش‌های اخیر در داشبورد هستند');

// ---------- امنیت: خروجی HTML کوتیشن‌ها را escape می‌کند
T::eq('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'), 'تابع e از XSS جلوگیری می‌کند');
T::eq('&quot;x&quot; &amp; &#039;y&#039;', e('"x" & \'y\''), 'کاراکترهای حساس escape می‌شوند');

// ---------- CSRF
$_SESSION = [];
$token = RB\Support\Csrf::token();
T::ok(strlen($token) === 64, 'توکن CSRF با طول ۶۴ کاراکتر ساخته شد');
$_POST['_csrf'] = $token;
T::eq(true, RB\Support\Csrf::check(), 'توکن درست پذیرفته می‌شود');
T::eq(false, RB\Support\Csrf::check(''), 'توکن خالی رد می‌شود');
T::eq(false, RB\Support\Csrf::check(str_repeat('a', 64)), 'توکن جعلی رد می‌شود');

exit(T::summary());
