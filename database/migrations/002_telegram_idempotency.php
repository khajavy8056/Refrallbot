<?php
declare(strict_types=1);

/**
 * Migration 002 — جدول‌های ضدتکرار (Idempotency) برای آپدیت‌های تلگرام
 *
 * چرا لازم است؟
 *   تلگرام اگر پاسخ 200 نگیرد (Timeout، خطای 5xx، قطع شبکه) همان آپدیت را
 *   دوباره می‌فرستد. بدون ثبت update_id، یک `/start` یا دکمهٔ «ثبت برداشت»
 *   می‌تواند دو بار اجرا شود و عملیات مالی دوباره ثبت شود.
 *
 * جدول‌ها:
 *   telegram_updates        — لاگ پردازش آپدیت‌ها با update_id یکتا
 *   miniapp_auth_nonces     — جلوگیری از استفادهٔ مجدد initData (Replay)
 *
 * هر دو جدول با زمان انقضا پاک‌سازی می‌شوند (CronService::maintenance).
 */

use RB\Support\SchemaBuilder;

return static function (SchemaBuilder $schema): void {

    // ------------------------------------------------------------ آپدیت‌های تلگرام
    $schema->table('telegram_updates', function ($t) {
        $t->id();
        // update_id رسمی تلگرام؛ یکتا تا آپدیت تکراری پذیرفته نشود
        $t->bigint('update_id', false);
        // نوع آپدیت برای عیب‌یابی: message / callback_query / ...
        $t->string('update_type', 32, true);
        // شناسهٔ کاربر تلگرام (برای گزارش و پاک‌سازی گروهی)
        $t->bigint('telegram_user_id', true);
        // pending → processing → done | failed
        $t->string('status', 20, false, "'pending'");
        // تعداد تلاش‌های پردازش (برای سیاست تلاش مجدد)
        $t->int('attempts', false, '0');
        // پیام خطای آخر (بدون اطلاعات حساس)
        $t->string('last_error', 255, true);
        // قفل نرم: تا این زمان آپدیت «در حال پردازش» است
        $t->datetime('locked_until', true);
        $t->datetime('processed_at', true);
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->datetime('updated_at', true);

        $t->unique('update_id');
        $t->index('status');
        $t->index('created_at');
    });

    // ------------------------------------------------------------ Nonce احراز Mini App
    $schema->table('miniapp_auth_nonces', function ($t) {
        $t->id();
        // شناسهٔ یکتای initData (hash از auth_date + query_id + user id)
        $t->string('nonce', 64);
        $t->bigint('user_id', true)->foreign('user_id', 'users', 'CASCADE');
        $t->datetime('expires_at');
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');

        $t->unique('nonce');
        $t->index('expires_at');
    });
};
