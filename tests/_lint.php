<?php
declare(strict_types=1);
/**
 * بررسی سینتکس همه فایل‌های PHP بدون نیاز به php -l
 */
$root = dirname(__DIR__);
$dirs = ['src', 'views', 'database', 'bin', 'config', 'tests', 'public'];
$files = [];
foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
}
sort($files);
$errors = 0;
foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    try {
        token_get_all($src, TOKEN_PARSE);
    } catch (ParseError $e) {
        $errors++;
        echo '✘ ' . str_replace($root . '/', '', $file) . ' — ' . $e->getMessage() . ' (خط ' . $e->getLine() . ')' . PHP_EOL;
    }
}
// ---------------------------------------------------------------------------
// بررسی ارجاع‌های کلاس با نام کاملاً-کیفی داخل نام‌فضا (namespace)
//
// در PHP، نوشتن `Foo\Bar::baz()` داخل فایلِ دارای namespace به `CurrentNs\Foo\Bar`
// تفسیر می‌شود. این کلاس وجود ندارد و فقط در زمان اجرا خطا می‌دهد. چنین باگ‌هایی
// در «صفحه نصب» باعث کرش کامل صفحه می‌شوند، پس اینجا ایستا تشخیص داده می‌شوند.
// ---------------------------------------------------------------------------
$nsErrors = 0;
foreach ($files as $file) {
    if (!str_contains($file, '/src/')) {
        continue;
    }
    $src = (string) file_get_contents($file);
    if (!preg_match('~^namespace\s+([A-Za-z0-9_\\\\]+);~m', $src, $nsMatch)) {
        continue;
    }
    $namespace = trim($nsMatch[1], '\\');
    $lines = explode("\n", $src);
    foreach ($lines as $i => $line) {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, 'use ') || str_starts_with($trimmed, 'namespace ')
            || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
            continue;
        }
        // ارجاع بدون بک‌اسلش ابتدایی به کلاسی با پیشوند RB\
        if (preg_match_all('~(?<![\\\\A-Za-z0-9_$])(RB\\\\[A-Za-z0-9_\\\\]+)~', $line, $m)) {
            foreach ($m[1] as $ref) {
                $nsErrors++;
                echo '✘ ' . str_replace($root . '/', '', $file) . ':' . ($i + 1)
                    . ' — ارجاع نسبی «' . $ref . '» در namespace ' . $namespace . ' (باید \\' . $ref . ' باشد)' . PHP_EOL;
            }
        }
    }
}
if ($nsErrors === 0) {
    echo '✔ ارجاع کلاس‌ها با نام کامل بررسی شد، 0 خطا' . PHP_EOL;
}

$total = $errors + $nsErrors;
printf("%s %d فایل بررسی شد، %d خطا\n", $total === 0 ? '✔' : '✘', count($files), $total);
exit($total === 0 ? 0 : 1);
