<?php
declare(strict_types=1);

namespace RB\Messengers;

use RB\Support\Crypto;
use RB\Support\Database;
use RB\Support\ErrorHandler;

/**
 * کلاس پایه مشترک درایورهای پیام‌رسان با محافظت کامل در برابر افشای توکن،
 * مدیریت خطای ایزوله، ثبت گزارش پیام و شبیه‌ساز ترنسپورت برای تست‌های خودکار.
 */
abstract class AbstractMessengerDriver implements MessengerDriverInterface
{
    /**
     * شبیه‌ساز ترنسپورت HTTP به تفکیک پیام‌رسان برای تست‌های خودکار:
     *   [platform => callable(string $method, array $params, array $context): array]
     * @var array<string,callable>
     */
    public static array $fakeTransports = [];

    protected Database $db;
    protected array $connection;
    protected string $token;
    protected string $secondaryToken;
    protected int $timeout = 15;

    public function __construct(array $connection, ?Database $db = null, ?string $tokenOverride = null)
    {
        $this->db = $db ?? Database::instance();
        $this->connection = $connection;
        $this->token = $tokenOverride !== null
            ? trim($tokenOverride)
            : trim((string) (Crypto::decrypt($connection['bot_token_enc'] ?? null) ?? ''));
        $this->secondaryToken = trim((string) (Crypto::decrypt($connection['secondary_token_enc'] ?? null) ?? ''));
    }

    public static function setFakeTransport(string $platform, ?callable $handler): void
    {
        $key = strtolower(trim($platform));
        if ($handler === null) {
            unset(self::$fakeTransports[$key]);
        } else {
            self::$fakeTransports[$key] = $handler;
        }
    }

    public static function clearFakeTransports(): void
    {
        self::$fakeTransports = [];
    }

    public function connection(): array
    {
        return $this->connection;
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    public function isEnabled(): bool
    {
        return !empty($this->connection['is_enabled']) && $this->isConfigured();
    }

    public function supports(string $capability): bool
    {
        return MessengerCapabilities::supports($this->platform(), $capability);
    }

    public function botUsername(): string
    {
        return ltrim(trim((string) ($this->connection['bot_username'] ?? '')), '@');
    }

    public function botName(): string
    {
        return trim((string) ($this->connection['bot_name'] ?? ''));
    }

    /** پاک‌سازی کامل هرگونه توکن از متن خطا یا لاگ پیش از ذخیره و نمایش */
    protected function scrubSecrets(string $text): string
    {
        foreach ([$this->token, $this->secondaryToken] as $secret) {
            if ($secret !== '' && strlen($secret) >= 6) {
                $text = str_replace([$secret, rawurlencode($secret), urlencode($secret)], '[REDACTED]', $text);
            }
        }
        return ErrorHandler::redact($text);
    }

    /** ارسال درخواست JSON POST استاندارد با cURL یا stream */
    protected function httpJson(string $url, string $methodName, array $payload = [], array $headers = []): array
    {
        $platform = $this->platform();
        if (isset(self::$fakeTransports[$platform])) {
            return (self::$fakeTransports[$platform])($methodName, $payload, [
                'mode' => 'json',
                'platform' => $platform,
                'has_token' => $this->token !== '',
            ]);
        }

        $body = $payload === [] ? '{}' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $hdrs = array_merge([
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ], $headers);

        $raw = false;
        $httpCode = 0;
        $err = '';
        $transportKind = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $hdrs,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 7,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'RefrallBot/' . (defined('RB_VERSION') ? RB_VERSION : '1.4'),
            ]);
            $raw = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = (string) curl_error($ch);
            $errno = (int) curl_errno($ch);
            $transportKind = match (true) {
                $errno === 28 => 'timeout',
                in_array($errno, [5, 6], true) => 'dns',
                in_array($errno, [35, 51, 60, 77, 80, 82, 83, 90, 91], true) => 'tls',
                $errno === 7 => 'connect',
                $errno !== 0 => 'network',
                default => '',
            };
            unset($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $hdrs),
                    'content' => $body,
                    'timeout' => $this->timeout,
                    'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $stream = @fopen($url, 'rb', false, $ctx);
            if ($stream !== false) {
                $raw = stream_get_contents($stream);
                $streamMeta = stream_get_meta_data($stream);
                fclose($stream);
                foreach (($streamMeta['wrapper_data'] ?? []) as $headerLine) {
                    if (preg_match('~^HTTP/\S+\s+(\d{3})~i', (string)$headerLine, $m)) $httpCode = (int)$m[1];
                }
            }
            if ($raw === false) {
                $err = 'خطای اتصال شبکه‌ای';
                $transportKind = 'network';
            }
        }

        if ($raw === false || $raw === '') {
            $safeErr = $this->scrubSecrets($err !== '' ? $err : 'عدم دریافت پاسخ از سرور');
            return [
                '_transport_ok' => false,
                '_http_code' => $httpCode,
                '_transport_kind' => $transportKind ?: 'network',
                '_error' => MessengerCapabilities::humanizeError($platform, $safeErr, $httpCode),
            ];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [
                '_transport_ok' => false,
                '_http_code' => $httpCode,
                '_transport_kind' => 'non_json',
                '_error' => MessengerCapabilities::humanizeError($platform, 'پاسخ غیر JSON از سرور دریافت شد', $httpCode),
            ];
        }
        $decoded['_transport_ok'] = true;
        $decoded['_http_code'] = $httpCode;
        return $decoded;
    }

    /** ارسال درخواست multipart/form-data (برای آپلود تصویر/فایل بدون افشای توکن) */
    protected function httpMultipart(string $url, string $methodName, array $fields, string $fileField, string $filePath, ?string $mimeType = null): array
    {
        $platform = $this->platform();
        if (isset(self::$fakeTransports[$platform])) {
            return (self::$fakeTransports[$platform])($methodName, $fields + [$fileField => $filePath], [
                'mode' => 'multipart',
                'platform' => $platform,
                'file_field' => $fileField,
                'file_path' => $filePath,
                'mime' => $mimeType,
            ]);
        }

        if (!is_file($filePath) || !is_readable($filePath)) {
            return ['_transport_ok' => false, '_http_code' => 0, '_error' => 'فایل تصویر یافت نشد یا قابل خواندن نیست.'];
        }
        if (!function_exists('curl_init')) {
            return ['_transport_ok' => false, '_http_code' => 0, '_error' => 'افزونه cURL برای آپلود فایل روی هاست فعال نیست.'];
        }

        $mime = $mimeType ?: 'application/octet-stream';
        if ($mimeType === null && function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $detected = finfo_file($fi, $filePath);
                unset($fi);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            }
        }

        $post = [];
        foreach ($fields as $k => $v) {
            if ($v === null) {
                continue;
            }
            $post[$k] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v;
        }
        $post[$fileField] = new \CURLFile($filePath, $mime, basename($filePath));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'RefrallBot/' . (defined('RB_VERSION') ? RB_VERSION : '1.4'),
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string) curl_error($ch);
        unset($ch);

        if ($raw === false || $raw === '') {
            $safeErr = $this->scrubSecrets($err !== '' ? $err : 'خطا در آپلود فایل');
            return [
                '_transport_ok' => false,
                '_http_code' => $httpCode,
                '_error' => MessengerCapabilities::humanizeError($platform, $safeErr, $httpCode),
            ];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['_transport_ok' => false, '_http_code' => $httpCode, '_error' => 'پاسخ نامعتبر هنگام آپلود فایل'];
        }
        $decoded['_transport_ok'] = true;
        $decoded['_http_code'] = $httpCode;
        return $decoded;
    }

    /** دانلود امن فایل باینری با سقف حجم ۱۰ مگابایت */
    protected function httpDownload(string $url, string $fileId = ''): ?string
    {
        $platform = $this->platform();
        if (isset(self::$fakeTransports[$platform])) {
            $res = (self::$fakeTransports[$platform])('__download__', ['file_id' => $fileId], ['mode' => 'download']);
            return isset($res['bytes']) && is_string($res['bytes']) ? $res['bytes'] : null;
        }
        if (!str_starts_with(strtolower($url), 'https://')) {
            return null;
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 7,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $data = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);
            if (is_string($data) && $data !== '' && $code >= 200 && $code < 300 && strlen($data) <= 10 * 1024 * 1024) {
                return $data;
            }
        }
        return null;
    }

    /**
     * تبدیل متن HTML تلگرامی به متن تمیز ساده (برای بله و روبیکا) با حفظ لینک‌ها، ایموجی‌ها و خطوط جدید
     */
    public static function htmlToPlain(string $html): string
    {
        if ($html === '') {
            return '';
        }
        // تبدیل <a href="URL">TEXT</a> به TEXT (URL) در صورتی که URL داخل TEXT نباشد
        $text = preg_replace_callback(
            '~<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~isu',
            static function (array $m): string {
                $href = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = trim(strip_tags($m[2]));
                if ($label === '' || $label === $href || str_contains($label, $href)) {
                    return $href;
                }
                return $label . ' (' . $href . ')';
            },
            $html
        ) ?? $html;

        $text = preg_replace('~<br\s*/?>~i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("~\n{3,}~", "\n\n", $text) ?? $text;
        return trim($text);
    }

    /**
     * تبدیل متن HTML به Markdown رسمی ایتا (بر اساس developer.eitaa.com/docs/Develop/SendMassage/)
     */
    public static function htmlToEitaaMarkdown(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $text = preg_replace_callback(
            '~<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~isu',
            static function (array $m): string {
                $href = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = trim(strip_tags($m[2]));
                return $label !== '' && $label !== $href ? '[' . $label . '](' . $href . ')' : $href;
            },
            $html
        ) ?? $html;
        $text = preg_replace('~<(?:b|strong)>(.*?)</(?:b|strong)>~isu', '**$1**', $text) ?? $text;
        $text = preg_replace('~<(?:i|em)>(.*?)</(?:i|em)>~isu', '__$1__', $text) ?? $text;
        $text = preg_replace('~<code>(.*?)</code>~isu', '`$1`', $text) ?? $text;
        $text = preg_replace('~<pre>(.*?)</pre>~isu', "```\n$1\n```", $text) ?? $text;
        $text = preg_replace('~<br\s*/?>~i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** ثبت آمار ارسال موفق یا ناموفق به تفکیک هر پیام‌رسان (ایزوله و بدون شکست عملیات اصلی) */
    protected function recordSendMetric(bool $ok, ?string $error = null): void
    {
        try {
            if (!$this->db->tableExists('messenger_connections')) {
                return;
            }
            if ($ok) {
                $this->db->run(
                    'UPDATE messenger_connections SET messages_sent = messages_sent + 1, updated_at = :now WHERE platform = :p',
                    ['now' => now(), 'p' => $this->platform()]
                );
            } else {
                $safeErr = mb_substr($this->scrubSecrets((string) $error), 0, 500);
                $this->db->run(
                    'UPDATE messenger_connections SET messages_failed = messages_failed + 1, last_error = :e, updated_at = :now WHERE platform = :p',
                    ['e' => $safeErr, 'now' => now(), 'p' => $this->platform()]
                );
                if ($safeErr !== '') {
                    MessengerRegistry::logError($this->platform(), 'send_message', $safeErr, 'sendMessage', null, [], $this->db);
                }
            }
        } catch (\Throwable) {
        }
    }

    /** ثبت پیام ورودی/خروجی در لاگ پیام‌ها با برچسب پیام‌رسان */
    public function logMessage(?int $userId, string $chatId, string $direction, string $kind, array $payload, string $status = 'ok', ?string $messageId = null): void
    {
        try {
            if (!$this->db->tableExists('telegram_messages')) {
                return;
            }
            $row = [
                'user_id' => $userId,
                'telegram_id' => ctype_digit($chatId) ? (int) $chatId : null,
                'direction' => $direction,
                'kind' => mb_substr($kind, 0, 40),
                'payload' => mb_substr($this->scrubSecrets((string) json_encode($payload, JSON_UNESCAPED_UNICODE)), 0, 2000),
                'chat_id' => mb_substr($chatId, 0, 64),
                'message_id' => $messageId !== null ? mb_substr($messageId, 0, 64) : null,
                'status' => mb_substr($status, 0, 20),
                'created_at' => now(),
            ];
            if ($this->db->columnExists('telegram_messages', 'platform')) {
                $row['platform'] = $this->platform();
            }
            $this->db->insert('telegram_messages', $row);
        } catch (\Throwable) {
        }
    }

    public function buildMiniAppUrl(string $baseMiniAppUrl, ?string $startParam = null): string
    {
        if (!$this->supports('miniapp')) {
            return '';
        }
        $base = trim($baseMiniAppUrl);
        if ($base === '' || !str_starts_with(strtolower($base), 'https://')) {
            return '';
        }
        $sep = str_contains($base, '?') ? '&' : '?';
        $out = $base;
        if ($this->platform() !== MessengerCapabilities::TELEGRAM && !str_contains($base, 'platform=')) {
            $out .= $sep . 'platform=' . rawurlencode($this->platform());
            $sep = '&';
        }
        if ($startParam !== null && $startParam !== '') {
            $out .= $sep . 'tgWebAppStartParam=' . rawurlencode($startParam);
        }
        return $out;
    }

    /** استخراج /start و پارامتر آن از متن پیام */
    public static function extractStartPayload(string $text): array
    {
        $trimmed = trim($text);
        if (!preg_match('~^/start(?:@[A-Za-z0-9_]+)?(?:\s+(.+))?$~iu', $trimmed, $m)) {
            return [false, null];
        }
        $payload = isset($m[1]) ? trim($m[1]) : null;
        return [true, ($payload !== '' ? $payload : null)];
    }
}
