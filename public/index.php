<?php
/**
 * RefrallBot - تنها نقطه ورود وب
 * همه درخواست‌ها از این فایل عبور می‌کنند (public root هاست باید این پوشه باشد)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

// ---- هدرهای امنیتی
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
if (\RB\Support\Request::isHttps()) {
    header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
}

$path = \RB\Support\Request::path();

// ---- فایل‌های استاتیک در حالت توسعه (server داخلی PHP)
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file) && !str_starts_with(realpath($file) ?: '', __DIR__ . '/index.php')) {
        return false;
    }
}

\RB\Support\Session::start();

// ردیابی سبک برای عیب‌یابی (فقط با APP_DEBUG) — در لاگ سرور دیده می‌شود
$rbTrace = \RB\Support\Env::bool('APP_DEBUG', false);
if ($rbTrace) {
    register_shutdown_function(static function () use ($rbTrace): void {
        $err = error_get_last();
        error_log('[rb] shutdown reason=' . ($err['message'] ?? 'normal')
            . ' type=' . ($err['type'] ?? 0) . ' at ' . basename((string) ($err['file'] ?? '-')));
    });
    error_log('[rb] request ' . $_SERVER['REQUEST_METHOD'] . ' ' . $path);
}

$app = require RB_ROOT . '/src/routes.php';
$router = new \RB\Http\Router();
$app($router);

// ---- اجرای مسیر
// خروجی در بافر گرفته می‌شود تا اگر کنترلر هیچ چیزی تولید نکرد
// (مثلاً به‌خاطر یک خطای بی‌صدا)، پاسخ «۲۰۰ با بدنهٔ خالی» ندهیم.
if ($rbTrace) {
    ob_start();
    $router->dispatch();
    $rbBody = (string) ob_get_clean();
    if ($rbBody === '') {
        error_log('[rb] EMPTY BODY for ' . $path . ' — dispatch produced no output');
    }
    echo $rbBody;
} else {
    $router->dispatch();
}

if ($rbTrace) {
    error_log('[rb] handled ' . $path);
}
