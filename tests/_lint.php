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
printf("%s %d فایل بررسی شد، %d خطا\n", $errors === 0 ? '✔' : '✘', count($files), $errors);
exit($errors === 0 ? 0 : 1);
