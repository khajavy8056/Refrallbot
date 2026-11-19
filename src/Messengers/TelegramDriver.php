<?php
declare(strict_types=1);

namespace RB\Messengers;

use RB\Services\TelegramService;
use RB\Support\Database;

/**
 * درایور رسمی تلگرام (Telegram Bot API — https://core.telegram.org/bots/api)
 *
 * ۱۰۰٪ سازگار با TelegramService موجود (Webhook با Secret Header، Mini App،
 * مدیریت نام/Bio/توضیحات/تصویر پروفایل، دکمه copy_text و بررسی خودکار عضویت کانال).
 */
final class TelegramDriver extends AbstractMessengerDriver
{
    private TelegramService $tg;

    public function __construct(array $connection, ?Database $db = null, ?string $tokenOverride = null, ?TelegramService $telegramService = null)
    {
        parent::__construct($connection, $db, $tokenOverride);
        $this->tg = $telegramService ?? new TelegramService($this->token !== '' ? $this->token : null, $this->db);
    }

    public function platform(): string
    {
        return MessengerCapabilities::TELEGRAM;
    }

    public function telegramService(): TelegramService
    {
        return $this->tg;
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' || $this->tg->isConfigured();
    }

    private function callApi(string $method, array $params = []): array
    {
        if (isset(self::$fakeTransports[MessengerCapabilities::TELEGRAM])) {
            return (self::$fakeTransports[MessengerCapabilities::TELEGRAM])($method, $params, ['platform' => 'telegram']);
        }
        return $this->tg->call($method, $params);
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'توکن ربات تلگرام ثبت نشده است.'];
        }
        $res = $this->callApi('getMe');
        if (!($res['ok'] ?? false) || !is_array($res['result'] ?? null)) {
            $err = MessengerCapabilities::humanizeError(
                'telegram',
                (string) ($res['description'] ?? 'خطا در اتصال به سرور تلگرام'),
                (int) ($res['error_code'] ?? 0)
            );
            return ['ok' => false, 'error' => $err];
        }
        $me = $res['result'];
        $username = ltrim((string) ($me['username'] ?? ''), '@');
        return [
            'ok' => true,
            'bot' => [
                'id' => (string) ($me['id'] ?? ''),
                'username' => $username,
                'name' => trim((string) (($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''))),
                'share_url' => $username !== '' ? 'https://t.me/' . $username : '',
            ],
        ];
    }

    private function buildMarkup(array $options): ?array
    {
        if (isset($options['reply_markup'])) {
            if (is_array($options['reply_markup'])) {
                return $options['reply_markup'];
            }
            if (is_string($options['reply_markup'])) {
                $d = json_decode($options['reply_markup'], true);
                return is_array($d) ? $d : null;
            }
        }
        if (!empty($options['remove_keyboard'])) {
            return ['remove_keyboard' => true];
        }
        if (!empty($options['inline_rows']) && is_array($options['inline_rows'])) {
            return TelegramService::inlineKeyboard($options['inline_rows']);
        }
        if (!empty($options['reply_rows']) && is_array($options['reply_rows'])) {
            return TelegramService::replyKeyboard($options['reply_rows']);
        }
        return null;
    }

    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        $markup = $this->buildMarkup($options);
        $extra = [];
        if ($markup !== null) {
            $extra['reply_markup'] = $markup;
        }
        if (isset($options['parse_mode'])) {
            $extra['parse_mode'] = $options['parse_mode'];
        }
        if (isset($options['disable_web_page_preview'])) {
            $extra['disable_web_page_preview'] = (bool) $options['disable_web_page_preview'];
        }

        if (isset(self::$fakeTransports[MessengerCapabilities::TELEGRAM])) {
            $res = $this->callApi('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'] + $extra);
        } else {
            $res = $this->tg->sendMessage($chatId, $text, $extra);
        }

        $ok = (bool) ($res['ok'] ?? false);
        $this->recordSendMetric($ok, $ok ? null : (string) ($res['description'] ?? ''));
        return [
            'ok' => $ok,
            'message_id' => isset($res['result']['message_id']) ? (string) $res['result']['message_id'] : null,
            'description' => (string) ($res['description'] ?? ''),
            'raw' => $res,
        ];
    }

    public function sendPhoto(int|string $chatId, string $photoSource, string $caption = '', array $options = []): array
    {
        $markup = $this->buildMarkup($options);
        $cachedId = trim((string) ($options['file_id'] ?? ''));
        $trimmedCaption = mb_substr($caption, 0, 1024);

        if ($cachedId === '' && is_file($photoSource) && !str_starts_with($photoSource, 'https://')) {
            $fields = [
                'chat_id' => (string) $chatId,
                'caption' => $trimmedCaption,
                'parse_mode' => (string) ($options['parse_mode'] ?? 'HTML'),
            ];
            if ($markup !== null) {
                $fields['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
            }
            if (isset(self::$fakeTransports[MessengerCapabilities::TELEGRAM])) {
                $res = $this->callApi('sendPhoto', $fields + ['photo' => $photoSource]);
            } else {
                $res = $this->tg->sendFile('sendPhoto', $fields, 'photo', $photoSource);
            }
        } else {
            $params = [
                'chat_id' => $chatId,
                'photo' => $cachedId !== '' ? $cachedId : $photoSource,
                'caption' => $trimmedCaption,
                'parse_mode' => (string) ($options['parse_mode'] ?? 'HTML'),
            ];
            if ($markup !== null) {
                $params['reply_markup'] = $markup;
            }
            $res = $this->callApi('sendPhoto', $params);
        }

        $ok = (bool) ($res['ok'] ?? false);
        $fileId = null;
        if ($ok && !empty($res['result']['photo']) && is_array($res['result']['photo'])) {
            $last = end($res['result']['photo']);
            if (is_array($last) && !empty($last['file_id'])) {
                $fileId = (string) $last['file_id'];
            }
        }
        $this->recordSendMetric($ok, $ok ? null : (string) ($res['description'] ?? ''));

        return [
            'ok' => $ok,
            'message_id' => isset($res['result']['message_id']) ? (string) $res['result']['message_id'] : null,
            'file_id' => $fileId,
            'description' => (string) ($res['description'] ?? ''),
            'raw' => $res,
        ];
    }

    public function editMessageText(int|string $chatId, int|string $messageId, string $text, array $options = []): array
    {
        $extra = [];
        $markup = $this->buildMarkup($options);
        if ($markup !== null) {
            $extra['reply_markup'] = $markup;
        }
        $res = isset(self::$fakeTransports[MessengerCapabilities::TELEGRAM])
            ? $this->callApi('editMessageText', ['chat_id' => $chatId, 'message_id' => (int) $messageId, 'text' => $text, 'parse_mode' => 'HTML'] + $extra)
            : $this->tg->editMessageText($chatId, (int) $messageId, $text, $extra);
        return ['ok' => (bool) ($res['ok'] ?? false), 'description' => (string) ($res['description'] ?? ''), 'raw' => $res];
    }

    public function answerCallback(string $callbackId, string $text = '', bool $alert = false): array
    {
        if ($callbackId === '') {
            return ['ok' => true];
        }
        if (isset(self::$fakeTransports[MessengerCapabilities::TELEGRAM])) {
            return $this->callApi('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text, 'show_alert' => $alert]);
        }
        return $this->tg->answerCallbackQuery($callbackId, $text, $alert);
    }

    public function checkChannelMembership(array $channel, string $externalUserId): array
    {
        if (!ctype_digit($externalUserId)) {
            return ['ok' => false, 'member' => false, 'supported' => true, 'error' => 'شناسه تلگرام نامعتبر است.'];
        }
        if (isset(self::$fakeTransports[MessengerCapabilities::TELEGRAM])) {
            $res = $this->callApi('getChatMember', ['chat_id' => $channel['chat_id'], 'user_id' => (int) $externalUserId]);
            if (!($res['ok'] ?? false)) {
                return ['ok' => false, 'member' => false, 'supported' => true, 'error' => (string) ($res['description'] ?? 'خطای API')];
            }
            $st = (string) ($res['result']['status'] ?? 'left');
            $member = in_array($st, ['creator', 'administrator', 'member'], true)
                || ($st === 'restricted' && !empty($res['result']['is_member']));
            return ['ok' => true, 'member' => $member, 'supported' => true];
        }
        $check = $this->tg->checkMembership($channel, (int) $externalUserId);
        return [
            'ok' => (bool) ($check['ok'] ?? false),
            'member' => (bool) ($check['member'] ?? false),
            'supported' => true,
            'error' => isset($check['error']) ? (string) $check['error'] : null,
            'permission_error' => !empty($check['permission_error']),
            'transient' => !empty($check['transient']),
        ];
    }

    public function registerWebhook(string $webhookUrl, string $secret): array
    {
        if (!str_starts_with(strtolower($webhookUrl), 'https://')) {
            return ['ok' => false, 'description' => 'آدرس Webhook تلگرام باید با https:// آغاز شود.'];
        }
        $opts = $secret !== '' ? ['secret_token' => $secret] : [];
        $res = isset(self::$fakeTransports[MessengerCapabilities::TELEGRAM])
            ? $this->callApi('setWebhook', ['url' => $webhookUrl] + $opts)
            : $this->tg->setWebhook($webhookUrl, $opts);
        return [
            'ok' => (bool) ($res['ok'] ?? false),
            'url' => $webhookUrl,
            'description' => ($res['ok'] ?? false)
                ? 'وب‌هوک تلگرام با موفقیت ثبت شد.'
                : (string) ($res['description'] ?? 'خطا در ثبت وب‌هوک تلگرام'),
        ];
    }

    public function removeWebhook(): array
    {
        $res = isset(self::$fakeTransports[MessengerCapabilities::TELEGRAM])
            ? $this->callApi('deleteWebhook', ['drop_pending_updates' => false])
            : $this->tg->deleteWebhook();
        return ['ok' => (bool) ($res['ok'] ?? false), 'description' => (string) ($res['description'] ?? '')];
    }

    public function fetchUpdates(?string $offset = null, int $limit = 50): array
    {
        $intOffset = ($offset !== null && ctype_digit($offset)) ? (int) $offset : 0;
        $res = isset(self::$fakeTransports[MessengerCapabilities::TELEGRAM])
            ? $this->callApi('getUpdates', ['offset' => $intOffset, 'timeout' => 0, 'limit' => max(1, min(100, $limit))])
            : $this->tg->getUpdates($intOffset, 0, $limit);
        if (!($res['ok'] ?? false) || !is_array($res['result'] ?? null)) {
            return ['ok' => false, 'updates' => [], 'next_offset' => $offset, 'error' => (string) ($res['description'] ?? 'خطای getUpdates')];
        }
        $updates = [];
        $next = $offset;
        foreach ($res['result'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['update_id'])) {
                $next = (string) (((int) $item['update_id']) + 1);
            }
            foreach ($this->parseWebhookPayload($item) as $u) {
                $updates[] = $u;
            }
        }
        return ['ok' => true, 'updates' => $updates, 'next_offset' => $next];
    }

    public function parseWebhookPayload(array $payload, string $endpointHint = ''): array
    {
        return self::parseTelegramStyleUpdate(MessengerCapabilities::TELEGRAM, $payload);
    }

    /** مبدل مشترک آپدیت سبک Telegram / Bale به IncomingUpdate استاندارد */
    public static function parseTelegramStyleUpdate(string $platform, array $payload): array
    {
        $uid = isset($payload['update_id']) ? (string) $payload['update_id'] : hash('sha256', (string) json_encode($payload));

        if (isset($payload['callback_query']) && is_array($payload['callback_query'])) {
            $cb = $payload['callback_query'];
            $from = is_array($cb['from'] ?? null) ? $cb['from'] : [];
            $msg = is_array($cb['message'] ?? null) ? $cb['message'] : [];
            $chat = is_array($msg['chat'] ?? null) ? $msg['chat'] : [];
            return [new IncomingUpdate(
                platform: $platform,
                updateKey: $uid,
                kind: 'callback',
                externalUserId: (string) ($from['id'] ?? ''),
                chatId: (string) ($chat['id'] ?? ($from['id'] ?? '')),
                chatType: (string) ($chat['type'] ?? 'private'),
                messageId: isset($msg['message_id']) ? (string) $msg['message_id'] : null,
                firstName: isset($from['first_name']) ? (string) $from['first_name'] : null,
                lastName: isset($from['last_name']) ? (string) $from['last_name'] : null,
                username: isset($from['username']) ? (string) $from['username'] : null,
                languageCode: (string) ($from['language_code'] ?? 'fa'),
                isBot: !empty($from['is_bot']),
                callbackId: isset($cb['id']) ? (string) $cb['id'] : null,
                callbackData: isset($cb['data']) ? (string) $cb['data'] : '',
                raw: $payload,
            )];
        }

        $msg = $payload['message'] ?? null;
        if (!is_array($msg)) {
            return [];
        }
        $from = is_array($msg['from'] ?? null) ? $msg['from'] : [];
        $chat = is_array($msg['chat'] ?? null) ? $msg['chat'] : [];
        $text = trim((string) ($msg['text'] ?? ''));

        if (isset($msg['contact']) && is_array($msg['contact'])) {
            return [new IncomingUpdate(
                platform: $platform,
                updateKey: $uid,
                kind: 'contact',
                externalUserId: (string) ($from['id'] ?? ''),
                chatId: (string) ($chat['id'] ?? ''),
                chatType: (string) ($chat['type'] ?? 'private'),
                messageId: isset($msg['message_id']) ? (string) $msg['message_id'] : null,
                firstName: isset($from['first_name']) ? (string) $from['first_name'] : null,
                lastName: isset($from['last_name']) ? (string) $from['last_name'] : null,
                username: isset($from['username']) ? (string) $from['username'] : null,
                languageCode: (string) ($from['language_code'] ?? 'fa'),
                isBot: !empty($from['is_bot']),
                contactPhone: (string) ($msg['contact']['phone_number'] ?? ''),
                contactUserId: isset($msg['contact']['user_id']) ? (string) $msg['contact']['user_id'] : null,
                raw: $payload,
            )];
        }

        [$isStart, $startPayload] = self::extractStartPayload($text);

        return [new IncomingUpdate(
            platform: $platform,
            updateKey: $uid,
            kind: $isStart ? 'started' : 'message',
            externalUserId: (string) ($from['id'] ?? ''),
            chatId: (string) ($chat['id'] ?? ''),
            chatType: (string) ($chat['type'] ?? 'private'),
            messageId: isset($msg['message_id']) ? (string) $msg['message_id'] : null,
            firstName: isset($from['first_name']) ? (string) $from['first_name'] : null,
            lastName: isset($from['last_name']) ? (string) $from['last_name'] : null,
            username: isset($from['username']) ? (string) $from['username'] : null,
            languageCode: (string) ($from['language_code'] ?? 'fa'),
            isBot: !empty($from['is_bot']),
            text: $text,
            startPayload: $startPayload,
            raw: $payload,
        )];
    }

    public function syncCommands(array $commands): array
    {
        $res = $this->callApi('setMyCommands', ['commands' => array_values($commands)]);
        return ['ok' => (bool) ($res['ok'] ?? false), 'description' => (string) ($res['description'] ?? '')];
    }

    public function buildReferralLink(string $referralCode, string $fallbackWebUrl = ''): string
    {
        $code = rawurlencode(trim($referralCode));
        $user = $this->botUsername();
        if ($user === '') {
            $user = TelegramService::botUsername($this->db);
        }
        if ($user !== '') {
            return 'https://t.me/' . $user . '?start=' . $code;
        }
        return $fallbackWebUrl !== '' ? $fallbackWebUrl : ('کد دعوت: ' . $referralCode);
    }

    public function fetchProfile(): array
    {
        $test = $this->testConnection();
        if (!$test['ok']) {
            return $test;
        }
        $bot = $test['bot'];
        $nameRes = $this->callApi('getMyName');
        $shortRes = $this->callApi('getMyShortDescription');
        $descRes = $this->callApi('getMyDescription');

        $photoFileId = null;
        if (!empty($bot['id']) && ctype_digit((string) $bot['id'])) {
            $photos = $this->callApi('getUserProfilePhotos', ['user_id' => (int) $bot['id'], 'limit' => 1]);
            $list = $photos['result']['photos'][0] ?? [];
            if (is_array($list) && $list !== []) {
                $best = end($list);
                if (is_array($best) && !empty($best['file_id'])) {
                    $photoFileId = (string) $best['file_id'];
                }
            }
        }

        return [
            'ok' => true,
            'profile' => [
                'bot_id' => $bot['id'],
                'bot_username' => $bot['username'],
                'bot_name' => (string) (($nameRes['result']['name'] ?? '') ?: $bot['name']),
                'bot_short_description' => (string) ($shortRes['result']['short_description'] ?? ''),
                'bot_description' => (string) ($descRes['result']['description'] ?? ''),
                'bot_share_url' => $bot['share_url'] ?? '',
                'bot_photo_file_id' => $photoFileId,
            ],
        ];
    }

    public function pushProfile(string $name, string $shortDescription, string $description): array
    {
        $updated = [];
        $errors = [];

        if ($name !== '') {
            $r = $this->callApi('setMyName', ['name' => mb_substr(trim($name), 0, 64)]);
            if ($r['ok'] ?? false) {
                $updated[] = 'نام ربات';
            } else {
                $errors[] = 'نام ربات: ' . ($r['description'] ?? 'خطا');
            }
        }
        $rBio = $this->callApi('setMyShortDescription', ['short_description' => mb_substr(trim($shortDescription), 0, 120)]);
        if ($rBio['ok'] ?? false) {
            $updated[] = 'بیوگرافی کوتاه (Bio)';
        } else {
            $errors[] = 'بیوگرافی: ' . ($rBio['description'] ?? 'خطا');
        }
        $rDesc = $this->callApi('setMyDescription', ['description' => mb_substr(trim($description), 0, 512)]);
        if ($rDesc['ok'] ?? false) {
            $updated[] = 'توضیحات شروع';
        } else {
            $errors[] = 'توضیحات: ' . ($rDesc['description'] ?? 'خطا');
        }

        return [
            'ok' => $errors === [],
            'supported' => true,
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    public function pushProfilePhoto(string $localPath, string $mimeType): array
    {
        $res = $this->tg->setMyProfilePhoto($localPath);
        if (!($res['ok'] ?? false)) {
            return [
                'ok' => false,
                'supported' => true,
                'error' => (string) ($res['description'] ?? 'خطا در تغییر تصویر پروفایل تلگرام'),
            ];
        }
        return ['ok' => true, 'supported' => true, 'file_id' => null];
    }

    public function deleteProfilePhoto(): array
    {
        $res = $this->callApi('removeMyProfilePhoto');
        return [
            'ok' => (bool) ($res['ok'] ?? false),
            'supported' => true,
            'error' => ($res['ok'] ?? false) ? null : (string) ($res['description'] ?? 'خطا در حذف تصویر پروفایل'),
        ];
    }

    public function downloadFile(string $fileId): ?string
    {
        $f = $this->callApi('getFile', ['file_id' => $fileId]);
        $path = (string) ($f['result']['file_path'] ?? '');
        if ($path === '') {
            return null;
        }
        return $this->tg->downloadFile($path);
    }
}
