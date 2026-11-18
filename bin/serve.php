<?php
declare(strict_types=1);
/**
 * ─────────────────────────────────────────────────────────────────────────────
 *  اجرای محلی RefrallBot — بدون MySQL و بدون هاست
 *
 *  این اسکریپت برای «تست» است، نه برای اجرای واقعی:
 *    • اگر MySQL در دسترس نباشد، خودکار روی SQLite اجرا می‌شود
 *      (همان کاری که تست‌های خودکار می‌کنند)
 *    • دیتابیس و جدول‌ها را می‌سازد و چند داده اولیه می‌ریزد
 *    • سرور داخلی PHP را روی 0.0.0.0 بالا می‌آورد تا از گوشی هم باز شود
 *    • اگر APP_KEY یا .env نبود، خودش می‌سازد
 *
 *  استفاده:
 *      php bin/serve.php                 # http://localhost:8080
 *      php bin/serve.php --port=9000 --host=0.0.0.0
 *      php bin/serve.php --mysql         # تلاش برای استفاده از MySQL واقعی
 *      php bin/serve.php --fresh         # دیتابیس تست را از نو بساز
 *
 *  روی اندروید (Termux):
 *      pkg install php php-sqlite
 *      cd refrallbot && php bin/serve.php
 *  سپس در مرورگر گوشی: http://127.0.0.1:8080
 *
 *  ⚠️ این حالت برای تست است: بدون HTTPS، بدون Cron سیستمی، تک‌رشته‌ای.
 *     برای اجرای واقعی از install.php روی هاست استفاده کنید.
 * ─────────────────────────────────────────────────────────────────────────────
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('این اسکریپت فقط از خط فرمان اجرا می‌شود.');
}

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
        $args[$key] = $value;
    }
}

$root = dirname(__DIR__);
$port = (int) ($args['port'] ?? 8080);
$host = (string) ($args['host'] ?? '0.0.0.0');
$useMysql = isset($args['mysql']);
$fresh = isset($args['fresh']);

$out = static function (string $line): void {
    echo $line . PHP_EOL;
};

$out('┌───────────────────────────────────────────────────────────');
$out('│  RefrallBot — حالت اجرای محلی (تست)');
$out('└───────────────────────────────────────────────────────────');

if (PHP_VERSION_ID < 80100) {
    $out('✘ PHP 8.1 یا بالاتر لازم است. نسخهٔ فعلی: ' . PHP_VERSION);
    exit(1);
}

// ------------------------------------------------------------------ انتخاب درایور
$driver = $useMysql ? 'mysql' : 'sqlite';
$sqlitePath = $root . '/storage/local.sqlite';

if ($useMysql) {
    $hasMysql = extension_loaded('pdo_mysql');
    if (!$hasMysql) {
        $out('✘ افزونهٔ pdo_mysql نصب نیست؛ به SQLite برمی‌گردیم.');
        $driver = 'sqlite';
    } else {
        $out('ℹ حالت MySQL: اگر .env تنظیم نشده باشد، اتصال ناموفق خواهد بود.');
    }
}

$envFile = $root . '/.env';
$envExists = is_file($envFile);
if ($driver === 'sqlite') {
    if ($fresh && is_file($sqlitePath)) {
        @unlink($sqlitePath);
        $out('ℹ دیتابیس تست قبلی حذف شد (--fresh).');
    }
    if (!$envExists) {
        $key = bin2hex(random_bytes(32));
        $env = "APP_NAME=\"رفرال‌بات (تست محلی)\"\n"
            . "APP_ENV=local\n"
            . "APP_DEBUG=true\n"
            . "APP_URL=http://localhost:{$port}\n"
            . "APP_BASE_PATH=\n"
            . "APP_TIMEZONE=Asia/Tehran\n"
            . "APP_KEY={$key}\n"
            . "DB_DRIVER=sqlite\n"
            . "DB_DATABASE=storage/local.sqlite\n"
            . "SESSION_SECURE=false\n"
            . "BROADCAST_DELAY_MS=60\n"
            . "RB_INSTALLED=true\n";
        if (@file_put_contents($envFile, $env) === false) {
            $out('✘ نمی‌توان .env ساخت — دسترسی نوشتن در ریشهٔ پروژه را بررسی کنید.');
            exit(1);
        }
        $out('✔ .env برای حالت تست ساخته شد (SQLite).');
    } else {
        // اگر .env برای هاست ساخته شده، آن را دست نمی‌زنیم؛ فقط هشدار می‌دهیم.
        $out('ℹ .env موجود است و دست‌نخورده می‌ماند.');
        $out('  اگر می‌خواهید با SQLite اجرا شود، DB_DRIVER=sqlite و DB_DATABASE=storage/local.sqlite را تنظیم کنید');
        $out('  یا ابتدا .env را موقتاً تغییر نام دهید:  mv .env .env.host');
    }
}

// ------------------------------------------------------------------ آماده‌سازی دیتابیس
$_ENV['RB_TEST_MODE'] = '1';
require_once $root . '/src/bootstrap.php';

if ($driver === 'sqlite' && (string) \RB\Support\Env::get('DB_DRIVER', 'mysql') !== 'sqlite') {
    // .env موجود برای MySQL است ولی MySQL در دسترس نیست: موقتاً SQLite
    \RB\Support\Env::set('DB_DRIVER', 'sqlite');
    \RB\Support\Env::set('DB_DATABASE', $sqlitePath);
    \RB\Support\Database::setInstance(null);
    $out('⚠ .env روی MySQL تنظیم شده؛ این اجرا موقتاً از SQLite استفاده می‌کند.');
}

try {
    $db = \RB\Support\Database::instance();
    $db->pdo();
} catch (\Throwable $e) {
    $out('✘ اتصال به دیتابیس ناموفق: ' . $e->getMessage());
    exit(1);
}

$migrated = (new \RB\Support\Migrator($db))->run();
$out($migrated['applied'] === []
    ? '✔ جداول از قبل موجود بودند (' . count($migrated['skipped']) . ' Migration).'
    : '✔ ' . count($migrated['applied']) . ' Migration اجرا شد.');

// ------------------------------------------------------------------ داده اولیه
$seed = require $root . '/tests/_seed_http.php';

$out('');
$out('┌── آماده است ───────────────────────────────────────────────');
$out('│  پنل مدیر : http://localhost:' . $port . '/admin/login');
$out('│  ورود      : ciadmin / CiPassword123!');
$out('│  سلامت     : http://localhost:' . $port . '/healthz');
$out('│  کرون وب   : http://localhost:' . $port . '/cron/run?token=ci-cron-token-0123456789abcdef01234567&task=all');
$out('│');
$out('│  از گوشی (همان وای‌فای): http://IP-لپ‌تاپ:' . $port);
$out('│  برای بستن: Ctrl+C');
$out('└────────────────────────────────────────────────────────────');
$out('');

// ------------------------------------------------------------------ سرور داخلی
$cmd = escapeshellarg(PHP_BINARY)
    . ' -S ' . $host . ':' . $port
    . ' -t ' . escapeshellarg($root . '/public')
    . ' ' . escapeshellarg($root . '/public/index.php');

passthru($cmd, $code);
exit((int) $code);
