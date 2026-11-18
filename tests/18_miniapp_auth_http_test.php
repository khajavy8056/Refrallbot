<?php
declare(strict_types=1);
/**
 * تست مسیر واقعی HTTP احراز هویت Mini App.
 *
 * چرا لازم بود؟ تست‌های ۰۶ و ۱۱ فقط سرویس را صدا می‌زدند و باگ واقعی
 * «فرانت JSON می‌فرستد ولی کنترلر $_POST می‌خواند» را نمی‌گرفتند. این
 * تست دقیقاً همان مسیر HTTP (کنترلر + پارس بدنه) را با بدنۀ JSON و فرم
 * بازسازی می‌کند.
 *
 * پوشش:
 *   • JSON و فرم هر دو پذیرفته شوند
 *   • امضای معتبر/نامعتبر/دستکاری‌شده
 *   • داده منقضی، بدون user، بدون auth_date
 *   • ضدتکرار (replay) initData از یک نشست دیگر
 *   • کاربر مسدود و کاربر ثبت‌نام‌نشده رد شوند
 *   • نشست و CSRF پس از احراز هویت
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Controllers\MiniApp\HomeController;
use RB\Services\TelegramService;
use RB\Services\TelegramWebApp;
use RB\Services\UserService;
use RB\Support\Crypto;
use RB\Support\Csrf;
use RB\Support\Database;
use RB\Support\Request;
use RB\Support\Session;

T::suite('Mini App: احراز هویت از مسیر واقعی HTTP (JSON و فرم)');

$db = Database::instance();

// ---------------------------------------------------------------- آماده‌سازی ربات
$botToken = '111222333:' . str_repeat('Q', 35);
$settingsId = (int) $db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
if ($settingsId === 0) {
    $settingsId = $db->insert('telegram_settings', [
        'connection_status' => 'connected', 'webhook_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
$db->update('telegram_settings', [
    'bot_token_enc' => Crypto::encrypt($botToken),
    'bot_username' => 'miniapp_http_bot',
    'connection_status' => 'connected',
    'updated_at' => now(),
], 'id = :id', ['id' => $settingsId]);
TelegramService::forgetSettingsCache();

$users = new UserService($db);

/** @return array کاربر ثبت‌شده */
$register = static function (int $telegramId, string $name) use ($users): array {
    return $users->registerFromTelegram([
        'id' => $telegramId, 'is_bot' => false, 'first_name' => $name, 'username' => 'u' . $telegramId,
    ])['user'];
};

$alice = $register(910000001, 'آلیس');
$blocked = $register(910000002, 'مسدود');
$db->update('users', ['status' => 'blocked'], 'id = :id', ['id' => (int) $blocked['id']]);

/**
 * فراخوانی اکشن auth از مسیر HTTP.
 * @return array{status:int,json:?array,raw:string}
 */
$callAuth = static function (string $initData, string $mode = 'json', string $headerInitData = ''): array {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/app/auth';
    $_SERVER['HTTP_HOST'] = 'example.test';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Telegram WebApp)';
    if ($headerInitData !== '') {
        $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] = $headerInitData;
    } else {
        unset($_SERVER['HTTP_X_TELEGRAM_INIT_DATA']);
    }

    if ($mode === 'json') {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_POST = [];
        Request::setRawBody(json_encode(['init_data' => $initData], JSON_UNESCAPED_UNICODE));
    } elseif ($mode === 'form') {
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = ['init_data' => $initData];
        Request::setRawBody('');
    } else { // header-only با بدنهٔ خالی
        $_SERVER['CONTENT_TYPE'] = '';
        $_POST = [];
        Request::setRawBody('');
    }
    $_GET = [];

    ob_start();
    try {
        (new HomeController())->auth();
    } catch (\Throwable $e) {
        ob_end_clean();
        return ['status' => 500, 'json' => null, 'raw' => 'EXC: ' . $e->getMessage()];
    }
    $raw = (string) ob_get_clean();
    return [
        'status' => (int) ($GLOBALS['RB_LAST_JSON_STATUS'] ?? 200),
        'json' => json_decode($raw, true),
        'raw' => $raw,
    ];
};

/** نشست تستی را پاک می‌کند تا «مرورگر تازه» شبیه‌سازی شود */
$freshBrowser = static function (): void {
    Session::start();
    $_SESSION = [];
    // کش پارس بدنه بین «درخواست‌ها» باید خالی شود
    Request::resetCaches();
    unset($_SERVER['HTTP_X_TELEGRAM_INIT_DATA']);
    $_POST = [];
};

$aliceInit = TelegramWebApp::buildInitData(
    ['id' => (int) $alice['telegram_id'], 'first_name' => 'آلیس', 'username' => 'u910000001'],
    $botToken
);

// ---------------------------------------------------------------- ۱) JSON
$freshBrowser();
$r = $callAuth($aliceInit, 'json');
T::eq(true, $r['json']['ok'] ?? null, 'بدنهٔ JSON پذیرفته شد (باگ اصلی: قبلاً 401 می‌داد)' . ' — ' . substr($r['raw'], 0, 120));
T::eq((int) $alice['id'], (int) ($r['json']['user']['id'] ?? 0), 'کاربر درست در پاسخ برگشت');
T::ok(!isset($r['json']['user']['wallet_balance']), 'داده خصوصی (موجودی) در پاسخ احراز هویت نیست');

// ---------------------------------------------------------------- ۲) فرم
// initData تازه، چون هر initData فقط یک بار از یک نشست تازه پذیرفته می‌شود
$freshBrowser();
$formInit = TelegramWebApp::buildInitData(['id' => (int) $alice['telegram_id'], 'first_name' => 'آلیس'], $botToken);
$r = $callAuth($formInit, 'form');
T::eq(true, $r['json']['ok'] ?? null, 'بدنهٔ فرم (application/x-www-form-urlencoded) هم پذیرفته شد');

// ---------------------------------------------------------------- ۳) هدر
$freshBrowser();
$init2 = TelegramWebApp::buildInitData(['id' => (int) $alice['telegram_id'], 'first_name' => 'آلیس'], $botToken);
$r = $callAuth('', 'header', $init2);
T::eq(true, $r['json']['ok'] ?? null, 'ارسال initData از هدر X-Telegram-Init-Data پشتیبانی می‌شود');

// ---------------------------------------------------------------- ۴) بدون داده
$freshBrowser();
$r = $callAuth('', 'json');
T::eq(false, $r['json']['ok'] ?? null, 'درخواست بدون initData رد شد');
T::eq(401, $r['status'], 'کد وضعیت 401 است');

// ---------------------------------------------------------------- ۵) امضای نامعتبر
$freshBrowser();
$tampered = str_replace('u910000001', 'u910000009', $aliceInit);
$r = $callAuth($tampered, 'json');
T::eq(false, $r['json']['ok'] ?? null, 'دادهٔ دستکاری‌شده رد شد');
T::ok(str_contains((string) ($r['json']['error'] ?? ''), 'امضا'), 'پیام خطا به امضای نامعتبر اشاره دارد');

// ---------------------------------------------------------------- ۶) امضای توکن دیگر
$freshBrowser();
$wrongTokenInit = TelegramWebApp::buildInitData(['id' => (int) $alice['telegram_id']], '999999999:' . str_repeat('W', 35));
$r = $callAuth($wrongTokenInit, 'json');
T::eq(false, $r['json']['ok'] ?? null, 'امضا با توکن ربات دیگر پذیرفته نشد');

// ---------------------------------------------------------------- ۷) منقضی
$freshBrowser();
$expiredInit = TelegramWebApp::buildInitData(
    ['id' => (int) $alice['telegram_id'], 'first_name' => 'آلیس'],
    $botToken,
    time() - (TelegramWebApp::MAX_AGE + 600)
);
$r = $callAuth($expiredInit, 'json');
T::eq(false, $r['json']['ok'] ?? null, 'دادهٔ منقضی (>۲۴ ساعت) رد شد');
T::ok(str_contains((string) ($r['json']['error'] ?? ''), 'منقضی'), 'پیام خطا «منقضی» است');

// ---------------------------------------------------------------- ۸) زمان آینده
$freshBrowser();
$futureInit = TelegramWebApp::buildInitData(['id' => (int) $alice['telegram_id']], $botToken, time() + 3600);
$r = $callAuth($futureInit, 'json');
T::eq(false, $r['json']['ok'] ?? null, 'auth_date در آینده رد شد');

// ---------------------------------------------------------------- ۹) بدون user
$freshBrowser();
$noUser = TelegramWebApp::buildInitData(['first_name' => 'بدون شناسه'], $botToken);
$r = $callAuth($noUser, 'json');
T::eq(false, $r['json']['ok'] ?? null, 'دادهٔ بدون user رد شد');

// ---------------------------------------------------------------- ۱۰) ضدتکرار
// یک مرورگر (نشست) می‌تواند رفرش کند؛ مرورگر دیگر با همان initData نه.
$freshBrowser();
$replayInit = TelegramWebApp::buildInitData(['id' => (int) $alice['telegram_id'], 'first_name' => 'آلیس'], $botToken);
$first = $callAuth($replayInit, 'json');
T::eq(true, $first['json']['ok'] ?? null, 'اولین استفاده از initData پذیرفته شد');

$again = $callAuth($replayInit, 'json');
T::eq(true, $again['json']['ok'] ?? null, 'رفرش همان مرورگر با همان initData مجاز است (تلگرام initData را عوض نمی‌کند)');

$freshBrowser();
$stolen = $callAuth($replayInit, 'json');
T::eq(false, $stolen['json']['ok'] ?? null, 'استفادهٔ همان initData از مرورگر/نشست دیگر رد شد (ضدتکرار)');
T::ok(str_contains((string) ($stolen['json']['error'] ?? ''), 'قبلاً استفاده'), 'پیام خطا replay را شفاف اعلام می‌کند');

// ---------------------------------------------------------------- ۱۱) کاربر مسدود
$freshBrowser();
$blockedInit = TelegramWebApp::buildInitData(['id' => (int) $blocked['telegram_id'], 'first_name' => 'مسدود'], $botToken);
$r = $callAuth($blockedInit, 'json');
T::eq(false, $r['json']['ok'] ?? null, 'کاربر مسدود اجازهٔ ورود ندارد');
T::ok(str_contains((string) ($r['json']['error'] ?? ''), 'مسدود'), 'پیام مسدودی صریح است');

// ---------------------------------------------------------------- ۱۲) ثبت‌نام‌نشده
$freshBrowser();
$unknownInit = TelegramWebApp::buildInitData(['id' => 919999999, 'first_name' => 'ناشناس'], $botToken);
$r = $callAuth($unknownInit, 'json');
T::eq(false, $r['json']['ok'] ?? null, 'کاربری که /start نزده اجازهٔ ورود ندارد');
T::ok(str_contains((string) ($r['json']['error'] ?? ''), '/start'), 'پیام راهنمایی /start می‌دهد');

// ---------------------------------------------------------------- ۱۳) نشست
$freshBrowser();
$sessionInit = TelegramWebApp::buildInitData(['id' => (int) $alice['telegram_id'], 'first_name' => 'آلیس'], $botToken);
$regenBefore = \RB\Support\Session::$regenerateCount;
$r = $callAuth($sessionInit, 'json');
T::eq(true, $r['json']['ok'] ?? null, 'ورود موفق برای بررسی نشست');
T::ok(\RB\Support\Session::$regenerateCount > $regenBefore, 'شناسهٔ نشست پس از احراز هویت بازتولید شد (session fixation بسته است)');
T::eq((int) $alice['id'], (int) ($_SESSION['miniapp_user_id'] ?? 0), 'شناسه کاربر در نشست ذخیره شد');
T::ok(!empty($_SESSION['miniapp_auth_at']), 'زمان احراز هویت در نشست ثبت شد');

// ---------------------------------------------------------------- ۱۴) currentUser
$meResp = null;
ob_start();
try {
    (new HomeController())->me();
} catch (\Throwable $e) {
    // ignore
}
$meResp = (string) ob_get_clean();
$meJson = json_decode($meResp, true);
T::eq(true, $meJson['ok'] ?? null, 'me() با نشست معتبر پاسخ ok داد');

// نشست پاک → بدون داده
$freshBrowser();
ob_start();
try {
    (new HomeController())->me();
} catch (\Throwable $e) {
}
$anonJson = json_decode((string) ob_get_clean(), true);
T::eq(false, $anonJson['ok'] ?? null, 'me() بدون نشست پاسخ 401 داد (بدون افشای داده)');

// ---------------------------------------------------------------- ۱۵) کاربر مسدود بعد از ورود
$freshBrowser();
$lateInit = TelegramWebApp::buildInitData(['id' => (int) $alice['telegram_id']], $botToken);
$callAuth($lateInit, 'json');
$db->update('users', ['status' => 'blocked'], 'id = :id', ['id' => (int) $alice['id']]);
T::eq(null, TelegramWebApp::currentUser(), 'کاربری که پس از ورود مسدود شود، نشست معتبر ندارد');
$db->update('users', ['status' => 'active'], 'id = :id', ['id' => (int) $alice['id']]);

// ---------------------------------------------------------------- ۱۶) پروفایل از داده تأییدشده
$freshBrowser();
$db->update('users', ['first_name' => 'قدیمی', 'username' => 'old_name'], 'id = :id', ['id' => (int) $alice['id']]);
$profileInit = TelegramWebApp::buildInitData(
    ['id' => (int) $alice['telegram_id'], 'first_name' => 'نام تازه', 'username' => 'new_name'],
    $botToken
);
$callAuth($profileInit, 'json');
$refreshed = $db->first('SELECT first_name, username FROM users WHERE id = :id', ['id' => (int) $alice['id']]);
T::eq('نام تازه', $refreshed['first_name'] ?? null, 'نام پروفایل از دادهٔ تأییدشده تلگرام به‌روزرسانی شد');
T::eq('new_name', $refreshed['username'] ?? null, 'نام کاربری از دادهٔ تأییدشده تلگرام به‌روزرسانی شد');

// ---------------------------------------------------------------- ۱۷) CSRF روی عملیات حساس
$token = Csrf::token();
T::ok($token !== '', 'توکن CSRF برای فرم‌های Mini App تولید می‌شود');
T::eq(false, Csrf::check(''), 'CSRF خالی رد می‌شود');
T::eq(false, Csrf::check('forged-token'), 'CSRF جعلی رد می‌شود');
T::eq(true, Csrf::check($token), 'CSRF معتبر پذیرفته می‌شود');

exit(T::summary());
