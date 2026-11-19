<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Messengers\MessengerCapabilities;
use RB\Support\Crypto;
use RB\Support\Database;

/**
 * احراز هویت Telegram / Multi-Messenger Mini App (سازگار ۱۰۰٪ با نسخه قبلی + متصل به WebAppAuthService)
 */
final class TelegramWebApp
{
    public const MAX_AGE = WebAppAuthService::MAX_AGE_SECONDS;

    /** @return array{ok:bool,user?:array,error?:string,auth_date?:int,start_param?:?string} */
    public static function verify(string $initData): array
    {
        return WebAppAuthService::validateWithToken($initData, self::botToken(), self::MAX_AGE, true);
    }

    /** @return array{ok:bool,user?:array,error?:string,auth_date?:int,start_param?:?string} */
    public static function validate(string $initData, ?string $botToken = null): array
    {
        return WebAppAuthService::validateWithToken($initData, $botToken ?? self::botToken(), self::MAX_AGE, true);
    }

    /** @return array{ok:bool,user?:array,platform?:string,reused?:bool,error?:string} */
    public static function authenticate(string $initData, ?string $platformHint = null, ?string $startParam = null): array
    {
        return WebAppAuthService::authenticate(
            $initData,
            $platformHint ?? MessengerCapabilities::TELEGRAM,
            $startParam,
            Database::instance()
        );
    }

    public static function currentUser(): ?array
    {
        return WebAppAuthService::currentUser();
    }

    public static function currentPlatform(): string
    {
        return WebAppAuthService::currentPlatform();
    }

    public static function logout(): void
    {
        WebAppAuthService::logout();
    }

    public static function botToken(): string
    {
        $settings = TelegramService::settings();
        return (string) (Crypto::decrypt($settings['bot_token_enc'] ?? null) ?? '');
    }

    public static function buildInitData(array $user, string $botToken, ?int $authDate = null, ?string $startParam = null): string
    {
        return WebAppAuthService::buildSignedInitData($user, $botToken, $authDate, $startParam);
    }
}
