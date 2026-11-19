<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Support\Crypto;
use RB\Support\Database;
use RB\Support\ErrorHandler;
use RB\Support\Request;
use RB\Support\Session;

/**
 * احراز هویت رمزنگاری‌شده و ضدبازپخش (Anti-Replay) Mini App در تلگرام، بله و ایتا
 *
 * الگوریتم رسمی مشترک هر سه پلتفرم:
 *   - Telegram : https://core.telegram.org/bots/webapps
 *   - Bale     : https://docs.bale.ai/miniapp (window.Bale.WebApp.initData)
 *   - Eitaa    : https://developer.eitaa.com/docs/Develop/JsSDK/ (window.Eitaa.WebApp.initData)
 *
 *   secret_key = HMAC_SHA256(key="WebAppData", data=bot_token, raw=true)
 *   hash       = hex(HMAC_SHA256(key=secret_key, data=sorted_data_check_string))
 */
final class WebAppAuthService
{
    public const MAX_AGE_SECONDS = 86400; // ۲۴ ساعت

    /**
     * اعتبارسنجی رمزنگاری‌شده رشته initData با توکن پیام‌رسان
     * @return array{ok:bool, user?:array, auth_date?:int, start_param?:?string, data?:array, error?:string}
     */
    public static function validateWithToken(string $initData, string $botToken, int $maxAge = self::MAX_AGE_SECONDS, bool $logFailure = true): array
    {
        $initData = trim($initData);
        if ($initData === '') {
            return ['ok' => false, 'error' => 'داده احراز هویت مینی‌اپ ارسال نشده است.'];
        }
        parse_str($initData, $data);
        if (!is_array($data) || empty($data['hash']) || !is_string($data['hash'])) {
            return ['ok' => false, 'error' => 'ساختار داده احراز هویت نامعتبر است.'];
        }
        if ($botToken === '') {
            return ['ok' => false, 'error' => 'توکن ربات تنظیم نشده است.'];
        }

        $hash = (string) $data['hash'];
        unset($data['hash']);

        ksort($data, SORT_STRING);
        $pairs = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $v = (string) json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            $pairs[] = $k . '=' . $v;
        }
        $dataCheckString = implode("\n", $pairs);

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $expected = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals(strtolower($expected), strtolower($hash))) {
            if ($logFailure) {
                SecurityService::log('miniapp_invalid_signature', 'high', ['ip' => Request::ip()]);
            }
            return ['ok' => false, 'error' => 'امضای داده احراز هویت معتبر نیست.'];
        }

        $authDate = (int) ($data['auth_date'] ?? 0);
        if ($authDate <= 0) {
            return ['ok' => false, 'error' => 'زمان احراز هویت نامشخص است.'];
        }
        if ($maxAge > 0 && (time() - $authDate) > $maxAge) {
            return ['ok' => false, 'error' => 'اعتبار داده احراز هویت منقضی شده است. صفحه را دوباره باز کنید.'];
        }
        if (($authDate - time()) > 300) {
            return ['ok' => false, 'error' => 'زمان داده احراز هویت معتبر نیست.'];
        }

        $userRaw = $data['user'] ?? '{}';
        $user = is_string($userRaw) ? json_decode($userRaw, true) : (is_array($userRaw) ? $userRaw : null);
        if (!is_array($user) || empty($user['id'])) {
            return ['ok' => false, 'error' => 'اطلاعات کاربر در داده احراز هویت یافت نشد.'];
        }

        $startParam = isset($data['start_param']) && is_string($data['start_param']) && trim($data['start_param']) !== ''
            ? trim($data['start_param'])
            : null;

        return [
            'ok' => true,
            'user' => $user,
            'auth_date' => $authDate,
            'start_param' => $startParam,
            'data' => $data,
        ];
    }

    /** شناسایی و اعتبارسنجی initData در برابر پیام‌رسان اعلام‌شده یا تمام پیام‌رسان‌های دارای Mini App */
    public static function validateMulti(string $initData, ?string $platformHint = null, ?Database $db = null): array
    {
        $initData = trim($initData);
        if ($initData === '') {
            return ['ok' => false, 'error' => 'داده احراز هویت مینی‌اپ ارسال نشده است.'];
        }

        $targetDb = $db ?? Database::instance();
        $registry = new MessengerRegistry($targetDb);

        $hint = $platformHint !== null ? strtolower(trim($platformHint)) : '';
        $miniPlatforms = [MessengerCapabilities::TELEGRAM, MessengerCapabilities::BALE, MessengerCapabilities::EITAA];
        $candidates = ($hint !== '' && in_array($hint, $miniPlatforms, true)) ? [$hint] : $miniPlatforms;

        $lastError = 'توکن پیام‌رسان تنظیم نشده است.';
        $checkedCount = 0;

        foreach ($candidates as $platform) {
            $conn = $registry->rawConnection($platform);
            $token = trim((string) (Crypto::decrypt($conn['bot_token_enc'] ?? null) ?? ''));
            $isEnabled = !empty($conn['is_enabled']);

            if ($platform === MessengerCapabilities::TELEGRAM && $token === '') {
                $tg = TelegramService::settings($targetDb);
                $token = trim((string) (Crypto::decrypt($tg['bot_token_enc'] ?? null) ?? ''));
                if ($token !== '') {
                    $isEnabled = true;
                }
            }
            if ($token === '' || (!$isEnabled && $platform !== MessengerCapabilities::TELEGRAM)) {
                if ($hint !== '') {
                    $lastError = 'پیام‌رسان «' . MessengerCapabilities::label($platform) . '» فعال یا پیکربندی نشده است.';
                }
                continue;
            }
            $checkedCount++;
            $res = self::validateWithToken($initData, $token, self::MAX_AGE_SECONDS, count($candidates) === 1);
            if ($res['ok']) {
                $res['platform'] = $platform;
                return $res;
            }
            $lastError = (string) ($res['error'] ?? $lastError);
        }

        if ($checkedCount > 1) {
            SecurityService::log('miniapp_invalid_signature', 'high', ['ip' => Request::ip(), 'hint' => $hint]);
        }
        return ['ok' => false, 'error' => $lastError];
    }

    /**
     * احراز هویت کامل کاربر Mini App + ضدبازپخش (Nonce) + ایجاد نشست امن
     *
     * @return array{ok:bool, user?:array, platform?:string, is_new?:bool, reused?:bool, allows_write_to_pm?:bool, error?:string}
     */
    public static function authenticate(
        string $initData,
        ?string $platformHint = null,
        ?string $fallbackStartParam = null,
        ?Database $db = null
    ): array {
        $targetDb = $db ?? Database::instance();
        $verified = self::validateMulti($initData, $platformHint, $targetDb);
        if (!$verified['ok']) {
            return $verified;
        }

        $platform = (string) $verified['platform'];
        $extUser = (array) $verified['user'];
        $externalId = (string) ($extUser['id'] ?? '');
        if ($externalId === '') {
            return ['ok' => false, 'error' => 'شناسه کاربری در پیام‌رسان یافت نشد.'];
        }

        $startParam = $verified['start_param'] ?? null;
        if (($startParam === null || $startParam === '') && $fallbackStartParam !== null && trim($fallbackStartParam) !== '') {
            $candidate = trim($fallbackStartParam);
            if (preg_match('~^[A-Za-z0-9_-]{3,64}$~', $candidate)) {
                $startParam = $candidate;
            }
        }

        $users = new UserService($targetDb);
        $existing = $users->findByMessenger($platform, $externalId);
        $isNew = false;

        if ($existing === null) {
            // در ایتا (برنامک نقطه ورود رسمی است) و یا ورود با لینک دعوت startapp ثبت‌نام خودکار انجام می‌شود
            $shouldAutoRegister = ($platform === MessengerCapabilities::EITAA)
                || ($startParam !== null && $startParam !== '');

            if (!$shouldAutoRegister) {
                return ['ok' => false, 'error' => 'حساب کاربری یافت نشد. ابتدا ربات را در ' . MessengerCapabilities::label($platform) . ' با /start فعال کنید.'];
            }

            $canWrite = true;
            if ($platform === MessengerCapabilities::EITAA && array_key_exists('allows_write_to_pm', $extUser)) {
                $canWrite = !empty($extUser['allows_write_to_pm']);
            }

            $reg = $users->registerFromMessenger($platform, [
                'id' => $externalId,
                'chat_id' => $externalId,
                'first_name' => $extUser['first_name'] ?? null,
                'last_name' => $extUser['last_name'] ?? null,
                'username' => $extUser['username'] ?? null,
                'language_code' => $extUser['language_code'] ?? 'fa',
                'can_send_message' => $canWrite,
            ], $startParam, ['source_platform' => $platform]);

            $user = $reg['user'];
            $isNew = (bool) ($reg['is_new'] ?? false);
        } else {
            if (in_array(($existing['status'] ?? 'active'), ['blocked', 'banned'], true)) {
                return ['ok' => false, 'error' => 'حساب کاربری شما مسدود شده است.'];
            }
            $canWrite = null;
            if ($platform === MessengerCapabilities::EITAA && array_key_exists('allows_write_to_pm', $extUser)) {
                $canWrite = !empty($extUser['allows_write_to_pm']);
            }
            $entryPlat = (string) ($existing['entry_platform'] ?? MessengerCapabilities::TELEGRAM);
            if ($platform === $entryPlat) {
                $targetDb->update('users', [
                    'username' => $extUser['username'] ?? $existing['username'],
                    'first_name' => $extUser['first_name'] ?? $existing['first_name'],
                    'last_name' => $extUser['last_name'] ?? $existing['last_name'],
                    'last_activity_at' => now(),
                    'updated_at' => now(),
                ], 'id = :id', ['id' => (int) $existing['id']]);
            }
            $users->touchMessengerActivity($platform, $externalId, (int) $existing['id'], $extUser, $canWrite);
            $user = $users->find((int) $existing['id']) ?? $existing;
        }

        if (in_array(($user['status'] ?? 'active'), ['blocked', 'banned'], true)) {
            return ['ok' => false, 'error' => 'حساب کاربری شما مسدود شده است.'];
        }

        // ضدتکرار (Replay Protection) با Nonce در پایگاه داده
        Session::start();
        $alreadyAuthed = (int) ($_SESSION['miniapp_user_id'] ?? 0) === (int) $user['id'];

        if (!self::claimNonce($targetDb, $platform . ':' . $initData, (int) $user['id'], (int) ($verified['auth_date'] ?? 0))) {
            if ($alreadyAuthed) {
                $_SESSION['miniapp_auth_at'] = time();
                $_SESSION['miniapp_platform'] = $platform;
                $_SESSION['miniapp_external_id'] = $externalId;
                return [
                    'ok' => true,
                    'user' => $user,
                    'platform' => $platform,
                    'external_user_id' => $externalId,
                    'reused' => true,
                    'allows_write_to_pm' => (bool) ($extUser['allows_write_to_pm'] ?? true),
                ];
            }
            SecurityService::log('miniapp_replay', 'medium', ['user_id' => (int) $user['id'], 'platform' => $platform]);
            return ['ok' => false, 'error' => 'این دادهٔ احراز هویت قبلاً استفاده شده است. صفحه را دوباره از پیام‌رسان باز کنید.'];
        }

        Session::regenerate();
        $_SESSION['miniapp_user_id'] = (int) $user['id'];
        $_SESSION['miniapp_auth_at'] = time();
        $_SESSION['miniapp_telegram_id'] = (int) $user['telegram_id'];
        $_SESSION['miniapp_platform'] = $platform;
        $_SESSION['miniapp_external_id'] = $externalId;

        return [
            'ok' => true,
            'user' => $user,
            'platform' => $platform,
            'external_user_id' => $externalId,
            'is_new' => $isNew,
            'allows_write_to_pm' => (bool) ($extUser['allows_write_to_pm'] ?? true),
        ];
    }

    public static function currentUser(): ?array
    {
        Session::start();
        if (empty($_SESSION['miniapp_user_id'])) {
            return null;
        }
        if (time() - (int) ($_SESSION['miniapp_auth_at'] ?? 0) > self::MAX_AGE_SECONDS) {
            self::logout();
            return null;
        }
        $user = Database::instance()->first('SELECT * FROM users WHERE id = :id', ['id' => (int) $_SESSION['miniapp_user_id']]);
        if ($user === null || ($user['status'] ?? 'active') === 'blocked') {
            return null;
        }
        return $user;
    }

    public static function currentPlatform(): string
    {
        Session::start();
        $p = strtolower(trim((string) ($_SESSION['miniapp_platform'] ?? '')));
        return MessengerCapabilities::isSupported($p) ? $p : MessengerCapabilities::TELEGRAM;
    }

    public static function currentExternalId(): string
    {
        Session::start();
        return (string) ($_SESSION['miniapp_external_id'] ?? ($_SESSION['miniapp_telegram_id'] ?? ''));
    }

    public static function logout(): void
    {
        Session::start();
        unset(
            $_SESSION['miniapp_user_id'],
            $_SESSION['miniapp_auth_at'],
            $_SESSION['miniapp_telegram_id'],
            $_SESSION['miniapp_platform'],
            $_SESSION['miniapp_external_id']
        );
    }

    private static function claimNonce(Database $db, string $rawKey, int $userId, int $authDate): bool
    {
        $nonce = hash('sha256', $rawKey);
        try {
            if (random_int(1, 20) === 1) {
                $db->delete('miniapp_auth_nonces', 'expires_at < :n', ['n' => now()]);
            }
            $db->insert('miniapp_auth_nonces', [
                'nonce' => $nonce,
                'user_id' => $userId > 0 ? $userId : null,
                'expires_at' => date('Y-m-d H:i:s', ($authDate > 0 ? $authDate : time()) + self::MAX_AGE_SECONDS + 3600),
                'created_at' => now(),
            ]);
            return true;
        } catch (\Throwable $e) {
            if ($db->tableExists('miniapp_auth_nonces')) {
                return false;
            }
            ErrorHandler::write('warning', 'miniapp nonce table missing: ' . $e->getMessage());
            return true;
        }
    }

    /** ساخت initData امضاشده استاندارد برای تست‌های خودکار هر پیام‌رسان */
    public static function buildSignedInitData(array $user, string $botToken, ?int $authDate = null, ?string $startParam = null): string
    {
        $data = [
            'auth_date' => (string) ($authDate ?? time()),
            'query_id' => 'AA' . bin2hex(random_bytes(8)),
            'user' => (string) json_encode($user, JSON_UNESCAPED_UNICODE),
        ];
        if ($startParam !== null && $startParam !== '') {
            $data['start_param'] = $startParam;
        }
        ksort($data, SORT_STRING);
        $pairs = [];
        foreach ($data as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $data['hash'] = hash_hmac('sha256', implode("\n", $pairs), $secretKey);
        return http_build_query($data);
    }
}
