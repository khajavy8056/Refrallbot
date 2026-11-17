<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * اجرای Migrationها به ترتیب شماره فایل
 */
final class Migrator
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /** @return string[] نام migrationهای اجرانشده */
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
        $applied = $this->applied();
        $files = glob(RB_ROOT . '/database/migrations/*.php') ?: [];
        sort($files);
        $newlyApplied = [];
        $skipped = [];

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                $skipped[] = $name;
                continue;
            }
            $schema = new SchemaBuilder($this->db->driver());
            $migration = require $file;
            if (!is_callable($migration)) {
                throw new \RuntimeException('Migration معتبر نیست: ' . $name);
            }
            $migration($schema);

            foreach ($schema->statements() as $sql) {
                try {
                    $this->db->pdo()->exec($sql);
                } catch (\PDOException $e) {
                    throw new \RuntimeException('خطا در اجرای Migration ' . $name . ': ' . ErrorHandler::redact($e->getMessage()), 0, $e);
                }
            }

            if (!$this->db->tableExists('migrations')) {
                $this->db->pdo()->exec(
                    $this->db->driver() === 'sqlite'
                        ? 'CREATE TABLE IF NOT EXISTS migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, applied_at TEXT NOT NULL)'
                        : 'CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, migration VARCHAR(191) NOT NULL, applied_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_migration (migration)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
            }
            $this->db->insert('migrations', [
                'migration' => $name,
                'applied_at' => date('Y-m-d H:i:s'),
            ]);
            $newlyApplied[] = $name;
        }

        return ['applied' => $newlyApplied, 'skipped' => $skipped];
    }
}
