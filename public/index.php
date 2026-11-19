<?php
/**
 * RefrallBot - تنها نقطه ورود وب
 * همه درخواست‌ها از این فایل عبور می‌کنند (public root هاست باید این پوشه باشد)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

// ---- هدرهای امنیتی
$path = \RB\Support\Request::path();
if (str_starts_with($path, '/app')) {
    // Mini App در نسخهٔ وب تلگرام، بله و ایتا داخل iframe اجرا می‌شود (بدون X-Frame-Options)
    header("Content-Security-Policy: frame-ancestors 'self' https://web.telegram.org https://*.telegram.org https://web.bale.ai https://*.bale.ai https://*.ble.ir https://web.eitaa.com https://*.eitaa.com https://*.eitaayar.ir");
} else {
    header('X-Frame-Options: SAMEORIGIN');
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
if (\RB\Support\Request::isHttps()) {
    header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
}

// ---- نصب تک‌فایلی: /install.php همان /install است
// (روی هاست‌هایی که docroot روی ریشهٔ پروژه است، install.php خودش این فایل را
//  صدا می‌زند و REQUEST_URI را از قبل تنظیم می‌کند؛ اینجا فقط حالت docroot=public
//  پوشش داده می‌شود تا به‌جای «سرو شدن فایل توسط وب‌سرور» از مسیر واقعی رد شود.)
if ($path === '/install.php' || $path === '/index.php') {
    $path = $path === '/index.php' ? '/' : '/install';
    $_SERVER['REQUEST_URI'] = $path;
}

// ---- فایل‌های استاتیک در حالت توسعه (server داخلی PHP)
// نکته: هرگز برای فایل‌های PHP مقدار false برنمی‌گردانیم؛ سپردن اجرای
// install.php به سرور داخلی باعث می‌شد پاسخ «۲۰۰ با بدنهٔ خالی» شود،
// در حالی که مسیر واقعی باید مسیر نصب با پاسخ ۴۰۳ (پس از نصب) باشد.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . $path;
    $isPhp = str_ends_with(strtolower($path), '.php');
    $isStatic = $path !== '/' && !$isPhp
        && (bool) preg_match('~\.[a-z0-9]{2,5}$~i', $path)
        && is_file($file);
    if ($isStatic) {
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
