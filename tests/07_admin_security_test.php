<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\AdminAuth;
use RB\Services\AuditService;
use RB\Services\SecurityService;
use RB\Services\UserService;
use RB\Support\Database;

T::suite('پنل مدیریت، احراز هویت و امنیت');

$db = Database::instance();
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$_SERVER['HTTP_USER_AGENT'] = 'RefrallBot-Test/1.0';

// ---------- نقش‌ها (نصب‌کننده این‌ها را می‌سازد؛ برای تست دیتابیس تازه، اینجا درج می‌شوند)
if (!(int) $db->value("SELECT id FROM admin_roles WHERE slug = 'superadmin'")) {
    $db->insert('admin_roles', [
        'name' => 'مدیر کل',
        'slug' => 'superadmin',
        'permissions' => json_encode(['*'], JSON_UNESCAPED_UNICODE),
        'description' => 'دسترسی کامل',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
$roles = $db->all('SELECT * FROM admin_roles ORDER BY id');
T::ok(count($roles) >= 1, 'نقش‌های مدیریتی تعریف شده‌اند (' . count($roles) . ')');
$superRoleId = (int) $db->value("SELECT id FROM admin_roles WHERE slug = 'superadmin'");
T::ok($superRoleId > 0, 'نقش superadmin وجود دارد');

// ---------- ساخت مدیر با رمز قوی
$username = 'admin_test_1';
$password = 'Str0ngPassw0rd!';
$db->delete('admins', 'username = :u', ['u' => $username]);
$adminId = $db->insert('admins', [
    'name' => 'مدیر تست',
    'username' => $username,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'role_id' => $superRoleId,
    'is_active' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);
T::ok($adminId > 0, 'مدیر با رمز هش‌شده ساخته شد');
$stored = (string) $db->value('SELECT password_hash FROM admins WHERE id = :id', ['id' => $adminId]);
T::ok($stored !== $password, 'رمز عبور به‌صورت متن ساده ذخیره نشده');
T::ok(str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon'), 'الگوریتم هش معتبر است (bcrypt/argon)');
T::ok(password_verify($password, $stored), 'بررسی رمز صحیح موفق است');
T::eq(false, password_verify('wrongpass', $stored), 'رمز اشتباه تأیید نمی‌شود');

// ---------- ورود موفق
$_SESSION = [];
$login = AdminAuth::attempt($username, $password, '203.0.113.10');
T::eq(true, $login['ok'], 'ورود با رمز درست موفق است');
T::eq($adminId, (int) ($_SESSION['admin_id'] ?? 0), 'شناسه مدیر در سشن ثبت شد');
T::ok(AdminAuth::validateSession(), 'سشن مدیر معتبر است');
$current = AdminAuth::current();
T::eq('superadmin', (string) ($current['role_slug'] ?? ''), 'نقش مدیر جاری superadmin است');
T::eq(true, AdminAuth::requireSuperAdmin(), 'دسترسی مدیر کل تأیید می‌شود');

// ---------- رمز اشتباه
$_SESSION = [];
$badLogin = AdminAuth::attempt($username, 'wrong-password', '203.0.113.10');
T::eq(false, $badLogin['ok'], 'ورود با رمز اشتباه رد می‌شود');
T::ok(str_contains((string) $badLogin['error'], 'نادرست'), 'پیام خطای ورود گمراه‌کننده نیست');

// ---------- قفل شدن پس از تلاش‌های ناموفق
for ($i = 0; $i < AdminAuth::MAX_ATTEMPTS; $i++) {
    AdminAuth::attempt($username, 'wrong-' . $i, '198.51.100.7');
}
$locked = AdminAuth::attempt($username, $password, '198.51.100.7');
T::eq(false, $locked['ok'], 'پس از ' . AdminAuth::MAX_ATTEMPTS . ' تلاش ناموفق، ورود قفل می‌شود');
T::ok(str_contains((string) $locked['error'], 'تلاش‌های ناموفق'), 'پیام قفل بودن حساب نمایش داده می‌شود');
T::ok((int) $db->value('SELECT COUNT(*) FROM login_attempts') > 0, 'تلاش‌های ناموفق ثبت می‌شوند');

// ---------- لاگ فعالیت مدیران
$countBefore = (int) $db->value('SELECT COUNT(*) FROM admin_activity_logs');
AuditService::log('test.action', 'user', 1, ['note' => 'تست'], $adminId);
$countAfter = (int) $db->value('SELECT COUNT(*) FROM admin_activity_logs');
T::eq($countBefore + 1, $countAfter, 'فعالیت مدیر در لاگ ثبت می‌شود');
$log = $db->first('SELECT * FROM admin_activity_logs ORDER BY id DESC LIMIT 1');
T::eq('test.action', $log['action'], 'نوع اقدام درست ثبت شد');
T::ok(!empty($log['admin_name']), 'نام مدیر برای خوانایی لاگ ذخیره می‌شود');

// ---------- مدیریت کاربران
$users = new UserService($db);
$target = $users->registerFromTelegram(['id' => 810000001, 'is_bot' => false, 'first_name' => 'کاربر', 'last_name' => 'آزمایشی'])['user'];
$list = $users->paginate(['q' => 'آزمایشی'], 1, 10);
T::ok($list['total'] >= 1, 'جستجوی کاربران در پنل مدیریت کار می‌کند');
$users->setStatus((int) $target['id'], 'blocked', $adminId, 'تست مسدودسازی');
T::eq('blocked', $db->value('SELECT status FROM users WHERE id = :id', ['id' => (int) $target['id']]), 'مسدودسازی کاربر ثبت شد');
$users->setStatus((int) $target['id'], 'active', $adminId, 'رفع مسدودی');
T::eq('active', $db->value('SELECT status FROM users WHERE id = :id', ['id' => (int) $target['id']]), 'رفع مسدودی کاربر ثبت شد');

// ---------- تغییر معرف با دلیل (فقط مدیر)
$ref1 = $users->registerFromTelegram(['id' => 810000002, 'is_bot' => false, 'first_name' => 'معرف۱'])['user'];
$ref2 = $users->registerFromTelegram(['id' => 810000003, 'is_bot' => false, 'first_name' => 'معرف۲'])['user'];
$child = $users->registerFromTelegram(['id' => 810000004, 'is_bot' => false, 'first_name' => 'فرزند'], $ref1['referral_code'])['user'];
T::eq((int) $ref1['id'], (int) $db->value('SELECT referred_by FROM users WHERE id = :id', ['id' => (int) $child['id']]), 'معرف اولیه ثبت شد');
T::throws(static function () use ($users, $child, $ref2) {
    $users->changeReferrer((int) $child['id'], (int) $ref2['id'], 'کم', null);
}, 'دلیل کوتاه برای تغییر معرف پذیرفته نمی‌شود');
$users->changeReferrer((int) $child['id'], (int) $ref2['id'], 'اصلاح اشتباه کاربر در انتخاب لینک', $adminId);
T::eq((int) $ref2['id'], (int) $db->value('SELECT referred_by FROM users WHERE id = :id', ['id' => (int) $child['id']]), 'تغییر معرف توسط مدیر اعمال شد');

// ---------- تشخیص تقلب: چند حساب با IP یکسان
$s = new UserService($db);
$ip = '203.0.113.55';
for ($i = 0; $i < 4; $i++) {
    $u = $s->registerFromTelegram(['id' => 820000000 + $i, 'is_bot' => false, 'first_name' => 'هم‌IP'], null, ['ip' => $ip])['user'];
    SecurityService::evaluateUser((int) $u['id']);
}
T::ok((int) $db->value('SELECT COUNT(*) FROM security_events') > 0, 'رویداد امنیتی ثبت شد');
$sameIp = (int) $db->value("SELECT COUNT(*) FROM security_events WHERE type IN ('multiple_accounts_same_ip','signup_burst','duplicate_names','auto_flagged')");
T::ok($sameIp > 0, 'الگوی چند حساب مشابه شناسایی شد');
T::ok((int) $db->value('SELECT COUNT(*) FROM users WHERE risk_score > 0') > 0, 'امتیاز ریسک کاربران افزایش یافت');

// ---------- کارت تکراری: دو درخواست برداشت واقعی با یک کارت از دو کاربر
$wallet = new RB\Services\WalletService($db);
$withdrawals = new RB\Services\WithdrawalService($db);
$card = '6037701689095443';
foreach ([$target, $ref1] as $u) {
    $wallet->credit((int) $u['id'], 'commission_direct', 80000, ['idempotency_key' => 'card-test:' . $u['id']]);
    $withdrawals->create((int) $u['id'], 50000, $card, 'صاحب کارت تست', '203.0.113.10');
}
T::ok((int) $db->value("SELECT COUNT(*) FROM security_events WHERE type = 'card_reuse'") > 0, 'استفاده مجدد از یک کارت شناسایی شد');
T::eq(2, (int) $db->value('SELECT COUNT(DISTINCT user_id) FROM withdrawal_requests WHERE card_last4 = :l', ['l' => substr($card, -4)]), 'دو کاربر با یک کارت ثبت درخواست کرده‌اند');

// ---------- ارجاع به خود و حلقه
T::throws(static function () use ($users, $child) {
    $users->changeReferrer((int) $child['id'], (int) $child['id'], 'تلاش برای ارجاع به خود', null);
}, 'ارجاع کاربر به خودش رد می‌شود');
T::throws(static function () use ($users, $child, $ref2) {
    $users->changeReferrer((int) $ref2['id'], (int) $child['id'], 'ایجاد حلقه در شبکه', null);
}, 'ایجاد حلقه در شبکه رد می‌شود');

// ---------- جلوگیری از SQL Injection در جستجو
$payloads = ["' OR 1=1 --", "'; DROP TABLE users; --", "1' UNION SELECT * FROM admins --"];
foreach ($payloads as $payload) {
    $res = $users->paginate(['q' => $payload], 1, 5);
    T::eq(0, $res['total'], 'ورودی مخرب نتیجه نمی‌دهد: ' . mb_substr($payload, 0, 24));
}
T::ok($db->tableExists('users'), 'جدول users پس از تلاش تزریق سالم است');
T::ok($db->tableExists('admins'), 'جدول admins پس از تلاش تزریق سالم است');

// ---------- امنیت خروجی (XSS)
T::eq('&lt;img src=x onerror=alert(1)&gt;', e('<img src=x onerror=alert(1)>'), 'خروجی HTML امن است');

// ---------- سقف پارامترهای مجاز در گزارش
T::ok(is_file(RB_ROOT . '/src/Controllers/Admin/ReportController.php'), 'کنترلر گزارش‌ها موجود است');
require_once RB_ROOT . '/src/Controllers/Admin/ReportController.php';
T::ok(array_key_exists('transactions', RB\Controllers\Admin\ReportController::TYPES), 'نوع گزارش تراکنش تعریف شده');
T::ok(count(RB\Controllers\Admin\ReportController::TYPES) >= 8, 'حداقل ۸ نوع گزارش تعریف شده');

// ---------- جستجوی تاریخ شمسی
T::eq('2024-03-20', RB\Support\Jalali::jalaliStringToGregorian('1403/01/01'), 'تبدیل تاریخ شمسی به میلادی درست است');
T::eq('۱۴۰۳/۰۱/۰۱', RB\Support\Jalali::toJalaliString('2024-03-20'), 'تبدیل میلادی به شمسی درست است');

// ---------- آی‌پی: هدر پروکسی فقط از مبدأ مطمئن پذیرفته می‌شود
$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
RB\Support\Env::set('TRUSTED_PROXIES', '');
T::eq('198.51.100.20', RB\Support\Request::ip(), 'بدون TRUSTED_PROXIES هدر X-Forwarded-For نادیده گرفته می‌شود (ضدجعل)');
RB\Support\Env::set('TRUSTED_PROXIES', '10.0.0.0/8');
T::eq('198.51.100.20', RB\Support\Request::ip(), 'مبدأ غیرمطمئن همچنان هدر را جعل‌شده می‌بیند');
RB\Support\Env::set('TRUSTED_PROXIES', '198.51.100.0/24');
T::eq('1.2.3.4', RB\Support\Request::ip(), 'با پروکسی مطمئن، آی‌پی واقعی کاربر خوانده می‌شود');
T::ok(RB\Support\Request::ipInCidr('192.168.1.7', '192.168.1.0/24'), 'بررسی CIDR برای IPv4 درست است');
T::ok(!RB\Support\Request::ipInCidr('192.168.2.7', '192.168.1.0/24'), 'آی‌پی خارج از محدوده CIDR رد می‌شود');
T::ok(RB\Support\Request::ipInCidr('2001:db8::1', '2001:db8::/32'), 'بررسی CIDR برای IPv6 درست است');
RB\Support\Env::set('TRUSTED_PROXIES', '');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);


exit(T::summary());
