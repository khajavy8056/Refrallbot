<?php
declare(strict_types=1);

use RB\Support\SchemaBuilder;

/**
 * بازیابی صف ارسال همگانی:
 * - شمارش تلاش‌ها برای جلوگیری از تلاش بی‌پایان
 * - زمان تلاش بعدی برای backoff پیام‌های گذرا
 * - هیچ رکورد موجودی حذف یا تغییر نمی‌شود
 */
return static function (SchemaBuilder $schema): void {
    $schema->addColumn('broadcast_targets', 'attempts', 'INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
    $schema->addColumn('broadcast_targets', 'last_attempt_at', 'DATETIME NULL DEFAULT NULL', 'TEXT NULL DEFAULT NULL');
    $schema->addColumn('broadcast_targets', 'next_attempt_at', 'DATETIME NULL DEFAULT NULL', 'TEXT NULL DEFAULT NULL');
};
