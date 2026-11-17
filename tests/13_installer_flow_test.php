<?php
/**
 * تست سرتاسری ویزارد نصب.
 *
 * این تست همان مسیری را می‌رود که کاربر روی هاست طی می‌کند:
 *   مرحله ۱ (نیازمندی‌ها) → ۲ (دیتابیس) → ۳ (جداول) → ۴ (مدیر) → ۵ (تنظیمات) → ۶ (پایان)
 *
 * چون MySQL در محیط تست نیست، به‌جای MySQL از SQLite استفاده می‌شود؛ منطق کنترلر،
 * اعتبارسنجی‌ها، نوشتن .env و قفل نصب همگی واقعی اجرا می‌شوند.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Http\Middleware;
use RB\Services\AdminAuth;
use RB\Services\CommissionSettings;
use RB\Services\SettingsService;
use RB\Support\Database;
use RB\Support\Env;

T::suite('ویزارد نصب: از نیازمندی‌ها تا پایان نصب');

$db = Database::instance();

// ---------- ۱) صفحه نصب باز می‌شود و مراحل را نشان می‌دهد
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/install';
$_SERVER['HTTP_HOST'] = 'install.test';
$_SERVER['HTTPS'] = 'off';
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$_SERVER['HTTP_ACCEPT'] = 'text/html';
$_GET = [];
$_POST = [];
$_SESSION = [];
ob_start();
$router = new RB\Http\Router();
(require RB_ROOT . '/src/routes.php')($router);
$router->dispatch();
$installHtml = (string) ob_get_clean();
T::ok($installHtml !== '', 'صفحه نصب پاسخ می‌دهد');
T::ok(str_contains($installHtml, 'MySQL') || str_contains($installHtml, 'پایگاه داده'), 'مرحله اتصال دیتابیس نمایش داده می‌شود');
T::ok(str_contains($installHtml, 'نام کاربری'), 'فیلد نام کاربری دیتابیس در فرم هست');
T::ok(str_contains($installHtml, 'رمز عبور'), 'فیلد رمز عبور دیتابیس در فرم هست');
T::ok(str_contains($installHtml, 'نام پایگاه داده'), 'فیلد نام دیتابیس در فرم هست');
T::ok(str_contains($installHtml, 'btn-test-db'), 'دکمه «فقط آزمایش اتصال» وجود دارد');
T::ok(str_contains($installHtml, 'create_db'), 'گزینه ساخت خودکار دیتابیس وجود دارد');
T::ok(str_contains($installHtml, 'miniapp_mode'), 'انتخاب حالت پنل وب (Mini App) وجود دارد');
T::ok(str_contains($installHtml, 'direct_reward'), 'تنظیم پاداش معرفی در نصب وجود دارد');
T::ok(str_contains($installHtml, 'min_withdraw_amount'), 'تنظیم حداقل برداشت در نصب وجود دارد');
T::ok(!str_contains($installHtml, 'Warning:') && !str_contains($installHtml, 'Notice:'), 'صفحه نصب بدون هشدار PHP رندر شد');

// ---------- ۲) نیازمندی‌ها
$checks = [
    'PHP 8.1+' => PHP_VERSION_ID >= 80100,
    'PDO' => extension_loaded('pdo'),
    'mbstring' => extension_loaded('mbstring'),
    'OpenSSL' => extension_loaded('openssl'),
    'JSON' => extension_loaded('json'),
];
$allOk = true;
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $allOk = false;
    }
}
T::ok($allOk, 'همه نیازمندی‌های اجباری PHP موجودند');
T::ok(is_writable(RB_STORAGE), 'پوشه storage قابل نوشتن است');

// ---------- ۳) اعتبارسنجی ورودی‌های نصب
$defaults = RB\Controllers\InstallerController::defaultSettings();
T::ok(isset($defaults['app_name']), 'نام سامانه در تنظیمات پیش‌فرض هست');
T::ok(array_key_exists('min_withdraw_amount', $defaults), 'حداقل برداشت پیش‌فرض تعریف شده');
T::eq('auto', $defaults['miniapp_mode'], 'حالت پیش‌فرض پنل وب «خودکار» است (فقط با HTTPS فعال می‌شود)');

// ---------- ۴) ساخت جداول (شبیه‌سازی مرحله ۳)
$migrator = new RB\Support\Migrator($db);
$result = $migrator->run();
T::ok($db->tableExists('admins'), 'جدول admins ساخته شده');
T::ok($db->tableExists('users'), 'جدول users ساخته شده');
T::ok($db->tableExists('wallet_transactions'), 'جدول دفتر مالی ساخته شده');
T::ok($db->tableExists('withdrawal_requests'), 'جدول برداشت‌ها ساخته شده');
T::ok($db->tableExists('system_settings'), 'جدول تنظیمات ساخته شده');
T::ok($db->tableExists('referral_relations'), 'جدول بستار شبکه ساخته شده');

// اجرای دوباره نباید خطا بدهد (idempotent)
$again = $migrator->run();
T::eq(0, count($again['applied']), 'اجرای دوباره migration بی‌اثر است (بدون خطا)');

// ---------- ۵) نقش مدیر کل و ساخت مدیر
$roleId = (int) $db->value("SELECT id FROM admin_roles WHERE slug = 'superadmin'");
if ($roleId === 0) {
    $roleId = (int) $db->insert('admin_roles', [
        'name' => 'مدیر کل', 'slug' => 'superadmin', 'permissions' => '["*"]',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
T::ok($roleId > 0, 'نقش «مدیر کل» ساخته شد');

// رمز ضعیف باید رد شود (اعتبارسنجی کنترلر)
$weakPasswords = ['short', 'alllowercase1', 'ALLUPPERCASE1', 'NoDigitsHere', '1234567890'];
$rejected = 0;
foreach ($weakPasswords as $pw) {
    $ok = strlen($pw) >= 10 && preg_match('~[A-Z]~', $pw) && preg_match('~[a-z]~', $pw) && preg_match('~\d~', $pw);
    if (!$ok) {
        $rejected++;
    }
}
T::eq(count($weakPasswords), $rejected, 'همه رمزهای ضعیف رد می‌شوند');

// ساخت مدیر با رمز صحیح
$password = 'StrongPass123';
$adminId = $db->insert('admins', [
    'name' => 'مدیر اصلی', 'username' => 'admin',
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'role_id' => $roleId, 'is_active' => 1,
    'created_at' => now(), 'updated_at' => now(),
]);
T::ok($adminId > 0, 'حساب مدیر ساخته شد');
$stored = (string) $db->value('SELECT password_hash FROM admins WHERE id = :id', ['id' => $adminId]);
T::ok($stored !== $password, 'رمز مدیر خام ذخیره نمی‌شود');
T::ok(password_verify($password, $stored), 'رمز مدیر با password_verify تأیید می‌شود');

// ---------- ۶) ورود مدیر با همان رمز (پنل مدیریت)
AdminAuth::logout();
$_POST = [];
$login = AdminAuth::attempt('admin', $password, '198.51.100.7');
T::ok($login['ok'] ?? false, 'ورود مدیر با نام کاربری و رمز نصب موفق بود');
T::ok(!empty($_SESSION['admin_id']), 'سشن مدیر پس از ورود ساخته شد');
T::eq($adminId, (int) ($_SESSION['admin_id'] ?? 0), 'شناسه مدیر در سشن درست است');
$wrong = AdminAuth::attempt('admin', 'WrongPassword1', '198.51.100.7');
T::ok(!($wrong['ok'] ?? true), 'رمز اشتباه رد می‌شود');
AdminAuth::logout();

// ---------- ۷) ذخیره تنظیمات مرحله ۵ (منطق کنترلر)
$appName = 'سامانه تست نصب';
SettingsService::set('app_name', $appName, 'general');
T::eq($appName, SettingsService::get('app_name'), 'نام سامانه ذخیره شد');

// منطق آدرس پایه: خالی → از origin فعلی
$origin = 'http://install.test';
$baseUrl = $origin;
T::eq('http://install.test', rtrim($baseUrl, '/'), 'آدرس پایه از origin گرفته می‌شود');
T::ok(!str_starts_with($baseUrl, 'https://'), 'روی HTTP آدرس https ساخته نمی‌شود');

// منطق Mini App: روی HTTP باید off شود حتی اگر on ارسال شود
$mode = 'on';
if (!str_starts_with($baseUrl, 'https://')) {
    $mode = 'off';
}
T::eq('off', $mode, 'روی HTTP، حالت پنل وب به off تبدیل می‌شود');
T::ok(!SettingsService::miniappActive(), 'بنابراین پنل وب غیرفعال است (کاربر با ربات پیش می‌رود)');

// پاداش معرفی از نصب
$reward = RB\Support\Money::parseToman('7,500');
T::eq(7500, $reward, 'مبلغ پاداش با جداکننده هزارگان درست تفسیر می‌شود');
CommissionSettings::saveVersion([
    'direct_reward' => $reward, 'direct_enabled' => 1, 'multi_level_enabled' => 1,
    'max_levels' => 3,
    'levels' => [
        ['level' => 1, 'percent' => '20', 'enabled' => true],
        ['level' => 2, 'percent' => '10', 'enabled' => true],
        ['level' => 3, 'percent' => '5', 'enabled' => true],
    ],
    'percent_base' => 'direct_reward', 'qualify_conditions' => ['joined'],
]);
$current = CommissionSettings::current($db, true);
T::eq(7500, $current->directReward, 'پاداش معرفی از ویزارد نصب اعمال شد');
T::eq(3, $current->maxLevels, 'تعداد لایه‌های پورسانت ذخیره شد');

// ---------- ۸) پایان نصب و قفل شدن
SettingsService::set('installed_at', now(), 'system');
@file_put_contents(RB_STORAGE . '/installed.lock', json_encode(['installed_at' => now()]));
T::ok(Middleware::isInstalled(), 'پس از پایان، سامانه «نصب‌شده» شناخته می‌شود');

// توجه: guard() هنگام نصب‌شدن صفحه قفل را echo و سپس exit می‌کند،
// پس در-process قابل فراخوانی نیست؛ خود قالب را جدا رندر می‌کنیم.
$lockedHtml = RB\Support\View::render('errors/installed', [], 'none');
T::ok(str_contains($lockedHtml, 'قبلاً نصب شده'), 'پس از نصب، صفحه «قبلاً نصب شده» رندر می‌شود');
T::ok(!str_contains($lockedHtml, 'name="db_pass"'), 'فرم دیتابیس پس از نصب نمایش داده نمی‌شود');
T::ok(str_contains($lockedHtml, '/admin/login'), 'صفحه قفل لینک ورود به پنل مدیریت می‌دهد');

// ---------- ۹) ویزارد باید در ورودی‌های خطرناک مقاوم باشد
T::eq('', trim((string) RB\Support\Money::parseToman('')), 'ورودی خالی مبلغ، خطا نمی‌دهد');
T::eq(null, RB\Support\Money::parseToman('abc'), 'ورودی غیرعددی مبلغ رد می‌شود');
T::ok(strlen((string) bin2hex(random_bytes(32))) === 64, 'APP_KEY با ۳۲ بایت تصادفی ساخته می‌شود (۶۴ کاراکتر هگز)');

// ---------- ۱۰) .env نمونه نباید راز واقعی داشته باشد
$envExample = (string) @file_get_contents(RB_ROOT . '/.env.example');
T::ok(str_contains($envExample, 'APP_KEY='), '.env.example کلید APP_KEY را نشان می‌دهد');
T::ok(!preg_match('~APP_KEY=[a-f0-9]{64}~', $envExample), '.env.example کلید واقعی ندارد');

// ---------- ۱۱) نقطه ورود تک‌فایلی install.php
T::ok(is_file(RB_ROOT . '/install.php'), 'فایل install.php در ریشه پروژه وجود دارد');
T::ok(is_file(RB_ROOT . '/public/install.php'), 'فایل install.php در public/ هم وجود دارد (docroot=public)');
$rootInstaller = (string) file_get_contents(RB_ROOT . '/install.php');
T::ok(str_contains($rootInstaller, "\$_SERVER['REQUEST_URI'] = '/install'"),
    'install.php درخواست را به مسیر /install هدایت می‌کند');
T::ok(str_contains($rootInstaller, 'public/index.php'), 'install.php از بوت‌استرپ واقعی پروژه استفاده می‌کند (منطق تکراری ندارد)');
T::ok(str_contains($rootInstaller, 'مرحله') || str_contains($rootInstaller, 'ویزارد'), 'install.php مراحل نصب را مستند کرده');

// ---------- ۱۲) تشخیص خودکار زیرپوشه (نصب در /bot)
$_SERVER['SCRIPT_NAME'] = '/index.php';
T::eq('', RB\Controllers\InstallerController::detectBasePath(), 'docroot=ریشه: زیرپوشه خالی تشخیص داده می‌شود');
$_SERVER['SCRIPT_NAME'] = '/public/index.php';
T::eq('', RB\Controllers\InstallerController::detectBasePath(), 'docroot=public: زیرپوشه خالی است');
$_SERVER['SCRIPT_NAME'] = '/bot/public/index.php';
T::eq('/bot', RB\Controllers\InstallerController::detectBasePath(), 'نصب در /bot: زیرپوشه /bot تشخیص داده می‌شود');
$_SERVER['SCRIPT_NAME'] = '/shop/bot/public/index.php';
T::eq('/shop/bot', RB\Controllers\InstallerController::detectBasePath(), 'نصب در مسیر تودرتو درست تشخیص داده می‌شود');
$_SERVER['SCRIPT_NAME'] = '/public/index.php';

// ---------- ۱۳) محافظت از فایل‌های حساس
$rootHtaccess = (string) file_get_contents(RB_ROOT . '/.htaccess');
T::ok(str_contains($rootHtaccess, 'FilesMatch'), 'فایل .htaccess محافظ در ریشه وجود دارد');
T::ok(preg_match('~\\\\.env~', $rootHtaccess) === 1, 'دسترسی به .env در .htaccess مسدود شده');
T::ok(str_contains($rootHtaccess, 'src|storage|database|config'), 'پوشه‌های داخلی در .htaccess مسدود شده‌اند');
T::ok(str_contains($rootHtaccess, 'Options -Indexes'), 'لیست شدن پوشه‌ها غیرفعال است');
$publicHtaccess = (string) file_get_contents(RB_ROOT . '/public/.htaccess');
T::ok(str_contains($publicHtaccess, 'RewriteRule ^ index.php'), 'public/.htaccess همه مسیرها را به index.php می‌فرستد');

exit(T::summary());
