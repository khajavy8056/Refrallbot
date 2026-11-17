<?php
/**
 * تست دود عملیات (Action smoke)
 * هندلرهای واقعی POST کنترلرهای مدیریت را اجرا می‌کند و اثر آن‌ها را در دیتابیس بررسی می‌کند.
 * این تست مکمل تست‌های سناریویی است: اینجا مسیر «HTTP → کنترلر → سرویس → دیتابیس» پوشش داده می‌شود.
 *
 * اجرا: php tests/_actions.php
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Services\WalletService;
use RB\Support\Database;
use RB\Support\Money;

T::suite('تست دود عملیات پنل مدیریت (HTTP handlers)');

$db = Database::instance();

// ---------- آماده‌سازی: نصب‌شده، مدیر، کاربر
$db->insertIgnore('system_settings', [
    'key' => 'installed_at', 'value' => now(), 'group_name' => 'system',
    'created_at' => now(), 'updated_at' => now(),
], 'key');
$roleId = (int) $db->value("SELECT id FROM admin_roles WHERE slug = 'superadmin'");
if ($roleId <= 0) {
    $roleId = (int) $db->insert('admin_roles', [
        'name' => 'مدیر کل', 'slug' => 'superadmin', 'permissions' => '["*"]',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
$adminId = $db->insert('admins', [
    'name' => 'مدیر عملیات', 'username' => 'actions',
    'password_hash' => password_hash('Str0ngPassw0rd!', PASSWORD_DEFAULT),
    'role_id' => $roleId, 'is_active' => 1,
    'created_at' => now(), 'updated_at' => now(),
]);

$user = makeUser(['id' => 555000111, 'first_name' => 'کاربر', 'username' => 'actionuser', 'language_code' => 'fa']);
$userId = (int) $user['id'];

// ---------- محیط جلسه مدیر
$_SESSION = [];
$_SESSION['admin_id'] = $adminId;
$_SESSION['admin_last_seen'] = time();
$_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
$_SERVER['HTTP_HOST'] = 'actions.test';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_USER_AGENT'] = 'RefrallBot-Tests/1.0';
$_SERVER['REQUEST_URI'] = '/admin';
$_POST = [];

/**
 * اجرای یک هندلر واقعی و برگرداندن [کد HTTP, خروجی].
 * redirect() از exit استفاده می‌کند، پس در زیرفرایند... نه — هندلرها JSON برمی‌گردانند
 * چون Accept: application/json است. برای هندلرهایی که redirect می‌کنند، خروجی شامل meta refresh است.
 */
function act(string $controllerClass, string $action, array $params = [], array $post = []): array
{
    $_POST = array_merge(['_csrf' => (string) ($_SESSION['_csrf_token'] ?? '')], $post);
    $redirect = null;
    RB\Support\Request::$redirectHandler = static function (string $to, int $status) use (&$redirect): void {
        $redirect = ['to' => $to, 'status' => $status];
    };
    ob_start();
    $error = null;
    try {
        $controller = new $controllerClass();
        $method = new ReflectionMethod($controller, $action);
        if (!$method->isPublic()) {
            throw new RuntimeException('اکشن ' . $action . ' عمومی نیست.');
        }
        $controller->{$action}($params);
    } catch (RB\Support\RedirectException) {
        // ریدایرکت عمدی — خطا نیست
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }
    $out = (string) ob_get_clean();
    RB\Support\Request::$redirectHandler = null;
    return ['out' => $out, 'error' => $error, 'json' => json_decode($out, true), 'redirect' => $redirect];
}

function noPhpNotice(string $out): bool
{
    foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Undefined '] as $needle) {
        if (str_contains($out, $needle)) {
            return false;
        }
    }
    return true;
}

// ---------- ۱) تنظیمات عمومی
$r = act(RB\Controllers\Admin\SettingsController::class, 'saveGeneral', [], ['app_name' => 'سامانه تست عملیات']);
T::ok($r['json']['ok'] ?? false, 'ذخیره تنظیمات عمومی پاسخ ok می‌دهد');
T::eq('سامانه تست عملیات', SettingsService::get('app_name'), 'نام سامانه واقعاً در دیتابیس ذخیره شد');
T::ok(noPhpNotice($r['out']), 'ذخیره تنظیمات بدون هشدار PHP اجرا شد');

// ---------- ۲) تنظیمات برداشت
$r = act(RB\Controllers\Admin\SettingsController::class, 'saveWithdrawal', [], [
    'min_withdraw_amount' => '45,000', 'withdrawal_enabled' => '1',
    'max_open_withdrawals' => '2', 'max_daily_withdrawal' => '500000',
]);
T::eq('45000', SettingsService::get('min_withdraw_amount'), 'حداقل برداشت با جداکننده هزارگان درست ذخیره شد');
T::eq('2', SettingsService::get('max_open_withdrawals'), 'تعداد درخواست باز ذخیره شد');

// ---------- ۳) تنظیمات پورسانت
$r = act(RB\Controllers\Admin\CommissionController::class, 'save', [], [
    'direct_reward' => '10,000', 'direct_enabled' => '1', 'multi_level_enabled' => '1', 'max_levels' => '3',
    'percent_base' => 'direct_reward', 'levels' => [
        1 => ['percent' => '20', 'enabled' => '1'],
        2 => ['percent' => '10', 'enabled' => '1'],
        3 => ['percent' => '5', 'enabled' => '1'],
    ],
    'conditions' => ['joined'],
]);
T::ok($r['json']['ok'] ?? false, 'ذخیره تنظیمات پورسانت موفق بود');
$current = RB\Services\CommissionSettings::current($db, true);
T::eq(10000, $current->directReward, 'پاداش مستقیم در دیتابیس ثبت شد');
T::eq(3, $current->maxLevels, 'حداکثر لایه ذخیره شد');
T::eq('20', $current->levels[0]['percent'] ?? null, 'درصد لایه اول درست ذخیره شد');
$versions = (int) $db->value('SELECT COUNT(*) FROM commission_setting_versions');
T::ok($versions >= 1, 'نسخه تنظیمات پورسانت ساخته شد (نسخه‌بندی فعال است)');

// ---------- ۴) افزودن کانال اجباری + اعتبارسنجی ورودی
$r = act(RB\Controllers\Admin\ChannelController::class, 'save', [], [
    'title' => 'کانال اصلی', 'chat_id' => '@refrallbot_test', 'is_active' => '1', 'sort_order' => '1',
]);
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM telegram_channels WHERE chat_id = :c', ['c' => '@refrallbot_test']), 'کانال اجباری ثبت شد');
$r = act(RB\Controllers\Admin\ChannelController::class, 'save', [], ['title' => 'بد', 'chat_id' => 'kanal-bi-etiket']);
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM telegram_channels'), 'شناسه کانال نامعتبر ثبت نشد (اعتبارسنجی کار می‌کند)');

// ---------- ۵) اتصال ربات با توکن ساختگی (بدون تماس با تلگرام)
TelegramService::$fakeTransport = static fn (string $method, array $params): array => $method === 'getMe'
    ? ['ok' => true, 'result' => ['id' => 424242, 'is_bot' => true, 'first_name' => 'RefrallBot', 'username' => 'refrallbot_test_bot']]
    : ['ok' => true, 'result' => true];
$r = act(RB\Controllers\Admin\TelegramController::class, 'saveToken', [], [
    'bot_token' => '424242:AAFakeTokenForAutomatedTests_abcdefghijklmno',
    'base_url' => 'https://bot.example.test',
]);
T::ok($r['json']['ok'] ?? false, 'توکن ربات پذیرفته و اعتبارسنجی شد (با ترنسپورت ساختگی)');
$tgSettings = TelegramService::settings($db);
T::eq('refrallbot_test_bot', $tgSettings['bot_username'] ?? null, 'نام کاربری ربات از getMe ذخیره شد');
$rawRow = $db->first('SELECT bot_token_enc FROM telegram_settings ORDER BY id DESC LIMIT 1');
T::ok(str_starts_with((string) ($rawRow['bot_token_enc'] ?? ''), 'v1:'), 'توکن ربات به‌صورت رمزنگاری‌شده ذخیره شد');
T::ok(!str_contains((string) ($rawRow['bot_token_enc'] ?? ''), 'AAFakeToken'), 'توکن خام در دیتابیس نیست');

// ---------- ۶) تنظیم Webhook
$r = act(RB\Controllers\Admin\TelegramController::class, 'setWebhook');
T::ok($r['json']['ok'] ?? false, 'تنظیم Webhook موفق بود (ترنسپورت ساختگی)');
T::ok(!empty(TelegramService::settings($db)['webhook_active']), 'وضعیت Webhook در دیتابیس فعال شد');

// ---------- ۷) پیش‌نمایش پیام همگانی
$r = act(RB\Controllers\Admin\BroadcastController::class, 'preview', [], [
    'message_text' => 'سلام {name} عزیز!', 'segment' => 'all', 'segment_params' => '{}',
]);
T::ok($r['json']['ok'] ?? false, 'پیش‌نمایش پیام همگانی پاسخ ok داد');
T::ok(isset($r['json']['count']) && is_int($r['json']['count']), 'تعداد مخاطبان در پاسخ پیش‌نمایش برگشت');

// ---------- ۸) ارسال همگانی (صف ساخته می‌شود، ارسال واقعی انجام نمی‌شود)
$r = act(RB\Controllers\Admin\BroadcastController::class, 'send', [], [
    'title' => 'کمپین تست', 'message_text' => 'پیام تست همگانی', 'segment' => 'all', 'segment_params' => '{}',
]);
T::ok($r['json']['ok'] ?? false, 'ایجاد کمپین پیام همگانی موفق بود');
$campaignId = (int) ($r['json']['campaign']['id'] ?? $db->value('SELECT id FROM broadcast_campaigns ORDER BY id DESC LIMIT 1'));
T::ok($campaignId > 0, 'کمپین همگانی در دیتابیس ثبت شد');
T::ok((int) $db->value('SELECT COUNT(*) FROM broadcast_targets WHERE campaign_id = :c', ['c' => $campaignId]) > 0, 'مخاطبان در صف ارسال snapshot شدند');

// ---------- ۹) ایجاد کمپین جایزه از طریق کنترلر
$r = act(RB\Controllers\Admin\CampaignController::class, 'store', [], [
    'title' => 'کمپین دعوت زمستانه', 'type' => 'invite_count', 'description' => 'توضیح تست',
    'starts_at' => '', 'ends_at' => '', 'required_invites' => '2', 'fixed_reward' => '25,000',
    'max_winners' => '10', 'is_active' => '1', 'audience' => 'all',
]);
$newCampaign = $db->first('SELECT * FROM campaigns WHERE title = :t', ['t' => 'کمپین دعوت زمستانه']);
T::ok($newCampaign !== null, 'کمپین جدید از طریق فرم ساخته شد');
T::eq(25000, (int) ($newCampaign['fixed_reward'] ?? 0), 'مبلغ جایزه درست ذخیره شد');
T::eq(2, (int) ($newCampaign['required_invites'] ?? 0), 'شرط تعداد دعوت درست ذخیره شد');

// ---------- ۱۰) تنظیم مالی کاربر و ثبت در دفتر
$r = act(RB\Controllers\Admin\UserController::class, 'adjustWallet', ['id' => (string) $userId], [
    'amount' => '120,000', 'type' => 'credit', 'reason' => 'پاداش تبلیغاتی تست',
]);
$wallet = new WalletService($db);
$summary = $wallet->summary($userId);
T::eq(120000, (int) $summary['balance'], 'موجودی کاربر پس از اصلاح دستی درست است');
T::eq(1, (int) $db->value("SELECT COUNT(*) FROM wallet_transactions WHERE user_id = :u AND type = 'adjustment'", ['u' => $userId]), 'تراکنش تعدیل در دفتر ثبت شد');
T::ok(str_contains((string) $db->value("SELECT description FROM wallet_transactions WHERE user_id = :u AND type = 'adjustment'", ['u' => $userId]), 'پاداش تبلیغاتی'), 'دلیل تعدیل مالی در توضیح تراکنش ثبت شد');
T::ok((int) $db->value("SELECT COUNT(*) FROM admin_activity_logs WHERE admin_id = :a", ['a' => $adminId]) > 0, 'عملیات مدیر در لاگ فعالیت ثبت شد');

// ---------- ۱۱) اصلاح مالی بدون دلیل رد می‌شود
$before = $wallet->summary($userId)['balance'];
$_SERVER['HTTP_REFERER'] = 'https://actions.test/admin/users/' . $userId;
$r = act(RB\Controllers\Admin\UserController::class, 'adjustWallet', ['id' => (string) $userId], [
    'amount' => '999000', 'type' => 'credit', 'reason' => 'کم',
]);
T::eq($before, $wallet->summary($userId)['balance'], 'اصلاح مالی با دلیل کوتاه انجام نشد و موجودی تغییر نکرد');
T::ok(($r['redirect']['to'] ?? null) === '/admin/users/' . $userId, 'پس از رد، کاربر به صفحه کاربر redirect شد');

// ---------- ۱۲) تغییر وضعیت کاربر (مسدودسازی)
$r = act(RB\Controllers\Admin\UserController::class, 'updateStatus', ['id' => (string) $userId], [
    'status' => 'blocked', 'reason' => 'تست مسدودسازی',
]);
T::eq('blocked', $db->value('SELECT status FROM users WHERE id = :id', ['id' => $userId]), 'وضعیت کاربر به blocked تغییر کرد');
$r = act(RB\Controllers\Admin\UserController::class, 'updateStatus', ['id' => (string) $userId], [
    'status' => 'active', 'reason' => 'رفع مسدودی تست',
]);
T::eq('active', $db->value('SELECT status FROM users WHERE id = :id', ['id' => $userId]), 'وضعیت کاربر به active بازگشت');

// ---------- ۱۳) درخواست برداشت کاربر و تأیید مدیر
// حداقل برداشت در تست قبلی روی ۴۵٬۰۰۰ تنظیم شد
$request = (new RB\Services\WithdrawalService($db))->create(
    $userId, 50000, '6037701689095443', 'کاربر تست', '198.51.100.9'
);
T::ok(isset($request['id']), 'درخواست برداشت برای تست تأیید ساخته شد');
$requestId = (int) $request['id'];
$r = act(RB\Controllers\Admin\WithdrawalController::class, 'approve', ['id' => (string) $requestId]);
T::ok($r['json']['ok'] ?? false, 'تأیید درخواست برداشت از طریق کنترلر موفق بود');
T::eq('approved', $db->value('SELECT status FROM withdrawal_requests WHERE id = :id', ['id' => $requestId]), 'وضعیت درخواست approved شد');
$r = act(RB\Controllers\Admin\WithdrawalController::class, 'pay', ['id' => (string) $requestId], [
    'tracking_code' => '987654321', 'note' => 'پرداخت تست',
]);
T::ok($r['json']['ok'] ?? false, 'ثبت پرداخت از طریق کنترلر موفق بود');
T::eq('paid', $db->value('SELECT status FROM withdrawal_requests WHERE id = :id', ['id' => $requestId]), 'وضعیت درخواست paid شد');
T::eq(70000, (int) $wallet->summary($userId)['balance'], 'موجودی کاربر پس از پرداخت برداشت درست است (۱۲۰٬۰۰۰ − ۵۰٬۰۰۰)');
$match = $wallet->reconcile($userId);
T::ok($match['match'], 'دفتر مالی و موجودی کاربر پس از چرخه کامل هم‌خوان هستند');

// ---------- ۱۴) ردّ درخواست و بازگشت وجه
$request2 = (new RB\Services\WithdrawalService($db))->create(
    $userId, 45000, '6037701689095443', 'کاربر تست', '198.51.100.9'
);
$balanceAfterReserve = (int) $wallet->summary($userId)['balance'];
$r = act(RB\Controllers\Admin\WithdrawalController::class, 'reject', ['id' => (string) $request2['id']], [
    'reason' => 'شماره کارت با نام کاربر هم‌خوانی ندارد',
]);
T::ok($r['json']['ok'] ?? false, 'ردّ درخواست برداشت موفق بود');
T::eq($balanceAfterReserve + 45000, (int) $wallet->summary($userId)['balance'], 'مبلغ درخواست رد‌شده به موجودی بازگشت');

// ---------- ۱۵) ایجاد مدیر جدید و اعتبارسنجی رمز ضعیف
$r = act(RB\Controllers\Admin\AdminController::class, 'store', [], [
    'name' => 'مدیر دوم', 'username' => 'second_admin', 'password' => 'StrongPass1234', 'role_id' => (string) $roleId,
]);
T::ok($r['json']['ok'] ?? false, 'مدیر جدید ساخته شد');
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM admins WHERE username = :u', ['u' => 'second_admin']), 'مدیر جدید در دیتابیس موجود است');
$r = act(RB\Controllers\Admin\AdminController::class, 'store', [], [
    'name' => 'مدیر سوم', 'username' => 'third_admin', 'password' => 'weak', 'role_id' => (string) $roleId,
]);
T::eq(0, (int) $db->value('SELECT COUNT(*) FROM admins WHERE username = :u', ['u' => 'third_admin']), 'رمز ضعیف رد شد و مدیر ساخته نشد');

// ---------- ۱۶) بررسی امنیتی: CSRF
// Csrf::verify() در صورت توکن نامعتبر exit می‌کند (تا هیچ خروجی‌ای نشت نکند)،
// بنابراین مسیر پذیرش را با Middleware و مسیر رد را با Csrf::check() می‌سنجیم.
$_SESSION['_csrf_token'] = 'abc123';
$_POST = ['_csrf' => 'abc123'];
T::ok(RB\Support\Csrf::check(), 'Csrf::check با توکن صحیح true برمی‌گرداند');
T::ok(RB\Http\Middleware::csrf(), 'میدل‌ور CSRF درخواست با توکن صحیح را عبور می‌دهد');
$_POST = ['_csrf' => 'nope'];
T::ok(!RB\Support\Csrf::check(), 'Csrf::check با توکن اشتباه false برمی‌گرداند');
unset($_POST['_csrf']);
T::ok(!RB\Support\Csrf::check(), 'Csrf::check بدون توکن false برمی‌گرداند');
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'abc123';
T::ok(RB\Support\Csrf::check(), 'توکن CSRF از هدر X-CSRF-Token هم پذیرفته می‌شود');
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
$_POST = ['_csrf' => (string) $_SESSION['_csrf_token']];
T::eq('سامانه تست عملیات', SettingsService::get('app_name'), 'نام سامانه واقعاً در دیتابیس ذخیره شد');
T::ok(noPhpNotice($r['out']), 'ذخیره تنظیمات بدون هشدار PHP اجرا شد');

// ---------- ۱۷) بازیابی ترنسپورت واقعی
TelegramService::$fakeTransport = null;
T::ok(TelegramService::$fakeTransport === null, 'ترنسپورت تلگرام به حالت واقعی برگشت');

exit(T::summary());
