<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Crypto;
use RB\Support\Database;
use RB\Support\ErrorHandler;

/**
 * کلاینت Telegram Bot API
 * - توکن به‌صورت رمزنگاری‌شده ذخیره می‌شود و هرگز در لاگ‌ها چاپ نمی‌شود.
 * - تمام خطاها به‌صورت ساختاریافته برگردانده می‌شوند.
 */
final class TelegramService
{
    public const API_BASE = 'https://api.telegram.org';

    private string $token;
    private Database $db;
    private static ?array $settingsCache = null;

    /**
     * ترانسپورت جایگزین برای تست خودکار (در محیط تولید همیشه null است).
     * @var null|callable(string $method, array $params): array
     */
    public static $fakeTransport = null;

    public function __construct(?string $token = null, ?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
        if ($token !== null) {
            $this->token = $token;
        } else {
            $settings = self::settings($this->db);
            $decrypted = Crypto::decrypt($settings['bot_token_enc'] ?? null);
            $this->token = (string) ($decrypted ?? '');
        }
    }

    public static function settings(?Database $db = null): array
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }
        $db ??= Database::instance();
        if (!$db->tableExists('telegram_settings')) {
            return [];
        }
        $row = $db->first('SELECT * FROM telegram_settings ORDER BY id ASC LIMIT 1');
        return self::$settingsCache = $row ?? [];
    }

    public static function forgetSettingsCache(): void
    {
        self::$settingsCache = null;
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    public static function configured(): bool
    {
        $settings = self::settings();
        return !empty($settings['bot_token_enc']);
    }

    /**
     * فراخوانی متد API تلگرام
     * @return array{ok:bool, result?:mixed, description?:string, error_code?:int, parameters?:array}
     */
    public function call(string $method, array $params = [], int $timeout = 20): array
    {
        if (self::$fakeTransport !== null) {
            /** @var callable $fake */
            $fake = self::$fakeTransport;
            $result = $fake($method, $params);
            if (!is_array($result)) {
                return self::failure('پاسخ تست نامعتبر');
            }
            // خروجی ترنسپورت ساختگی هم از همان مسیر عادی‌سازی می‌گذرد تا
            // تست‌ها همان شکل خروجی تولید را ببینند.
            $status = (int) ($result['http_status'] ?? 0);
            return self::normalize($result, $status);
        }
        if ($this->token === '') {
            return self::failure('توکن ربات تنظیم نشده است. ابتدا در پنل مدیریت توکن را ذخیره کنید.', 401);
        }
        $url = self::API_BASE . '/bot' . $this->token . '/' . $method;

        $payload = json_encode($params, JSON_UNESCAPED_UNICODE);
        $response = $this->httpPost($url, (string) $payload, $timeout);

        if ($response['error'] !== null) {
            // پیام خطای cURL هرگز شامل توکن نیست، اما برای اطمینان پاک‌سازی می‌کنیم.
            ErrorHandler::write('TELEGRAM', 'transport error on ' . $method . ': ' . self::redact($response['error']));
            return self::failure(
                'خطای ارتباط با تلگرام: ' . self::redact($response['error']),
                0,
                $response['status']
            );
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            // پاسخ HTML پروکسی/دروازه یا بدنهٔ خالی
            $status = $response['status'];
            ErrorHandler::write('TELEGRAM', 'non-JSON response on ' . $method . ' (HTTP ' . $status . ')');
            return self::failure(
                $status >= 400
                    ? 'پاسخ غیرمنتظره از تلگرام (HTTP ' . $status . '). احتمالاً درخواست از سمت پروکسی/فایروال مسدود شده است.'
                    : 'پاسخ نامعتبر از تلگرام.',
                $status >= 400 ? $status : 0,
                $status
            );
        }

        // ---- شکل یکسان خروجی در همهٔ مسیرها ----
        return self::normalize($decoded, (int) $response['status']);
    }

    /**
     * یکسان‌سازی خروجی API تلگرام.
     * همیشه: ok, description, error_code, http_status (+ result / retry_after)
     * @return array{ok:bool,description:string,error_code:int,http_status:int}
     */
    private static function normalize(array $decoded, int $httpStatus): array
    {
        $ok = !empty($decoded['ok']);
        $errorCode = (int) ($decoded['error_code'] ?? 0);
        if ($errorCode === 0 && $httpStatus >= 400) {
            $errorCode = $httpStatus;
        }
        $description = (string) ($decoded['description'] ?? ($ok ? '' : 'خطای نامشخص'));
        $retryAfter = isset($decoded['parameters']['retry_after'])
            ? (int) $decoded['parameters']['retry_after']
            : null;
        if ($errorCode === 429 && ($retryAfter === null || $retryAfter <= 0)) {
            $retryAfter = 5; // مقدار پیش‌فرض محافظه‌کارانه
        }

        if (!$ok && $description !== '') {
            ErrorHandler::write(
                'TELEGRAM',
                'API error [' . $errorCode . ']'
                    . ($retryAfter !== null ? ' retry_after=' . $retryAfter : '')
                    . ': ' . self::redact($description)
            );
        }

        $out = [
            'ok' => $ok,
            'description' => $description,
            'error_code' => $errorCode,
            'http_status' => $httpStatus,
        ];
        if (array_key_exists('result', $decoded)) {
            $out['result'] = $decoded['result'];
        }
        if ($retryAfter !== null) {
            $out['retry_after'] = $retryAfter;
            $out['parameters'] = ['retry_after' => $retryAfter];
        } elseif (isset($decoded['parameters']) && is_array($decoded['parameters'])) {
            $out['parameters'] = $decoded['parameters'];
        }
        return $out;
    }

    /**
     * پاسخ خطای یکسان (شکل خروجی همیشه یکی است)
     * @return array{ok:bool,description:string,error_code:int,http_status:int}
     */
    private static function failure(string $description, int $errorCode = 0, int $httpStatus = 0): array
    {
        return [
            'ok' => false,
            'description' => $description,
            'error_code' => $errorCode,
            'http_status' => $httpStatus,
        ];
    }

    /**
     * پاک‌سازی توکن ربات از هر متنی که ممکن است لاگ شود.
     * الگوی توکن تلگرام: <digits>:<35+ chars>
     */
    public static function redact(string $text): string
    {
        // توکن تلگرام: ۶ تا ۱۲ رقم، دو نقطه، سپس ۳۰+ کاراکتر. مرز کلمه در
        // ابتدا استفاده نمی‌شود چون توکن معمولاً داخل URL و چسبیده به «bot»
        // می‌آید (bot123456789:AA...).
        return (string) preg_replace('/\d{6,12}:[A-Za-z0-9_-]{30,}/', 'bot<redacted>', $text);
    }

    /** @return array{body:string,error:?string,status:int} */
    private function httpPost(string $url, string $jsonBody, int $timeout): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonBody,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch); // در PHP 8.5 تابع curl_close منسوخ شده؛ CurlHandle خودکار بسته می‌شود
            if ($body === false) {
                return ['body' => '', 'error' => $error !== '' ? $error : 'اتصال برقرار نشد', 'status' => $status];
            }
            return ['body' => (string) $body, 'error' => null, 'status' => $status];
        }

        // fallback با stream context (بدون $http_response_header که در PHP 8.5 منسوخ شده)
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $jsonBody,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $fp = @fopen($url, 'rb', false, $context);
        if ($fp === false) {
            $lastError = error_get_last()['message'] ?? '';
            return [
                'body' => '',
                'error' => 'اتصال به تلگرام برقرار نشد (افزونه cURL توصیه می‌شود).'
                    . ($lastError !== '' ? ' ' . self::redact($lastError) : ''),
                'status' => 0,
            ];
        }
        $meta = stream_get_meta_data($fp);
        $body = stream_get_contents($fp);
        fclose($fp);
        $status = 0;
        $wrapperHeaders = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
        foreach ($wrapperHeaders as $line) {
            if (is_string($line) && preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1]; // آخرین هدر = پاسخ نهایی (پس از ریدایرکت)
            }
        }
        if ($body === false) {
            return ['body' => '', 'error' => 'خواندن پاسخ تلگرام ناموفق بود.', 'status' => $status];
        }
        return ['body' => $body, 'error' => null, 'status' => $status > 0 ? $status : 200];
    }

    /** دریافت اطلاعات ربات */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $options);
        $result = $this->call('sendMessage', $params);
        $this->trackSend($result['ok'] ?? false);
        return $result;
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, array $options = []): array
    {
        return $this->call('editMessageText', array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $options));
    }

    public function answerCallbackQuery(string $callbackId, string $text = '', bool $alert = false): array
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => mb_substr($text, 0, 190),
            'show_alert' => $alert,
        ]);
    }

    public function deleteMessage(int|string $chatId, int $messageId): array
    {
        return $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    public function getChatMember(int|string $chatId, int $userId): array
    {
        return $this->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    }

    public function setWebhook(string $url, array $options = []): array
    {
        return $this->call('setWebhook', array_merge([
            'url' => $url,
            'max_connections' => 40,
            'allowed_updates' => ['message', 'callback_query', 'my_chat_member', 'chat_member'],
            'drop_pending_updates' => false,
        ], $options), 25);
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => false]);
    }

    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo');
    }

    /**
     * دریافت آپدیت‌ها با Long Polling (وقتی Webhook در دسترس نیست)
     * @return array{ok:bool,result?:array,description?:string}
     */
    public function getUpdates(int $offset = 0, int $timeout = 25, int $limit = 100): array
    {
        return $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => max(0, min(50, $timeout)),
            'limit' => max(1, min(100, $limit)),
            'allowed_updates' => ['message', 'callback_query', 'my_chat_member', 'chat_member'],
        ], $timeout + 10);
    }

    public function setMyCommands(array $commands): array
    {
        return $this->call('setMyCommands', ['commands' => $commands]);
    }

    public function setChatMenuButton(array $params = []): array
    {
        return $this->call('setChatMenuButton', $params);
    }

    /** ارسال فایل با multipart */
    public function sendFile(string $method, array $fields, string $fileField, string $filePath): array
    {
        if (!is_file($filePath)) {
            return ['ok' => false, 'description' => 'فایل یافت نشد.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'description' => 'ارسال فایل نیازمند افزونه cURL است.'];
        }
        if ($this->token === '') {
            return self::failure('توکن ربات تنظیم نشده است.');
        }
        $url = self::API_BASE . '/bot' . $this->token . '/' . $method;
        $fields[$fileField] = new \CURLFile($filePath);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            // تأیید گواهی SSL روی مسیر آپلود فایل هم الزامی است
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($body === false) {
            ErrorHandler::write('TELEGRAM', 'file upload transport error on ' . $method . ': ' . self::redact((string) $curlError));
            $this->trackSend(false);
            return self::failure('خطای ارتباط در ارسال فایل: ' . self::redact((string) $curlError), 0, $status);
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            ErrorHandler::write('TELEGRAM', 'non-JSON response on file upload ' . $method . ' (HTTP ' . $status . ')');
            $this->trackSend(false);
            return self::failure('پاسخ نامعتبر از تلگرام در ارسال فایل (HTTP ' . $status . ').', $status, $status);
        }

        $ok = !empty($decoded['ok']);
        $this->trackSend($ok);
        $errorCode = (int) ($decoded['error_code'] ?? 0);
        $retryAfter = isset($decoded['parameters']['retry_after']) ? (int) $decoded['parameters']['retry_after'] : null;
        if (!$ok) {
            ErrorHandler::write('TELEGRAM', 'file upload API error on ' . $method . ' [' . $errorCode . ']: ' . self::redact((string) ($decoded['description'] ?? '')));
        }
        $out = [
            'ok' => $ok,
            'description' => (string) ($decoded['description'] ?? ''),
            'error_code' => $errorCode,
            'http_status' => $status,
        ];
        if (array_key_exists('result', $decoded)) {
            $out['result'] = $decoded['result'];
        }
        if ($retryAfter !== null) {
            $out['retry_after'] = $errorCode === 429 && $retryAfter <= 0 ? 5 : $retryAfter;
            $out['parameters'] = ['retry_after' => $out['retry_after']];
        }
        return $out;
    }

    private function trackSend(bool $ok): void
    {
        try {
            if (!empty(self::settings($this->db)['id'])) {
                $this->db->run(
                    'UPDATE telegram_settings SET ' . ($ok ? 'messages_sent = messages_sent + 1' : 'messages_failed = messages_failed + 1') . ', updated_at = :now',
                    ['now' => now()]
                );
            }
        } catch (\Throwable) {
            // بی‌اهمیت
        }
    }

    /** ساخت پیام با دکمه‌های شیشه‌ای */
    public static function inlineKeyboard(array $rows): array
    {
        $keyboard = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $button) {
                $line[] = $button;
            }
            if ($line) {
                $keyboard[] = $line;
            }
        }
        return ['inline_keyboard' => $keyboard];
    }

    public static function replyKeyboard(array $rows, bool $resize = true, bool $oneTime = false): array
    {
        $keyboard = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $text) {
                $line[] = ['text' => $text];
            }
            if ($line) {
                $keyboard[] = $line;
            }
        }
        return [
            'keyboard' => $keyboard,
            'resize_keyboard' => $resize,
            'one_time_keyboard' => $oneTime,
            'is_persistent' => true,
        ];
    }

    /**
     * بررسی عضویت کاربر در کانال
     * @return array{ok:bool, member:?bool, error:?string, chat_id:string}
     */
    public function checkMembership(array $channel, int $telegramUserId): array
    {
        $chatId = (string) $channel['chat_id'];
        $result = $this->getChatMember($chatId, $telegramUserId);

        if (!($result['ok'] ?? false)) {
            $code = (int) ($result['error_code'] ?? 0);
            $lower = mb_strtolower((string) ($result['description'] ?? ''));

            // کاربر واقعاً در کانال نیست (هرگز عضو نشده / شناسه نامعتبر)
            if (str_contains($lower, 'user not found') || str_contains($lower, 'participant_id_invalid')) {
                return [
                    'ok' => true,
                    'member' => false,
                    'error' => null,
                    'chat_id' => $chatId,
                    'status' => 'unknown_user',
                    'reason_code' => 'not_member',
                ];
            }

            // این‌ها خطای پیکربندی/دسترسی هستند، نه «عدم عضویت»
            $isPermission = $code === 403
                || str_contains($lower, 'not enough rights')
                || str_contains($lower, 'chat not found')
                || str_contains($lower, 'member list is inaccessible')
                || str_contains($lower, 'bot is not a member')
                || str_contains($lower, 'bot was blocked');
            $isTransient = $code === 429 || $code >= 500 || $code === 0;

            return [
                'ok' => false,
                'member' => null,
                'error' => match (true) {
                    str_contains($lower, 'chat not found')
                        => 'کانال یافت نشد؛ شناسهٔ کانال یا عمومی‌بودن آن را بررسی کنید.',
                    str_contains($lower, 'not enough rights') || str_contains($lower, 'member list is inaccessible')
                        => 'ربات در این کانال ادمین نیست یا حق دیدن اعضا را ندارد.',
                    str_contains($lower, 'bot is not a member') || str_contains($lower, 'bot was blocked')
                        => 'ربات عضو این کانال نیست؛ ابتدا ربات را به کانال اضافه کنید.',
                    $isTransient
                        => 'تلگرام در دسترس نیست یا محدودیت نرخ اعمال شده؛ چند لحظه بعد تلاش کنید.',
                    default => (string) ($result['description'] ?? 'خطای نامشخص'),
                },
                'chat_id' => $chatId,
                'permission_error' => $isPermission,
                'transient' => $isTransient,
                'reason_code' => $isPermission ? 'permission' : ($isTransient ? 'transient' : 'unknown'),
                'retry_after' => $result['retry_after'] ?? null,
            ];
        }

        // وضعیت‌های ممکن: creator, administrator, member, restricted, left, kicked
        $status = (string) ($result['result']['status'] ?? '');
        $member = match ($status) {
            'creator', 'administrator', 'member' => true,
            'restricted' => !empty($result['result']['is_member']),
            'left', 'kicked' => false,
            default => false,
        };

        return [
            'ok' => true,
            'member' => $member,
            'error' => null,
            'chat_id' => $chatId,
            'status' => $status,
            'reason_code' => $member ? 'member' : ($status === 'kicked' ? 'kicked' : 'not_member'),
        ];
    }

    /**
     * بررسی دسترسی ربات به یک کانال (آیا ربات ادمین است؟)
     * @return array{ok:bool,admin:bool,message:string,status:string}
     */
    public function checkBotAccess(array $channel): array
    {
        $settings = self::settings($this->db);
        $botId = (int) ($settings['bot_id'] ?? 0);
        if ($botId <= 0) {
            return ['ok' => false, 'admin' => false, 'status' => '', 'message' => 'شناسهٔ ربات مشخص نیست؛ ابتدا «تست اتصال» را اجرا کنید.'];
        }
        $chatId = (string) $channel['chat_id'];
        $result = $this->getChatMember($chatId, $botId);
        if (!($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'admin' => false,
                'status' => '',
                'message' => (string) ($result['description'] ?? 'دسترسی به کانال برقرار نشد'),
            ];
        }
        $status = (string) ($result['result']['status'] ?? '');
        $admin = in_array($status, ['creator', 'administrator'], true);
        return [
            'ok' => true,
            'admin' => $admin,
            'status' => $status,
            'message' => $admin
                ? 'ربات ادمین کانال است.'
                : 'ربات در کانال «' . $chatId . '» ادمین نیست (وضعیت: ' . $status . ').',
        ];
    }

    /** بررسی صحت توکن و ذخیره اطلاعات ربات */
    public function testAndStore(?string $plainToken = null, ?int $adminId = null): array
    {
        $db = $this->db;
        $result = $this->getMe();
        $settings = self::settings($db);
        $settingsId = (int) ($settings['id'] ?? 0);

        if (!($result['ok'] ?? false)) {
            if ($settingsId) {
                $db->update('telegram_settings', [
                    'connection_status' => 'error',
                    'last_error' => mb_substr((string) ($result['description'] ?? 'خطای نامشخص'), 0, 900),
                    'last_test_at' => now(),
                    'updated_at' => now(),
                ], 'id = :id', ['id' => $settingsId]);
                self::forgetSettingsCache();
            }
            return ['ok' => false, 'error' => $result['description'] ?? 'خطای نامشخص'];
        }

        $bot = $result['result'];
        $data = [
            'bot_id' => (int) ($bot['id'] ?? 0),
            'bot_username' => (string) ($bot['username'] ?? ''),
            'bot_name' => (string) ($bot['first_name'] ?? ''),
            'connection_status' => 'connected',
            'last_error' => null,
            'last_test_at' => now(),
            'updated_at' => now(),
        ];
        if ($plainToken !== null) {
            $data['bot_token_enc'] = Crypto::encrypt($plainToken);
        } elseif (empty($settings['bot_token_enc'])) {
            $data['bot_token_enc'] = Crypto::encrypt($this->token);
        }

        if ($settingsId) {
            $db->update('telegram_settings', $data, 'id = :id', ['id' => $settingsId]);
        } else {
            $data['created_at'] = now();
            $db->insert('telegram_settings', $data);
        }
        self::forgetSettingsCache();
        return ['ok' => true, 'bot' => $bot];
    }

    /** آدرس Webhook پیشنهادی بر اساس دامنه/آی‌پی فعلی */
    public function suggestedWebhookUrl(): string
    {
        $stored = (string) (SettingsService::get('webhook_base_url', '') ?: '');
        $base = $stored !== '' ? rtrim($stored, '/') : \RB\Support\Request::origin();
        $path = (string) \RB\Support\Config::get('app.base_path', '');
        $path = rtrim($path, '/');
        return $base . $path . '/webhook/telegram';
    }

    public function maskedToken(): string
    {
        return Crypto::maskSecret($this->token);
    }

    /** توکن برای نمایش ماسک‌شده (فقط در سرور) */
    public function tokenForDisplay(): string
    {
        return $this->token;
    }
}
