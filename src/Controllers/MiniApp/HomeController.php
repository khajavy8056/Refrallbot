<?php
declare(strict_types=1);

namespace RB\Controllers\MiniApp;

use RB\Http\Controller;
use RB\Http\Middleware;
use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Services\CommissionService;
use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Services\WebAppAuthService;
use RB\Support\Csrf;
use RB\Support\Database;
use RB\Support\Request;
use RB\Support\View;

/**
 * صفحه Mini App چندپیام‌رسانی و احراز هویت رمزنگاری‌شده (تلگرام، بله، ایتا)
 */
final class HomeController extends Controller
{
    /** فهرست لینک پیام‌رسان‌های فعال برای صفحه فرود و صفحه غیرفعال */
    private function activeMessengerLinks(): array
    {
        $out = [];
        try {
            $registry = new MessengerRegistry(Database::instance());
            foreach ($registry->activeConnectedConnections() as $p => $conn) {
                $username = ltrim((string) ($conn['bot_username'] ?? ''), '@');
                if ($username === '') {
                    continue;
                }
                $extra = is_array($conn['extra'] ?? null) ? $conn['extra'] : [];
                $appSlug = trim((string) ($extra['app_slug'] ?? 'app'), '/');
                $url = match ($p) {
                    MessengerCapabilities::TELEGRAM => 'https://t.me/' . $username,
                    MessengerCapabilities::BALE     => 'https://ble.ir/' . $username,
                    MessengerCapabilities::RUBIKA   => 'https://rubika.ir/' . $username,
                    MessengerCapabilities::EITAA    => 'https://eitaa.com/' . $username . ($appSlug !== '' ? '/' . $appSlug : ''),
                    default                         => '',
                };
                if ($url === '') {
                    continue;
                }
                $out[] = [
                    'platform' => $p,
                    'label' => MessengerCapabilities::label($p),
                    'icon' => (string) ($conn['meta']['icon'] ?? '🤖'),
                    'username' => $username,
                    'url' => $url,
                ];
            }
        } catch (\Throwable) {
        }
        return $out;
    }

    /** صفحه فرود عمومی (وقتی کسی آدرس اصلی را باز می‌کند) */
    public function landing(): void
    {
        if (!Middleware::isInstalled()) {
            Request::redirect(url('install'));
            return;
        }
        $settings = TelegramService::settings();
        $botUsername = (string) ($settings['bot_username'] ?? '');
        View::share('botUsername', $botUsername);
        echo View::render('miniapp/landing', [
            'title' => (string) SettingsService::get('app_name', 'رفرال‌بات') . ' — ورود',
            'skipWebAppSdk' => true,
            'appName' => (string) SettingsService::get('app_name', 'رفرال‌بات'),
            'botUsername' => $botUsername,
            'messengerLinks' => $this->activeMessengerLinks(),
            'miniappUrl' => (string) SettingsService::get('miniapp_url', ''),
        ], 'miniapp');
    }

    /** Mini App اصلی (پشتیبانی از تلگرام، بله و ایتا) */
    public function index(): void
    {
        $rawHint = strtolower(trim((string) ($_GET['platform'] ?? '')));
        $platformHint = in_array($rawHint, [
            MessengerCapabilities::TELEGRAM,
            MessengerCapabilities::BALE,
            MessengerCapabilities::EITAA,
        ], true) ? $rawHint : null;

        if (!SettingsService::miniappActive($platformHint)) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            echo View::render('miniapp/disabled', [
                'title' => 'پنل کاربری',
                'skipWebAppSdk' => true,
                'appName' => (string) SettingsService::get('app_name', 'رفرال‌بات'),
                'botUsername' => (string) (TelegramService::settings()['bot_username'] ?? ''),
                'messengerLinks' => $this->activeMessengerLinks(),
            ], 'miniapp');
            return;
        }

        echo View::render('miniapp/home', [
            'title' => (string) SettingsService::get('app_name', 'رفرال‌بات') . ' — پنل کاربری',
            'platformHint' => $platformHint ?? '',
            'appName' => (string) SettingsService::get('app_name', 'رفرال‌بات'),
            'supportText' => (string) SettingsService::get('support_text', ''),
        ], 'miniapp');
    }

    /** احراز هویت یکپارچه با initData امضاشده (Telegram / Bale / Eitaa) */
    public function auth(): void
    {
        $initData = Request::string('init_data', '');
        if ($initData === '') {
            $initData = trim((string) (
                $_SERVER['HTTP_X_MINIAPP_INIT_DATA']
                ?? $_SERVER['HTTP_X_TELEGRAM_INIT_DATA']
                ?? ''
            ));
        }

        $platformHint = strtolower(trim(Request::string('platform', '')));
        if (!in_array($platformHint, [
            MessengerCapabilities::TELEGRAM,
            MessengerCapabilities::BALE,
            MessengerCapabilities::EITAA,
        ], true)) {
            $platformHint = '';
        }

        $startParam = trim(Request::string('start_param', ''));
        $db = Database::instance();

        $result = WebAppAuthService::authenticate(
            $initData,
            $platformHint !== '' ? $platformHint : null,
            $startParam !== '' ? $startParam : null,
            $db
        );
        if (!$result['ok']) {
            $this->json(['ok' => false, 'error' => $result['error'] ?? 'احراز هویت ناموفق بود.'], 401);
            return;
        }

        $user = $result['user'];
        $platform = (string) ($result['platform'] ?? MessengerCapabilities::TELEGRAM);

        // اگر کاربر از طریق مینی‌اپ (ایتا یا لینک startapp بله/تلگرام) تازه ثبت‌نام شده و معرف دارد، بررسی واجدشرایط‌شدن اجرا شود
        if (!empty($result['is_new']) && !empty($user['referred_by'])) {
            try {
                (new \RB\Services\UserService($db))->maybeQualify($user, false, $platform);
                \RB\Services\Notifier::flushDeferred();
                $user = (new \RB\Services\UserService($db))->find((int) $user['id']) ?? $user;
            } catch (\Throwable) {
                \RB\Services\Notifier::clearDeferred();
            }
        }

        $this->json([
            'ok' => true,
            'csrf' => Csrf::token(),
            'platform' => $platform,
            'platform_label' => MessengerCapabilities::label($platform),
            'allows_write_to_pm' => (bool) ($result['allows_write_to_pm'] ?? true),
            'user' => [
                'id' => (int) $user['id'],
                'telegram_id' => (string) $user['telegram_id'],
                'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
                'username' => $user['username'] ?? null,
                'referral_code' => $user['referral_code'],
                'status' => $user['status'],
                'entry_platform' => $user['entry_platform'] ?? $platform,
            ],
        ]);
    }

    /** اطلاعات پایه برای فرانت */
    public function me(): void
    {
        $user = WebAppAuthService::currentUser();
        if ($user === null) {
            $this->json(['ok' => false, 'error' => 'ابتدا از طریق پیام‌رسان وارد شوید.'], 401);
            return;
        }
        $platform = WebAppAuthService::currentPlatform();
        $this->json([
            'ok' => true,
            'platform' => $platform,
            'platform_label' => MessengerCapabilities::label($platform),
            'user' => [
                'id' => (int) $user['id'],
                'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            ],
        ]);
    }
}
