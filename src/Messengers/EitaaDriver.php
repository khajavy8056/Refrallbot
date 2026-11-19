<?php
declare(strict_types=1);

namespace RB\Messengers;

/**
 * درایور رسمی پیام‌رسان ایتا (Eitaa Mini App / برنامک + EitaaYar API)
 *
 * مستندات رسمی:
 *   - برنامک و SDK ایتا:   https://developer.eitaa.com/docs/Develop/JsSDK/ (https://developer.eitaa.com/eitaa-web-app.js)
 *   - ارسال پیام به کاربر: POST https://eitaayar.ir/api/app/sendMessage  {"token","chat_id","text"}
 *   - API ایتایار (کانال): https://eitaayar.ir/api/{token}/{getMe|sendMessage|sendFile}
 *   - لینک دعوت برنامک:   https://eitaa.com/{bot_username}/{app_slug}?startapp={referral_code}
 *   - احراز هویت کاربر:    امضای HMAC-SHA256 روی Eitaa.WebApp.initData با کلید خام HMAC("WebAppData", token)
 *
 * محدودیت‌های رسمی مستندشده:
 *   - ایتا فاقد Webhook ورودی چت و دکمه‌های کیبورد/شیشه‌ای در گفتگوست؛ تعامل کاربر در برنامک (Mini App) انجام می‌شود.
 *   - ارسال پیام مستقیم نیازمند اعطای دسترسی ارسال پیام (requestWriteAccess) توسط کاربر در برنامک است.
 *   - بررسی خودکار عضویت کانال و تغییر تصویر/بیوگرافی از طریق API ارائه نشده است.
 */
final class EitaaDriver extends AbstractMessengerDriver
{
    public const APP_SEND_URL   = 'https://eitaayar.ir/api/app/sendMessage';
    public const EITAAYAR_BASE  = 'https://eitaayar.ir/api';

    public function platform(): string
    {
        return MessengerCapabilities::EITAA;
    }

    public function appSlug(): string
    {
        $slug = trim((string) ($this->connection['app_slug'] ?? ''), "/ \t\n\r\0\x0B");
        return $slug;
    }

    /** بررسی اعتبار ساختار توکن برنامه ایتا یا توکن ایتایار */
    public static function looksLikeValidToken(string $token): bool
    {
        $t = trim($token);
        if (strlen($t) < 12 || strlen($t) > 200) {
            return false;
        }
        // قالب توکن برنامه ایتا (5768337691:AAG...) یا توکن API ایتایار (bot12345:uuid...)
        return (bool) preg_match('~^(?:bot)?\d+:[A-Za-z0-9_-]{8,}$~i', $t);
    }

    /** فراخوانی متد API ایتایار (getMe / sendMessage / sendFile) با توکن API */
    public function callEitaaYar(string $method, array $params = [], ?string $useToken = null): array
    {
        $tok = $useToken ?? ($this->secondaryToken !== '' ? $this->secondaryToken : $this->token);
        if ($tok === '') {
            return ['ok' => false, 'description' => 'توکن ایتا تنظیم نشده است.'];
        }
        $url = self::EITAAYAR_BASE . '/' . rawurlencode($tok) . '/' . $method;
        $res = $this->httpJson($url, $method, $params);
        if (($res['_transport_ok'] ?? true) === false) {
            return ['ok' => false, 'description' => (string) ($res['_error'] ?? 'خطا در اتصال به سرور ایتایار')];
        }
        if (!empty($res['ok'])) {
            return $res;
        }
        return [
            'ok' => false,
            'description' => MessengerCapabilities::humanizeError(
                'eitaa',
                (string) ($res['description'] ?? $res['result'] ?? 'خطای API ایتایار'),
                (int) ($res['_http_code'] ?? 0)
            ),
            'raw' => $res,
        ];
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'توکن برنامه/ربات ایتا ثبت نشده است.'];
        }
        if (!self::looksLikeValidToken($this->token)) {
            return ['ok' => false, 'error' => 'ساختار توکن ایتا معتبر نیست (نمونه صحیح: 5768337691:AAG... یا bot12345:...).'];
        }

        // تلاش برای فراخوانی getMe از سرور ایتایار
        $tokenForGetMe = $this->secondaryToken !== '' ? $this->secondaryToken : $this->token;
        $res = $this->callEitaaYar('getMe', [], $tokenForGetMe);

        $configuredUsername = $this->botUsername();
        $configuredName = $this->botName();
        $slug = $this->appSlug();

        if (!empty($res['ok']) && is_array($res['result'] ?? null)) {
            $me = $res['result'];
            $username = $configuredUsername !== '' ? $configuredUsername : ltrim((string) ($me['username'] ?? ''), '@');
            $name = trim((string) (($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''))) ?: ($configuredName ?: 'برنامه ایتا');
            $shareUrl = $username !== ''
                ? ('https://eitaa.com/' . $username . ($slug !== '' ? '/' . $slug : ''))
                : '';
            return [
                'ok' => true,
                'bot' => [
                    'id' => (string) ($me['id'] ?? ''),
                    'username' => $username,
                    'name' => $name,
                    'share_url' => $shareUrl,
                ],
            ];
        }

        // اگر توکن از نوع «برنامه/برنامک ایتا» باشد و متد getMe تنها برای توکن ایتایار کانال فعال باشد،
        // در صورت خطای صریح احراز هویت (401) خطا برمی‌گردانیم؛ در غیر این صورت نتیجه دقیق را برمی‌گردانیم.
        return [
            'ok' => false,
            'error' => (string) ($res['description'] ?? 'پاسخ معتبری از getMe ایتایار دریافت نشد. توکن و دسترسی شبکه سرور به eitaayar.ir را بررسی کنید.'),
        ];
    }

    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'description' => 'توکن ایتا تنظیم نشده است.'];
        }

        // قالب‌بندی Markdown رسمی ایتا (developer.eitaa.com/docs/Develop/SendMassage/)
        $formatted = mb_substr(self::htmlToEitaaMarkdown($text), 0, 4096);
        $chatStr = trim((string) $chatId);

        // اگر مقصد یک کاربر عددی ایتاست و توکن از نوع برنامه ایتا (نه bot123:uuid)، از api/app/sendMessage استفاده می‌کنیم
        $isChannelToken = str_starts_with(strtolower($this->token), 'bot');
        if (!$isChannelToken && ctype_digit($chatStr)) {
            $payload = [
                'token' => $this->token,
                'chat_id' => (int) $chatStr,
                'text' => $formatted !== '' ? $formatted : '—',
            ];
            $res = $this->httpJson(self::APP_SEND_URL, 'app/sendMessage', $payload);
            $ok = (($res['_transport_ok'] ?? true) !== false) && !empty($res['ok']);
            $err = $ok ? '' : MessengerCapabilities::humanizeError(
                'eitaa',
                $this->scrubSecrets((string) ($res['description'] ?? $res['result'] ?? $res['_error'] ?? 'عدم موفقیت ارسال پیام در ایتا')),
                (int) ($res['_http_code'] ?? 0)
            );
            $this->recordSendMetric($ok, $ok ? null : $err);
            return [
                'ok' => $ok,
                'message_id' => isset($res['result']['message_id']) ? (string) $res['result']['message_id'] : null,
                'description' => $err,
                'raw' => $res,
            ];
        }

        // مسیر ایتایار (ارسال به کانال/گروه یا توکن bot...)
        $res = $this->callEitaaYar('sendMessage', [
            'chat_id' => ltrim($chatStr, '@'),
            'text' => $formatted !== '' ? $formatted : '—',
        ], $this->token);
        $ok = !empty($res['ok']);
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
        $chatStr = trim((string) $chatId);
        $apiTok = $this->secondaryToken !== ''
            ? $this->secondaryToken
            : (str_starts_with(strtolower($this->token), 'bot') ? $this->token : '');

        // اگر توکن ایتایار و فایل محلی برای ارسال فایل (sendFile) داریم
        if ($apiTok !== '' && is_file($photoSource) && !ctype_digit($chatStr)) {
            $url = self::EITAAYAR_BASE . '/' . rawurlencode($apiTok) . '/sendFile';
            $res = $this->httpMultipart($url, 'sendFile', [
                'chat_id' => ltrim($chatStr, '@'),
                'caption' => mb_substr(self::htmlToEitaaMarkdown($caption), 0, 2000),
            ], 'file', $photoSource);
            $ok = (($res['_transport_ok'] ?? true) !== false) && !empty($res['ok']);
            $this->recordSendMetric($ok, $ok ? null : (string) ($res['description'] ?? ($res['_error'] ?? '')));
            return [
                'ok' => $ok,
                'message_id' => isset($res['result']['message_id']) ? (string) $res['result']['message_id'] : null,
                'description' => (string) ($res['description'] ?? ($res['_error'] ?? '')),
                'raw' => $res,
            ];
        }

        // برای چت خصوصی کاربر در ایتا: api/app/sendMessage فقط متن/لینک می‌پذیرد → ارسال متن همراه لینک بنر
        $body = $caption;
        if (str_starts_with(strtolower($photoSource), 'https://') && !str_contains($body, $photoSource)) {
            $body = trim($body . "\n\n🖼 [مشاهده بنر دعوت](" . $photoSource . ')');
        }
        $sent = $this->sendMessage($chatId, $body !== '' ? $body : 'بنر دعوت: ' . $photoSource, $options);
        $sent['fallback_text_only'] = true;
        return $sent;
    }

    public function editMessageText(int|string $chatId, int|string $messageId, string $text, array $options = []): array
    {
        // ایتا متد ویرایش پیام ندارد → جایگزین امن با ارسال پیام جدید
        return $this->sendMessage($chatId, $text, $options);
    }

    public function answerCallback(string $callbackId, string $text = '', bool $alert = false): array
    {
        return ['ok' => true];
    }

    public function checkChannelMembership(array $channel, string $externalUserId): array
    {
        return [
            'ok' => false,
            'member' => false,
            'supported' => false,
            'error' => 'API رسمی ایتا متد بررسی خودکار عضویت کانال ندارد؛ لینک عضویت کانال در برنامک نمایش داده می‌شود.',
        ];
    }

    public function registerWebhook(string $webhookUrl, string $secret): array
    {
        return [
            'ok' => false,
            'description' => 'ایتا بر اساس مستندات رسمی فاقد Webhook چت است؛ ورود و تعامل کاربران به‌صورت مستقیم و امن از طریق «برنامک ایتا (Mini App)» انجام می‌شود.',
        ];
    }

    public function removeWebhook(): array
    {
        return ['ok' => true, 'description' => 'ایتا از Webhook چت استفاده نمی‌کند.'];
    }

    public function fetchUpdates(?string $offset = null, int $limit = 50): array
    {
        return ['ok' => true, 'updates' => [], 'next_offset' => $offset];
    }

    public function parseWebhookPayload(array $payload, string $endpointHint = ''): array
    {
        return [];
    }

    public function syncCommands(array $commands): array
    {
        return ['ok' => false, 'description' => 'ایتا فاقد منوی دستورات چت است؛ تمام بخش‌ها در برنامک ایتا در دسترس هستند.'];
    }

    public function buildReferralLink(string $referralCode, string $fallbackWebUrl = ''): string
    {
        $code = rawurlencode(trim($referralCode));
        $username = $this->botUsername();
        $slug = $this->appSlug();
        if ($username !== '') {
            // لینک مستقیم رسمی برنامک ایتا (پارامتر startapp که در Eitaa.WebApp.initData.start_param دریافت می‌شود)
            $path = $slug !== '' ? ($username . '/' . $slug) : $username;
            return 'https://eitaa.com/' . $path . '?startapp=' . $code;
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
        return [
            'ok' => true,
            'profile' => [
                'bot_id' => $bot['id'],
                'bot_username' => $bot['username'],
                'bot_name' => $bot['name'],
                'bot_share_url' => $bot['share_url'] ?? '',
            ],
        ];
    }

    public function pushProfile(string $name, string $shortDescription, string $description): array
    {
        return [
            'ok' => false,
            'supported' => false,
            'message' => (string) MessengerCapabilities::get('eitaa')['profile_limitation'],
        ];
    }

    public function pushProfilePhoto(string $localPath, string $mimeType): array
    {
        return [
            'ok' => false,
            'supported' => false,
            'error' => 'API رسمی ایتا متد تغییر تصویر برنامه ندارد؛ تصویر را از پنل توسعه‌دهندگان eitaayar.ir بارگذاری کنید.',
        ];
    }

    public function deleteProfilePhoto(): array
    {
        return [
            'ok' => false,
            'supported' => false,
            'error' => 'مدیریت تصویر برنامه ایتا از طریق پنل eitaayar.ir انجام می‌شود.',
        ];
    }

    public function downloadFile(string $fileId): ?string
    {
        return null;
    }
}
