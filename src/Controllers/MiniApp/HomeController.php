<?php
declare(strict_types=1);

namespace RB\Controllers\MiniApp;

use RB\Http\Controller;
use RB\Http\Middleware;
use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Services\TelegramWebApp;
use RB\Services\UserService;
use RB\Support\Request;
use RB\Support\View;

/**
 * صفحه Mini App و احراز هویت تلگرام
 */
final class HomeController extends Controller
{
    /** صفحه فرود عمومی (وقتی کسی آدرس اصلی را باز می‌کند) */
    public function landing(): void
    {
        if (!Middleware::isInstalled()) {
            Request::redirect(url('install'));
            return;
        }
        $settings = TelegramService::settings();
        View::share('botUsername', (string) ($settings['bot_username'] ?? ''));
        echo View::render('miniapp/landing', [
            'title' => 'پنل کاربری',
            'appName' => (string) SettingsService::get('app_name', 'رفرال‌بات'),
            'miniappUrl' => (string) SettingsService::get('miniapp_url', ''),
        ], 'miniapp');
    }

    /** Mini App اصلی */
    public function index(): void
    {
        if (!SettingsService::miniappActive()) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            echo View::render('miniapp/disabled', [
                'title' => 'پنل کاربری',
                'appName' => (string) SettingsService::get('app_name', 'رفرال‌بات'),
                'botUsername' => (string) (TelegramService::settings()['bot_username'] ?? ''),
            ], 'miniapp');
            return;
        }
        echo View::render('miniapp/home', [
            'title' => 'پنل کاربری',
            'appName' => (string) SettingsService::get('app_name', 'رفرال‌بات'),
            'supportText' => (string) SettingsService::get('support_text', ''),
        ], 'miniapp');
    }

    /** احراز هویت با initData تلگرام */
    public function auth(): void
    {
        // ورودی یکپارچه: miniapp.js بدنه را با Content-Type: application/json
        // می‌فرستد، پس $_POST خالی است. نسخهٔ قبلی فقط $_POST را می‌خواند و
        // احراز هویت همیشه با خطای «داده ارسال نشده» شکست می‌خورد.
        $initData = Request::string('init_data', '');
        if ($initData === '') {
            // هدر تلگرام هم پذیرفته می‌شود
            $initData = trim((string) ($_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? ''));
        }
        $result = TelegramWebApp::authenticate($initData);
        if (!$result['ok']) {
            $this->json(['ok' => false, 'error' => $result['error'] ?? 'احراز هویت ناموفق بود.'], 401);
            return;
        }
        $user = $result['user'];
        $this->json([
            'ok' => true,
            'csrf' => \RB\Support\Csrf::token(),
            'user' => [
                'id' => (int) $user['id'],
                'telegram_id' => (string) $user['telegram_id'],
                'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
                'username' => $user['username'] ?? null,
                'referral_code' => $user['referral_code'],
                'status' => $user['status'],
            ],
        ]);
    }

    /** اطلاعات پایه برای فرانت */
    public function me(): void
    {
        $user = TelegramWebApp::currentUser();
        if ($user === null) {
            $this->json(['ok' => false, 'error' => 'ابتدا از طریق ربات وارد شوید.'], 401);
            return;
        }
        $this->json(['ok' => true, 'user' => ['id' => (int) $user['id'], 'name' => trim($user['first_name'] . ' ' . $user['last_name'])]]);
    }
}
