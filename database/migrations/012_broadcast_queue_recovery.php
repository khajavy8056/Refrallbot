<?php
declare(strict_types=1);

use RB\Support\Database;
use RB\Support\SchemaBuilder;

/**
 * بازیابی صف ارسال همگانی:
 * - شمارش تلاش‌ها برای جلوگیری از تلاش بی‌پایان
 * - زمان تلاش بعدی برای backoff پیام‌های گذرا
 * - فعال‌سازی خودکار و بازنشانی صف‌های معلق قبلی
 */
return static function (SchemaBuilder $schema): void {
    $schema->addColumn('broadcast_targets', 'attempts', 'INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
    $schema->addColumn('broadcast_targets', 'last_attempt_at', 'DATETIME NULL DEFAULT NULL', 'TEXT NULL DEFAULT NULL');
    $schema->addColumn('broadcast_targets', 'next_attempt_at', 'DATETIME NULL DEFAULT NULL', 'TEXT NULL DEFAULT NULL');

    $schema->after(static function (Database $db): void {
        try {
            // ۱) بازنشانی رکوردهای هدف معلق قبلی برای شروع فوری ارسال
            if ($db->tableExists('broadcast_targets')) {
                $db->run("UPDATE broadcast_targets SET attempts = 0, next_attempt_at = NULL WHERE status = 'pending'");
            }
            // ۲) بازیابی و قرار دادن کمپین‌های دارای هدف معلق در صف ارسال فعال
            if ($db->tableExists('broadcast_campaigns') && $db->tableExists('broadcast_targets')) {
                $db->run(
                    "UPDATE broadcast_campaigns
                     SET status = 'queued', finished_at = NULL, updated_at = :now
                     WHERE status IN ('sending', 'failed')
                       AND EXISTS (SELECT 1 FROM broadcast_targets t WHERE t.campaign_id = broadcast_campaigns.id AND t.status = 'pending')",
                    ['now' => now()]
                );
            }
        } catch (\Throwable) {
            // نادیده‌گیری خطای احتمالی
        }
    });
};
