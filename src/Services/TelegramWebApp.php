<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Crypto;
use RB\Support\Database;
use RB\Support\Request;
use RB\Support\Session;

/**
 * احراز هویت Telegram Web App (Mini App)
 *
 * بر اساس مستندات رسمی تلگرام:
 *   secret_key = HMAC_SHA256(bot_token, "WebAppData")
 *   hash       = hex(HMAC_SHA256(data_check_string, secret_key))
 * که data_check_string تمام فیلدها به‌جز hash است، مرتب‌شده و با \n جدا شده.
 * اعتبار زمانی auth_date نیز بررسی می‌شود.
 */
final class TelegramWebApp
{
    public const MAX_AGE = 86400; // ۲۴ ساعت

    /**
     * بررسی initData
     * @return array{ok:bool,user?:array,error?:string,auth_date?:int}
     */
    public static function verify(string $initData): array
    {
        if ($initData === '') {
            return ['ok' => false, 'error' => 'داده احراز هویت تلگرام ارسال نشده است.'];
        }
        parse_str($initData, $data);
        if (!is_array($data) || empty($data['hash'])) {
            return ['ok' => false, 'error' => 'ساختار داده احراز هویت نامعتبر است.'];
        }

        $hash = (string) $data['hash'];
        unset($data['hash']);

        // مرتب‌سازی کلیدها
        ksort($data);
        $pairs = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $pairs[] = $key . '=' . $value;
        }
        $dataCheckString = implode("\n", $pairs);

        $botToken = self::botToken();
        if ($botToken === '') {
            return ['ok' => false, 'error' => 'توکن ربات تنظیم نشده است.'];
        }

        // فرمول جدید (WebAppData)
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $expected = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($expected, $hash)) {
            // برخی کتابخانه‌ها از فرمول قدیمی استفاده می‌کنند؛ سازگاری عقب‌رو
            $legacySecret = hash('sha256', $botToken, true);
            $legacyExpected = hash_hmac('sha256', $dataCheckString, $legacySecret);
            if (!hash_equals($legacyExpected, $hash)) {
                SecurityService::log('miniapp_invalid_signature', 'high', ['ip' => Request::ip()]);
                return ['ok' => false, 'error' => 'امضای داده تلگرام معتبر نیست.'];
            }
        }

        $authDate = (int) ($data['auth_date'] ?? 0);
        if ($authDate <= 0) {
            return ['ok' => false, 'error' => 'زمان احراز هویت نامشخص است.'];
        }
        if (time() - $authDate > self::MAX_AGE) {
            return ['ok' => false, 'error' => 'اعتبار داده احراز هویت منقضی شده است. صفحه را دوباره باز کنید.'];
        }
        if ($authDate - time() > 300) {
            return ['ok' => false, 'error' => 'زمان داده احراز هویت معتبر نیست.'];
        }

        $user = json_decode((string) ($data['user'] ?? '{}'), true);
        if (!is_array($user) || empty($user['id'])) {
            return ['ok' => false, 'error' => 'اطلاعات کاربر در داده تلگرام یافت نشد.'];
        }

        return ['ok' => true, 'user' => $user, 'auth_date' => $authDate];
    }

    /**
     * ورود کاربر Mini App بر اساس initData
     * @return array{ok:bool,user?:array,error?:string}
     */
    public static function authenticate(string $initData): array
    {
        $verified = self::verify($initData);
        if (!$verified['ok']) {
            return $verified;
        }
        $tgUser = $verified['user'];
        $users = new UserService();
        $existing = $users->findByTelegramId((int) $tgUser['id']);
        if ($existing === null) {
            return ['ok' => false, 'error' => 'حساب کاربری یافت نشد. ابتدا ربات را با /start فعال کنید.'];
        }
        if ($existing['status'] === 'blocked') {
            return ['ok' => false, 'error' => 'حساب کاربری شما مسدود شده است.'];
        }

        // به‌روزرسانی پروفایل
        Database::instance()->update('users', [
            'username' => $tgUser['username'] ?? $existing['username'],
            'first_name' => $tgUser['first_name'] ?? $existing['first_name'],
            'last_name' => $tgUser['last_name'] ?? $existing['last_name'],
            'last_activity_at' => now(),
            'updated_at' => now(),
        ], 'id = :id', ['id' => (int) $existing['id']]);

        $user = $users->find((int) $existing['id']) ?? $existing;

        // ---- ضدتکرار (Replay): همان initData نباید دو بار پذیرفته شود
        //
        // initData تا ۲۴ ساعت معتبر است؛ اگر کسی آن را از URL/لاگ بردارد،
        // می‌تواند دوباره به همان حساب وارد شود. با ثبت یک nonce یکتا
        // (هش کل initData) استفادهٔ دوم رد می‌شود.
        //
        // استثنا: همان مرورگری که همین حالا با همین initData وارد شده است
        // می‌تواند صفحه را رفرش کند (تلگرام initData را در هر باز شدن صفحه
        // عوض نمی‌کند). در این حالت احراز هویت تکراری است، نه حمله.
        Session::start();
        $alreadyAuthed = (int) ($_SESSION['miniapp_user_id'] ?? 0) === (int) $user['id'];

        if (!self::claimNonce($initData, (int) $user['id'], (int) ($verified['auth_date'] ?? 0))) {
            if ($alreadyAuthed) {
                $_SESSION['miniapp_auth_at'] = time();
                return ['ok' => true, 'user' => $user, 'reused' => true];
            }
            SecurityService::log('miniapp_replay', 'medium', ['user_id' => (int) $user['id']]);
            return ['ok' => false, 'error' => 'این دادهٔ احراز هویت قبلاً استفاده شده است. صفحه را دوباره از ربات باز کنید.'];
        }

        Session::regenerate();
        $_SESSION['miniapp_user_id'] = (int) $user['id'];
        $_SESSION['miniapp_auth_at'] = time();
        $_SESSION['miniapp_telegram_id'] = (int) $user['telegram_id'];

        return ['ok' => true, 'user' => $user];
    }

    /** کاربر جاری Mini App (با بررسی مجدد دیتابیس) */
    public static function currentUser(): ?array
    {
        Session::start();
        if (empty($_SESSION['miniapp_user_id'])) {
            return null;
        }
        if (time() - (int) ($_SESSION['miniapp_auth_at'] ?? 0) > self::MAX_AGE) {
            unset($_SESSION['miniapp_user_id']);
            return null;
        }
        $user = Database::instance()->first(
            'SELECT * FROM users WHERE id = :id',
            ['id' => (int) $_SESSION['miniapp_user_id']]
        );
        if ($user === null || $user['status'] === 'blocked') {
            return null;
        }
        return $user;
    }

    public static function logout(): void
    {
        Session::start();
        unset($_SESSION['miniapp_user_id'], $_SESSION['miniapp_auth_at'], $_SESSION['miniapp_telegram_id']);
    }

    public static function botToken(): string
    {
        $settings = TelegramService::settings();
        return (string) (Crypto::decrypt($settings['bot_token_enc'] ?? null) ?? '');
    }

    /**
     * ثبت nonce یکتا برای initData و رد استفادهٔ مجدد.
     *
     * @return bool false اگر این initData قبلاً استفاده شده باشد
     */
    private static function claimNonce(string $initData, int $userId, int $authDate): bool
    {
        $db = Database::instance();
        $nonce = hash('sha256', $initData);

        try {
            // پاک‌سازی فرصت‌طلبانهٔ nonce های منقضی (بدون وابستگی به Cron)
            if (random_int(1, 20) === 1) {
                $db->delete('miniapp_auth_nonces', 'expires_at < :n', ['n' => now()]);
            }

            $db->insert('miniapp_auth_nonces', [
                'nonce' => $nonce,
                'user_id' => $userId > 0 ? $userId : null,
                'expires_at' => date('Y-m-d H:i:s', ($authDate > 0 ? $authDate : time()) + self::MAX_AGE + 3600),
                'created_at' => now(),
            ]);
            return true;
        } catch (\Throwable $e) {
            // اگر جدول موجود نیست (نصب قدیمی) یا خطای Duplicate:
            // Duplicate → replay؛ جدول ناموجود → اجازه بده (سازگاری عقب‌رو)
            if ($db->tableExists('miniapp_auth_nonces')) {
                return false; // تقریباً همیشه یعنی UNIQUE شکسته = nonce تکراری
            }
            \RB\Support\ErrorHandler::write('warning', 'miniapp nonce table missing: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * ساخت initData تست (برای تست‌های خودکار)
     */
    public static function buildInitData(array $user, string $botToken, ?int $authDate = null): string
    {
        $data = [
            'auth_date' => (string) ($authDate ?? time()),
            'query_id' => 'AA' . bin2hex(random_bytes(8)),
            'user' => json_encode($user, JSON_UNESCAPED_UNICODE),
        ];
        ksort($data);
        $pairs = [];
        foreach ($data as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $hash = hash_hmac('sha256', implode("\n", $pairs), $secretKey);
        $data['hash'] = $hash;
        return http_build_query($data);
    }
}
