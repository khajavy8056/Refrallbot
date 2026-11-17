<?php
declare(strict_types=1);
/**
 * اجرای همه تست‌ها
 *   php tests/run_all.php
 * هر فایل تست در پروسه جداگانه اجرا می‌شود تا وضعیت سراسری تداخل نکند.
 */
if (PHP_SAPI !== 'cli' && !defined('RB_TEST_MODE') && getenv('RB_TEST_MODE') !== '1' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit('این اسکریپت فقط از خط فرمان قابل اجراست.');
}
if (!function_exists('exec')) {
    fwrite(STDERR, "تابع exec غیرفعال است؛ تست‌ها را تک‌تک اجرا کنید: php tests/01_setup_test.php\n");
    exit(1);
}

$files = glob(__DIR__ . '/[0-9][0-9]_*_test.php') ?: [];
sort($files);
// تست‌های کمکی (رندر قالب‌ها و دود مسیرها)
foreach (['_lint.php', '_smoke.php', '_route.php', '_actions.php'] as $extra) {
    if (is_file(__DIR__ . '/' . $extra)) {
        $files[] = __DIR__ . '/' . $extra;
    }
}
$php = PHP_BINARY;
$totalPassed = 0;
$totalFailed = 0;
$started = microtime(true);
$results = [];

foreach ($files as $file) {
    $name = basename($file);
    echo str_repeat('=', 62) . "\n" . '▶ ' . $name . "\n" . str_repeat('=', 62) . "\n";
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($file) . ' 2>&1';
    exec($cmd, $output, $exitCode);
    echo implode("\n", $output) . "\n\n";
    $text = implode("\n", $output);
    preg_match('~نتیجه: (\d+) موفق، (\d+) ناموفق~u', $text, $m);
    if (isset($m[1])) {
        $passed = (int) $m[1];
        $failed = (int) $m[2];
    } else {
        // تست‌های کمکی (_smoke/_route/_lint) خروجی T:: ندارند؛ بر اساس کد خروج
        $passed = $exitCode === 0 ? 1 : 0;
        $failed = $exitCode === 0 ? 0 : 1;
    }
    $totalPassed += $passed;
    $totalFailed += $failed;
    $results[] = ['name' => $name, 'passed' => $passed, 'failed' => $failed, 'exit' => $exitCode];
    $output = [];
}

echo str_repeat('=', 62) . "\n";
echo "خلاصه نهایی\n";
echo str_repeat('-', 62) . "\n";
foreach ($results as $r) {
    printf("  %-38s %3d ✔   %2d ✘\n", $r['name'], $r['passed'], $r['failed']);
}
echo str_repeat('-', 62) . "\n";
printf("  مجموع: %d موفق، %d ناموفق  (%.1f ثانیه)\n", $totalPassed, $totalFailed, microtime(true) - $started);
echo $totalFailed === 0 ? "\n✅ همه تست‌ها موفق\n" : "\n❌ برخی تست‌ها ناموفق بودند\n";
exit($totalFailed === 0 ? 0 : 1);
