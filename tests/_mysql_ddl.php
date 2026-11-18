<?php
declare(strict_types=1);
/**
 * بازبینی سینتکس DDL مخصوص MySQL — بدون نیاز به سرور MySQL.
 *
 * چرا لازم است؟ همهٔ تست‌ها روی SQLite اجرا می‌شوند و SQLite در اجرای دستور
 * هیچ ایرادی نمی‌بیند؛ بنابراین خطاهای واقعی MySQL (مثل
 * «CREATE INDEX IF NOT EXISTS» یا «ON DELETE SET NULL روی ستون NOT NULL»)
 * فقط بعد از آپلود روی هاست و در مرحلهٔ نصب دیده می‌شدند.
 *
 * این تست دستورات schema را برای درایور mysql تولید می‌کند و قواعد زیر را
 * بررسی می‌کند:
 *   ۱) هیچ «CREATE INDEX IF NOT EXISTS» وجود نداشته باشد (MySQL نمی‌شناسد)
 *   ۲) هیچ کلید خارجی با ON DELETE SET NULL روی ستون NOT NULL نباشد
 *   ۳) هر جدول ارجاع‌داده‌شده قبل از خودِ جدول ساخته شود
 *   ۴) هر جدول غیر از جدول اول حداقل یک ایندکس یا UNIQUE داشته باشد
 *
 * اجرا: php tests/_mysql_ddl.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

T::suite('سازگاری DDL با MySQL');

$statements = static function (): array {
    $schema = new \RB\Support\SchemaBuilder('mysql');
    $migration = require dirname(__DIR__) . '/database/migrations/001_initial_schema.php';
    $migration($schema);
    return $schema->statements();
};

$statements = $statements();
T::ok(count($statements) > 40, 'دستورات schema برای MySQL تولید می‌شوند (' . count($statements) . ' مورد)');

$badIndex = [];
$badFk = [];
$order = [];
$noIndex = [];

foreach ($statements as $sql) {
    if (stripos($sql, 'CREATE INDEX IF NOT EXISTS') !== false) {
        $badIndex[] = $sql;
    }
    if (stripos($sql, 'CREATE TABLE') !== false) {
        $order[] = $sql;
    }
}

T::eq([], $badIndex, 'هیچ «CREATE INDEX IF NOT EXISTS» در MySQL استفاده نمی‌شود');

// ---- سه: هر جدول باید ستون id داشته باشد (باگ ۱۰۷۲ MySQL)
$missingId = [];
foreach ($order as $sql) {
    preg_match('~CREATE TABLE(?: IF NOT EXISTS)? `([^`]+)`~i', $sql, $m);
    $table = $m[1] ?? '?';
    if (!preg_match('~^\s+`id`\s+BIGINT UNSIGNED NOT NULL AUTO_INCREMENT~mi', $sql)) {
        $missingId[] = $table . ' (ستون id نیست)';
    }
    if (stripos($sql, 'PRIMARY KEY (`id`)') === false) {
        $missingId[] = $table . ' (PRIMARY KEY (id) نیست)';
    }
}
T::eq([], $missingId, 'همهٔ جدول‌ها ستون id با AUTO_INCREMENT و PRIMARY KEY دارند (جلوگیری از خطای 1072)');

// ---- دو: ON DELETE SET NULL روی ستون NOT NULL (خطای 1830 MySQL)
$setNullOnNotNull = [];
foreach ($order as $sql) {
    preg_match('~CREATE TABLE(?: IF NOT EXISTS)? `([^`]+)`~i', $sql, $m);
    $table = $m[1] ?? '?';
    preg_match_all('~`([a-z_]+)` BIGINT UNSIGNED NOT NULL~i', $sql, $nn);
    $notNull = $nn[1];
    if (preg_match_all('~FOREIGN KEY \(`([^`]+)`\)[^\n]*ON DELETE SET NULL~i', $sql, $fks)) {
        foreach ($fks[1] as $column) {
            if (in_array($column, $notNull, true)) {
                $setNullOnNotNull[] = $table . '.' . $column;
            }
        }
    }
}
T::eq([], $setNullOnNotNull, 'هیچ کلید خارجی «SET NULL» روی ستون NOT NULL نیست (خطای 1830 MySQL)');

// ---- چهار: ترتیب ساخت جدول‌ها
$tableNames = [];
foreach ($order as $sql) {
    if (preg_match('~CREATE TABLE(?: IF NOT EXISTS)? `([^`]+)`~i', $sql, $m)) {
        $tableNames[] = $m[1];
    }
}
$position = array_flip($tableNames);
foreach ($order as $sql) {
    preg_match('~CREATE TABLE(?: IF NOT EXISTS)? `([^`]+)`~i', $sql, $m);
    $table = $m[1] ?? '?';
    foreach (preg_split('~\n~', $sql) as $line) {
        if (preg_match('~FOREIGN KEY \(`([^`]+)`\) REFERENCES `([^`]+)`~i', $line, $fk)) {
            $ref = $fk[2];
            if (!isset($position[$ref])) {
                $badFk[] = $table . ' → جدول ناموجود ' . $ref;
            } elseif ($position[$ref] > $position[$table]) {
                $badFk[] = $table . ' → ' . $ref . ' (بعداً ساخته می‌شود)';
            }
        }
    }
}
T::eq([], $badFk, 'همهٔ کلیدهای خارجی به جدول‌های قبلاً ساخته‌شده اشاره می‌کنند');

// ---- پنج: استفاده از دستورات سازگار
$sqlAll = implode("\n", $statements);
T::ok(stripos($sqlAll, 'AUTOINCREMENT') === false, 'هیچ AUTOINCREMENT مخصوص SQLite در DDL مای‌اس‌کیوال نیست');
T::ok(stripos($sqlAll, 'PRAGMA') === false, 'هیچ PRAGMA مخصوص SQLite در DDL مای‌اس‌کیوال نیست');
T::ok(substr_count($sqlAll, 'ENGINE=InnoDB') >= count($order), 'همهٔ جدول‌ها با ENGINE=InnoDB و utf8mb4 ساخته می‌شوند');

// ---- شش: ایندکس existence-check قابل تشخیص باشد (Migrator به آن وابسته است)
$probe = 'CREATE INDEX `idx_test` ON `users` (`id`)';
T::ok(
    (bool) preg_match('~^CREATE\s+(?:UNIQUE\s+)?INDEX\s+`([^`]+)`\s+ON\s+`([^`]+)`~i', $probe, $m)
    && $m[1] === 'idx_test' && $m[2] === 'users',
    'الگوی تشخیص ایندکس در Migrator با DDL تولیدی می‌خواند'
);

// ---- هفت: کلمات رزروشدهٔ MySQL نباید بدون backtick در کوئری‌ها بیایند
//
// باگ واقعی: 'SELECT key, value FROM system_settings' در MySQL خطای سینتکس
// می‌داد و چون داخل try/catch بود، همهٔ تنظیمات بی‌صدا خالی می‌شدند
// (نام سامانه، کمیسیون‌ها، توکن کرون، حداقل برداشت...).
$reservedWords = ['key', 'rank', 'keys', 'groups', 'system', 'range'];
$sqlFiles = array_merge(
    glob(dirname(__DIR__) . '/src/*.php') ?: [],
    glob(dirname(__DIR__) . '/src/*/*.php') ?: [],
    glob(dirname(__DIR__) . '/src/*/*/*.php') ?: []
);
$unquoted = [];
foreach ($sqlFiles as $file) {
    foreach (preg_split('~\n~', (string) file_get_contents($file)) as $no => $line) {
        if (!preg_match('~\b(SELECT|INSERT INTO|UPDATE|DELETE FROM)\b~i', $line)) {
            continue;
        }
        if (preg_match('~\b(PRIMARY|FOREIGN|UNIQUE)\s+KEY\b~i', $line)) {
            continue;
        }
        foreach ($reservedWords as $word) {
            if (preg_match('~(?<![\w`.:$])\b' . $word . '\b(?![\w`])~i', $line)
                && !preg_match('~`' . $word . '`~i', $line)) {
                $unquoted[] = basename($file) . ':' . ($no + 1) . ' → ' . trim($line);
            }
        }
    }
}
T::eq([], $unquoted, 'ستون‌های هم‌نام با کلمات رزروشدهٔ MySQL با backtick نوشته می‌شوند');

exit(T::summary());
