<?php
declare(strict_types=1);

/**
 * توابع کوچک عمومی (جدای از helpers برای استفاده در سرویس‌ها)
 */

if (!function_exists('now')) {
    function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('today_start')) {
    function today_start(): string
    {
        return date('Y-m-d 00:00:00');
    }
}

if (!function_exists('today_end')) {
    function today_end(): string
    {
        return date('Y-m-d 23:59:59');
    }
}
