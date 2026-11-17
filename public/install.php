<?php
/**
 * نقطه ورود تک‌فایلی نصب — نسخه‌ای که کنار public/index.php قرار دارد
 * (برای وقتی که docroot هاست را روی پوشه public تنظیم کرده‌اید).
 *
 * کافی است https://example.com/install.php را باز کنید.
 */
declare(strict_types=1);

$_SERVER['REQUEST_URI'] = '/install';

require_once __DIR__ . '/index.php';
