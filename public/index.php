<?php
/**
 * RefrallBot - تنها نقطه ورود وب
 * همه درخواست‌ها از این فایل عبور می‌کنند (public root هاست باید این پوشه باشد)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use RB\Http\Middleware;
use RB\Http\Router;
use RB\Support\Request;
use RB\Support\Session;

// ---- هدرهای امنیتی
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
if (Request::isHttps()) {
    header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
}

$path = Request::path();

// ---- فایل‌های استاتیک در حالت توسعه (server داخلی PHP)
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file) && !str_starts_with(realpath($file) ?: '', __DIR__ . '/index.php')) {
        return false;
    }
}

Session::start();

$app = require RB_ROOT . '/src/routes.php';
$app(new Router());
