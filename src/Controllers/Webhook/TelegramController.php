<?php
declare(strict_types=1);

namespace RB\Controllers\Webhook;

use RB\Bot\BotHandler;
use RB\Http\Controller;
use RB\Services\SecurityService;
use RB\Services\TelegramService;
use RB\Support\Config;
use RB\Support\Database;
use RB\Support\Request;

/**
 * دریافت آپدیت‌های تلگرام
 * امنیت: تطبیق هدر X-Telegram-Bot-Api-Secret-Token با راز ذخیره‌شده
 */
final class TelegramController extends Controller
{
    public function handle(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->json(['ok' => false, 'error' => 'متد نامعتبر'], 405);
            return;
        }

        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            $this->json(['ok' => false, 'error' => 'بدنه درخواست خالی است'], 400);
            return;
        }
        if (strlen($raw) > 2 * 1024 * 1024) {
            $this->json(['ok' => false, 'error' => 'حجم آپدیت بیش از حد مجاز است'], 413);
            return;
        }

        $settings = TelegramService::settings();
        $secret = (string) ($settings['webhook_secret'] ?? '');
        if ($secret !== '') {
            $provided = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
            if (!hash_equals($secret, $provided)) {
                SecurityService::log('webhook_invalid_secret', 'high', ['ip' => Request::ip()]);
                $this->json(['ok' => false, 'error' => 'راز Webhook مطابقت ندارد'], 403);
                return;
            }
        }

        $update = json_decode($raw, true);
        if (!is_array($update)) {
            $this->json(['ok' => false, 'error' => 'JSON نامعتبر'], 400);
            return;
        }

        // همیشه سریع پاسخ می‌دهیم تا تلگرام آپدیت را مجدداً ارسال نکند؛
        // پردازش در همان درخواست انجام می‌شود اما خطاها پاسخ را خراب نمی‌کنند.
        try {
            (new BotHandler())->handle($update);
            $this->json(['ok' => true]);
        } catch (\Throwable $e) {
            error_log('[refrallbot] webhook error: ' . $e->getMessage());
            $this->json(['ok' => true, 'warning' => 'processed_with_error']);
        }
    }

    public function status(): void
    {
        $settings = TelegramService::settings();
        $this->json([
            'ok' => true,
            'endpoint' => 'telegram-webhook',
            'configured' => !empty($settings['bot_token_enc']),
            'webhook_active' => !empty($settings['webhook_active']),
            'last_update_at' => $settings['last_update_at'] ?? null,
            'updates_count' => (int) ($settings['updates_count'] ?? 0),
        ]);
    }

    public function health(): void
    {
        $checks = ['app' => 'ok', 'db' => 'fail', 'installed' => \RB\Http\Middleware::isInstalled(), 'php' => PHP_VERSION];
        try {
            Database::instance()->value('SELECT 1');
            $checks['db'] = 'ok';
        } catch (\Throwable $e) {
            $checks['db_error'] = $e->getMessage();
        }
        $healthy = $checks['db'] === 'ok';
        $this->json(['ok' => $healthy, 'checks' => $checks, 'time' => date('c')], $healthy ? 200 : 503);
    }

    public function notFound(): void
    {
        $this->json(['ok' => false, 'error' => 'مسیر مورد نظر یافت نشد.'], 404);
    }
}
