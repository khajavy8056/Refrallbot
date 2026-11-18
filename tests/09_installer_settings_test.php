<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\CommissionSettings;
use RB\Services\CommissionSettingsService;
use RB\Services\RankingService;
use RB\Services\SettingsService;
use RB\Support\Crypto;
use RB\Support\Database;
use RB\Support\Money;

T::suite('نصب، تنظیمات قابل تغییر و فرمت پول');

$db = Database::instance();

// ---------- بررسی نیازمندی‌های نصب
$requirements = [
    'PHP >= 8.1' => PHP_VERSION_ID >= 80100,
    'PDO' => extension_loaded('pdo'),
    'mbstring' => extension_loaded('mbstring'),
    'OpenSSL' => extension_loaded('openssl'),
];
foreach ($requirements as $label => $ok) {
    T::ok($ok, 'نیازمندی نصب: ' . $label);
}
T::ok(is_file(RB_VIEWS . '/install/index.php'), 'صفحه ویزارد نصب موجود است');
T::ok(is_file(RB_ROOT . '/src/Controllers/InstallerController.php'), 'کنترلر نصب موجود است');
T::ok(method_exists(RB\Controllers\InstallerController::class, 'createAdmin'), 'ساخت اولین مدیر در ویزارد وجود دارد');
$middlewareSrc = (string) file_get_contents(RB_ROOT . '/src/Http/Middleware.php');
T::ok(str_contains($middlewareSrc, 'isInstalled') && str_contains($middlewareSrc, "'installed_at'"),
    'پس از نصب، ویزارد نصب قفل می‌شود (کلید installed_at)');
T::eq(false, RB\Http\Middleware::isInstalled(), 'در دیتابیس تست، نصب‌شده علامت نخورده است');
SettingsService::set('installed_at', now(), 'system');
T::eq(true, RB\Http\Middleware::isInstalled(), 'با ثبت installed_at، سامانه نصب‌شده شناخته می‌شود');
$db->delete('system_settings', "`key` = :k", ['k' => 'installed_at']);

// ---------- پول: تومان صحیح، بدون اعشار
T::eq(50000, Money::parseToman('50,000'), 'پردازش مبلغ با جداکننده هزارگان');
T::eq(50000, Money::parseToman('۵۰٬۰۰۰'), 'پردازش مبلغ با ارقام فارسی');
T::eq(50000, Money::parseToman('50000 تومان'), 'پردازش مبلغ با واحد');
T::eq(null, Money::parseToman('abc'), 'ورودی غیرعددی مبلغ، نامعتبر است');
T::eq(500000, Money::rialsToToman(5000000), 'تبدیل ریال به تومان درست است');
T::eq(5000000, Money::tomanToRial(500000), 'تبدیل تومان به ریال درست است');
T::eq('20', Money::fromMicro(Money::toMicro('20')), 'درصد اعشاری بدون خطای شناور ذخیره می‌شود');
T::eq('12.5', Money::fromMicro(Money::toMicro('12.5')), 'درصد اعشاری با نیم درصد درست است');

// ---------- تنظیمات: هیچ مقدار ثابتی در کد نباشد
SettingsService::set('min_withdraw_amount', '120000', 'withdrawal');
SettingsService::forget();
T::eq('120000', SettingsService::get('min_withdraw_amount'), 'حداقل برداشت از دیتابیس خوانده می‌شود');
$src = file_get_contents(RB_ROOT . '/src/Services/WithdrawalService.php');
T::ok(str_contains($src, "SettingsService::int('min_withdraw_amount'"), 'سرویس برداشت مقدار را از تنظیمات می‌خواند');

SettingsService::set('force_channel_membership', '1', 'telegram');
SettingsService::forget();
T::eq(true, SettingsService::bool('force_channel_membership'), 'تنظیم بولین درست خوانده می‌شود');

// ---------- نسخه‌بندی پورسانت
$v1 = CommissionSettings::saveVersion([
    'direct_reward' => 20000,
    'direct_enabled' => 1,
    'multi_level_enabled' => 1,
    'max_levels' => 2,
    'levels' => [
        ['level' => 1, 'percent' => '15', 'enabled' => true],
        ['level' => 2, 'percent' => '5', 'enabled' => true],
    ],
    'percent_base' => 'direct_reward',
    'fixed_base_amount' => 0,
    'max_payout_per_event' => 0,
    'daily_payout_cap' => 0,
    'qualify_conditions' => ['joined'],
    'blocked_users_no_commission' => 1,
], 1);
T::ok($v1->versionId > 0, 'نسخه پورسانت ذخیره شد');
$v2 = CommissionSettings::saveVersion([
    'direct_reward' => 25000,
    'direct_enabled' => 1,
    'multi_level_enabled' => 1,
    'max_levels' => 3,
    'levels' => [
        ['level' => 1, 'percent' => '20', 'enabled' => true],
        ['level' => 2, 'percent' => '10', 'enabled' => true],
        ['level' => 3, 'percent' => '5', 'enabled' => true],
    ],
    'percent_base' => 'direct_reward',
    'fixed_base_amount' => 0,
    'max_payout_per_event' => 0,
    'daily_payout_cap' => 0,
    'qualify_conditions' => ['joined', 'phone'],
    'blocked_users_no_commission' => 1,
], 1);
T::eq($v1->version + 1, $v2->version, 'نسخه جدید شماره بعدی را می‌گیرد');
T::eq($v1->versionId, (int) $db->value('SELECT id FROM commission_setting_versions WHERE version = :v', ['v' => $v1->version]), 'نسخه قبلی حذف یا بازنویسی نمی‌شود');
$current = CommissionSettings::current($db, true);
T::eq($v2->versionId, $current->versionId, 'نسخه فعال، آخرین نسخه است');
T::eq(25000, $current->directReward, 'مبلغ پاداش مستقیم از نسخه فعال خوانده می‌شود');
T::eq('35', $current->totalPercent(), 'مجموع درصد لایه‌ها محاسبه می‌شود');

// ---------- اعتبارسنجی: مجموع درصد > ۱۰۰
[$ok, $errors] = CommissionSettingsService::validate([
    'direct_reward' => 1000, 'direct_enabled' => 1, 'multi_level_enabled' => 1, 'max_levels' => 3,
    'levels' => [
        ['level' => 1, 'percent' => 60, 'enabled' => true],
        ['level' => 2, 'percent' => 50, 'enabled' => true],
    ],
]);
T::eq(false, $ok, 'مجموع درصد بیش از ۱۰۰ رد می‌شود');
T::ok(count($errors) > 0, 'پیام خطای اعتبارسنجی برگردانده می‌شود');

[$ok2] = CommissionSettingsService::validate([
    'direct_reward' => 1000, 'direct_enabled' => 1, 'multi_level_enabled' => 1, 'max_levels' => 3,
    'levels' => [
        ['level' => 1, 'percent' => 30, 'enabled' => true],
        ['level' => 2, 'percent' => 20, 'enabled' => true],
    ],
]);
T::eq(true, $ok2, 'تنظیمات معتبر پذیرفته می‌شود');

// ---------- رتبه‌بندی
T::ok(array_key_exists('direct_ref_count', RankingService::METRICS), 'معیار پیش‌فرض رتبه‌بندی تعریف شده');
T::eq(4, count(RankingService::METRICS), 'چهار معیار رتبه‌بندی موجود است');
SettingsService::set('ranking_metric', 'invalid_metric', 'ranking');
SettingsService::forget();
T::eq('direct_ref_count', RankingService::metric(), 'معیار نامعتبر به پیش‌فرض بازمی‌گردد');

// ---------- رمزنگاری
$secret = 'my-super-secret-value-123';
$enc = (string) Crypto::encrypt($secret);
T::ok($enc !== $secret, 'مقدار حساس رمزنگاری می‌شود');
T::ok(str_starts_with($enc, 'v1:'), 'نسخه الگوریتم رمزنگاری مشخص است');
T::eq($secret, Crypto::decrypt($enc), 'رمزگشایی درست است');
$tampered = $enc . 'x';
T::eq(null, Crypto::decrypt($tampered), 'داده دستکاری‌شده رمزگشایی نمی‌شود');
$masked = Crypto::maskSecret('123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
T::ok(str_contains($masked, '•') && !str_contains($masked, 'AAAAAAA'), 'ماسک کردن راز برای نمایش');
T::eq('—', Crypto::maskSecret(null), 'راز خالی به‌صورت خط تیره نمایش داده می‌شود');

// ---------- امنیت فایل: هیچ رازی در مخزن نباشد
$envExample = (string) @file_get_contents(RB_ROOT . '/.env.example');
T::ok(str_contains($envExample, 'APP_KEY='), 'فایل .env.example وجود دارد');
T::ok(!preg_match('~APP_KEY=[a-f0-9]{32,}~', $envExample), 'کلید واقعی در .env.example نیست');
$gitignore = (string) @file_get_contents(RB_ROOT . '/.gitignore');
T::ok(str_contains($gitignore, '.env'), '.env در .gitignore است');
T::ok(str_contains($gitignore, 'storage'), 'پوشه storage در .gitignore است');

// ---------- گزارش‌ها و خروجی CSV: BOM فارسی
$reportSource = file_get_contents(RB_ROOT . '/src/Controllers/Admin/ReportController.php');
T::ok(str_contains($reportSource, '\xEF\xBB\xBF'), 'خروجی CSV با BOM یونیکد برای Excel ساخته می‌شود');
T::ok(str_contains($reportSource, 'fputcsv'), 'فایل CSV با متد استاندارد نوشته می‌شود');
T::ok(str_contains($reportSource, 'Content-Disposition'), 'هدر دانلود فایل تنظیم شده است');

// ---------- بدون هشدار خطای ناشناخته در کد
exit(T::summary());
