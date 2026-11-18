<?php
declare(strict_types=1);
/**
 * تست جامع بخش «به‌روزرسانی سامانه» (UpdateService + UpdateController + فرانت multipart)
 *
 * ⚠️ ایمنی فایل‌سیستم:
 *   تمام عملیات کپی، پشتیبان‌گیری و بازگردانی فقط روی پوشهٔ موقت ($copyTarget = $tmp . '/target')
 *   انجام می‌شود و در پایان تأیید می‌شود که هیچ فایلی از مخزن واقعی (RB_ROOT) تغییر نکرده است.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Controllers\Admin\UpdateController;
use RB\Services\SettingsService;
use RB\Services\UpdateService;
use RB\Support\Database;
use RB\Support\RedirectException;
use RB\Support\Request;
use RB\Support\View;

T::suite('سناریو ۲۱: بخش به‌روزرسانی سامانه، امنیت بسته، بازگردانی خودکار و کنترل دسترسی');

$db = Database::instance();
$realBootstrapHash = (string) hash_file('sha256', RB_ROOT . '/src/bootstrap.php');
$realVersionHash = (string) hash_file('sha256', RB_ROOT . '/VERSION');

// پوشه‌های کاملاً ایزولهٔ موقت — هرگز روی RB_ROOT واقعی نوشته نمی‌شود
$tmp = sys_get_temp_dir() . '/rb-update-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
$copyTarget = $tmp . '/target';
$storageTarget = $tmp . '/storage';
$zipsDir = $tmp . '/zips';

foreach ([$copyTarget . '/src', $copyTarget . '/database/migrations', $copyTarget . '/storage', $storageTarget . '/backups', $storageTarget . '/logs', $zipsDir] as $d) {
    mkdir($d, 0775, true);
}

// فایل‌های اولیهٔ نصب فرضی (شامل .env و storage/ که هرگز نباید لمس شوند)
$secretEnvContent = "APP_KEY=KEEP_THIS_SUPER_SECRET_KEY_1234567890\nDB_PASSWORD=keep_db_pass\n";
$lockContent = "{\"installed_at\":\"2026-01-01T00:00:00Z\"}\n";
file_put_contents($copyTarget . '/VERSION', "1.1.0\n");
file_put_contents($copyTarget . '/.env', $secretEnvContent);
file_put_contents($copyTarget . '/storage/installed.lock', $lockContent);
file_put_contents($copyTarget . '/storage/user-data.txt', 'USER_STORAGE_UNTOUCHED');
file_put_contents($copyTarget . '/src/sample.php', "<?php\nreturn 'v1.1.0';\n");
file_put_contents($copyTarget . '/README.md', "# RefrallBot 1.1.0\n");

/** پاک‌سازی بازگشتی پوشهٔ موقت در پایان تست */
$rrmdir = static function (string $dir) use (&$rrmdir): void {
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $p = $dir . '/' . $item;
        if (is_dir($p) && !is_link($p)) {
            $rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
};

/**
 * ساخت بستهٔ ZIP آزمایشی همراه با CHECKSUMS.sha256 معتبر
 * @param array<string,string> $files
 */
$makePackageZip = static function (
    string $zipPath,
    array $files,
    bool $withChecksums = true,
    bool $dotSlashPrefix = false,
    ?array $manifest = null,
    ?string $overrideChecksums = null
): string {
    $zip = new \ZipArchive();
    $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    ksort($files);
    $lines = [];
    foreach ($files as $rel => $content) {
        $zip->addFromString($rel, $content);
        $prefix = $dotSlashPrefix ? './' : '';
        $lines[] = hash('sha256', $content) . '  ' . $prefix . $rel;
    }
    if ($overrideChecksums !== null) {
        $zip->addFromString('CHECKSUMS.sha256', $overrideChecksums);
    } elseif ($withChecksums) {
        $zip->addFromString('CHECKSUMS.sha256', implode("\n", $lines) . "\n");
    }
    if ($manifest !== null) {
        $zip->addFromString('update.json', (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $zip->close();
    return $zipPath;
};

$svc = new UpdateService($db, $copyTarget, $storageTarget);

// ---------------------------------------------------------------- ۱) VERSION و ALLOWED_PATHS (اصلاح ۱)
T::eq('1.1.0', trim((string) file_get_contents(RB_ROOT . '/VERSION')), 'فایل VERSION پروژه برابر 1.1.0 است');
T::eq('1.1.0', RB_VERSION, 'ثابت RB_VERSION از فایل VERSION مقدار 1.1.0 گرفته است');
T::ok(in_array('VERSION', UpdateService::ALLOWED_PATHS, true), 'VERSION در UpdateService::ALLOWED_PATHS قرار دارد');
T::ok(in_array('README.md', UpdateService::ALLOWED_PATHS, true), 'README.md در UpdateService::ALLOWED_PATHS قرار دارد');
T::ok(UpdateService::isAllowedPath('VERSION') && UpdateService::isAllowedPath('src/bootstrap.php'), 'مسیرهای مجاز تأیید می‌شوند');
T::ok(
    UpdateService::isProtectedPath('.env')
    && UpdateService::isProtectedPath('./.env')
    && UpdateService::isProtectedPath('.env.production')
    && UpdateService::isProtectedPath('storage/installed.lock')
    && UpdateService::isProtectedPath('storage/logs/app.log')
    && UpdateService::isProtectedPath('.git/config'),
    '.env و storage/ و .git/ به‌عنوان مسیر محافظت‌شده شناخته می‌شوند'
);
T::ok(
    !UpdateService::isAllowedPath('.env')
    && !UpdateService::isAllowedPath('storage/x.txt')
    && !UpdateService::isAllowedPath('tests/evil.php'),
    '.env و storage/ و مسیرهای خارج از لیست سفید در isAllowedPath رد می‌شوند'
);

// ---------------------------------------------------------------- ۲) مقابله با Zip Slip، Symlink و فایل غیر ZIP
$slipZip = $zipsDir . '/zipslip.zip';
$z = new \ZipArchive();
$z->open($slipZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
$z->addFromString('../../pwned.php', '<?php echo 1;');
$z->close();
$res = $svc->extractArchive($slipZip, $tmp . '/ext-slip');
T::eq(false, $res['ok'], 'حملهٔ Zip Slip (../../pwned.php) مسدود شد');
T::eq(false, is_file($tmp . '/pwned.php'), 'هیچ فایلی بیرون از پوشهٔ امن ساخته نشد');

$absZip = $zipsDir . '/abs.zip';
$z = new \ZipArchive();
$z->open($absZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
$z->addFromString('/etc/evil.txt', 'x');
$z->close();
T::eq(false, $svc->extractArchive($absZip, $tmp . '/ext-abs')['ok'], 'مسیر مطلق داخل ZIP مسدود شد');

$bsZip = $zipsDir . '/backslash.zip';
$z = new \ZipArchive();
$z->open($bsZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
$z->addFromString('src\\..\\..\\escape.php', 'x');
$z->close();
T::eq(false, $svc->extractArchive($bsZip, $tmp . '/ext-bs')['ok'], 'پیمایش مسیر با بک‌اسلش مسدود شد');

$symZip = $zipsDir . '/symlink.zip';
$z = new \ZipArchive();
$z->open($symZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
$z->addFromString('src/link', '/etc/passwd');
$z->setExternalAttributesName('src/link', \ZipArchive::OPSYS_UNIX, (0120777 << 16));
$z->close();
T::eq(false, $svc->extractArchive($symZip, $tmp . '/ext-sym')['ok'], 'ورودی Symlink داخل ZIP مسدود شد');

$fakeZip = $zipsDir . '/not-a-zip.zip';
file_put_contents($fakeZip, "<?php system('id');");
$uploadBad = $svc->installFromUpload([
    'name' => 'not-a-zip.zip',
    'tmp_name' => $fakeZip,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($fakeZip),
    '_test_local' => true,
], null, ['target_root' => $copyTarget]);
T::eq(false, $uploadBad['ok'], 'فایل PHP تغییرنام‌داده‌شده به .zip با بررسی امضای باینری رد شد');

// ---------------------------------------------------------------- ۳) اعتبارسنجی CHECKSUMS.sha256 و باگ ltrim('.env')
$noSumZip = $makePackageZip($zipsDir . '/nosum.zip', [
    'VERSION' => "1.2.0\n",
    'src/sample.php' => "<?php return '1.2.0';\n",
], false);
$noSumRes = $svc->applyArchive($noSumZip, 'upload', null, ['target_root' => $copyTarget]);
T::eq(false, $noSumRes['ok'], 'بستهٔ بدون CHECKSUMS.sha256 رد شد');

$badSumZip = $makePackageZip(
    $zipsDir . '/badsum.zip',
    ['VERSION' => "1.2.0\n", 'src/sample.php' => "<?php return 'tampered';\n"],
    true,
    false,
    null,
    hash('sha256', "1.2.0\n") . "  VERSION\n" . str_repeat('0', 64) . "  src/sample.php\n"
);
T::eq(false, $svc->applyArchive($badSumZip, 'upload', null, ['target_root' => $copyTarget])['ok'], 'بسته با چک‌سام دستکاری‌شده رد شد');

// فایل اضافی بدون چک‌سام (backdoor)
$unsignedZip = $zipsDir . '/unsigned.zip';
$makePackageZip($unsignedZip, ['VERSION' => "1.2.0\n", 'src/sample.php' => "<?php return 'ok';\n"]);
$z = new \ZipArchive();
$z->open($unsignedZip);
$z->addFromString('src/backdoor.php', '<?php // unsigned');
$z->close();
T::eq(false, $svc->applyArchive($unsignedZip, 'upload', null, ['target_root' => $copyTarget])['ok'], 'فایل اضافی ثبت‌نشده در CHECKSUMS.sha256 رد شد');

// باگ قبلی #1: ./.env در CHECKSUMS.sha256 نباید به «env» تبدیل شود؛ باید به‌عنوان .env مسدود گردد
$dotEnvZip = $makePackageZip($zipsDir . '/dotenv.zip', [
    'VERSION' => "1.2.0\n",
    '.env' => "APP_KEY=HACKED\n",
], true, true);
$dotEnvRes = $svc->applyArchive($dotEnvZip, 'upload', null, ['target_root' => $copyTarget]);
T::eq(false, $dotEnvRes['ok'], 'بستهٔ حاوی ./.env در CHECKSUMS.sha256 شناسایی و رد شد (حفظ نقطهٔ .env)');
T::ok(str_contains((string) ($dotEnvRes['error'] ?? ''), '.env'), 'پیام خطا دقیقاً به «.env» اشاره می‌کند (نه «env»)');

// بستهٔ حاوی storage/
$storageZip = $makePackageZip($zipsDir . '/storage.zip', [
    'VERSION' => "1.2.0\n",
    'storage/installed.lock' => "OVERWRITE\n",
]);
T::eq(false, $svc->applyArchive($storageZip, 'upload', null, ['target_root' => $copyTarget])['ok'], 'بستهٔ حاوی storage/ رد شد');

// جلوگیری از Downgrade
$downgradeZip = $makePackageZip($zipsDir . '/downgrade.zip', [
    'VERSION' => "1.0.0\n",
    'src/sample.php' => "<?php return 'old';\n",
]);
T::eq(false, $svc->applyArchive($downgradeZip, 'upload', null, ['target_root' => $copyTarget])['ok'], 'نصب نسخهٔ قدیمی‌تر (Downgrade) مسدود شد');

// بررسی سازگاری min_php در update.json
$highPhpZip = $makePackageZip(
    $zipsDir . '/highphp.zip',
    ['VERSION' => "1.2.0\n", 'src/sample.php' => "<?php return '1.2';\n"],
    true,
    false,
    ['name' => 'RefrallBot', 'version' => '1.2.0', 'min_php' => '99.0.0']
);
T::eq(false, $svc->applyArchive($highPhpZip, 'upload', null, ['target_root' => $copyTarget])['ok'], 'بستهٔ نیازمند PHP ناسازگار رد شد');

// ---------------------------------------------------------------- ۴) نصب موفق v1.2.0 + به‌روز شدن VERSION + مصونیت .env/storage + اصلاح ۴ و ۶ و ۷
// فایل قدیمی داخل workDir برای آزمودن فراخوانی خودکار pruneWorkDir() در finally (اصلاح ۶)
$staleFile = $svc->workDir() . '/stale-artifact.tmp';
file_put_contents($staleFile, 'old');
touch($staleFile, time() - 7200);

$migrationCode = <<<'PHP'
<?php
declare(strict_types=1);
return static function (\RB\Support\SchemaBuilder $schema): void {
    $schema->table('update_test_probe', function ($t): void {
        $t->id();
        $t->string('note', 80);
    });
};
PHP;

$good120 = $makePackageZip($zipsDir . '/update-1.2.0.zip', [
    'VERSION' => "1.2.0\n",
    'README.md' => "# RefrallBot 1.2.0\n",
    'src/sample.php' => "<?php\nreturn 'v1.2.0';\n",
    'src/new_feature.php' => "<?php\nreturn 'added-in-1.2.0';\n",
    'database/migrations/099_update_test_probe.php' => $migrationCode,
], true, true, ['name' => 'RefrallBot', 'version' => '1.2.0', 'min_php' => '8.1.0']);

$applied = $svc->applyArchive($good120, 'upload', null, ['target_root' => $copyTarget]);
T::eq(true, $applied['ok'], 'بستهٔ معتبر v1.2.0 با موفقیت نصب شد');
T::eq('1.1.0', $applied['from'] ?? null, 'نسخهٔ قبلی 1.1.0 ثبت شد');
T::eq('1.2.0', $applied['to'] ?? null, 'نسخهٔ جدید 1.2.0 ثبت شد');
T::eq('1.2.0', $svc->currentVersion($copyTarget), 'فایل VERSION مقصد به 1.2.0 به‌روز شد (حلقهٔ آپدیت رخ نمی‌دهد)');
T::ok(str_contains((string) file_get_contents($copyTarget . '/src/sample.php'), 'v1.2.0'), 'فایل برنامه به‌روز شد');
T::ok(is_file($copyTarget . '/src/new_feature.php'), 'فایل جدید نسخهٔ 1.2.0 اضافه شد');
T::ok($db->tableExists('update_test_probe'), 'Migration جدید بسته اجرا شد');
T::eq($secretEnvContent, (string) file_get_contents($copyTarget . '/.env'), '.env پس از به‌روزرسانی دست‌نخورده ماند');
T::eq($lockContent, (string) file_get_contents($copyTarget . '/storage/installed.lock'), 'storage/installed.lock دست‌نخورده ماند');
T::eq('USER_STORAGE_UNTOUCHED', (string) file_get_contents($copyTarget . '/storage/user-data.txt'), 'فایل‌های داخل storage/ دست‌نخورده ماندند');
T::eq(false, is_file($staleFile), 'pruneWorkDir() در بلوک finally فایل‌های موقت قدیمی را پاک کرد (اصلاح ۶)');

// اصلاح ۴: جدول migrations باید در پشتیبان SQL حضور داشته باشد
$sqlBackupPath = $svc->backupDir() . '/' . (string) ($applied['backup'] ?? '');
$sqlContent = is_file($sqlBackupPath) ? (string) file_get_contents($sqlBackupPath) : '';
T::ok(
    str_contains($sqlContent, '-- table: migrations') && str_contains($sqlContent, 'INSERT INTO `migrations`'),
    'جدول migrations در پشتیبان SQL قرار دارد (اصلاح ۴ — جلوگیری از خطای table already exists)'
);

// اصلاح ۷: پشتیبان فایل‌ها فاقد .env و storage/ و فاقد بلوک if خالی در UpdateService
$filesBackupZip = $svc->backupDir() . '/' . (string) ($applied['files_backup'] ?? '');
$zb = new \ZipArchive();
$zb->open($filesBackupZip, \ZipArchive::RDONLY);
$hasBackupVersion = $zb->locateName('VERSION') !== false;
$hasBackupEnv = $zb->locateName('.env') !== false;
$hasBackupStorage = $zb->locateName('storage/installed.lock') !== false;
$zb->close();
T::ok($hasBackupVersion && !$hasBackupEnv && !$hasBackupStorage, 'پشتیبان ZIP فایل‌ها شامل VERSION است و فاقد .env و storage/ است');
$serviceSrc = (string) file_get_contents(RB_ROOT . '/src/Services/UpdateService.php');
T::ok(!preg_match('~if\s*\([^)]*woff2[^)]*\)\s*\{\s*\}~', $serviceSrc), 'بلوک if خالی در backupFiles() وجود ندارد (اصلاح ۷)');

// ---------------------------------------------------------------- ۵) شکست کپی فایل‌ها → بازگردانی خودکار (اصلاح ۲)
$failCopyZip = $makePackageZip($zipsDir . '/fail-copy-1.3.0.zip', [
    'VERSION' => "1.3.0\n",
    'src/a_first.php' => "<?php return 'orphan-1.3.0';\n",
    'src/sample.php' => "<?php\nreturn 'v1.3.0-broken';\n",
    'src/z_fail.php' => "<?php return 'trigger-fail';\n",
]);
UpdateService::$testCopyHook = static function (string $rel): bool {
    return $rel !== 'src/z_fail.php';
};
$copyFailRes = $svc->applyArchive($failCopyZip, 'upload', null, ['target_root' => $copyTarget]);
UpdateService::$testCopyHook = null;

T::eq(false, $copyFailRes['ok'], 'در خطای کپی فایل، applyArchive مقدار ok:false برگرداند');
T::eq(true, $copyFailRes['restored'] ?? null, 'در خطای کپی فایل، بازگردانی خودکار انجام شد (restored:true)');
T::eq(true, $copyFailRes['backup_ok'] ?? null, 'پرچم backup_ok:true در پاسخ خطا ثبت شد');
T::ok(str_contains((string) ($copyFailRes['error'] ?? ''), 'فایل‌های قبلی بازگردانی شدند'), 'پیام خطا بازگردانی خودکار را اعلام کرد');
T::eq('1.2.0', $svc->currentVersion($copyTarget), 'پس از شکست کپی، VERSION به 1.2.0 بازگردانی شد');
T::ok(str_contains((string) file_get_contents($copyTarget . '/src/sample.php'), 'v1.2.0'), 'محتوای src/sample.php به نسخهٔ قبلی بازگردانی شد');
T::eq(false, is_file($copyTarget . '/src/a_first.php'), 'فایل نیمه‌اضافه‌شده در به‌روزرسانی ناموفق پاک شد');
T::eq($secretEnvContent, (string) file_get_contents($copyTarget . '/.env'), '.env در جریان بازگردانی هم دست‌نخورده ماند');

// ---------------------------------------------------------------- ۶) شکست Migration → بازگردانی خودکار فایل‌ها
$badMigrationCode = <<<'PHP'
<?php
declare(strict_types=1);
return static function (\RB\Support\SchemaBuilder $schema): void {
    $schema->raw('INVALID SQL STATEMENT THAT MUST FAIL');
};
PHP;
$failMigZip = $makePackageZip($zipsDir . '/fail-mig-1.3.1.zip', [
    'VERSION' => "1.3.1\n",
    'src/sample.php' => "<?php\nreturn 'v1.3.1';\n",
    'database/migrations/100_broken_migration.php' => $badMigrationCode,
]);
$migFailRes = $svc->applyArchive($failMigZip, 'upload', null, ['target_root' => $copyTarget]);
T::eq(false, $migFailRes['ok'], 'شکست Migration باعث شکست کنترل‌شدهٔ به‌روزرسانی شد');
T::eq(true, $migFailRes['restored'] ?? null, 'پس از شکست Migration فایل‌ها خودکار بازگردانی شدند');
T::eq('1.2.0', $svc->currentVersion($copyTarget), 'پس از شکست Migration، فایل VERSION به 1.2.0 برگشت');
T::eq(false, is_file($copyTarget . '/database/migrations/100_broken_migration.php'), 'فایل Migration خراب در بازگردانی حذف شد');

// ---------------------------------------------------------------- ۷) گیت‌هاب: بررسی نسخه، SSRF، نصب و بررسی خودکار روزانه (اصلاح ۳)
T::ok(
    UpdateService::isAllowedDownloadUrl('https://github.com/khajavy8056/Refrallbot/releases/download/v1.4.0/pkg.zip')
    && !UpdateService::isAllowedDownloadUrl('http://github.com/khajavy8056/Refrallbot/releases/download/v1.4.0/pkg.zip')
    && !UpdateService::isAllowedDownloadUrl('https://evil.example.com/pkg.zip'),
    'فقط آدرس‌های HTTPS دامنه‌های رسمی GitHub برای دانلود مجازند (ضد SSRF)'
);

$ghZipPath = $makePackageZip($zipsDir . '/refrallbot-update-v1.4.0.zip', [
    'VERSION' => "1.4.0\n",
    'src/sample.php' => "<?php\nreturn 'v1.4.0-from-github';\n",
]);
$ghZipBytes = (string) file_get_contents($ghZipPath);
$ghZipSha = hash('sha256', $ghZipBytes);
$assetUrl = 'https://github.com/khajavy8056/Refrallbot/releases/download/v1.4.0/refrallbot-update-v1.4.0.zip';
$shaUrl = $assetUrl . '.sha256';

$httpCalls = 0;
UpdateService::$fakeHttp = static function (string $url, array $headers, bool $binary) use (&$httpCalls, $assetUrl, $shaUrl, $ghZipBytes, $ghZipSha): array {
    $httpCalls++;
    if ($url === $assetUrl) {
        return ['status' => 200, 'body' => $ghZipBytes, 'error' => null];
    }
    if ($url === $shaUrl) {
        return ['status' => 200, 'body' => $ghZipSha . "  refrallbot-update-v1.4.0.zip\n", 'error' => null];
    }
    return [
        'status' => 200,
        'error' => null,
        'body' => (string) json_encode([
            'tag_name' => 'v1.4.0',
            'name' => 'نسخه ۱.۴.۰',
            'body' => "- افزودن بخش به‌روزرسانی\n- پیام دعوت موفق",
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2026-11-18T12:00:00Z',
            'html_url' => 'https://github.com/khajavy8056/Refrallbot/releases/tag/v1.4.0',
            'assets' => [
                ['name' => 'refrallbot-update-v1.4.0.zip', 'browser_download_url' => $assetUrl, 'size' => strlen($ghZipBytes)],
                ['name' => 'refrallbot-update-v1.4.0.zip.sha256', 'browser_download_url' => $shaUrl, 'size' => 96],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
};

// اصلاح ۳: بررسی خودکار روزانه
SettingsService::set('update_auto_check', '0', 'update');
SettingsService::set('update_last_check_at', '', 'update');
$httpCalls = 0;
$svc->autoCheckIfDue();
T::eq(0, $httpCalls, 'وقتی بررسی خودکار خاموش است، autoCheckIfDue هیچ درخواستی نمی‌زند');

SettingsService::set('update_auto_check', '1', 'update');
SettingsService::set('update_last_check_at', date('Y-m-d H:i:s', time() - 90000), 'update');
$svc->autoCheckIfDue();
T::eq(1, $httpCalls, 'وقتی بیش از ۲۴ ساعت گذشته و بررسی خودکار روشن است، check() اجرا شد (اصلاح ۳)');
T::eq(true, UpdateService::hasCachedUpdate($svc->currentVersion($copyTarget)), 'نسخهٔ جدید v1.4.0 شناسایی و کش شد');

$svc->autoCheckIfDue();
T::eq(1, $httpCalls, 'فراخوانی دوبارهٔ autoCheckIfDue در کمتر از ۲۴ ساعت درخواست تکراری نزد');

// نصب خودکار از گیت‌هاب
$ghInstall = $svc->installFromGithub('v1.4.0', null, ['target_root' => $copyTarget]);
T::eq(true, $ghInstall['ok'], 'دانلود و نصب نسخهٔ v1.4.0 از گیت‌هاب با تأیید SHA-256 موفق بود');
T::eq('1.4.0', $svc->currentVersion($copyTarget), 'نسخهٔ مقصد به 1.4.0 ارتقا یافت');
T::eq(false, UpdateService::hasCachedUpdate($svc->currentVersion($copyTarget)), 'پس از نصب 1.4.0 دیگر به‌روزرسانی تکراری نشان داده نمی‌شود');

UpdateService::$fakeHttp = null;
SettingsService::set('update_auto_check', '0', 'update');

// ---------------------------------------------------------------- ۸) کنترل دسترسی superadmin، منو، بازگردانی امن و multipart JS (اصلاح ۵)
T::eq(false, $svc->restoreFiles('../evil.zip', $copyTarget)['ok'], 'پیمایش مسیر در نام فایل restoreFiles مسدود شد');

// ساخت مدیر کل و مدیر پشتیبان (غیر superadmin)
$superRoleId = (int) $db->value("SELECT id FROM admin_roles WHERE slug = 'superadmin'")
    ?: $db->insert('admin_roles', ['name' => 'مدیر کل', 'slug' => 'superadmin', 'permissions' => '["*"]', 'created_at' => now(), 'updated_at' => now()]);
$supportRoleId = (int) $db->value("SELECT id FROM admin_roles WHERE slug = 'support'")
    ?: $db->insert('admin_roles', ['name' => 'پشتیبانی', 'slug' => 'support', 'permissions' => '["support"]', 'created_at' => now(), 'updated_at' => now()]);
$supportAdminId = $db->insert('admins', [
    'name' => 'پشتیبان', 'username' => 'sup_upd_test',
    'password_hash' => password_hash('StrongPass123!', PASSWORD_DEFAULT),
    'role_id' => $supportRoleId, 'is_active' => 1,
    'created_at' => now(), 'updated_at' => now(),
]);

// بررسی نمایش آیتم «به‌روزرسانی» در منوی مدیر کل و عدم نمایش برای غیر مدیر کل
View::share('currentAdmin', ['id' => 1, 'name' => 'مدیر کل', 'role_name' => 'مدیر کل', 'role_slug' => 'superadmin']);
View::share('flashMessages', []);
View::share('navBadges', ['update' => 1]);
View::share('currentPath', '/admin/update');
$superHtml = View::render('admin/update/index', [
    'title' => 'به‌روزرسانی سامانه',
    'currentVersion' => '1.1.0',
    'latest' => null,
    'hasUpdate' => false,
    'repo' => UpdateService::DEFAULT_REPO,
    'channel' => 'stable',
    'autoCheck' => false,
    'maskedToken' => null,
    'lastCheckAt' => '',
    'lastError' => '',
    'lastAppliedAt' => '',
    'lastAppliedFrom' => '',
    'lastAppliedTo' => '',
    'checks' => $svc->environmentChecks(),
    'backups' => [],
    'history' => [],
    'maxUploadMb' => 50,
], 'app');
T::ok(str_contains($superHtml, '/admin/update') && str_contains($superHtml, 'به‌روزرسانی'), 'آیتم «به‌روزرسانی» در منوی پنل مدیر کل دیده می‌شود');
T::ok(str_contains($superHtml, 'enctype="multipart/form-data"') && str_contains($superHtml, 'name="package"'), 'فرم آپلود ZIP دارای enctype="multipart/form-data" است');

View::share('currentAdmin', ['id' => $supportAdminId, 'name' => 'پشتیبان', 'role_name' => 'پشتیبانی', 'role_slug' => 'support']);
$supportLayout = View::render('layouts/blank', ['content' => 'ok'], 'app');
T::ok(!str_contains($supportLayout, 'href="/admin/update"'), 'آیتم «به‌روزرسانی» برای مدیر غیر superadmin در منو مخفی است');

// مسدود بودن تمام اکشن‌های UpdateController برای مدیر غیر superadmin
$_SESSION['admin_id'] = $supportAdminId;
$_SESSION['admin_last_seen'] = time();
$redirectedTo = null;
Request::$redirectHandler = static function (string $to) use (&$redirectedTo): void {
    $redirectedTo = $to;
};
try {
    (new UpdateController())->index();
} catch (RedirectException) {
}
Request::$redirectHandler = null;
T::eq('/admin', $redirectedTo, 'صفحه GET /admin/update مدیر غیر superadmin را به /admin برمی‌گرداند');

$ctrl = new UpdateController();
$allForbidden = true;
foreach (['check', 'installGithub', 'upload', 'restore', 'saveSettings'] as $action) {
    $GLOBALS['RB_LAST_JSON_STATUS'] = 0;
    ob_start();
    $ctrl->{$action}();
    $raw = (string) ob_get_clean();
    $json = json_decode($raw, true);
    if (($GLOBALS['RB_LAST_JSON_STATUS'] ?? 0) !== 403 || ($json['ok'] ?? true) !== false) {
        $allForbidden = false;
    }
}
T::ok($allForbidden, 'هر ۵ مسیر POST /admin/update/* برای مدیر غیر superadmin با کد 403 رد شدند');

// اصلاح ۵: بررسی پشتیبانی FormData در public/assets/js/app.js
$appJs = (string) file_get_contents(RB_ROOT . '/public/assets/js/app.js');
T::ok(
    str_contains($appJs, 'instanceof FormData')
    && str_contains($appJs, 'multipart/form-data')
    && str_contains($appJs, "delete headers['Content-Type']")
    && str_contains($appJs, "'X-CSRF-Token': token"),
    'app.js در ارسال FormData هدر X-CSRF-Token را می‌فرستد، Content-Type دستی نمی‌گذارد و JSON.stringify نمی‌کند (اصلاح ۵)'
);

// ---------------------------------------------------------------- ۹) تأیید ساخت بستهٔ رسمی (buildPackage / bin/build-update.php) و ایمنی مخزن واقعی
$builtZip = $zipsDir . '/built-by-script.zip';
$builtRes = UpdateService::buildPackage($copyTarget, '1.5.0', $builtZip);
T::ok(($builtRes['ok'] ?? false) && is_file($builtZip) && is_file($builtZip . '.sha256'), 'UpdateService::buildPackage فایل ZIP رسمی و .sha256 را بدون .env/storage ساخت');

$builtApply = $svc->applyArchive($builtZip, 'upload', null, ['target_root' => $copyTarget, 'skip_migrations' => true]);
T::eq(true, $builtApply['ok'], 'بستهٔ رسمی ساخته‌شده از اعتبارسنجی CHECKSUMS.sha256 گذشت و نصب شد');
T::eq('1.5.0', $svc->currentVersion($copyTarget), 'نسخهٔ مقصد پس از نصب بستهٔ رسمی به 1.5.0 رسید');

// اطمینان قطعی از دست‌نخورده ماندن مخزن واقعی (حفظ اصلاح باگ قبلی #2)
T::eq($realBootstrapHash, (string) hash_file('sha256', RB_ROOT . '/src/bootstrap.php'), 'src/bootstrap.php واقعی مخزن کاملاً دست‌نخورده ماند');
T::eq($realVersionHash, (string) hash_file('sha256', RB_ROOT . '/VERSION'), 'فایل VERSION واقعی مخزن کاملاً دست‌نخورده ماند');

// پاک‌سازی کامل پوشهٔ موقت تست
$rrmdir($tmp);

exit(T::summary());
