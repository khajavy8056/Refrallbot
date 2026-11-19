<?php
/**
 * RefrallBot - bootstrap
 * نقطه‌ی ورود مشترک برای وب، ربات و Cron
 */
declare(strict_types=1);

define('RB_ROOT', dirname(__DIR__));
define('RB_APP', RB_ROOT . '/src');
define('RB_STORAGE', RB_ROOT . '/storage');
define('RB_VIEWS', RB_ROOT . '/views');
define('RB_VERSION', (static function (): string {
    $file = RB_ROOT . '/VERSION';
    if (is_file($file)) {
        $raw = trim((string) @file_get_contents($file));
        if ($raw !== '' && preg_match('~^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$~', $raw)) {
            return $raw;
        }
    }
    return '1.1.0';
})());

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    echo 'RefrallBot requires PHP 8.1 or newer.';
    exit;
}

require_once RB_APP . '/Support/functions.php';
require_once RB_APP . '/Support/helpers.php';
require_once RB_APP . '/Support/admin_helper.php';

spl_autoload_register(static function (string $class): void {
    $prefixes = ['RB\\' => RB_APP . '/'];
    foreach ($prefixes as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }
});

\RB\Support\Env::load(RB_ROOT . '/.env');

date_default_timezone_set(\RB\Support\Env::get('APP_TIMEZONE', 'Asia/Tehran'));

\RB\Support\Config::init(RB_ROOT . '/config');
\RB\Support\ErrorHandler::register();
