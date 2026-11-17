<?php
declare(strict_types=1);
/**
 * تست دود HTTP روی سرور واقعی PHP — داخل جریان تست‌های عادی.
 *
 * چرا اینجا و نه فقط در workflow؟
 *   بررسی مسیرها با curl در GitHub Actions، وقتی شکست می‌خورد فقط
 *   «exit code 1» می‌دهد و جزئیات (بدنهٔ پاسخ، خطای PHP) در لاگ گم می‌شود.
 *   این نسخه همان بررسی‌ها را انجام می‌دهد و در صورت شکست، خطوط
 *   `::error::` با بدنهٔ واقعی چاپ می‌کند تا در تب Actions دیده شود.
 *
 * در محیط‌هایی که proc_open یا سرور داخلی PHP ندارند (مثل wasm)
 * با پیام «رد شد» و کد ۰ کنار می‌رود تا تست‌های دیگر را نشکند.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

T::suite('دود HTTP روی سرور واقعی PHP');

/** چاپ خطای قابل‌مشاهده در GitHub Actions */
$ghError = static function (string $text): void {
    foreach (explode("\n", trim($text)) as $line) {
        $line = trim($line);
        if ($line !== '') {
            echo '::error::' . mb_substr($line, 0, 900) . "\n";
        }
    }
};

if (!function_exists('proc_open') || !function_exists('stream_socket_client')) {
    echo "⊘ proc_open/stream_socket_client در دسترس نیست — این تست رد شد (کد ۰).\n";
    exit(0);
}

// proc_open می‌تواند در php.ini غیرفعال شده باشد (disable_functions)
$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
if (in_array('proc_open', $disabled, true)) {
    echo "⊘ proc_open در disable_functions است — این تست رد شد (کد ۰).\n";
    exit(0);
}

$root = dirname(__DIR__);
$fail = 0;

// ---------------------------------------------------------- رگرسیون «بدنهٔ خالی»
//
// باگ: public/index.php هدرهای امنیتی را قبل از dispatch می‌فرستاد و خروجی
// کنترلر در بافر گرفته نمی‌شد. در برخی محیط‌ها این باعث می‌شد json_out
// هیچ چیزی تولید نکند و کل پاسخ «۲۰۰ با ۰ بایت» شود.
$entry = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
T::ok(
    str_contains($entry, 'ob_start()'),
    'public/index.php خروجی مسیر را بافر می‌کند (جلوگیری از پاسخ ۲۰۰ خالی)'
);
T::ok(
    str_contains($entry, 'EMPTY BODY'),
    'اگر بدنه خالی بماند، در لاگ هشدار ثبت می‌شود'
);
T::ok(
    !preg_match("~^\s*use\s+RB\\\\Support\\\\(Session|Request)~m", $entry),
    'public/index.php از نام کامل کلاس استفاده می‌کند (بدون ابهام use)'
);


// ---------------------------------------------------------- رگرسیون باگ CLI/HTTP — ایستا
//
// باگ واقعی: ErrorHandler، `cli-server` را مثل CLI حساب می‌کرد و به‌جای صفحه
// خطا فقط بدنه خالی با HTTP 200 برمی‌گرداند. این باعث شد عیب‌یابی تست دود
// ساعاتی طول بکشد. حالا بررسی می‌کنیم که این اشتباه برنگردد.
$handlerSource = (string) file_get_contents(dirname(__DIR__) . '/src/Support/ErrorHandler.php');
T::ok(
    !preg_match("~PHP_SAPI\s*===\s*'cli'\s*\|\|\s*PHP_SAPI\s*===\s*'cli-server'~", $handlerSource),
    'ErrorHandler دیگر cli-server (سرور داخلی PHP) را CLI حساب نمی‌کند'
);
T::ok(
    str_contains($handlerSource, "PHP_SAPI === 'cli'"),
    'شرط CLI واقعی در ErrorHandler وجود دارد'
);
T::ok(
    !str_contains($handlerSource, "!isset(\$_SERVER['REQUEST_METHOD']) || PHP_SAPI"),
    'شرط «نبود REQUEST_METHOD» به‌تنهایی باعث رفتار CLI نمی‌شود'
);




// ---------------------------------------------------------- دیتابیس تازه
$dbFile = sys_get_temp_dir() . '/rb-hs-' . getmypid() . '.sqlite';
foreach (['', '-wal', '-shm'] as $sfx) {
    @unlink($dbFile . $sfx);
}

$env = array_merge(getenv() ?: [], [
    'DB_DRIVER' => 'sqlite',
    'DB_DATABASE' => $dbFile,
    'APP_DEBUG' => '1',
    'PATH' => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin',
]);

$runProc = static function (string $cmd, array $env) use ($root): array {
    // proc_open ممکن است Warning بدهد (محیط wasm / disable_functions)
    $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    if (!is_resource($p)) {
        return ['code' => -1, 'out' => ''];
    }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($p), 'out' => $out];
};

$seed = $runProc(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/_seed_http.php'), $env);
if ($seed['code'] === -1) {
    // اجرای زیرپروسه در این محیط ممکن نیست (مثلاً wasm یا disable_functions)
    echo "⊘ امکان اجرای زیرپروسه نیست — تست دود HTTP رد شد (کد ۰).\n";
    exit(0);
}
T::eq(0, $seed['code'], 'آماده‌سازی دیتابیس تست HTTP (seed)');
if ($seed['code'] !== 0) {
    $ghError("seed failed:\n" . $seed['out']);
    exit(T::summary());
}

// ---------------------------------------------------------- پورت آزاد
$port = 0;
for ($try = 8400; $try < 8460; $try++) {
    $probe = @stream_socket_server('tcp://127.0.0.1:' . $try);
    if ($probe !== false) {
        fclose($probe);
        $port = $try;
        break;
    }
}
T::ok($port > 0, 'پورت آزاد برای سرور تست پیدا شد');
if ($port === 0) {
    exit(T::summary());
}

// ---------------------------------------------------------- سرور
$logFile = sys_get_temp_dir() . '/rb-hs-server-' . getmypid() . '.log';
$cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($root . '/public')
    . ' ' . escapeshellarg($root . '/public/index.php');
$server = @proc_open($cmd, [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', $logFile, 'a'],
    2 => ['file', $logFile, 'a'],
], $sp, $root, $env);

register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        @proc_terminate($server);
        @proc_close($server);
    }
});

$fetch = static function (string $path) use ($port): array {
    // نکتهٔ مهم: follow_location پیش‌فرض ۱ است؛ اگر آن را صفر نکنیم،
    // /admin (۳۰۲) به‌طور خودکار به /admin/login (۲۰۰) تبدیل می‌شود و
    // تست به‌اشتباه شکست می‌خورد. curl در جابِ دود هم -L ندارد.
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects' => 1,
        'timeout' => 15,
        'header' => "Accept: */*\r\n",
    ]]);
    $body = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $ctx);
    $status = 0;
    $headers = $http_response_header ?? [];
    foreach ($headers as $h) {
        if (preg_match('~^HTTP/\d\.\d\s+(\d{3})~', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    return ['status' => $status, 'body' => (string) $body, 'headers' => $headers];
};

// انتظار برای بالا آمدن
$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(250000);
    if ($fetch('/healthz')['status'] === 200) {
        $up = true;
        break;
    }
}
T::ok($up, 'سرور داخلی PHP بالا آمد و به /healthz پاسخ داد');

if (!$up) {
    exit(T::summary());
}

// ---------------------------------------------------------- عیب‌یابی محیط سرور
$diag = $fetch('/healthz');
echo "── servers ──\n";
echo 'php: ' . PHP_BINARY . ' / ' . PHP_VERSION . '\n';
echo 'cwd: ' . $root . '\n';
echo 'docroot: ' . $root . '/public\n';
echo 'server log: ' . $logFile . '\n';
echo 'healthz headers: ' . json_encode($diag['headers'], JSON_UNESCAPED_UNICODE) . '\n';
T::ok($diag['status'] === 200, '/healthz با HTTP 200 پاسخ می‌دهد');

// ---------------------------------------------------------- بررسی مسیرها
$cases = [
    '/healthz' => 200,
    '/' => 200,
    '/admin/login' => 200,
    '/app' => 200,
    '/webhook/telegram' => 200,
    '/admin' => 302,
    '/install' => 403,
    '/install.php' => 403,
    '/nope-404' => 404,
    '/cron/run' => 403,
];

$failedRoutes = [];
foreach ($cases as $path => $want) {
    $r = $fetch($path);
    T::eq($want, $r['status'], 'مسیر ' . $path . ' پاسخ ' . $want . ' می‌دهد');
    if ($r['status'] !== $want) {
        $failedRoutes[$path] = $r['status'] . ' (انتظار ' . $want . ')';
    }
    if ($r['status'] !== $want) {
        $body = trim($r['body']);
        $tail = array_slice(array_values(array_filter(array_map('trim', explode("\n", (string) @file_get_contents($logFile))))), -12);
        if ($tail) {
            $ghError("server.log بعد از " . $path . ":\n" . implode("\n", $tail));
        }
        $ghError(sprintf(
            "%s → HTTP %d (انتظار %d) — بدنه: %s — هدرها: %s",
            $path,
            $r['status'],
            $want,
            $body === '' ? '(خالی)' : mb_substr($body, 0, 400),
            implode(' | ', array_slice($r['headers'], 0, 6))
        ));
    }
}

// /healthz باید JSON با ok:true باشد
$health = $fetch('/healthz');
$hJson = json_decode($health['body'], true);
T::ok(is_array($hJson) && ($hJson['ok'] ?? false) === true, '/healthz پاسخ JSON با ok:true می‌دهد');
if (!is_array($hJson) || ($hJson['ok'] ?? false) !== true) {
    $ghError('/healthz body: ' . (trim($health['body']) === '' ? '(خالی)' : mb_substr(trim($health['body']), 0, 700)));
}

// assets
foreach (['assets/css/app.css', 'assets/js/app.js', 'assets/fonts/Vazirmatn-Regular.woff2'] as $asset) {
    $r = $fetch('/' . $asset);
    $okAsset = $r['status'] === 200 && strlen($r['body']) > 500;
    T::ok($okAsset, 'فایل استاتیک ' . $asset . ' سرو می‌شود');
    if (!$okAsset) {
        $ghError($asset . ' → HTTP ' . $r['status'] . ' (' . strlen($r['body']) . ' بایت)');
    }
}

// فایل‌های حساس
foreach (['.env', 'src/bootstrap.php', 'config/app.php'] as $secret) {
    $r = $fetch('/' . $secret);
    T::ok(in_array($r['status'], [403, 404], true), 'دسترسی به ' . $secret . ' مسدود است');
    if (!in_array($r['status'], [403, 404], true)) {
        $ghError($secret . ' قابل دسترسی بود → HTTP ' . $r['status']);
    }
}

// کرون وب با توکن
$cron = $fetch('/cron/run?token=ci-cron-token-0123456789abcdef01234567&task=maintenance');
$cronJson = json_decode($cron['body'], true);
T::eq(200, $cron['status'], 'کرون وب با توکن معتبر اجرا می‌شود');
T::ok(($cronJson['ok'] ?? false) === true, 'پاسخ کرون ok:true است');
if ($cron['status'] !== 200 || ($cronJson['ok'] ?? false) !== true) {
    $ghError('cron body: ' . mb_substr(trim($cron['body']), 0, 700));
}

// خطای PHP در لاگ سرور
$serverLog = (string) @file_get_contents($logFile);

// اگر هر تستی شکست خورد، لاگ خام سرور را کامل نشان بده (مهم‌ترین سرنخ)
if ($failedRoutes !== []) {
    $ghError('مسیرهای ناموفق: ' . json_encode($failedRoutes, JSON_UNESCAPED_UNICODE));
}
if (T::$failed > 0) {
    $lines = array_slice(array_values(array_filter(array_map('trim', explode("\n", $serverLog)))), -40);
    $ghError("server.log (last 40 lines):\n" . implode("\n", $lines));
}
$hasPhpError = (bool) preg_match('~(PHP (Fatal|Parse) error|Uncaught|PHP Warning|PHP Notice)~', $serverLog);
T::ok(!$hasPhpError, 'هیچ خطای PHP در لاگ سرور نیست');
if ($hasPhpError) {
    $ghError("PHP errors in server log:\n" . substr($serverLog, -2500));
}

if (is_resource($server)) {
    @proc_terminate($server);
    @proc_close($server);
}
@unlink($dbFile);
@unlink($logFile);

exit(T::summary());
