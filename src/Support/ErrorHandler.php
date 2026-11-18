<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * مدیریت خطا و لاگ (بدون افشای اطلاعات محرمانه)
 */
final class ErrorHandler
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        $debug = Env::bool('APP_DEBUG', false);

        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $no, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            throw new \ErrorException($message, 0, $no, $file, $line);
        });

        set_exception_handler(static function (\Throwable $e) use ($debug): void {
            self::logException($e);

            // فقط CLI واقعی (خط فرمان / cron) باید به STDERR بنویسد.
            //
            // ⚠️ `cli-server` عمداً اینجا نیست: سرور داخلی PHP (`php -S`) و
            // بسیاری از محیط‌های اجرایی، SAPI آن‌ها «cli-server» است ولی
            // درخواست واقعی HTTP را سرو می‌کنند. اگر آن‌ها را CLI حساب کنیم،
            // به‌جای صفحه خطا فقط یک بدنه خالی با HTTP 200 برمی‌گردد
            // (چون exit(1) بدون هیچ خروجی انجام می‌شود) و عیب‌یابی بی‌نهایت سخت می‌شود.
            $isCli = PHP_SAPI === 'cli' || (!isset($_SERVER['REQUEST_METHOD']) && PHP_SAPI !== 'cli-server');
            if ($isCli) {
                fwrite(STDERR, '[' . date('c') . '] ' . $e->getMessage() . "\n" . ($debug ? $e->getTraceAsString() : '') . "\n");
                exit(1);
            }
            if (headers_sent()) {
                echo '\n[خطا] ' . ($debug ? $e->getMessage() : 'خطای داخلی') . "\n";
                return;
            }
            // ⚠️ اگر تا اینجا هیچ خروجی تولید نشده باشد، پاسخ «۲۰۰ با بدنهٔ خالی»
            // می‌شود و عیب‌یابی عملاً غیرممکن است. پس وضعیت را حتماً ۵۰۰ می‌کنیم
            // و همیشه بدنه تولید می‌کنیم.
            // HttpException کد وضعیت خودش را دارد (مثلاً 409 یا 422) و نباید
            // همیشه ۵۰۰ گزارش شود.
            $status = $e instanceof \RB\Support\HttpException ? $e->statusCode() : 500;
            if ($status < 400 || $status > 599) {
                $status = 500;
            }
            $isJson = \RB\Support\Request::expectsJson()
                || str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/');
            http_response_code($status);
            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => false,
                    'code' => $status === 500 ? 'internal_error' : 'request_failed',
                    'error' => $debug
                        ? $e->getMessage()
                        : ($status === 500
                            ? 'خطای داخلی سرور رخ داد. لطفاً بعداً تلاش کنید.'
                            : $e->getMessage()),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }
            $message = $debug ? $e->getMessage() : 'خطای داخلی سرور';
            $trace = $debug ? $e->getTraceAsString() : '';
            if (is_file(RB_VIEWS . '/errors/500.php')) {
                include RB_VIEWS . '/errors/500.php';
            } else {
                echo '<h1>500</h1><pre>' . htmlspecialchars($message) . '</pre>';
            }
            if ($debug) {
                echo '\n<!-- ' . htmlspecialchars($e->getFile() . ':' . $e->getLine()) . ' -->';
            }
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::write('FATAL', $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
            }
        });
    }

    public static function logException(\Throwable $e): void
    {
        self::write('ERROR', sprintf(
            "%s: %s in %s:%d\n%s",
            get_class($e),
            self::redact($e->getMessage()),
            $e->getFile(),
            $e->getLine(),
            substr($e->getTraceAsString(), 0, 4000)
        ));
    }

    public static function write(string $level, string $message): void
    {
        if (!is_dir(RB_STORAGE . '/logs')) {
            @mkdir(RB_STORAGE . '/logs', 0775, true);
        }
        $line = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $level, self::redact($message));
        @file_put_contents(RB_STORAGE . '/logs/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * حذف توکن‌ها و رمزها از متن لاگ
     */
    public static function redact(string $text): string
    {
        $patterns = [
            '/\b\d{8,10}:[A-Za-z0-9_-]{30,50}\b/',          // bot token
            '/(bot[0-9]{8,10}:)[A-Za-z0-9_-]+/i',
            '/("?(?:password|passwd|token|secret|api_key|card_number)"?\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,;&]+)/i',
            '/(Bearer\s+)[A-Za-z0-9._-]+/i',
        ];
        return (string) preg_replace($patterns, '$1[REDACTED]', $text);
    }
}
