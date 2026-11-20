<?php
declare(strict_types=1);

namespace RB\Messengers;

use RB\Services\RubikaDiagnostics;

/**
 * درایور رسمی بات روبیکا (Rubika Bot API v3 — https://rubika.ir/botapi)
 *
 * مشخصات مستندات رسمی:
 *   - آدرس پایه:        POST https://botapi.rubika.ir/v3/{token}/{method}
 *   - پاسخ موفق:        {"status": "OK", "data": {...}}
 *   - دریافت مشخصات:    getMe → data.bot {bot_id, bot_title, username, description, start_message, share_url, avatar:{file_id}}
 *   - ارسال پیام:       sendMessage {chat_id, text, chat_keypad_type:"New"|"Remove", chat_keypad:{rows}, inline_keypad:{rows}}
 *   - انواع دکمه رسمی:   Simple, Link, AskMyPhoneNumber (هر دکمه دارای id, type, button_text)
 *   - ارسال تصویر:      requestSendFile {type:"Image"} → upload_url → multipart upload → file_id → sendFile
 *   - دریافت فایل:      getFile {file_id} → data.download_url
 *   - ثبت Endpoint:     updateBotEndpoints {url, type: "ReceiveUpdate" | "ReceiveInlineMessage"}
 *   - Polling:          getUpdates {offset_id, limit} → data.{updates, next_offset_id}
 *   - لینک دعوت:        https://rubika.ir/{username}?st={referral_code}  (دریافت در aux_data.start_id + پشتیبانی /start CODE)
 *   - محدودیت‌های رسمی: فاقد Mini App، فاقد getChatMember کانال، فاقد متد تغییر تصویر/بیو بات از API
 */
final class RubikaDriver extends AbstractMessengerDriver
{
    public const API_BASE = 'https://botapi.rubika.ir/v3';

    public function platform(): string
    {
        return MessengerCapabilities::RUBIKA;
    }

    public function call(string $method, array $params = []): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'description' => 'توکن بات روبیکا تنظیم نشده است.'];
        }
        $url = self::API_BASE . '/' . rawurlencode($this->token) . '/' . $method;
        $res = $this->httpJson($url, $method, $params);
        if (($res['_transport_ok'] ?? true) === false) {
            $out = ['ok' => false, 'description' => (string) ($res['_error'] ?? 'خطای ارتباط با سرور روبیکا'), 'raw' => $res];
            if (in_array($method, ['getMe','getUpdates','getChat'], true)) RubikaDiagnostics::apiResult($out);
            return $out;
        }
        $status = strtoupper((string) ($res['status'] ?? ''));
        $httpCode = (int) ($res['_http_code'] ?? 0);
        if (($status === 'OK' || ($status === '' && !empty($res['ok']))) && ($httpCode === 0 || ($httpCode >= 200 && $httpCode < 300))) {
            $out = [
                'ok' => true,
                'status' => 'OK',
                'data' => is_array($res['data'] ?? null) ? $res['data'] : [],
                'raw' => $res,
            ];
            if (in_array($method, ['getMe','getUpdates','getChat'], true)) RubikaDiagnostics::apiResult($out);
            return $out;
        }
        $rawErr = (string) ($res['dev_message'] ?? $res['status_det'] ?? $res['status'] ?? $res['description'] ?? 'خطای API روبیکا');
        $out = [
            'ok' => false,
            'status' => $status,
            'description' => MessengerCapabilities::humanizeError('rubika', $this->scrubSecrets($rawErr), (int) ($res['_http_code'] ?? 0)),
            'raw' => $res,
        ];
        if (in_array($method, ['getMe','getUpdates','getChat'], true)) RubikaDiagnostics::apiResult($out);
        return $out;
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'توکن بات روبیکا ثبت نشده است.'];
        }
        $res = $this->call('getMe');
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string) ($res['description'] ?? 'اتصال به API روبیکا برقرار نشد.')];
        }
        $bot = is_array($res['data']['bot'] ?? null) ? $res['data']['bot'] : $res['data'];
        if (empty($bot['bot_id']) && empty($bot['username']) && empty($bot['bot_title'])) {
            return ['ok' => false, 'error' => 'پاسخ getMe روبیکا فاقد اطلاعات بات بود. صحت توکن را بررسی کنید.'];
        }
        $username = ltrim((string) ($bot['username'] ?? ''), '@');
        $shareUrl = trim((string) ($bot['share_url'] ?? ''));
        if ($shareUrl === '' && $username !== '') {
            $shareUrl = 'https://rubika.ir/' . $username;
        }
        return [
            'ok' => true,
            'bot' => [
                'id' => (string) ($bot['bot_id'] ?? ''),
                'username' => $username,
                'name' => (string) ($bot['bot_title'] ?? $username),
                'description' => (string) ($bot['description'] ?? ''),
                'start_message' => (string) ($bot['start_message'] ?? ''),
                'share_url' => $shareUrl,
                'photo_file_id' => isset($bot['avatar']['file_id']) ? (string) $bot['avatar']['file_id'] : null,
            ],
        ];
    }

    /** تبدیل سطرهای کیبورد معمولی به مدل رسمی Keypad روبیکا (rows[].buttons[]) */
    public static function toChatKeypad(array $replyRows): array
    {
        $rows = [];
        $idx = 0;
        foreach ($replyRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $buttons = [];
            foreach ($row as $btn) {
                $idx++;
                if (is_string($btn)) {
                    $buttons[] = [
                        'id' => 'rk_' . $idx,
                        'type' => 'Simple',
                        'button_text' => $btn,
                    ];
                } elseif (is_array($btn) && isset($btn['text'])) {
                    // دکمه‌های web_app در روبیکا پشتیبانی نمی‌شوند — حذف هوشمند بدون ایجاد دکمه خراب
                    if (isset($btn['web_app'])) {
                        continue;
                    }
                    if (!empty($btn['request_contact'])) {
                        $buttons[] = [
                            'id' => 'ask_phone',
                            'type' => 'AskMyPhoneNumber',
                            'button_text' => (string) $btn['text'],
                        ];
                    } else {
                        $buttons[] = [
                            'id' => (string) ($btn['id'] ?? ('rk_' . $idx)),
                            'type' => 'Simple',
                            'button_text' => (string) $btn['text'],
                        ];
                    }
                }
            }
            if ($buttons !== []) {
                $rows[] = ['buttons' => $buttons];
            }
        }
        return [
            'rows' => $rows,
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }

    /**
     * تبدیل دکمه‌های شیشه‌ای استاندارد به InlineKeypad رسمی روبیکا.
     * لینک‌های خارجی (مانند کانال اجباری) هم به‌صورت متن شفاف ضمیمه می‌شوند تا روی تمام نسخه‌های روبیکا کار کنند.
     *
     * @return array{keypad:?array,appended_links:string[]}
     */
    public static function toInlineKeypad(array $inlineRows): array
    {
        $rows = [];
        $links = [];
        $idx = 0;

        foreach ($inlineRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $buttons = [];
            foreach ($row as $btn) {
                if (!is_array($btn) || !isset($btn['text'])) {
                    continue;
                }
                $idx++;
                $label = (string) $btn['text'];

                // Mini App و copy_text و t.me/share در روبیکا وجود ندارند — رد ایمن
                if (isset($btn['web_app']) || isset($btn['copy_text'])) {
                    continue;
                }
                if (isset($btn['callback_data']) && (string) $btn['callback_data'] !== '') {
                    $buttons[] = [
                        'id' => (string) $btn['callback_data'],
                        'type' => 'Simple',
                        'button_text' => $label,
                    ];
                } elseif (isset($btn['url']) && preg_match('~^https?://~i', (string) $btn['url'])) {
                    $url = (string) $btn['url'];
                    if (str_starts_with(strtolower($url), 'https://t.me/share/')) {
                        continue;
                    }
                    $links[] = '🔗 ' . $label . ': ' . $url;
                }
            }
            if ($buttons !== []) {
                $rows[] = ['buttons' => $buttons];
            }
        }

        return [
            'keypad' => $rows !== [] ? ['rows' => $rows] : null,
            'appended_links' => $links,
        ];
    }

    private function applyKeypads(array &$params, string &$plainText, array $options): void
    {
        if (!empty($options['remove_keyboard'])) {
            $params['chat_keypad_type'] = 'Remove';
        }
        $inlineRows = $options['inline_rows'] ?? null;
        $replyRows = $options['reply_rows'] ?? null;

        // پشتیبانی از reply_markup تلگرامی برای سازگاری کامل با BotHandler مشترک
        if ($inlineRows === null && $replyRows === null && isset($options['reply_markup'])) {
            $decoded = is_string($options['reply_markup'])
                ? json_decode($options['reply_markup'], true)
                : $options['reply_markup'];
            if (is_array($decoded)) {
                if (!empty($decoded['remove_keyboard'])) {
                    $params['chat_keypad_type'] = 'Remove';
                }
                if (!empty($decoded['inline_keyboard']) && is_array($decoded['inline_keyboard'])) {
                    $inlineRows = $decoded['inline_keyboard'];
                }
                if (!empty($decoded['keyboard']) && is_array($decoded['keyboard'])) {
                    $replyRows = $decoded['keyboard'];
                }
            }
        }

        if (is_array($inlineRows) && $inlineRows !== []) {
            $conv = self::toInlineKeypad($inlineRows);
            if ($conv['keypad'] !== null) {
                $params['inline_keypad'] = $conv['keypad'];
            }
            foreach ($conv['appended_links'] as $linkLine) {
                if (!str_contains($plainText, $linkLine)) {
                    $plainText .= "\n" . $linkLine;
                }
            }
        }

        if (is_array($replyRows) && $replyRows !== []) {
            $chatKeypad = self::toChatKeypad($replyRows);
            if ($chatKeypad['rows'] !== []) {
                $params['chat_keypad_type'] = 'New';
                $params['chat_keypad'] = $chatKeypad;
            }
        }
    }

    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        $plain = self::htmlToPlain($text);
        $params = ['chat_id' => (string) $chatId];
        $this->applyKeypads($params, $plain, $options);
        $params['text'] = mb_substr($plain !== '' ? $plain : '—', 0, 4000);

        $res = $this->call('sendMessage', $params);
        RubikaDiagnostics::sent($res, (string)$chatId);
        $confirmed = !empty($res['ok']) && !empty($res['data']['message_id']);
        if (!empty($res['ok']) && !$confirmed) $res['description'] = 'پاسخ روبیکا فاقد شناسه پیام است؛ ارسال تأیید نشد.';
        $this->recordSendMetric($confirmed, $confirmed ? null : (string) ($res['description'] ?? ''));

        return [
            'ok' => $confirmed,
            'message_id' => isset($res['data']['message_id']) ? (string) $res['data']['message_id'] : null,
            'description' => (string) ($res['description'] ?? ''),
            'raw' => $res,
        ];
    }

    /**
     * ارسال تصویر در روبیکا طبق مستندات رسمی:
     *   ۱) اگر file_id کش‌شده وجود دارد → مستقیماً sendFile
     *   ۲) در غیر این صورت: requestSendFile(type="Image") → آپلود به upload_url → دریافت file_id → sendFile
     */
    public function sendPhoto(int|string $chatId, string $photoSource, string $caption = '', array $options = []): array
    {
        $fileId = trim((string) ($options['file_id'] ?? ''));

        if ($fileId === '' && is_file($photoSource)) {
            $req = $this->call('requestSendFile', ['type' => 'Image']);
            $uploadUrl = (string) ($req['data']['upload_url'] ?? '');
            if ($req['ok'] && $uploadUrl !== '') {
                $up = $this->httpMultipart($uploadUrl, 'uploadFile', [], 'file', $photoSource);
                $fileId = (string) ($up['data']['file_id'] ?? $up['file_id'] ?? '');
            }
        }

        if ($fileId === '') {
            // بازگشت امن: اگر آپلود فایل موقتاً در دسترس نبود، متن دعوت بدون خطا ارسال می‌شود
            if ($caption !== '') {
                $fallback = $this->sendMessage($chatId, $caption, $options);
                $fallback['fallback_text_only'] = true;
                return $fallback;
            }
            return ['ok' => false, 'description' => 'بارگذاری تصویر در سرور روبیکا ناموفق بود.'];
        }

        $plain = self::htmlToPlain($caption);
        $params = [
            'chat_id' => (string) $chatId,
            'file_id' => $fileId,
        ];
        $this->applyKeypads($params, $plain, $options);
        if ($plain !== '') {
            $params['text'] = mb_substr($plain, 0, 2000);
        }

        $res = $this->call('sendFile', $params);
        RubikaDiagnostics::sent($res, (string)$chatId);
        $this->recordSendMetric($res['ok'], $res['ok'] ? null : (string) ($res['description'] ?? ''));

        return [
            'ok' => $res['ok'],
            'message_id' => isset($res['data']['message_id']) ? (string) $res['data']['message_id'] : null,
            'file_id' => $res['ok'] ? $fileId : null,
            'description' => (string) ($res['description'] ?? ''),
            'raw' => $res,
        ];
    }

    public function editMessageText(int|string $chatId, int|string $messageId, string $text, array $options = []): array
    {
        $plain = self::htmlToPlain($text);
        $res = $this->call('editMessageText', [
            'chat_id' => (string) $chatId,
            'message_id' => (string) $messageId,
            'text' => mb_substr($plain, 0, 4000),
        ]);
        if ($res['ok'] && !empty($options['inline_rows']) && is_array($options['inline_rows'])) {
            $conv = self::toInlineKeypad($options['inline_rows']);
            if ($conv['keypad'] !== null) {
                $this->call('editMessageKeypad', [
                    'chat_id' => (string) $chatId,
                    'message_id' => (string) $messageId,
                    'inline_keypad' => $conv['keypad'],
                ]);
            }
        }
        return ['ok' => $res['ok'], 'description' => (string) ($res['description'] ?? ''), 'raw' => $res];
    }

    public function answerCallback(string $callbackId, string $text = '', bool $alert = false): array
    {
        // در روبیکا کلیک InlineKeypad نیازی به answerCallbackQuery جداگانه ندارد
        return ['ok' => true];
    }

    public function checkChannelMembership(array $channel, string $externalUserId): array
    {
        // طبق مستندات رسمی rubika.ir/botapi نسخه v3، متد بررسی عضویت کاربر در کانال ارائه نشده است
        return [
            'ok' => false,
            'member' => false,
            'supported' => false,
            'error' => 'API رسمی روبیکا (v3) متد بررسی خودکار عضویت کانال ندارد؛ از حالت نمایش لینک و تأیید تعاملی استفاده می‌شود.',
        ];
    }

    public function registerWebhook(string $webhookUrl, string $secret): array
    {
        if (!str_starts_with(strtolower($webhookUrl), 'https://')) {
            return ['ok' => false, 'description' => 'آدرس Endpoint بات روبیکا باید HTTPS معتبر باشد.'];
        }
        $target = rtrim($webhookUrl, '/');
        if ($secret !== '' && !str_ends_with($target, '/' . $secret)) {
            $target .= '/' . rawurlencode($secret);
        }

        // ثبت هر دو Endpoint رسمی روبیکا (ReceiveUpdate + ReceiveInlineMessage)
        $errors = [];
        foreach (['ReceiveUpdate', 'ReceiveInlineMessage'] as $endpointType) {
            RubikaDiagnostics::begin($this->db, 'endpoint_setup', $endpointType);
            unset($r);
            try {
                $r = $this->call('updateBotEndpoints', [
                    'url' => $target,
                    'type' => $endpointType,
                ]);
                RubikaDiagnostics::apiResult($r);
            } finally {
                RubikaDiagnostics::finish(!empty($r['ok']) ? 'ok' : 'error');
            }
            if (!$r['ok']) {
                $errors[] = $endpointType . ': ' . ($r['description'] ?? 'خطا');
            }
        }

        return [
            'ok' => $errors === [],
            'url' => $target,
            'description' => $errors === []
                ? 'هر دو Endpoint رسمی روبیکا (ReceiveUpdate و ReceiveInlineMessage) با موفقیت ثبت شدند.'
                : implode(' | ', $errors),
        ];
    }

    public function removeWebhook(): array
    {
        foreach (['ReceiveUpdate', 'ReceiveInlineMessage'] as $endpointType) {
            $this->call('updateBotEndpoints', ['url' => '', 'type' => $endpointType]);
        }
        return ['ok' => true, 'description' => 'Endpoint وب‌هوک روبیکا غیرفعال شد.'];
    }

    public function fetchUpdates(?string $offset = null, int $limit = 50): array
    {
        $params = ['limit' => max(1, min(100, $limit))];
        if ($offset !== null && $offset !== '') {
            $params['offset_id'] = $offset;
        }
        $res = $this->call('getUpdates', $params);
        if (!$res['ok']) {
            return ['ok' => false, 'updates' => [], 'next_offset' => $offset, 'error' => (string) ($res['description'] ?? 'خطای getUpdates روبیکا')];
        }
        $list = is_array($res['data']['updates'] ?? null) ? $res['data']['updates'] : [];
        $next = isset($res['data']['next_offset_id']) ? (string) $res['data']['next_offset_id'] : $offset;
        $out = [];
        foreach ($list as $item) {
            if (is_array($item)) {
                foreach ($this->parseWebhookPayload(['update' => $item]) as $u) {
                    $out[] = $u;
                }
            }
        }
        return ['ok' => true, 'updates' => $out, 'next_offset' => $next];
    }

    /** دریافت نام و نام کاربری کاربر روبیکا از متد رسمی getChat */
    public function fetchChatInfo(string $chatId): array
    {
        $res = $this->call('getChat', ['chat_id' => $chatId]);
        if (!$res['ok'] || !is_array($res['data']['chat'] ?? null)) {
            return [];
        }
        return $res['data']['chat'];
    }

    public function parseWebhookPayload(array $payload, string $endpointHint = ''): array
    {
        // ۱) رویداد کلیک روی InlineKeypad (receiveInlineMessage)
        if (isset($payload['inline_message']) && is_array($payload['inline_message'])) {
            $im = $payload['inline_message'];
            $aux = is_array($im['aux_data'] ?? null) ? $im['aux_data'] : [];
            $senderId = trim((string) ($im['sender_id'] ?? $im['chat_id'] ?? ''));
            $chatId = trim((string) ($im['chat_id'] ?? $senderId));
            $msgId = trim((string) ($im['message_id'] ?? ''));
            $buttonId = trim((string) ($aux['button_id'] ?? ''));
            $startId = trim((string) ($aux['start_id'] ?? ''));

            if ($senderId === '' || $chatId === '') {
                return [];
            }

            // InlineMessage has no click/update ID; message_id identifies the
            // original message, so a content hash would suppress later clicks.
            $key = '';
            return [new IncomingUpdate(
                platform: MessengerCapabilities::RUBIKA,
                updateKey: $key,
                kind: 'callback',
                externalUserId: $senderId,
                chatId: $chatId,
                chatType: in_array(strtolower(substr($chatId, 0, 2)), ['g0', 'c0'], true) ? 'group' : 'private',
                messageId: $msgId !== '' ? $msgId : null,
                text: trim((string) ($im['text'] ?? '')),
                startPayload: $startId !== '' ? $startId : null,
                callbackId: $msgId !== '' ? $msgId : null,
                callbackData: $buttonId,
                raw: $payload,
            )];
        }

        // ۲) رویداد پیام و شروع/توقف بات (receiveUpdate یا getUpdates)
        $upd = is_array($payload['update'] ?? null) ? $payload['update'] : $payload;
        $type = (string) ($upd['type'] ?? '');
        $chatId = trim((string) ($upd['chat_id'] ?? ''));
        if ($chatId === '' || $type === '') {
            return [];
        }

        // نادیده‌گرفتن چت‌های گروهی و کانالی (شناسه گروه g0... و کانال c0...)
        $prefix = strtolower(substr($chatId, 0, 2));
        $chatType = ($prefix === 'g0' || $prefix === 'c0') ? 'group' : 'private';

        if ($type === 'StoppedBot') {
            return [new IncomingUpdate(
                platform: MessengerCapabilities::RUBIKA,
                updateKey: '', // no message/update identifier; repeated starts and stops must not be permanently suppressed
                kind: 'stopped',
                externalUserId: $chatId,
                chatId: $chatId,
                chatType: $chatType,
                raw: $payload,
            )];
        }

        $msg = is_array($upd['new_message'] ?? null) ? $upd['new_message'] : [];
        $senderType = (string) ($msg['sender_type'] ?? 'User');
        if (strcasecmp($senderType, 'Bot') === 0) {
            return [];
        }

        $senderId = trim((string) ($msg['sender_id'] ?? $chatId));
        $msgId = trim((string) ($msg['message_id'] ?? ''));
        $text = trim((string) ($msg['text'] ?? ''));
        $aux = is_array($msg['aux_data'] ?? null) ? $msg['aux_data'] : (is_array($upd['aux_data'] ?? null) ? $upd['aux_data'] : []);
        $startId = trim((string) ($aux['start_id'] ?? ''));
        $buttonId = trim((string) ($aux['button_id'] ?? ''));

        $updateKey = $msgId !== ''
            ? ('msg:' . $chatId . ':' . $msgId)
            : ($type === 'StartedBot' ? '' : ($type . ':' . $chatId . ':' . substr(hash('sha256', (string) json_encode($upd)), 0, 16)));

        // پیام حاوی مخاطب (AskMyPhoneNumber / ContactMessage)
        if (isset($msg['contact_message']) && is_array($msg['contact_message'])) {
            $cm = $msg['contact_message'];
            return [new IncomingUpdate(
                platform: MessengerCapabilities::RUBIKA,
                updateKey: $updateKey,
                kind: 'contact',
                externalUserId: $senderId,
                chatId: $chatId,
                chatType: $chatType,
                messageId: $msgId !== '' ? $msgId : null,
                firstName: isset($cm['first_name']) ? (string) $cm['first_name'] : null,
                lastName: isset($cm['last_name']) ? (string) $cm['last_name'] : null,
                contactPhone: (string) ($cm['phone_number'] ?? ''),
                contactUserId: $senderId,
                raw: $payload,
            )];
        }

        if ($type === 'StartedBot') {
            [, $textStartPayload] = self::extractStartPayload($text);
            $effectivePayload = $startId !== '' ? $startId : $textStartPayload;
            return [new IncomingUpdate(
                platform: MessengerCapabilities::RUBIKA,
                updateKey: $updateKey,
                kind: 'started',
                externalUserId: $senderId,
                chatId: $chatId,
                chatType: $chatType,
                messageId: $msgId !== '' ? $msgId : null,
                text: $text !== '' ? $text : '/start',
                startPayload: $effectivePayload,
                raw: $payload,
            )];
        }

        if ($type !== 'NewMessage') {
            return [];
        }

        // Old reply keypads used position-based IDs (rk_1, rk_2, ...).
        // Their ordering may have changed, so never guess the old action when
        // Rubika sends no text: refresh the menu instead.
        if ($text === '' && preg_match('/^rk_[0-9]+$/D', $buttonId)) {
            $buttonId = 'legacy:menu';
        }

        // اگر کلیک دکمه شیشه‌ای از مسیر NewMessage همراه button_id اختصاصی (دارای :) آمد
        if ($buttonId !== '' && str_contains($buttonId, ':')) {
            return [new IncomingUpdate(
                platform: MessengerCapabilities::RUBIKA,
                updateKey: $updateKey . ':btn:' . $buttonId,
                kind: 'callback',
                externalUserId: $senderId,
                chatId: $chatId,
                chatType: $chatType,
                messageId: $msgId !== '' ? $msgId : null,
                text: $text,
                startPayload: $startId !== '' ? $startId : null,
                callbackId: $msgId !== '' ? $msgId : null,
                callbackData: $buttonId,
                raw: $payload,
            )];
        }

        [$isStart, $textPayload] = self::extractStartPayload($text);
        $finalPayload = $startId !== '' ? $startId : $textPayload;
        $isStartEvent = $isStart || $startId !== '';

        return [new IncomingUpdate(
            platform: MessengerCapabilities::RUBIKA,
            updateKey: $updateKey,
            kind: $isStartEvent ? 'started' : 'message',
            externalUserId: $senderId,
            chatId: $chatId,
            chatType: $chatType,
            messageId: $msgId !== '' ? $msgId : null,
            text: $text,
            startPayload: $finalPayload,
            raw: $payload,
        )];
    }

    public function syncCommands(array $commands): array
    {
        $botCommands = [];
        foreach ($commands as $cmd) {
            if (!empty($cmd['command']) && !empty($cmd['description'])) {
                $botCommands[] = [
                    'command' => ltrim((string) $cmd['command'], '/'),
                    'description' => (string) $cmd['description'],
                ];
            }
        }
        $res = $this->call('setCommands', ['bot_commands' => $botCommands]);
        return [
            'ok' => $res['ok'],
            'description' => $res['ok'] ? 'دستورات بات روبیکا با موفقیت ثبت شد.' : (string) ($res['description'] ?? 'خطا در setCommands'),
        ];
    }

    public function buildReferralLink(string $referralCode, string $fallbackWebUrl = ''): string
    {
        $code = rawurlencode(trim($referralCode));
        $shareUrl = rtrim(trim((string) ($this->connection['bot_share_url'] ?? '')), '/');
        $username = $this->botUsername();

        // طبق مستندات رسمی rubika.ir/botapi/models (بخش AuxData.start_id): پارامتر رسمی لینک دعوت st است
        if ($username !== '') {
            return 'https://rubika.ir/' . $username . '?st=' . $code;
        }
        if ($shareUrl !== '' && str_starts_with(strtolower($shareUrl), 'https://rubika.ir/')) {
            $sep = str_contains($shareUrl, '?') ? '&' : '?';
            return $shareUrl . $sep . 'st=' . $code;
        }
        return $fallbackWebUrl !== '' ? $fallbackWebUrl : ('کد دعوت: ' . $referralCode);
    }

    public function buildMiniAppUrl(string $baseMiniAppUrl, ?string $startParam = null): string
    {
        // روبیکا در Bot API v3 از Mini App پشتیبانی نمی‌کند
        return '';
    }

    public function fetchProfile(): array
    {
        $test = $this->testConnection();
        if (!$test['ok']) {
            return $test;
        }
        $bot = $test['bot'];
        return [
            'ok' => true,
            'profile' => [
                'bot_id' => $bot['id'],
                'bot_username' => $bot['username'],
                'bot_name' => $bot['name'],
                'bot_description' => $bot['description'] ?? '',
                'bot_start_message' => $bot['start_message'] ?? '',
                'bot_share_url' => $bot['share_url'] ?? '',
                'bot_photo_file_id' => $bot['photo_file_id'] ?? null,
            ],
        ];
    }

    public function pushProfile(string $name, string $shortDescription, string $description): array
    {
        return [
            'ok' => false,
            'supported' => false,
            'message' => (string) MessengerCapabilities::get('rubika')['profile_limitation'],
        ];
    }

    public function pushProfilePhoto(string $localPath, string $mimeType): array
    {
        return [
            'ok' => false,
            'supported' => false,
            'error' => 'API رسمی روبیکا (v3) متد تغییر تصویر پروفایل بات ندارد؛ تغییر تصویر از طریق @BotFather در روبیکا انجام می‌شود.',
        ];
    }

    public function deleteProfilePhoto(): array
    {
        return [
            'ok' => false,
            'supported' => false,
            'error' => 'حذف تصویر پروفایل بات روبیکا باید از طریق @BotFather در روبیکا انجام شود.',
        ];
    }

    public function downloadFile(string $fileId): ?string
    {
        if (!$this->isConfigured() || $fileId === '') {
            return null;
        }
        $res = $this->call('getFile', ['file_id' => $fileId]);
        $dlUrl = trim((string) ($res['data']['download_url'] ?? ''));
        if ($dlUrl === '' || !str_starts_with(strtolower($dlUrl), 'https://')) {
            return null;
        }
        return $this->httpDownload($dlUrl, $fileId);
    }
}
