<?php
declare(strict_types=1);

namespace RB\Messengers;

/**
 * درایور رسمی پیام‌رسان بله (Bale Bot API — https://docs.bale.ai & https://docs.bale.ai/miniapp)
 *
 * مشخصات مستندات رسمی:
 *   - آدرس پایه API:   https://tapi.bale.ai/bot{token}/{method}
 *   - آدرس فایل:       https://tapi.bale.ai/file/bot{token}/{file_path}
 *   - پیوند دعوت بازو: https://ble.ir/{bot_username}?start={referral_code}
 *   - پیوند مینی‌اپ:    https://ble.ir/{bot_username}?startapp={referral_code}
 *   - کیبورد شیشه‌ای:  InlineKeyboardMarkup (callback_data, url, web_app, copy_text)
 *   - کیبورد معمولی:   ReplyKeyboardMarkup (text, request_contact, request_location, web_app)
 *   - عضویت کانال:    getChatMember(chat_id, user_id) — بازو باید مدیر کانال باشد
 *   - دستورات بازو:    setMyCommands / getMyCommands / deleteMyCommands
 *   - پروفایل بازو:    خواندن از طریق getMe و getChat؛ ویرایش تصویر و بیو از طریق @botfather
 */
final class BaleDriver extends AbstractMessengerDriver
{
    public const API_BASE  = 'https://tapi.bale.ai/bot';
    public const FILE_BASE = 'https://tapi.bale.ai/file/bot';

    public function platform(): string
    {
        return MessengerCapabilities::BALE;
    }

    public function call(string $method, array $params = []): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'description' => 'توکن بازوی بله تنظیم نشده است.'];
        }
        $url = self::API_BASE . $this->token . '/' . $method;
        $res = $this->httpJson($url, $method, $params);
        if (($res['_transport_ok'] ?? true) === false) {
            return ['ok' => false, 'description' => (string) ($res['_error'] ?? 'خطای ارتباط با سرور بله'), 'error_code' => (int) ($res['_http_code'] ?? 0)];
        }
        if (!($res['ok'] ?? false)) {
            $res['description'] = MessengerCapabilities::humanizeError(
                'bale',
                (string) ($res['description'] ?? 'خطا از API بله'),
                (int) ($res['error_code'] ?? ($res['_http_code'] ?? 0))
            );
        }
        return $res;
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'توکن بازوی بله ثبت نشده است.'];
        }
        $res = $this->call('getMe');
        if (!($res['ok'] ?? false) || !is_array($res['result'] ?? null)) {
            return ['ok' => false, 'error' => (string) ($res['description'] ?? 'اتصال به سرور بله برقرار نشد.')];
        }
        $me = $res['result'];
        $username = ltrim((string) ($me['username'] ?? ''), '@');
        return [
            'ok' => true,
            'bot' => [
                'id' => (string) ($me['id'] ?? ''),
                'username' => $username,
                'name' => trim((string) (($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''))),
                'share_url' => $username !== '' ? 'https://ble.ir/' . $username : '',
                'has_main_web_app' => !empty($me['has_main_web_app']),
            ],
        ];
    }

    /** پاک‌سازی دکمه‌های شیشه‌ای مطابق فیلدهای مجاز docs.bale.ai (بدون switch_inline_query غیررسمی) */
    private function sanitizeInlineRows(array $rows): array
    {
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $r = [];
            foreach ($row as $btn) {
                if (!is_array($btn) || !isset($btn['text'])) {
                    continue;
                }
                $text = (string) $btn['text'];
                if (isset($btn['web_app']['url']) && str_starts_with(strtolower((string) $btn['web_app']['url']), 'https://')) {
                    $r[] = ['text' => $text, 'web_app' => ['url' => (string) $btn['web_app']['url']]];
                } elseif (isset($btn['copy_text']['text']) && (string) $btn['copy_text']['text'] !== '') {
                    $r[] = ['text' => $text, 'copy_text' => ['text' => (string) $btn['copy_text']['text']]];
                } elseif (isset($btn['url']) && preg_match('~^https?://~i', (string) $btn['url'])) {
                    // لینک‌های مختص t.me/share را در بله نمایش ندهیم
                    if (str_starts_with(strtolower((string) $btn['url']), 'https://t.me/share/')) {
                        continue;
                    }
                    $r[] = ['text' => $text, 'url' => (string) $btn['url']];
                } elseif (isset($btn['callback_data'])) {
                    $r[] = ['text' => $text, 'callback_data' => substr((string) $btn['callback_data'], 0, 64)];
                }
            }
            if ($r !== []) {
                $clean[] = $r;
            }
        }
        return $clean;
    }

    private function buildReplyMarkup(array $options): ?array
    {
        if (!empty($options['remove_keyboard'])) {
            return ['remove_keyboard' => true];
        }
        if (!empty($options['inline_rows']) && is_array($options['inline_rows'])) {
            $rows = $this->sanitizeInlineRows($options['inline_rows']);
            return $rows !== [] ? ['inline_keyboard' => $rows] : null;
        }
        if (!empty($options['reply_rows']) && is_array($options['reply_rows'])) {
            $keyboard = [];
            foreach ($options['reply_rows'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $line = [];
                foreach ($row as $btn) {
                    if (is_string($btn)) {
                        $line[] = ['text' => $btn];
                    } elseif (is_array($btn) && isset($btn['text'])) {
                        $item = ['text' => (string) $btn['text']];
                        if (!empty($btn['request_contact'])) {
                            $item['request_contact'] = true;
                        } elseif (isset($btn['web_app']['url']) && str_starts_with(strtolower((string) $btn['web_app']['url']), 'https://')) {
                            $item['web_app'] = ['url' => (string) $btn['web_app']['url']];
                        }
                        $line[] = $item;
                    }
                }
                if ($line !== []) {
                    $keyboard[] = $line;
                }
            }
            return $keyboard !== [] ? ['keyboard' => $keyboard, 'resize_keyboard' => true] : null;
        }
        if (isset($options['reply_markup'])) {
            $m = is_string($options['reply_markup']) ? json_decode($options['reply_markup'], true) : $options['reply_markup'];
            if (is_array($m)) {
                if (isset($m['inline_keyboard']) && is_array($m['inline_keyboard'])) {
                    $rows = $this->sanitizeInlineRows($m['inline_keyboard']);
                    return $rows !== [] ? ['inline_keyboard' => $rows] : null;
                }
                return $m;
            }
        }
        return null;
    }

    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        // بله parse_mode HTML ندارد؛ متن را به متن خوانا و مرتب تبدیل می‌کنیم
        $plain = mb_substr(self::htmlToPlain($text), 0, 4096);
        $params = [
            'chat_id' => ctype_digit((string) $chatId) ? (int) $chatId : (string) $chatId,
            'text' => $plain !== '' ? $plain : '—',
        ];
        $markup = $this->buildReplyMarkup($options);
        if ($markup !== null) {
            $params['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
        }

        $res = $this->call('sendMessage', $params);
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
        $plainCaption = mb_substr(self::htmlToPlain($caption), 0, 1024);
        $markup = $this->buildReplyMarkup($options);
        $cachedId = trim((string) ($options['file_id'] ?? ''));

        if ($cachedId === '' && is_file($photoSource) && !str_starts_with($photoSource, 'https://')) {
            $fields = [
                'chat_id' => (string) $chatId,
                'caption' => $plainCaption,
            ];
            if ($markup !== null) {
                $fields['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
            }
            $res = $this->httpMultipart(self::API_BASE . $this->token . '/sendPhoto', 'sendPhoto', $fields, 'photo', $photoSource);
        } else {
            $params = [
                'chat_id' => ctype_digit((string) $chatId) ? (int) $chatId : (string) $chatId,
                'photo' => $cachedId !== '' ? $cachedId : $photoSource,
                'caption' => $plainCaption,
            ];
            if ($markup !== null) {
                $params['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
            }
            $res = $this->call('sendPhoto', $params);
        }

        $ok = (bool) ($res['ok'] ?? false);
        $fileId = null;
        if ($ok && !empty($res['result']['photo']) && is_array($res['result']['photo'])) {
            $last = end($res['result']['photo']);
            if (is_array($last) && !empty($last['file_id'])) {
                $fileId = (string) $last['file_id'];
            }
        }
        $this->recordSendMetric($ok, $ok ? null : (string) ($res['description'] ?? ($res['_error'] ?? '')));

        return [
            'ok' => $ok,
            'message_id' => isset($res['result']['message_id']) ? (string) $res['result']['message_id'] : null,
            'file_id' => $fileId,
            'description' => (string) ($res['description'] ?? ($res['_error'] ?? '')),
            'raw' => $res,
        ];
    }

    public function editMessageText(int|string $chatId, int|string $messageId, string $text, array $options = []): array
    {
        $params = [
            'chat_id' => ctype_digit((string) $chatId) ? (int) $chatId : (string) $chatId,
            'message_id' => (int) $messageId,
            'text' => mb_substr(self::htmlToPlain($text), 0, 4096),
        ];
        $markup = $this->buildReplyMarkup($options);
        if ($markup !== null && isset($markup['inline_keyboard'])) {
            $params['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
        }
        $res = $this->call('editMessageText', $params);
        return ['ok' => (bool) ($res['ok'] ?? false), 'description' => (string) ($res['description'] ?? ''), 'raw' => $res];
    }

    public function answerCallback(string $callbackId, string $text = '', bool $alert = false): array
    {
        if ($callbackId === '') {
            return ['ok' => true];
        }
        $params = ['callback_query_id' => $callbackId];
        if ($text !== '') {
            $params['text'] = mb_substr($text, 0, 200);
            $params['show_alert'] = $alert;
        }
        return $this->call('answerCallbackQuery', $params);
    }

    public function checkChannelMembership(array $channel, string $externalUserId): array
    {
        if (!ctype_digit($externalUserId)) {
            return ['ok' => false, 'member' => false, 'supported' => true, 'error' => 'شناسه کاربر بله نامعتبر است.'];
        }
        $chatId = trim((string) ($channel['chat_id'] ?? ''));
        if ($chatId === '') {
            return ['ok' => false, 'member' => false, 'supported' => true, 'error' => 'شناسه کانال بله خالی است.'];
        }
        $res = $this->call('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => (int) $externalUserId,
        ]);
        if (!($res['ok'] ?? false)) {
            return ['ok' => false, 'member' => false, 'supported' => true, 'error' => (string) ($res['description'] ?? 'خطای بررسی عضویت در بله')];
        }
        $status = (string) ($res['result']['status'] ?? 'left');
        $isMember = in_array($status, ['creator', 'owner', 'administrator', 'member'], true)
            || ($status === 'restricted' && !empty($res['result']['is_member']));
        return ['ok' => true, 'member' => $isMember, 'supported' => true];
    }

    public function registerWebhook(string $webhookUrl, string $secret): array
    {
        if (!str_starts_with(strtolower($webhookUrl), 'https://')) {
            return ['ok' => false, 'description' => 'آدرس Webhook بله باید با https:// شروع شود (طبق مستندات docs.bale.ai).'];
        }
        $target = rtrim($webhookUrl, '/');
        if ($secret !== '' && !str_ends_with($target, '/' . $secret)) {
            $target .= '/' . rawurlencode($secret);
        }
        $res = $this->call('setWebhook', ['url' => $target]);
        return [
            'ok' => (bool) ($res['ok'] ?? false),
            'url' => $target,
            'description' => ($res['ok'] ?? false)
                ? 'وب‌هوک بازوی بله با موفقیت ثبت شد.'
                : (string) ($res['description'] ?? 'خطا در ثبت وب‌هوک بله'),
        ];
    }

    public function removeWebhook(): array
    {
        $res = $this->call('deleteWebhook');
        if (!($res['ok'] ?? false)) {
            // طبق docs.bale.ai ارسال url خالی نیز وب‌هوک را غیرفعال می‌کند
            $res = $this->call('setWebhook', ['url' => '']);
        }
        return ['ok' => (bool) ($res['ok'] ?? false), 'description' => (string) ($res['description'] ?? '')];
    }

    public function fetchUpdates(?string $offset = null, int $limit = 50): array
    {
        $params = ['limit' => max(1, min(100, $limit)), 'timeout' => 0];
        if ($offset !== null && ctype_digit($offset)) {
            $params['offset'] = (int) $offset;
        }
        $res = $this->call('getUpdates', $params);
        if (!($res['ok'] ?? false) || !is_array($res['result'] ?? null)) {
            return ['ok' => false, 'updates' => [], 'next_offset' => $offset, 'error' => (string) ($res['description'] ?? 'خطای دریافت آپدیت بله')];
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
        return TelegramDriver::parseTelegramStyleUpdate(MessengerCapabilities::BALE, $payload);
    }

    public function syncCommands(array $commands): array
    {
        $res = $this->call('setMyCommands', ['commands' => array_values($commands)]);
        return [
            'ok' => (bool) ($res['ok'] ?? false),
            'description' => ($res['ok'] ?? false) ? 'دستورات بازوی بله همگام‌سازی شد.' : (string) ($res['description'] ?? 'خطا در ثبت دستورات بله'),
        ];
    }

    public function buildReferralLink(string $referralCode, string $fallbackWebUrl = ''): string
    {
        $code = rawurlencode(trim($referralCode));
        $username = $this->botUsername();
        if ($username !== '') {
            // طبق مستندات رسمی بازوهای بله: https://ble.ir/{bot_username}?start={parameter}
            return 'https://ble.ir/' . $username . '?start=' . $code;
        }
        return $fallbackWebUrl !== '' ? $fallbackWebUrl : ('کد دعوت: ' . $referralCode);
    }

    /** لینک مستقیم مینی‌اپ اصلی بله: https://ble.ir/{bot_username}?startapp={referral_code} */
    public function buildDirectMiniAppLink(string $referralCode): string
    {
        $username = $this->botUsername();
        if ($username === '') {
            return '';
        }
        return 'https://ble.ir/' . $username . '?startapp=' . rawurlencode(trim($referralCode));
    }

    public function fetchProfile(): array
    {
        $test = $this->testConnection();
        if (!$test['ok']) {
            return $test;
        }
        $bot = $test['bot'];
        $desc = (string) ($this->connection['bot_description'] ?? '');
        $photoId = null;

        if (!empty($bot['id']) && ctype_digit((string) $bot['id'])) {
            $chat = $this->call('getChat', ['chat_id' => (int) $bot['id']]);
            if (($chat['ok'] ?? false) && is_array($chat['result'] ?? null)) {
                if (!empty($chat['result']['description'])) {
                    $desc = (string) $chat['result']['description'];
                }
                if (!empty($chat['result']['photo']['big_file_id'])) {
                    $photoId = (string) $chat['result']['photo']['big_file_id'];
                }
            }
        }

        return [
            'ok' => true,
            'profile' => [
                'bot_id' => $bot['id'],
                'bot_username' => $bot['username'],
                'bot_name' => $bot['name'],
                'bot_description' => $desc,
                'bot_share_url' => $bot['share_url'] ?? '',
                'bot_photo_file_id' => $photoId,
            ],
        ];
    }

    public function pushProfile(string $name, string $shortDescription, string $description): array
    {
        return [
            'ok' => false,
            'supported' => false,
            'message' => (string) MessengerCapabilities::get('bale')['profile_limitation'],
        ];
    }

    public function pushProfilePhoto(string $localPath, string $mimeType): array
    {
        return [
            'ok' => false,
            'supported' => false,
            'error' => 'API عمومی بله متد تغییر تصویر پروفایل بازو ندارد؛ تصویر پیش‌نویس در پنل ذخیره شد و تغییر در بله از طریق @botfather انجام می‌شود.',
        ];
    }

    public function deleteProfilePhoto(): array
    {
        return [
            'ok' => false,
            'supported' => false,
            'error' => 'حذف تصویر پروفایل بازوی بله باید از طریق @botfather در پیام‌رسان بله انجام شود.',
        ];
    }

    public function downloadFile(string $fileId): ?string
    {
        if (!$this->isConfigured() || $fileId === '') {
            return null;
        }
        $f = $this->call('getFile', ['file_id' => $fileId]);
        $path = ltrim((string) ($f['result']['file_path'] ?? ''), '/');
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }
        return $this->httpDownload(self::FILE_BASE . $this->token . '/' . $path, $fileId);
    }
}
