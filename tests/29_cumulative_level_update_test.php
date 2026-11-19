<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\UpdateService;

T::suite('ارتقای واقعی بسته v1.2 به نسخه جدید با آپدیتر قدیمی');
// PHP-WASM cannot execute a native PHP subprocess. The normal CI and production PHP can.
if (PHP_OS_FAMILY === 'Unknown' || !function_exists('exec') || !class_exists(ZipArchive::class)) {
    T::ok(true, 'آزمون ارتقای واقعی به PHP CLI و ZipArchive نیاز دارد');
    exit(T::summary());
}

$tmp = sys_get_temp_dir() . '/rb-old-update-' . bin2hex(random_bytes(6));
$root = $tmp . '/old';
mkdir($root, 0775, true);
$oldZip = RB_ROOT . '/releases/refrallbot-update-v1.2.0.zip';
$archive = new ZipArchive();
$opened = $archive->open($oldZip) === true;
T::ok($opened, 'بسته اصلی v1.2 در دسترس است');
if (!$opened) exit(T::summary());
$archive->extractTo($root);
$archive->close();
mkdir($root . '/storage', 0775, true);
file_put_contents($root . '/.env', "APP_KEY=preserved\n");
file_put_contents($root . '/storage/user-data.txt', 'preserved');
$newZip = $tmp . '/new.zip';
$built = UpdateService::buildPackage(RB_ROOT, trim((string) file_get_contents(RB_ROOT . '/VERSION')), $newZip);
T::ok(!empty($built['ok']), 'بسته کامل نسخه فعلی ساخته شد');
if (empty($built['ok'])) exit(T::summary());

$setup = <<<'CODE'
<?php
require $argv[1] . '/src/bootstrap.php';
$db = new RB\Support\Database(['driver'=>'sqlite','database'=>$argv[2]]);
RB\Support\Database::setInstance($db);
(new RB\Support\Migrator($db))->run();
$uid = $db->insert('users', ['telegram_id'=>7654001,'first_name'=>'Old','referral_code'=>'OLDUSER1','wallet_balance'=>12345,'status'=>'active','joined_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
$db->insert('withdrawal_requests', ['request_code'=>'OLD-WD-1','user_id'=>$uid,'amount'=>1000,'status'=>'paid','card_number_enc'=>RB\Support\Crypto::encrypt('6037701689095443'),'card_last4'=>'5443','created_at'=>now(),'updated_at'=>now()]);
echo 'SETUP_OK';
CODE;
$apply = <<<'CODE'
<?php
require $argv[1] . '/src/bootstrap.php';
$db = new RB\Support\Database(['driver'=>'sqlite','database'=>$argv[2]]);
RB\Support\Database::setInstance($db);
$svc = new RB\Services\UpdateService($db, $argv[1], $argv[1] . '/storage');
$result = $svc->applyArchive($argv[3], 'upload');
$result['wallet'] = (int)$db->value('SELECT wallet_balance FROM users WHERE telegram_id=7654001');
$result['level_table'] = $db->tableExists('user_levels');
$result['card_table'] = $db->tableExists('withdrawal_card_details');
$result['old_card'] = RB\Support\Crypto::decrypt((string)$db->value("SELECT card_number_enc FROM withdrawal_requests WHERE request_code='OLD-WD-1'"));
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
CODE;
file_put_contents($tmp . '/setup.php', $setup);
file_put_contents($tmp . '/apply.php', $apply);
$sqlite = $tmp . '/legacy.sqlite';
$prefix = escapeshellarg(PHP_BINARY) . ' ';
$setupOutput = [];
exec($prefix . escapeshellarg($tmp . '/setup.php') . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($sqlite) . ' 2>&1', $setupOutput, $setupExit);
T::ok($setupExit === 0 && str_contains(implode("\n", $setupOutput), 'SETUP_OK'), 'دیتابیس واقعی v1.2 با کاربر قدیمی ساخته شد');
$output = [];
exec($prefix . escapeshellarg($tmp . '/apply.php') . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($sqlite) . ' ' . escapeshellarg($newZip) . ' 2>&1', $output, $exit);
$result = json_decode(implode("\n", $output), true);
T::ok($exit === 0 && is_array($result) && !empty($result['ok']), 'آپدیتر v1.2 بسته تجمیعی نسخه جدید را نصب کرد: ' . implode(' ', $output));
T::eq(trim((string) file_get_contents(RB_ROOT . '/VERSION')), trim((string) file_get_contents($root . '/VERSION')), 'نسخه مقصد به نسخه جدید رسید');
T::eq(['003_two_way_referral_and_bot_profile.php','004_multi_messenger_invite_studio_and_layers.php','005_cross_messenger_channels_and_error_logs.php','006_user_levels.php','007_withdrawal_card_details.php'], $result['migrations_applied'] ?? [], 'همه Migrationهای میانی اجرا شدند');
T::eq(12345, $result['wallet'] ?? null, 'موجودی کاربر قدیمی حفظ شد');
T::eq(true, $result['level_table'] ?? false, 'جدول‌های سطح‌بندی ایجاد شدند');
T::eq(true, $result['card_table'] ?? false, 'جدول اطلاعات اضافی کارت ایجاد شد');
T::eq('6037701689095443', $result['old_card'] ?? null, 'شماره کارت رمزگذاری‌شده قدیمی پس از ارتقا حفظ می‌شود');
T::eq("APP_KEY=preserved\n", file_get_contents($root . '/.env'), '.env محفوظ ماند');
T::eq('preserved', file_get_contents($root . '/storage/user-data.txt'), 'فایل کاربر در storage محفوظ ماند');
T::ok(!empty($result['backup']) && !empty($result['files_backup']), 'پشتیبان دیتابیس و فایل‌ها ایجاد شد');

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $part) if ($part !== '.' && $part !== '..') $remove($path . '/' . $part);
    @rmdir($path);
};
$remove($tmp);
exit(T::summary());
