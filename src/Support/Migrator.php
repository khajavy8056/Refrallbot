<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * اجرای Migrationها به ترتیب شماره فایل
 */
final class Migrator
{
    private Database $db;
    private string $migrationsDir;

    public function __construct(?Database $db = null, ?string $migrationsDir = null)
    {
        $this->db = $db ?? Database::instance();
        $this->migrationsDir = rtrim($migrationsDir ?? (RB_ROOT . '/database/migrations'), '/\\');
    }

    /** @return string[] نام migrationهای اجراشده */
    public function applied(): array
    {
        if (!$this->db->tableExists('migrations')) {
            return [];
        }
        return $this->db->column('SELECT migration FROM migrations ORDER BY migration');
    }

    /** @return array{applied:string[],skipped:string[]} */
    public function run(): array
    {
        // تضمین utf8mb4 روی نشست و دیتابیس پیش از ساخت جداول و درج داده‌های فارسی/ایموجی
        $this->db->ensureDatabaseUtf8mb4();

        $applied = $this->applied();
        $files = glob($this->migrationsDir . '/*.php') ?: [];
        sort($files);
        $newlyApplied = [];
        $skipped = [];
        $knownTables = ['migrations'];

        foreach ($files as $file) {
            $name = basename($file);
            $schema = new SchemaBuilder($this->db->driver());
            $migration = require $file;
            if (!is_callable($migration)) {
                throw new \RuntimeException('Migration معتبر نیست: ' . $name);
            }
            $migration($schema);
            foreach ($schema->tableNames() as $tName) {
                $knownTables[] = $tName;
            }

            if (in_array($name, $applied, true)) {
                $skipped[] = $name;
                continue;
            }

            foreach ($schema->statements() as $sql) {
                try {
                    // MySQL دستور «CREATE INDEX IF NOT EXISTS» ندارد؛ اگر اجرای
                    // Migration نیمه‌کاره مانده باشد و ایندکس از قبل ساخته شده
                    // باشد، بدون این بررسی Migration با خطای «Duplicate key
                    // name» شکست می‌خورد.
                    $existing = $this->existingIndexName($sql);
                    if ($existing !== null && $this->db->indexExists($existing[0], $existing[1])) {
                        continue;
                    }
                    $this->db->pdo()->exec($sql);
                } catch (\PDOException $e) {
                    throw new \RuntimeException('خطا در اجرای Migration ' . $name . ': ' . ErrorHandler::redact($e->getMessage()), 0, $e);
                }
            }

            if (!$this->db->tableExists('migrations')) {
                $this->db->pdo()->exec(
                    $this->db->driver() === 'sqlite'
                        ? 'CREATE TABLE IF NOT EXISTS migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, applied_at TEXT NOT NULL)'
                        : 'CREATE TABLE IF NOT EXISTS `migrations` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `migration` VARCHAR(191) NOT NULL, `applied_at` DATETIME NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uk_migration` (`migration`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            }
            $this->db->insert('migrations', [
                'migration' => $name,
                'applied_at' => date('Y-m-d H:i:s'),
            ]);
            $newlyApplied[] = $name;
        }

        // اگر جدولی از تلاش نصب قبلی با utf8mb3 یا collation ناهماهنگ مانده باشد، به utf8mb4_unicode_ci تبدیل شود
        $this->db->ensureTablesUtf8mb4(array_values(array_unique($knownTables)));

        return ['applied' => $newlyApplied, 'skipped' => $skipped];
    }

    /**
     * استخراج (جدول، ایندکس) از یک دستور CREATE INDEX تا بتوان تکرار آن را رد کرد.
     *
     * @return array{0:string,1:string}|null
     */
    private function existingIndexName(string $sql): ?array
    {
        if (!preg_match('~^CREATE\s+(?:UNIQUE\s+)?INDEX\s+`([^`]+)`\s+ON\s+`([^`]+)`~i', trim($sql), $m)) {
            return null;
        }
        return [$m[2], $m[1]];
    }
}
