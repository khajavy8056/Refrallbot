<?php
declare(strict_types=1);

return [
    /*
     * حداکثر تعداد ارسال در هر اجرای Cron (برای رعایت محدودیت نرخ تلگرام)
     * تلگرام حدود ۳۰ پیام در ثانیه مجاز می‌داند.
     */
    'broadcast_batch_size' => 25,
    'broadcast_delay_ms' => (int) (\RB\Support\Env::get('BROADCAST_DELAY_MS', '60') ?? '60'),
    'broadcast_max_per_run' => 500,

    /* بررسی خودکار واجد شرایط شدن معرفی‌ها */
    'qualify_batch_size' => 200,

    /* نگهداری گزارش‌ها (روز) */
    'log_retention_days' => (int) (\RB\Support\Env::get('LOG_RETENTION_DAYS', '90') ?? '90'),
];
