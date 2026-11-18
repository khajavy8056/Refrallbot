<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\AdminAuth;
use RB\Services\AuditService;
use RB\Support\Database;

/**
 * پشتیبان‌گیری و بازیابی — فقط مدیر کل
 * پشتیبان: دامپ SQL جدول‌به-جدول (سازگار با MySQL/MariaDB و SQLite) + فایل JSON خلاصه
 */
final class BackupController extends AdminController
{
    private function dir(): string
    {
        $dir = RB_STORAGE . '/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public function index(): void
    {
        if (!AdminAuth::requireSuperAdmin()) {
            $this->flash('error', 'پشتیبان‌گیری فقط برای مدیر کل مجاز است.');
            \RB\Support\Request::redirect(url('admin'));
            return;
        }
        $files = [];
        foreach (glob($this->dir() . '/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $files[] = [
                'name' => basename($path),
                'size' => filesize($path),
                'created_at' => date('Y-m-d H:i:s', filemtime($path) ?: time()),
            ];
        }
        usort($files, static fn($a, $b) => strcmp($b['name'], $a['name']));

        $db = Database::instance();
        $this->render('admin/backup/index', [
            'title' => 'پشتیبان‌گیری',
            'files' => $files,
            'driver' => $db->driver(),
            'tables' => count($this->tables($db)),
            'stats' => [
                'users' => (int) $db->value('SELECT COUNT(*) FROM users'),
                'transactions' => (int) $db->value('SELECT COUNT(*) FROM wallet_transactions'),
                'withdrawals' => (int) $db->value('SELECT COUNT(*) FROM withdrawal_requests'),
            ],
        ]);
    }

    public function create(): void
    {
        if (!AdminAuth::requireSuperAdmin()) {
            $this->fail('دسترسی کافی ندارید.', 403);
            return;
        }
        try {
            $db = Database::instance();
            $filename = 'refrallbot-backup-' . date('Ymd-His') . '.sql';
            $path = $this->dir() . '/' . $filename;
            $handle = fopen($path, 'wb');
            if ($handle === false) {
                throw new \RuntimeException('ساخت فایل پشتیبان ممکن نشد. دسترسی نوشتن پوشه storage/backups را بررسی کنید.');
            }

            $driver = $db->driver();
            fwrite($handle, "-- RefrallBot backup\n");
            fwrite($handle, '-- driver: ' . $driver . "\n");
            fwrite($handle, '-- created: ' . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, '-- tables: ' . count($this->tables($db)) . "\n\n");

            foreach ($this->tables($db) as $table) {
                $rows = $db->all('SELECT * FROM ' . $this->quote($table, $driver));
                fwrite($handle, "\n-- table: {$table} (" . count($rows) . " rows)\n");
                foreach ($rows as $row) {
                    $columns = array_map(static fn($c) => $this->quote($c, $driver), array_keys($row));
                    $values = array_map(static function ($v) use ($db): string {
                        if ($v === null) {
                            return 'NULL';
                        }
                        $quoted = $db->quote((string) $v);
                        return $quoted;
                    }, array_values($row));
                    fwrite($handle, 'INSERT INTO ' . $this->quote($table, $driver)
                        . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n");
                }
            }
            fclose($handle);

            AuditService::log('backup.created', 'backup', null, ['file' => $filename, 'size' => filesize($path)], current_admin_id());
            $this->ok('نسخه پشتیبان ساخته شد: ' . $filename, ['file' => $filename, 'reload' => true]);
        } catch (\Throwable $e) {
            $this->fail('خطا در پشتیبان‌گیری: ' . $e->getMessage());
        }
    }

    public function download(array $params): void
    {
        if (!AdminAuth::requireSuperAdmin()) {
            http_response_code(403);
            echo 'دسترسی ندارید.';
            return;
        }
        $name = basename((string) ($params['file'] ?? ''));
        $path = $this->dir() . '/' . $name;
        if ($name === '' || !is_file($path) || !str_ends_with($name, '.sql')) {
            http_response_code(404);
            echo 'فایل یافت نشد.';
            return;
        }
        AuditService::log('backup.downloaded', 'backup', null, ['file' => $name], current_admin_id());
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
    }

    private function quote(string $identifier, string $driver): string
    {
        return '`' . str_replace(['`', '\\'], '', $identifier) . '`';
    }

    /** فهرست جدول‌های دیتابیس */
    private function tables(Database $db): array
    {
        return $db->listTables();
    }
}
