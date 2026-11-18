<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * کمکی‌های درخواست HTTP
 */
final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = trim((string) Config::get('app.base_path', ''), '/');
        if ($base !== '' && str_starts_with(ltrim($path, '/'), $base)) {
            $path = substr(ltrim($path, '/'), strlen($base));
            $path = '/' . ltrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return false;
    }

    /** مبدأ (scheme://host[:port]) درخواست جاری */
    public static function origin(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $scheme = self::isHttps() ? 'https' : 'http';
        return $scheme . '://' . $host;
    }

    /**
     * آی‌پی کلاینت.
     * هدرهای پروکسی فقط وقتی معتبر شمرده می‌شوند که REMOTE_ADDR در TRUSTED_PROXIES باشد
     * (وگرنه هر کسی می‌تواند با X-Forwarded-For هویت جعل کند و قفل ورود/کنترل تقلب را دور بزند).
     */
    public static function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            $remote = '';
        }

        if ($remote !== '' && self::isTrustedProxy($remote)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $key) {
                $value = trim((string) ($_SERVER[$key] ?? ''));
                if ($value === '') {
                    continue;
                }
                $ip = trim(explode(',', $value)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $remote !== '' ? $remote : '0.0.0.0';
    }

    /** آیا آی‌پی در فهرست پروکسی‌های مطمئن (TRUSTED_PROXIES) است؟ */
    public static function isTrustedProxy(string $ip): bool
    {
        $list = (string) (Env::get('TRUSTED_PROXIES', '') ?? '');
        if (trim($list) === '') {
            return false;
        }
        foreach (explode(',', $list) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if ($entry === '*' || $entry === '0.0.0.0/0') {
                return true;
            }
            if (str_contains($entry, '/')) {
                if (self::ipInCidr($ip, $entry)) {
                    return true;
                }
                continue;
            }
            if ($entry === $ip) {
                return true;
            }
        }
        return false;
    }

    /** بررسی تعلق آی‌پی به محدوده CIDR (IPv4 و IPv6) */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($bits === null || !is_numeric($bits)) {
            return false;
        }
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $subnet);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = (int) $bits;
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = 0xFF << (8 - $remainder) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    public static function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    /**
     * آیا کلاینت پاسخ JSON می‌خواهد؟
     * (برای اینکه خطاها به‌جای HTML خام، JSON استاندارد باشند و RB.request
     *  بتواند پیام را به کاربر نشان دهد.)
     */
    public static function expectsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '')));
        if (str_contains($contentType, 'json')) {
            return true;
        }
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        return $requestedWith === 'xmlhttprequest';
    }

    public static function isAjax(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    }

    /**
     * بدنه JSON خام.
     *
     * اگر بدنه JSON نامعتبر (خراب) باشد، آرایهٔ خالی برمی‌گردد تا کد فراخوان
     * با «ورودی نامعتبر» رفتار کند. برای تشخیص «بدنهٔ سینتکسی خراب» از
     * Request::jsonInvalid() استفاده کنید.
     */
    public static function json(): array
    {
        $parsed = self::parseJsonBody();
        return is_array($parsed) ? $parsed : [];
    }

    /** آیا بدنهٔ درخواست JSON بود ولی معتبر نبود (سینتکس خراب)؟ */
    public static function jsonInvalid(): bool
    {
        self::parseJsonBody();
        return self::$jsonInvalid;
    }

    private static bool $jsonInvalid = false;
    private static bool $jsonParsed = false;
    /** @var mixed */
    private static $jsonCache = null;

    /**
     * @return mixed آرایه اگر JSON معتبر بود، null اگر بدنه JSON نبود
     */
    private static function parseJsonBody()
    {
        if (self::$jsonParsed) {
            return self::$jsonCache;
        }
        self::$jsonParsed = true;
        self::$jsonCache = null;
        self::$jsonInvalid = false;

        if (self::method() !== 'POST' && self::method() !== 'PUT' && self::method() !== 'PATCH') {
            return null;
        }

        $raw = self::raw();
        if (trim($raw) === '') {
            return null;
        }

        // فقط وقتی به‌عنوان JSON تفسیر می‌کنیم که هدر Content-Type این را بگوید
        // یا بدنه شبیه JSON باشد. (multipart/form-data و x-www-form-urlencoded
        // توسط PHP در $_POST پر می‌شوند و نباید دوباره پارس شوند.)
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '')));
        $looksJson = str_starts_with(ltrim($raw), '{') || str_starts_with(ltrim($raw), '[');
        if (!str_contains($contentType, 'json') && !($contentType === '' && $looksJson)) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::$jsonInvalid = true;
            return null;
        }
        self::$jsonCache = $data;
        return $data;
    }

    /**
     * همگام‌سازی بدنهٔ JSON با $_POST برای کنترلرهای پنل مدیریت و Mini App.
     *
     * وقتی فرانت‌اند (مثل RB.post یا miniapp.js یا نسخهٔ کش‌شدهٔ app.js) داده‌ها
     * را با Content-Type: application/json بفرستد، PHP به‌صورت خودکار $_POST را
     * پر نمی‌کند و تمام کنترلرهایی که $_POST می‌خوانند مقدار خالی می‌گیرند
     * (مثلاً «توکن ربات را وارد کنید»). این متد $_POST را از JSON پر می‌کند و
     * نام‌های براکت‌دار فرم (مثل levels[1][percent] و menus[2][title] و conditions[])
     * را هم به آرایهٔ استاندارد PHP تبدیل می‌کند.
     */
    public static function hydratePostFromJson(): void
    {
        if ($_POST !== [] || !in_array(self::method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        $json = self::json();
        if ($json === []) {
            return;
        }
        $_POST = self::expandBracketKeys($json);
    }

    /**
     * تبدیل کلیدهای براکت‌دار HTML (مثل a[b][c] یا items[]) به آرایهٔ تودرتو
     *
     * @param array<string|int,mixed> $data
     * @return array<string|int,mixed>
     */
    public static function expandBracketKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || !str_contains($key, '[')
                || !preg_match('~^([^\[\]]+)((?:\[[^\[\]]*\]){1,6})$~', $key, $m)) {
                $out[$key] = $value;
                continue;
            }
            $root = $m[1];
            preg_match_all('~\[([^\[\]]*)\]~', $m[2], $sm);
            $segments = $sm[1];
            if (!isset($out[$root]) || !is_array($out[$root])) {
                $out[$root] = [];
            }
            $cursor = &$out[$root];
            $lastIdx = count($segments) - 1;
            foreach ($segments as $idx => $seg) {
                $isLast = $idx === $lastIdx;
                if ($seg === '') {
                    if ($isLast) {
                        if (is_array($value)) {
                            foreach ($value as $item) {
                                $cursor[] = $item;
                            }
                        } else {
                            $cursor[] = $value;
                        }
                    } else {
                        $cursor[] = [];
                        $newKey = array_key_last($cursor);
                        $cursor = &$cursor[$newKey];
                    }
                } else {
                    if ($isLast) {
                        $cursor[$seg] = $value;
                    } else {
                        if (!isset($cursor[$seg]) || !is_array($cursor[$seg])) {
                            $cursor[$seg] = [];
                        }
                        $cursor = &$cursor[$seg];
                    }
                }
            }
            unset($cursor);
        }
        return $out;
    }

    /**
     * ورودی یکپارچهٔ درخواست: JSON یا فرم معمولی (یا multipart).
     *
     * اولویت: بدنهٔ JSON → $_POST. اگر هیچ‌کدام نبود، $_GET (فقط برای
     * پارامترهای ساده و بدون‌خطر مثل token کرون لازم است).
     *
     * چرا لازم است؟ کنترلر نصب قبلاً فقط $_POST را می‌خواند، در حالی که
     * RB.post() در JavaScript بدنه را با Content-Type: application/json و
     * JSON.stringify ارسال می‌کند. نتیجه: $_POST خالی → همهٔ مقادیر null →
     * پاسخ 422 «نام پایگاه داده و نام کاربری الزامی است» با وجود پر بودن فرم.
     *
     * @return array<string,mixed>
     */
    public static function input(): array
    {
        $json = self::json();
        if ($json !== []) {
            return self::expandBracketKeys($json);
        }
        if ($_POST !== []) {
            return $_POST;
        }
        return $_GET;
    }

    /**
     * خواندن یک مقدار از ورودی یکپارچه با تبدیل نوع قابل پیش‌بینی.
     *
     * @param array<string,mixed>|null $source اگر داده شده باشد از همان استفاده می‌شود
     */
    public static function field(string $key, mixed $default = null, ?array $source = null): mixed
    {
        $data = $source ?? self::input();
        if (!array_key_exists($key, $data)) {
            return $default;
        }
        $value = $data[$key];
        // FormData ممکن است همه‌چیز را رشته بفرستد؛ null/'' را یکسان می‌بینیم
        return $value;
    }

    /** مقدار رشته‌ای trim‌شده */
    public static function string(string $key, string $default = '', ?array $source = null): string
    {
        $value = self::field($key, null, $source);
        if ($value === null || is_array($value) || is_object($value)) {
            return $default;
        }
        $out = trim((string) $value);
        return $out === '' ? $default : $out;
    }

    /**
     * مقدار عددی صحیح (ارقام فارسی/عربی هم پذیرفته می‌شوند).
     * اگر مقدار نامعتبر باشد، null برمی‌گردد تا اعتبارسنجی بتواند خطا بدهد.
     */
    public static function int(string $key, ?int $default = null, ?array $source = null): ?int
    {
        $value = self::field($key, null, $source);
        if ($value === null || is_array($value) || is_object($value)) {
            return $default;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return $default;
        }
        // ارقام فارسی و عربی → لاتین
        $raw = strtr($raw, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $raw = str_replace([',', '٬', ' '], '', $raw);
        if (!preg_match('~^-?\d+$~', $raw)) {
            return $default;
        }
        return (int) $raw;
    }

    /**
     * مقدار بولی — برای checkbox ها که ممکن است نباشند، "on"، "1"، true یا 1 بیایند.
     */
    public static function bool(string $key, bool $default = false, ?array $source = null): bool
    {
        $data = $source ?? self::input();
        if (!array_key_exists($key, $data)) {
            return $default;
        }
        $value = $data[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_array($value) || is_object($value)) {
            return $default;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes', 'y'], true);
    }

    /**
     * بازنویسی بدنهٔ خام برای تست.
     *
     * در محیط تست (php://input فقط یک بار و فقط در SAPI واقعی قابل خواندن
     * است) با Request::setRawBody() می‌توان بدنهٔ JSON را شبیه‌سازی کرد.
     * این متد فقط زمانی اثر دارد که RB_TEST_MODE تعریف شده باشد.
     */
    private static ?string $rawOverride = null;

    public static function setRawBody(string $body): void
    {
        if (!defined('RB_TEST_MODE') && getenv('RB_TEST_MODE') !== '1') {
            throw new \LogicException('Request::setRawBody فقط در حالت تست قابل استفاده است.');
        }
        self::$rawOverride = $body;
        self::resetCaches();
    }

    /** پاک کردن کش‌های پارس بدنه (برای تست و درخواست‌های تازه) */
    public static function resetCaches(): void
    {
        self::$jsonParsed = false;
        self::$jsonCache = null;
        self::$jsonInvalid = false;
        self::$rawCache = null;
    }

    private static ?string $rawCache = null;

    public static function raw(): string
    {
        if (self::$rawOverride !== null) {
            return self::$rawOverride;
        }
        if (self::$rawCache === null) {
            self::$rawCache = (string) file_get_contents('php://input');
        }
        return self::$rawCache;
    }

    /**
     * بازگردانی کاربر. در محیط تست می‌توان با Request::$redirectHandler
     * به‌جای exit، یک RedirectException گرفت و مقصد را بررسی کرد.
     *
     * @var null|callable(string,int):void
     */
    public static $redirectHandler = null;

    public static function redirect(string $to, int $status = 302): never
    {
        if (self::$redirectHandler !== null) {
            (self::$redirectHandler)($to, $status);
            throw new RedirectException($to, $status);
        }
        if (!headers_sent()) {
            header('Location: ' . $to, true, $status);
        }
        echo '<meta http-equiv="refresh" content="0;url=' . e($to) . '">';
        exit;
    }
}
