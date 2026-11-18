<?php
declare(strict_types=1);

/**
 * بوت‌استرپ تست‌ها — دیتابیس SQLite در حافظه/فایل موقت
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';

// حالت تست: اسکریپت‌های CLI-محور (poll.php، seed) هم در محیط‌های غیر CLI قابل اجرا می‌شوند
if (!defined('RB_TEST_MODE')) {
    define('RB_TEST_MODE', true);
}

use RB\Support\Database;
use RB\Support\Env;
use RB\Support\Migrator;

Env::set('DB_DRIVER', 'sqlite');
Env::set('DB_DATABASE', getenv('RB_TEST_DB') ?: (sys_get_temp_dir() . '/refrallbot-test-' . substr(md5((string) getenv('RB_TEST_RUN_ID')), 0, 8) . '.sqlite'));
Env::set('APP_DEBUG', '1');

// در حالت تست هر اجرا دیتابیس تازه و مستقل خود را می‌سازد
$testDbPath = getenv('RB_TEST_DB') ?: (sys_get_temp_dir() . '/refrallbot-test-' . substr(md5((string) getenv('RB_TEST_RUN_ID')), 0, 8) . '.sqlite');
if (getenv('RB_TEST_FRESH') === '1') {
    foreach (['', '-wal', '-shm'] as $suffix) {
        if (is_file($testDbPath . $suffix)) {
            @unlink($testDbPath . $suffix);
        }
    }
}

$db = new Database([
    'driver' => 'sqlite',
    'database' => $testDbPath,
]);
Database::setInstance($db);

$migrator = new Migrator($db);
$result = $migrator->run();
if ($result['applied']) {
    echo "Migrations applied: " . implode(', ', $result['applied']) . "\n";
}

// تنظیمات پیش‌فرض تست
RB\Services\SettingsService::set('app_name', 'رفرال‌بات تست', 'general');
RB\Services\SettingsService::set('min_withdraw_amount', '30000', 'withdrawal');
RB\Services\SettingsService::set('withdrawal_enabled', '1', 'withdrawal');
RB\Services\SettingsService::set('max_open_withdrawals', '1', 'withdrawal');
RB\Services\SettingsService::set('ranking_enabled', '1', 'ranking');
RB\Services\SettingsService::set('force_channel_membership', '0', 'telegram');
RB\Services\SettingsService::set('contact_required', '0', 'telegram');
