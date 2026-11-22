#!/usr/bin/env php
<?php
/**
 * ساخت بستهٔ رسمی به‌روزرسانی RefrallBot (همراه با VERSION، update.json و CHECKSUMS.sha256)
 *
 * استفاده:
 *   php bin/build-update.php
 *   php bin/build-update.php --version=1.2.0 --out=/tmp/refrallbot-update-v1.2.0.zip
 *   php bin/build-update.php --source=/path/to/tree --version=1.2.0 --out=/tmp/pkg.zip --quiet
 *
 * خروجی:
 *   ۱) فایل ZIP رسمی:  refrallbot-update-vX.Y.Z.zip
 *   ۲) فایل چک‌سام:    refrallbot-update-vX.Y.Z.zip.sha256
 *
 * نکته امنیتی: .env، storage/، tests/، .git/ و فایل‌های موقت هرگز وارد بسته نمی‌شوند.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('RB_TEST_MODE') && getenv('RB_TEST_MODE') !== '1' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit('این اسکریپت فقط از خط فرمان قابل اجراست.');
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use RB\Services\UpdateService;

$args = $argv ?? [];
$sourceRoot = RB_ROOT;
$versionOverride = null;
$outPath = null;
$minPhp = '8.1.0';
$quiet = false;

foreach (array_slice($args, 1) as $arg) {
    if (str_starts_with($arg, '--version=')) {
        $versionOverride = substr($arg, 10);
    } elseif (str_starts_with($arg, '--out=')) {
        $outPath = substr($arg, 6);
    } elseif (str_starts_with($arg, '--source=')) {
        $sourceRoot = substr($arg, 9);
    } elseif (str_starts_with($arg, '--min-php=')) {
        $minPhp = substr($arg, 10);
    } elseif ($arg === '--quiet' || $arg === '-q') {
        $quiet = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "استفاده: php bin/build-update.php [--version=X.Y.Z] [--out=PATH.zip] [--source=DIR] [--min-php=8.1.0] [--quiet]\n";
        exit(0);
    }
}

$sourceRoot = rtrim($sourceRoot, '/\\');
$rawVersion = $versionOverride;
if ($rawVersion === null || $rawVersion === '') {
    $vFile = $sourceRoot . '/VERSION';
    $rawVersion = is_file($vFile) ? (string) file_get_contents($vFile) : RB_VERSION;
}
$version = UpdateService::normalizeVersion($rawVersion);

if ($outPath === null || trim($outPath) === '') {
    $outPath = $sourceRoot . '/releases/refrallbot-update-v' . $version . '.zip';
}

$built = UpdateService::buildPackage($sourceRoot, $version, $outPath, $minPhp);
if (!$built['ok']) {
    fwrite(STDERR, 'خطا: ' . (string) ($built['error'] ?? 'ساخت بسته ناموفق بود.') . "\n");
    exit(1);
}

if (!$quiet) {
    $kb = number_format(((int) $built['size']) / 1024, 1);
    echo "✔ بستهٔ به‌روزرسانی ساخته شد\n";
    echo "  نسخه:    v{$built['version']}\n";
    echo "  فایل:    {$built['path']} ({$kb} KB)\n";
    echo "  تعداد:   {$built['files_count']} فایل + CHECKSUMS.sha256 + update.json\n";
    echo "  SHA256:  {$built['sha256']}\n";
    echo "  فایل هش: {$built['sha256_file']}\n";
}
exit(0);
