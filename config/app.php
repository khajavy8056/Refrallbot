<?php
declare(strict_types=1);

/*
 * تنظیمات پایه برنامه
 * مقادیر حساس از .env خوانده می‌شوند (نه از این فایل)
 */
return [
    'name' => 'رفرال‌بات',
    'name_en' => 'RefrallBot',

    /*
     * آدرس پایه (زیرپوشه) — از APP_BASE_PATH در .env خوانده می‌شود
     * اگر برنامه در ریشه دامنه است، خالی بگذارید؛ اگر در /bot نصب شده، "/bot"
     */
    'base_path' => (static function (): string {
        $path = trim((string) (\RB\Support\Env::get('APP_BASE_PATH', '') ?? ''));
        if ($path === '' || $path === '/') {
            return '';
        }
        return '/' . trim($path, '/');
    })(),

    /* زمان‌بندی */
    'timezone' => (string) (\RB\Support\Env::get('APP_TIMEZONE', 'Asia/Tehran') ?? 'Asia/Tehran'),

    /* حداکثر عمق شبکه معرفی (تنظیمات مدیر از ۱ تا ۸) */
    'max_referral_depth' => 8,

    /* صفحه‌بندی */
    'per_page' => 20,
    'per_page_choices' => [10, 20, 50, 100],
];
