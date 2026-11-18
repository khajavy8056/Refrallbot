<?php
/**
 * تست کرون وب — وظیفه‌های زمان‌بندی‌شده بدون دسترسی به crontab.
 *
 * روی هاست اشتراکی معمولاً کاربر نمی‌تواند Cron بسازد؛ این تست اطمینان می‌دهد
 * مسیر /cron/run با توکن کار می‌کند، بدون توکن رد می‌شود و هیچ خطای PHP نمی‌دهد.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\CronService;
use RB\Services\SettingsService;
use RB\Support\Database;

T::suite('کرون وب: اجرای وظایف زمان‌بندی‌شده از طریق HTTP');

$db = Database::instance();

// ---------- ۱) توکن
$token = CronService::webToken(true);
T::ok($token !== '', 'توکن کرون در اولین درخواست ساخته می‌شود');
T::eq(48, strlen($token), 'طول توکن ۴۸ کاراکتر هگز است (۲۴ بایت تصادفی)');
T::eq($token, CronService::webToken(), 'توکن پایدار می‌ماند (دوباره ساخته نمی‌شود)');
T::ok(ctype_xdigit($token), 'توکن فقط از کاراکترهای هگز ساخته شده');

// ---------- ۲) وظیفه‌های مجاز
T::ok(in_array('all', CronService::TASKS, true), 'وظیفه all تعریف شده');
T::ok(in_array('broadcast', CronService::TASKS, true), 'وظیفه broadcast تعریف شده');
T::ok(in_array('campaigns', CronService::TASKS, true), 'وظیفه campaigns تعریف شده');
T::ok(in_array('maintenance', CronService::TASKS, true), 'وظیفه maintenance تعریف شده');
T::ok(in_array('backup', CronService::TASKS, true), 'وظیفه backup تعریف شده');
T::ok(in_array('security', CronService::TASKS, true), 'وظیفه security تعریف شده');
T::ok(!in_array('drop_tables', CronService::TASKS, true), 'وظیفه ناشناخته در فهرست نیست');

// ---------- ۳) اجرای واقعی وظیفه نگهداری
$service = new CronService($db);
$result = $service->run('maintenance');
T::ok($result['ok'], 'وظیفه نگهداری بدون خطا اجرا شد');
T::ok(is_array($result['output']) && count($result['output']) > 0, 'گزارش متنی از کار انجام‌شده برگشت');
T::ok($result['ms'] >= 0, 'زمان اجرا گزارش می‌شود');

// ---------- ۴) وظیفه نامعتبر باید رد شود (بدون اجرای هیچ کار)
$bad = $service->run('rm_rf');
T::ok(!$bad['ok'], 'وظیفه ناشناخته رد می‌شود');
T::eq(0, count($bad['output']) > 0 && str_contains($bad['output'][0], 'ناشناخته') ? 0 : 1, 'پیام «وظیفه ناشناخته» برگشت');

// ---------- ۵) endpoint از طریق روتر — توکن درست
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cron/run';
$_SERVER['HTTP_HOST'] = 'cron.test';
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_GET = ['token' => $token, 'task' => 'maintenance'];
$_POST = [];
$_SESSION = [];
ob_start();
try {
    $router = new RB\Http\Router();
    (require RB_ROOT . '/src/routes.php')($router);
    $router->dispatch();
} catch (Throwable $e) {
    // در CLI هدرها ممکن است ارسال شده باشند؛ نادیده بگیر
}
$body = (string) ob_get_clean();
$json = json_decode($body, true);
T::ok(is_array($json), 'پاسخ کرون JSON معتبر است');
T::ok(($json['ok'] ?? false) === true, 'با توکن صحیح، اجرا موفق گزارش می‌شود');
T::eq('maintenance', (string) ($json['task'] ?? ''), 'نام وظیفه در پاسخ هست');
T::ok(str_contains($body, 'ms'), 'زمان اجرا در پاسخ هست');
// توکن نباید در پاسخ تکرار شود
T::ok(!str_contains($body, $token), 'توکن در پاسخ بازتاب داده نمی‌شود');

// ---------- ۶) توکن اشتباه → رد
$_GET = ['token' => 'wrong-token', 'task' => 'maintenance'];
ob_start();
try {
    $router = new RB\Http\Router();
    (require RB_ROOT . '/src/routes.php')($router);
    $router->dispatch();
} catch (Throwable $e) {
}
$body2 = (string) ob_get_clean();
$json2 = json_decode($body2, true);
T::ok(is_array($json2), 'پاسخ رد درخواست هم JSON است');
T::ok(($json2['ok'] ?? true) === false, 'توکن اشتباه رد می‌شود');
T::ok(str_contains((string) ($json2['error'] ?? ''), 'نامعتبر'), 'پیام خطای توکن نامعتبر برگشت');
T::ok(!str_contains($body2, 'ارسال همگانی'), 'هیچ کاری با توکن اشتباه اجرا نشد');

// ---------- ۷) بدون توکن → رد
$_GET = ['task' => 'all'];
ob_start();
try {
    $router = new RB\Http\Router();
    (require RB_ROOT . '/src/routes.php')($router);
    $router->dispatch();
} catch (Throwable $e) {
}
$body3 = (string) ob_get_clean();
$json3 = json_decode($body3, true);
T::ok(is_array($json3) && ($json3['ok'] ?? true) === false, 'درخواست بدون توکن رد می‌شود');

// ---------- ۸) پیام خطای PHP نباید نشت کند
T::ok(!str_contains($body2 . $body3, 'Stack trace'), 'هیچ stack trace در پاسخ نیست');
T::ok(!str_contains($body2 . $body3, 'storage/'), 'مسیرهای داخلی سرور نشت نمی‌کنند');

// ---------- ۹) اسکریپت خط فرمان هم همان سرویس را استفاده می‌کند
$cronSrc = (string) file_get_contents(RB_ROOT . '/bin/cron.php');
T::ok(str_contains($cronSrc, 'CronService'), 'bin/cron.php از سرویس مشترک استفاده می‌کند (منطق تکراری ندارد)');
T::ok(str_contains($cronSrc, '/cron/run'), 'اسکریپت خط فرمان روش کرون وب را مستند کرده');
T::ok(is_file(RB_ROOT . '/src/Services/CronService.php'), 'فایل CronService موجود است');

// ---------- ۱۰) توکن در تنظیمات ذخیره شده (پس از ری‌استارت هم پایدار)
SettingsService::flush();
T::eq($token, (string) SettingsService::get('cron_token'), 'توکن در دیتابیس ذخیره شده و پس از پاک‌شدن کش هم می‌ماند');

exit(T::summary());
