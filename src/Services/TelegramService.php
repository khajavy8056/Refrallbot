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
            return is_array($result) ? $result : ['ok' => false, 'description' => 'پاسخ تست نامعتبر'];
        }
        if ($this->token === '') {
            return ['ok' => false, 'description' => 'توکن ربات تنظیم نشده است.', 'error_code' => 0];
        }
        $url = self::API_BASE . '/bot' . $this->token . '/' . $method;

        $payload = json_encode($params, JSON_UNESCAPED_UNICODE);
        $response = $this->httpPost($url, (string) $payload, $timeout);

        if ($response['error'] !== null) {
            ErrorHandler::write('TELEGRAM', 'HTTP error on ' . $method . ': ' . $response['error']);
            return ['ok' => false, 'description' => 'خطای ارتباط با تلگرام: ' . $response['error'], 'error_code' => 0];
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'description' => 'پاسخ نامعتبر از تلگرام.', 'error_code' => 0];
        }
        if (empty($decoded['ok'])) {
            $description = (string) ($decoded['description'] ?? 'خطای نامشخص');
            ErrorHandler::write('TELEGRAM', 'API error on ' . $method . ' [' . ($decoded['error_code'] ?? 0) . ']: ' . $description);
        }
        return $decoded;
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
            curl_close($ch);
            if ($body === false) {
                return ['body' => '', 'error' => $error !== '' ? $error : 'اتصال برقرار نشد', 'status' => $status];
            }
            return ['body' => (string) $body, 'error' => null, 'status' => $status];
        }

        // fallback با stream context
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
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return ['body' => '', 'error' => 'اتصال به تلگرام برقرار نشد (افزونه cURL توصیه می‌شود).', 'status' => 0];
        }
        return ['body' => $body, 'error' => null, 'status' => 200];
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
        $url = self::API_BASE . '/bot' . $this->token . '/' . $method;
        $fields[$fileField] = new \CURLFile($filePath);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        $ok = is_array($decoded) && !empty($decoded['ok']);
        $this->trackSend($ok);
        return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'خطای ارسال فایل'];
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
            $description = (string) ($result['description'] ?? '');
            // خطای دسترسی یا عدم وجود کانال ≠ عدم عضویت
            $isPermission = $code === 403 || stripos($description, 'not enough rights') !== false
                || stripos($description, 'chat not found') !== false
                || stripos($description, 'member list is inaccessible') !== false;
            return [
                'ok' => false,
                'member' => null,
                'error' => $isPermission
                    ? 'ربات به این کانال دسترسی ندارد یا در آن ادمین نیست.'
                    : $description,
                'chat_id' => $chatId,
                'permission_error' => $isPermission,
            ];
        }

        $status = (string) ($result['result']['status'] ?? '');
        $member = in_array($status, ['creator', 'administrator', 'member'], true)
            || ($status === 'restricted' && !empty($result['result']['is_member']));

        return ['ok' => true, 'member' => $member, 'error' => null, 'chat_id' => $chatId, 'status' => $status];
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
