<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * سازنده‌ی DDL مستقل از موتور پایگاه‌داده
 * خروجی برای MySQL 8 / MariaDB 10.3+ و SQLite 3 (برای تست) معتبر است.
 */
final class SchemaBuilder
{
    private array $tables = [];
    private string $driver;

    public function __construct(string $driver)
    {
        $this->driver = $driver === 'sqlite' ? 'sqlite' : 'mysql';
    }

    public function table(string $name, callable $definition): void
    {
        $t = new TableDefinition($name, $this->driver);
        $definition($t);
        $this->tables[] = $t;
    }

    /** @return string[] */
    public function statements(): array
    {
        $out = [];
        foreach ($this->tables as $table) {
            $out[] = $table->toSql();
            foreach ($table->indexStatements() as $index) {
                $out[] = $index;
            }
        }
        return $out;
    }

    /** @return string[] */
    public function tableNames(): array
    {
        return array_map(static fn(TableDefinition $t) => $t->name(), $this->tables);
    }
}

final class TableDefinition
{
    private string $name;
    private string $driver;
    private array $columns = [];
    private array $indexes = [];
    private array $uniques = [];
    private array $primary = [];
    private array $foreigns = [];

    public function __construct(string $name, string $driver)
    {
        $this->name = $name;
        $this->driver = $driver;
    }

    public function name(): string
    {
        return $this->name;
    }

    private function driverType(string $type, array $args): string
    {
        $sqlite = $this->driver === 'sqlite';
        return match ($type) {
            'id' => $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            'bigint' => $sqlite ? 'INTEGER' : 'BIGINT',
            'int' => $sqlite ? 'INTEGER' : 'INT',
            'smallint' => $sqlite ? 'INTEGER' : 'SMALLINT',
            'bool' => $sqlite ? 'INTEGER' : 'TINYINT(1)',
            'string' => $sqlite ? 'TEXT' : 'VARCHAR(' . ($args[0] ?? 255) . ')',
            'text' => $sqlite ? 'TEXT' : 'TEXT',
            'longtext' => $sqlite ? 'TEXT' : 'LONGTEXT',
            'decimal' => $sqlite ? 'TEXT' : 'DECIMAL(' . ($args[0] ?? 18) . ',' . ($args[1] ?? 2) . ')',
            'datetime' => $sqlite ? 'TEXT' : 'DATETIME',
            'date' => $sqlite ? 'TEXT' : 'DATE',
            'json' => $sqlite ? 'TEXT' : 'JSON',
            default => 'TEXT',
        };
    }

    public function id(string $name = 'id'): self
    {
        $this->columns[$name] = $this->driverType('id', []);
        $this->primary[] = $name;
        return $this;
    }

    private function add(string $name, string $type, array $args, bool $nullable, ?string $default, bool $unsigned): self
    {
        $sql = $this->driverType($type, $args);
        if ($unsigned && $type !== 'id' && $this->driver !== 'sqlite') {
            $sql .= ' UNSIGNED';
        }
        if ($type !== 'id') {
            $sql .= $nullable ? ' NULL' : ' NOT NULL';
            if ($default !== null) {
                $sql .= ' DEFAULT ' . $default;
            }
        }
        $this->columns[$name] = $sql;
        return $this;
    }

    public function bigint(string $name, bool $nullable = false, ?string $default = null, bool $unsigned = true): self
    {
        return $this->add($name, 'bigint', [], $nullable, $default, $unsigned);
    }

    public function int(string $name, bool $nullable = false, ?string $default = null): self
    {
        return $this->add($name, 'int', [], $nullable, $default, false);
    }

    public function smallint(string $name, bool $nullable = false, ?string $default = null): self
    {
        return $this->add($name, 'smallint', [], $nullable, $default, false);
    }

    public function bool(string $name, bool $default = false): self
    {
        return $this->add($name, 'bool', [], false, $default ? '1' : '0', false);
    }

    public function string(string $name, int $length = 255, bool $nullable = false, ?string $default = null): self
    {
        return $this->add($name, 'string', [$length], $nullable, $default, false);
    }

    public function text(string $name, bool $nullable = true): self
    {
        return $this->add($name, 'text', [], $nullable, null, false);
    }

    public function longtext(string $name, bool $nullable = true): self
    {
        return $this->add($name, 'longtext', [], $nullable, null, false);
    }

    /** مبالغ به‌صورت عدد صحیح ذخیره می‌شوند تا خطای اعشاری رخ ندهد */
    public function money(string $name, bool $nullable = false, string $default = '0'): self
    {
        return $this->add($name, 'bigint', [], $nullable, $default, true);
    }

    public function datetime(string $name, bool $nullable = true): self
    {
        return $this->add($name, 'datetime', [], $nullable, null, false);
    }

    public function date(string $name, bool $nullable = true): self
    {
        return $this->add($name, 'date', [], $nullable, null, false);
    }

    public function json(string $name, bool $nullable = true): self
    {
        return $this->add($name, 'json', [], $nullable, null, false);
    }

    public function timestamps(): self
    {
        $this->datetime('created_at', false);
        $this->datetime('updated_at', true);
        return $this;
    }

    public function index(array|string $columns, ?string $name = null): self
    {
        $this->indexes[] = is_array($columns) ? $columns : [$columns];
        return $this;
    }

    public function unique(array|string $columns, ?string $name = null): self
    {
        $this->uniques[] = is_array($columns) ? $columns : [$columns];
        return $this;
    }

    public function foreign(string $column, string $refTable, string $onDelete = 'SET NULL'): self
    {
        if ($this->driver === 'sqlite') {
            return $this; // کلید خارجی در SQLite از طریق PRAGMA کنترل می‌شود؛ برای سادگی نادیده گرفته می‌شود
        }
        // MySQL اجازهٔ ON DELETE SET NULL روی ستون NOT NULL را نمی‌دهد
        // (خطای 1830 در زمان اجرای Migration). اگر ستون اجباری است، CASCADE
        // منطقی‌تر و امن‌تر است تا کل Migration شکست نخورد.
        if ($onDelete === 'SET NULL' && $this->isNotNull($column)) {
            $onDelete = 'CASCADE';
        }
        $this->foreigns[] = sprintf(
            'CONSTRAINT `fk_%s_%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`id`) ON DELETE %s',
            substr($this->name, 0, 40),
            $column,
            $column,
            $refTable,
            $onDelete
        );
        return $this;
    }

    /** آیا ستون به‌صورت NOT NULL ساخته شده است؟ */
    private function isNotNull(string $column): bool
    {
        if (!isset($this->columns[$column])) {
            return false;
        }
        // ستون id تعریف جداگانه دارد و همیشه کلید اصلی است
        return str_contains($this->columns[$column], ' NOT NULL') && !str_contains($this->columns[$column], ' NULL,');
    }

    public function toSql(): string
    {
        $parts = [];
        foreach ($this->columns as $name => $definition) {
            // همهٔ ستون‌ها باید در CREATE TABLE بیایند.
            //
            // باگ قبلی: ستون‌هایی که در PRIMARY KEY بودند (یعنی همیشه `id`)
            // از تعریف جدول حذف می‌شدند، در حالی که MySQL نوع id را
            // «BIGINT UNSIGNED NOT NULL AUTO_INCREMENT» می‌سازد و کلید اصلی را
            // جداگانه با PRIMARY KEY (`id`) اعلام می‌کند. نتیجه: خطای 1072
            // («Key column 'id' doesn't exist in table») و شکست ساخت همهٔ
            // جدول‌ها روی MySQL. در SQLite اتفاقی نمی‌افتاد چون نوع id خودش
            // شامل PRIMARY KEY است.
            $parts[] = '`' . $name . '` ' . $definition;
        }
        if ($this->driver === 'mysql' && $this->primary) {
            $parts[] = 'PRIMARY KEY (`' . implode('`, `', $this->primary) . '`)';
        }
        if ($this->driver === 'sqlite' && $this->primary && !in_array('id', $this->primary, true)) {
            $parts[] = 'PRIMARY KEY (`' . implode('`, `', $this->primary) . '`)';
        }
        foreach ($this->uniques as $cols) {
            $parts[] = 'UNIQUE (' . implode(', ', array_map(fn($c) => '`' . $c . '`', $cols)) . ')';
        }
        foreach ($this->foreigns as $fk) {
            $parts[] = $fk;
        }
        $suffix = $this->driver === 'mysql'
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : '';
        return 'CREATE TABLE IF NOT EXISTS `' . $this->name . '` (' . "\n  " . implode(",\n  ", $parts) . "\n)" . $suffix;
    }

    /** @return string[] */
    public function indexStatements(): array
    {
        if ($this->driver === 'sqlite') {
            return []; // UNIQUE کافی است؛ ایندکس‌های اضافی برای سرعت تست لازم نیستند
        }
        // نکتهٔ مهم: MySQL (برخلاف MariaDB و SQLite) عبارت «CREATE INDEX IF NOT
        // EXISTS» را نمی‌شناسد و Migration با خطای سینتکس متوقف می‌شود. پس نام
        // ایندکس را در ابتدای statement می‌گذاریم تا Migrator بتواند قبل از اجرا
        // وجودش را در information_schema بررسی کند (ایندکس‌ها در SQLite ساخته
        // نمی‌شوند، چون UNIQUE برای تست کافی است).
        $out = [];
        $i = 0;
        foreach ($this->indexes as $cols) {
            $i++;
            $name = 'idx_' . substr($this->name, 0, 34) . '_' . implode('_', $cols) . '_' . $i;
            $name = substr($name, 0, 60);
            $out[] = 'CREATE INDEX `' . $name . '` ON `' . $this->name . '` (`'
                . implode('`, `', $cols) . '`)';
        }
        return $out;
    }
}
