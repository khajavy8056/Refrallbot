<?php
/**
 * آماده‌سازی دیتابیس برای تست دود HTTP روی سرور واقعی PHP
 * (در محیط CI استفاده می‌شود؛ روی دیتابیس SQLite موقت کار می‌کند)
 *
 * اجرا:  DB_DRIVER=sqlite DB_DATABASE=/tmp/rb-http.sqlite php tests/_seed_http.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('RB_TEST_MODE') && getenv('RB_TEST_MODE') !== '1' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

// اگر مسیر دیتابیس در محیط تنظیم نشده باشد، SQLite موقت استفاده می‌شود
if ((string) (\RB\Support\Env::get('DB_DRIVER', '') ?? '') === '') {
    \RB\Support\Env::set('DB_DRIVER', 'sqlite');
}
if ((string) (\RB\Support\Env::get('DB_DATABASE', '') ?? '') === '') {
    \RB\Support\Env::set('DB_DATABASE', sys_get_temp_dir() . '/refrallbot-http.sqlite');
}
echo 'db: ' . \RB\Support\Env::get('DB_DRIVER') . ' → ' . \RB\Support\Env::get('DB_DATABASE') . PHP_EOL;

use RB\Services\CommissionSettings;
use RB\Services\MenuService;
use RB\Services\SettingsService;
use RB\Support\Database;

$db = Database::instance();

// اگر جداول وجود ندارند، مهاجرت‌ها را اجرا کن
if (!$db->tableExists('system_settings')) {
    $result = (new RB\Support\Migrator($db))->run();
    echo 'migrations: ' . implode(', ', $result['applied']) . PHP_EOL;
}

$db->insertIgnore('system_settings', [
    'key' => 'installed_at', 'value' => now(), 'group_name' => 'system',
    'created_at' => now(), 'updated_at' => now(),
], 'key');

SettingsService::set('app_name', 'رفرال‌بات', 'general');
SettingsService::set('min_withdraw_amount', '30000', 'withdrawal');

// پنل وب عمداً روشن شبیه‌سازی می‌شود تا مسیر /app در تست HTTP
// صفحه‌ی واقعی پنل را برگرداند (نه پیام «غیرفعال است»).
SettingsService::set('miniapp_mode', 'on', 'telegram');
SettingsService::set('miniapp_url', 'https://smoke.test/app', 'telegram');

// توکن ربات جعلی تا کرون وب قابل آزمایش باشد (بدون تماس واقعی با تلگرام)
SettingsService::set('cron_token', 'ci-cron-token-0123456789abcdef01234567', 'system');

$roleId = (int) $db->value("SELECT id FROM admin_roles WHERE slug = 'superadmin'");
if ($roleId <= 0) {
    $roleId = (int) $db->insert('admin_roles', [
        'name' => 'مدیر کل', 'slug' => 'superadmin', 'permissions' => '["*"]',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
if (!(int) $db->value("SELECT id FROM admins WHERE username = 'ciadmin'")) {
    $db->insert('admins', [
        'name' => 'مدیر CI', 'username' => 'ciadmin',
        'password_hash' => password_hash('CiPassword123!', PASSWORD_DEFAULT),
        'role_id' => $roleId, 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
if (!(int) $db->value('SELECT id FROM commission_setting_versions LIMIT 1')) {
    CommissionSettings::saveVersion([
        'direct_reward' => 5000, 'direct_enabled' => 1, 'multi_level_enabled' => 1, 'max_levels' => 3,
        'levels' => [
            ['level' => 1, 'percent' => '20', 'enabled' => true],
            ['level' => 2, 'percent' => '10', 'enabled' => true],
            ['level' => 3, 'percent' => '5', 'enabled' => true],
        ],
        'percent_base' => 'direct_reward', 'qualify_conditions' => ['joined'],
    ]);
}
MenuService::syncDefaults($db);

echo 'seeded: installed_at, admin=ciadmin, commission v1, menus' . PHP_EOL;
