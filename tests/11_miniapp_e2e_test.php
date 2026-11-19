<?php
/**
 * تست سرتاسری Mini App:
 * امضای initData → ایجاد سشن → فراخوانی همه APIهای پنل کاربری → برداشت
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Http\Router;
use RB\Services\TelegramService;
use RB\Services\TelegramWebApp;
use RB\Services\WalletService;
use RB\Support\Database;

T::suite('پنل کاربری (Mini App): احراز هویت و APIها');

$db = Database::instance();
$botToken = '123456789:AATestTokenForMiniAppFlow_abcdefghijkl';

// ---------- پیکربندی ربات
$settingsId = (int) $db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
$row = [
    'bot_id' => 123456789,
    'bot_username' => 'refrallbot_test_bot',
    'bot_token_enc' => RB\Support\Crypto::encrypt($botToken),
    'connection_status' => 'connected',
    'webhook_active' => 0,
    'updated_at' => now(),
];
if ($settingsId > 0) {
    $db->update('telegram_settings', $row, 'id = :id', ['id' => $settingsId]);
} else {
    $db->insert('telegram_settings', $row + ['created_at' => now()]);
}
TelegramService::forgetSettingsCache();
T::eq($botToken, TelegramWebApp::botToken(), 'توکن ربات برای اعتبارسنجی Mini App خوانده می‌شود');

// ---------- کاربر واقعی از مسیر ربات
$referrer = makeUser(['id' => 700000001, 'first_name' => 'معرف', 'username' => 'referrer']);
$user = makeUser(
    ['id' => 700000002, 'first_name' => 'کاربر', 'last_name' => 'پنل', 'username' => 'paneluser', 'language_code' => 'fa'],
    $referrer['referral_code']
);
T::eq((int) $referrer['id'], (int) $user['referred_by'], 'کاربر مهمان با کد دعوت به معرف متصل شد');

// ---------- initData معتبر
$tgUser = ['id' => 700000002, 'first_name' => 'کاربر', 'username' => 'paneluser'];
$initData = TelegramWebApp::buildInitData($tgUser, $botToken);
$verify = TelegramWebApp::verify($initData);
T::ok($verify['ok'], 'initData ساخته‌شده با امضای صحیح تأیید شد');

// ---------- initData جعلی
$tampered = preg_replace('~user=[^&]+~', 'user=' . urlencode('{"id":700000002,"first_name":"هکر"}'), $initData);
T::ok(!TelegramWebApp::verify($tampered)['ok'], 'initData دستکاری‌شده رد می‌شود');
$freshButWrong = TelegramWebApp::buildInitData($tgUser, '999:WrongTokenForTheSameUser_abcdefghijklmn');
T::ok(!TelegramWebApp::verify($freshButWrong)['ok'], 'initData با توکن اشتباه رد می‌شود');
$expired = TelegramWebApp::buildInitData($tgUser, $botToken, time() - (25 * 3600));
T::ok(!TelegramWebApp::verify($expired)['ok'], 'initData منقضی (بیش از ۲۴ ساعت) رد می‌شود');

// ---------- ثبت‌نام کاربر ناشناخته از طریق Mini App رد می‌شود
$stranger = TelegramWebApp::buildInitData(['id' => 799999999, 'first_name' => 'ناشناس'], $botToken);
$result = TelegramWebApp::authenticate($stranger);
T::ok(!$result['ok'], 'کاربری که ربات را /start نکرده نمی‌تواند وارد پنل شود');

// ---------- احراز هویت موفق
$_POST = ['initData' => $initData];
$_SESSION = [];
$auth = TelegramWebApp::authenticate($initData);
T::ok($auth['ok'] ?? false, 'احراز هویت Mini App موفق بود');
T::eq((int) $user['id'], (int) ($_SESSION['miniapp_user_id'] ?? 0), 'شناسه کاربر در سشن Mini App ذخیره شد');
T::eq((int) $user['telegram_id'], (int) ($_SESSION['miniapp_telegram_id'] ?? 0), 'شناسه تلگرام در سشن ذخیره شد');

// ---------- کاربر مسدود نمی‌تواند وارد شود
$db->update('users', ['status' => 'blocked'], 'id = :id', ['id' => (int) $user['id']]);
$blocked = TelegramWebApp::authenticate($initData);
T::ok(!($blocked['ok'] ?? false), 'کاربر مسدود اجازه ورود به Mini App ندارد');
$db->update('users', ['status' => 'active'], 'id = :id', ['id' => (int) $user['id']]);
$auth = TelegramWebApp::authenticate($initData);
T::ok($auth['ok'] ?? false, 'پس از رفع مسدودی، ورود مجدد موفق است');

// ---------- درخواست واقعی به APIهای پنل کاربری
$sent = [];

function miniGet(string $path): array
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['HTTP_HOST'] = 'mini.test';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REMOTE_ADDR'] = '198.51.100.44';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_GET = [];
    $_POST = [];
    ob_start();
    $err = null;
    try {
        $router = new Router();
        (require RB_ROOT . '/src/routes.php')($router);
        $router->dispatch();
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    $out = (string) ob_get_clean();
    return ['json' => json_decode($out, true), 'raw' => $out, 'error' => $err];
}

$wallet = new WalletService($db);
$wallet->credit((int) $user['id'], 'adjustment', 200000, [
    'description' => 'شارژ تست پنل کاربری',
    'idempotency_key' => 'mini-test-credit-1',
]);

$boot = miniGet('/app/api/bootstrap');
T::ok($boot['json']['ok'] ?? false, 'API bootstrap پاسخ ok می‌دهد');
T::ok(!empty($boot['json']['csrf']), 'API bootstrap توکن CSRF برمی‌گرداند');

$dash = miniGet('/app/api/dashboard');
T::ok($dash['json']['ok'] ?? false, 'API داشبورد پاسخ ok می‌دهد');
T::eq(200000, (int) ($dash['json']['wallet']['balance'] ?? 0), 'موجودی در داشبورد درست نمایش داده می‌شود');
T::eq('https://t.me/refrallbot_test_bot?start=' . $user['referral_code'], (string) ($dash['json']['user']['invite_link'] ?? ''), 'لینک دعوت اختصاصی در داشبورد ساخته شد');

$refs = miniGet('/app/api/referrals');
T::ok($refs['json']['ok'] ?? false, 'API زیرمجموعه‌ها پاسخ ok می‌دهد');
T::ok(array_key_exists('direct', $refs['json']), 'ساختار پاسخ زیرمجموعه‌ها معتبر است');

$tx = miniGet('/app/api/transactions');
T::ok($tx['json']['ok'] ?? false, 'API تراکنش‌ها پاسخ ok می‌دهد');
T::ok(count($tx['json']['rows'] ?? []) >= 1, 'تراکنش شارژ در لیست تراکنش‌های کاربر دیده می‌شود');

$wd = miniGet('/app/api/withdrawals');
T::ok($wd['json']['ok'] ?? false, 'API برداشت‌ها پاسخ ok می‌دهد');

$camp = miniGet('/app/api/campaigns');
T::ok($camp['json']['ok'] ?? false, 'API کمپین‌ها پاسخ ok می‌دهد');

$rank = miniGet('/app/api/ranking');
T::ok($rank['json']['ok'] ?? false, 'API رتبه‌بندی پاسخ ok می‌دهد');

$notif = miniGet('/app/api/notifications');
T::ok($notif['json']['ok'] ?? false, 'API اعلان‌ها پاسخ ok می‌دهد');

$net = miniGet('/app/api/network/' . (int) $referrer['id']);
T::ok($net['json']['ok'] ?? false, 'API شبکه پاسخ ok می‌دهد');

// ---------- APIها بدون سشن باید خطای احراز هویت بدهند، نه داده
$savedSession = $_SESSION;
unset($_SESSION['miniapp_user_id']);
$anon = miniGet('/app/api/dashboard');
T::ok(!($anon['json']['ok'] ?? false), 'API داشبورد بدون سشن Mini App داده نمی‌دهد');
T::ok(isset($anon['json']['error']), 'پاسخ بدون سشن شامل پیام خطاست');
$_SESSION = $savedSession;

// ---------- برداشت از طریق API (POST + CSRF)
$csrfToken = (string) ($_SESSION['_csrf_token'] ?? \RB\Support\Csrf::token());
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_CSRF_TOKEN'] = $csrfToken;
$_SERVER['REQUEST_URI'] = '/app/api/withdraw';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_POST = [
    'amount' => '50,000',
    'card_number' => '۱۲۳-نامعتبر-۷',
    'card_holder' => 'کاربر تست پنل',
];
ob_start();
$err = null;
try {
    $router = new Router();
    (require RB_ROOT . '/src/routes.php')($router);
    $router->dispatch();
} catch (Throwable $e) {
    $err = $e->getMessage();
}
$withdrawOut = json_decode((string) ob_get_clean(), true);
T::ok($withdrawOut['ok'] ?? false, 'ثبت درخواست برداشت از Mini App با ورودی آزاد موفق بود');
$miniCardReq = (new \RB\Services\WithdrawalService($db))->find((int) ($withdrawOut['request']['id'] ?? 0));
T::eq('۱۲۳-نامعتبر-۷', \RB\Services\WithdrawalService::decryptedCard($miniCardReq), 'ورودی آزاد در مینی‌اپ دقیقاً ذخیره می‌شود');
T::eq(150000, (int) $wallet->summary((int) $user['id'])['balance'], 'مبلغ برداشت از موجودی رزرو شد');
$openRequests = (int) $db->value("SELECT COUNT(*) FROM withdrawal_requests WHERE user_id = :u AND status IN ('new','reviewing')", ['u' => (int) $user['id']]);
T::eq(1, $openRequests, 'یک درخواست برداشت باز برای کاربر ثبت شد');

// ---------- درخواست دوم باید رد شود
$_POST = [
    'amount' => '50,000',
    'card_number' => '6037701689095443',
    'card_holder' => 'کاربر تست پنل',
];
ob_start();
try {
    $router = new Router();
    (require RB_ROOT . '/src/routes.php')($router);
    $router->dispatch();
} catch (Throwable $e) {
    // ignore
}
$second = json_decode((string) ob_get_clean(), true);
T::ok(!($second['ok'] ?? true), 'درخواست برداشت دوم هم‌زمان رد می‌شود');
T::eq(1, (int) $db->value("SELECT COUNT(*) FROM withdrawal_requests WHERE user_id = :u AND status IN ('new','reviewing')", ['u' => (int) $user['id']]), 'تعداد درخواست باز تغییر نکرد');

// ---------- لغو درخواست توسط کاربر
$requestId = (int) $db->value("SELECT id FROM withdrawal_requests WHERE user_id = :u AND status IN ('new','reviewing') LIMIT 1", ['u' => (int) $user['id']]);
T::ok($requestId > 0, 'شناسه درخواست باز برای لغو یافت شد');
$_SERVER['REQUEST_URI'] = '/app/api/withdraw/' . $requestId . '/cancel';
$_POST = [];
ob_start();
try {
    $router = new Router();
    (require RB_ROOT . '/src/routes.php')($router);
    $router->dispatch();
} catch (Throwable $e) {
    // ignore
}
$cancel = json_decode((string) ob_get_clean(), true);
T::ok($cancel['ok'] ?? false, 'کاربر می‌تواند درخواست برداشت را لغو کند');
T::eq(200000, (int) $wallet->summary((int) $user['id'])['balance'], 'مبلغ پس از لغو کامل به موجودی بازگشت');
T::eq('cancelled', $db->value('SELECT status FROM withdrawal_requests WHERE id = :id', ['id' => $requestId]), 'وضعیت درخواست cancelled شد');
T::ok($wallet->reconcile((int) $user['id'])['match'], 'دفتر مالی پس از لغو هم‌خوان است');

// ---------- خروج از پنل
TelegramWebApp::logout();
T::ok(empty($_SESSION['miniapp_user_id']), 'خروج از Mini App سشن را پاک می‌کند');

exit(T::summary());
