<?php
declare(strict_types=1);

namespace RB\Controllers\Webhook;

use RB\Bot\BotHandler;
use RB\Http\Controller;
use RB\Services\SecurityService;
use RB\Services\TelegramService;
use RB\Services\UpdateDeduplicator;
use RB\Support\Config;
use RB\Support\Database;
use RB\Support\ErrorHandler;
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

        $raw = Request::raw();
        if ($raw === '') {
            $this->json(['ok' => false, 'error' => 'بدنه درخواست خالی است'], 400);
            return;
        }
        if (strlen($raw) > 2 * 1024 * 1024) {
            $this->json(['ok' => false, 'error' => 'حجم آپدیت بیش از حد مجاز است'], 413);
            return;
        }

        // ---------------------------------------------------------- راز Webhook
        //
        // سیاست: اگر Webhook فعال است، Secret اجباری است.
        // قبلاً اگر secret خالی بود کل بررسی رد می‌شد، یعنی هر کسی می‌توانست
        // آپدیت جعلی بفرستد. حالا نبود Secret با 403 رد می‌شود.
        $settings = TelegramService::settings();
        $secret = (string) ($settings['webhook_secret'] ?? '');
        $webhookActive = !empty($settings['webhook_active']);

        if ($secret === '') {
            // بدون Secret، وبهوک عملاً باز است؛ فقط اگر webhook فعال نیست
            // (یعنی این درخواست از مسیر Polling/تست می‌آید) اجازه می‌دهیم.
            if ($webhookActive) {
                SecurityService::log('webhook_missing_secret', 'high', ['ip' => Request::ip()]);
                $this->json(['ok' => false, 'error' => 'Secret Token برای Webhook تنظیم نشده است.'], 403);
                return;
            }
        } else {
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

        // ---------------------------------------------------------- ضدتکرار
        //
        // تلگرام اگر 200 نگیرد همان آپدیت را دوباره می‌فرستد. ثبت update_id
        // قبل از پردازش، جلوی اجرای دوبارهٔ عملیات مالی را می‌گیرد.
        $updateId = (int) ($update['update_id'] ?? 0);
        $dedupe = new UpdateDeduplicator();
        $claim = $dedupe->begin($updateId, $update);

        if (!$claim['proceed']) {
            // آپدیت تکراری یا در حال پردازش — پاسخ 200 تا تلگرام دوباره نفرستد
            $this->json(['ok' => true, 'duplicate' => true, 'reason' => $claim['reason']]);
            return;
        }

        try {
            (new BotHandler())->handle($update);
            $dedupe->done($claim['id']);
            // پاک‌سازی خودکار خطای پردازش قبلی پس از اجرای موفق آپدیت
            $lastErr = (string) ($settings['last_error'] ?? '');
            if ($lastErr !== '' && str_starts_with($lastErr, 'خطای پردازش آپدیت:')) {
                try {
                    Database::instance()->update('telegram_settings', [
                        'last_error' => null,
                        'updated_at' => now(),
                    ], 'id = :id', ['id' => (int) ($settings['id'] ?? 0)]);
                    TelegramService::forgetSettingsCache();
                } catch (\Throwable) {
                }
            }
            $this->json(['ok' => true]);
        } catch (\Throwable $e) {
            // خطا را ثبت می‌کنیم و قفل را آزاد می‌کنیم تا تلاش مجدد تلگرام
            // واقعاً پردازش کند. پاسخ 500 عمدی است: تلگرام همان آپدیت را
            // دوباره می‌فرستد و کاربر پیام گم‌شده نمی‌بیند. عملیات مالی با
            // idempotency_key در برابر اجرای دوباره محافظت شده‌اند.
            $safeMsg = ErrorHandler::redact($e->getMessage());
            $dedupe->fail($claim['id'], $safeMsg);
            ErrorHandler::write('error', 'webhook processing failed: ' . $safeMsg . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            if (!empty($settings['id'])) {
                try {
                    Database::instance()->update('telegram_settings', [
                        'last_error' => mb_substr('خطای پردازش آپدیت: ' . $safeMsg, 0, 900),
                        'updated_at' => now(),
                    ], 'id = :id', ['id' => (int) $settings['id']]);
                    TelegramService::forgetSettingsCache();
                } catch (\Throwable) {
                }
            }
            $this->json([
                'ok' => false,
                'error' => 'پردازش آپدیت ناموفق بود.',
                'retry' => true,
            ], 500);
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

    /**
     * بررسی سلامت — همیشه JSON برمی‌گرداند.
     *
     * اگر جزئیات داخلی خطا بدهد، به‌جای بدنهٔ خالی یک JSON با ok:false
     * برمی‌گردانیم تا مانیتور/CI هرگز «۲۰۰ خالی» نبیند.
     */
    public function health(): void
    {
        try {
            $checks = [
                'app' => 'ok',
                'db' => 'fail',
                'installed' => \RB\Http\Middleware::isInstalled(),
                'php' => PHP_VERSION,
            ];
            try {
                Database::instance()->value('SELECT 1');
                $checks['db'] = 'ok';
            } catch (\Throwable $e) {
                $checks['db_error'] = ErrorHandler::redact($e->getMessage());
            }
            $healthy = $checks['db'] === 'ok';
            $this->json(['ok' => $healthy, 'checks' => $checks, 'time' => date('c')], $healthy ? 200 : 503);
        } catch (\Throwable $e) {
            // حتی اگر ساخت JSON ممکن نبود، خروجی خالی نده
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok' => false,
                'error' => ErrorHandler::redact($e->getMessage()),
                'time' => date('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    public function notFound(): void
    {
        $this->json(['ok' => false, 'error' => 'مسیر مورد نظر یافت نشد.'], 404);
    }
}
