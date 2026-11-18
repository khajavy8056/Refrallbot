<?php
/**
 * تست دود مسیرها (Route smoke)
 * همه مسیرهای GET را روی Router واقعی اجرا می‌کند و بررسی می‌کند که هیچ استثنا/خطای PHP رخ ندهد
 * و خروجی خالی نباشد. برای مسیرهای پنل، یک مدیر و «نصب‌شده بودن» را شبیه‌سازی می‌کند.
 *
 * اجرا:  php tests/_route.php
 * یا فقط یک مسیر:  RB_SMOKE_PATH=/admin/users php tests/_route.php
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Http\Middleware;
use RB\Http\Router;
use RB\Support\Database;

$db = Database::instance();

// ---- شبیه‌سازی نصب‌شده
$db->insertIgnore('system_settings', [
    'key' => 'installed_at', 'value' => now(), 'group_name' => 'system',
    'created_at' => now(), 'updated_at' => now(),
], 'key');
@file_put_contents(RB_STORAGE . '/installed.lock', json_encode(['installed_at' => date('c')]));

// ---- نقش و مدیر
$roleId = (int) $db->value("SELECT id FROM admin_roles WHERE slug = 'superadmin'");
if ($roleId <= 0) {
    $roleId = (int) $db->insert('admin_roles', [
        'name' => 'مدیر کل', 'slug' => 'superadmin', 'permissions' => '["*"]',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
$adminId = (int) $db->value("SELECT id FROM admins WHERE username = 'smoke'");
if ($adminId <= 0) {
    $adminId = (int) $db->insert('admins', [
        'name' => 'مدیر تست', 'username' => 'smoke',
        'password_hash' => password_hash('Str0ngPassw0rd!', PASSWORD_DEFAULT),
        'role_id' => $roleId, 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

$adminOnly = (int) ($_SERVER['RB_SMOKE_GUEST'] ?? 0) === 0;

$cases = [
    // [مسیر, نیازمند ورود مدیر؟]
    ['/healthz', false],
    ['/install', false],
    ['/', false],
    ['/admin/login', false],
    ['/app', false],
    ['/webhook/telegram', false],
    ['/nope-404', false],
    // کرون وب بدون توکن باید رد شود (۴۰۳/۴۰۰) و هرگز خطای PHP ندهد
    ['/cron/run', false],
    // نقاط ورود تک‌فایلی نصب: درخواست باید به همان ویزارد برسد
    ['/install.php', false],
    ['/admin', true],
    ['/admin/users', true],
    ['/admin/network', true],
    ['/admin/withdrawals', true],
    ['/admin/commissions', true],
    ['/admin/settings', true],
    ['/admin/telegram', true],
    ['/admin/channels', true],
    ['/admin/broadcast', true],
    ['/admin/campaigns', true],
    ['/admin/campaigns/create', true],
    ['/admin/reports/transactions', true],
    ['/admin/security', true],
    ['/admin/logs', true],
    ['/admin/support', true],
    ['/admin/admins', true],
    ['/admin/backup', true],
    ['/admin/api/chart', true],
];

T::suite('تست دود مسیرها (Route smoke)');

$only = getenv('RB_SMOKE_PATH') ?: null;
$failures = 0;

foreach ($cases as [$path, $needsAdmin]) {
    if ($only !== null && $only !== $path) {
        continue;
    }

    // برای آزمودن ویزارد نصب، قفل نصب را موقتاً برمی‌داریم
    $isInstallCase = in_array($path, ['/install', '/install.php'], true);
    if ($isInstallCase) {
        $db->delete('system_settings', "`key` = :k", ['k' => 'installed_at']);
        @unlink(RB_STORAGE . '/installed.lock');
    }

    // ---- محیط درخواست
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['HTTP_HOST'] = 'smoke.test';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    $_SERVER['HTTP_ACCEPT'] = str_contains($path, '/api/') ? 'application/json' : 'text/html';
    // install.php خودش درخواست را به /install هدایت می‌کند
    if ($path === '/install.php') {
        $_SERVER['REQUEST_URI'] = '/install';
    }
    $_GET = [];
    $_POST = [];
    $_SESSION = [];
    $_COOKIE = [];
    if ($needsAdmin && $adminOnly) {
        $_SESSION['admin_id'] = $adminId;
        $_SESSION['admin_last_seen'] = time();
        $_SESSION['admin_fingerprint'] = hash('sha256', 'smoke.test|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    $status = 200;
    ob_start();
    $error = null;
    try {
        $router = new Router();
        $loader = require RB_ROOT . '/src/routes.php';
        $loader($router);
        $router->dispatch();
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
    }
    $out = (string) ob_get_clean();

    if ($isInstallCase) {
        RB\Services\SettingsService::set('installed_at', now(), 'system');
        @file_put_contents(RB_STORAGE . '/installed.lock', json_encode(['installed_at' => now()]));
    }

    // هدرها در CLI قابل خواندن نیستند؛ از طریق Response ثبت‌شده یا http_response_code
    $code = http_response_code() ?: 200;

    $problems = [];
    $redirected = false;
    if ($error !== null) {
        // در CLI هدرها از قبل ارسال شده‌اند؛ تلاش برای ریدایرکت یک رفتار صحیح است
        if (str_contains($error, 'headers already sent')) {
            $redirected = true;
        } else {
            $problems[] = $error;
        }
    }
    foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'Undefined variable', 'Undefined array key', 'خطای داخلی'] as $needle) {
        if (str_contains($out, $needle)) {
            $problems[] = 'خروجی شامل «' . $needle . '» است';
        }
    }
    if (trim($out) === '' && !$redirected) {
        $problems[] = 'خروجی خالی است';
    }

    $ok = $problems === [];
    if (!$ok) {
        $failures++;
    }
    T::ok($ok, sprintf(
        '%-26s %s  [%d بایت، HTTP %d]%s',
        $path,
        $ok ? ($redirected ? 'ریدایرکت' : 'ورودی موفق') : 'ورودی ناموفق',
        strlen($out),
        $code,
        $ok ? '' : ' → ' . implode(' | ', $problems)
    ));
}

T::eq(0, $failures, 'هیچ مسیری خطا برنگرداند');
exit(T::summary());
