<?php
declare(strict_types=1);

namespace RB\Controllers\Webhook;

use RB\Bot\BotHandler;
use RB\Http\Controller;
use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Services\CommissionSettings;
use RB\Services\InviteContentService;
use RB\Services\SettingsService;
use RB\Services\UserService;
use RB\Services\RubikaDiagnostics;
use RB\Support\Database;
use RB\Support\ErrorHandler;
use RB\Support\Request;
use RB\Support\View;

/**
 * کنترلر وب‌هوک‌های چندپیام‌رسانی (بله، روبیکا و درایورهای پلاگینی)،
 * درگاه وب دعوت چندپیام‌رسانی (/r/{code}) و سرو امن بنرهای تبلیغاتی (/media/banner/{id}).
 *
 * ایزوله‌سازی کامل: هرگونه خطا یا غیرفعال بودن یک پیام‌رسان فقط به همان درخواست محدود است
 * و هرگز سایر پیام‌رسان‌ها را تحت تأثیر قرار نمی‌دهد.
 */
final class MessengerWebhookController extends Controller
{
    public function bale(array $params = []): void
    {
        $secret = (string) ($params['secret'] ?? '');
        $this->processPlatformWebhook(MessengerCapabilities::BALE, $secret, 'update');
    }

    public function rubika(array $params = []): void
    {
        $secret = (string) ($params['secret'] ?? '');
        $endpoint = (string) ($params['endpoint'] ?? 'receiveUpdate');
        $this->processPlatformWebhook(MessengerCapabilities::RUBIKA, $secret, $endpoint);
    }

    public function generic(array $params = []): void
    {
        $platform = strtolower(trim((string) ($params['platform'] ?? '')));
        $secret = (string) ($params['secret'] ?? '');
        $endpoint = (string) ($params['endpoint'] ?? 'update');
        if (!MessengerCapabilities::isSupported($platform)) {
            $this->json(['ok' => false, 'error' => 'unsupported_platform'], 404);
            return;
        }
        $this->processPlatformWebhook($platform, $secret, $endpoint);
    }

    public function processPlatformWebhook(string $platform, string $secret, string $endpoint, ?string $rawBody = null): void
    {
        $db = Database::instance();
        $registry = new MessengerRegistry($db);

        // ۱) بررسی رمزنگاری‌شده توکن مسیر وب‌هوک (Timing-safe)
        if (!$registry->verifyWebhookSecret($platform, $secret)) {
            $this->json(['ok' => false, 'error' => 'forbidden'], 403);
            return;
        }

        $trace = $platform === MessengerCapabilities::RUBIKA;
        if ($trace) RubikaDiagnostics::begin($db, 'webhook');
        $outcome = 'ignored';
        try {
            // A valid secret only proves the request reached PHP; it does not
            // prove that it came from Rubika (the path is a bearer secret).
            $driver = $registry->driver($platform);
            if (!$driver->isEnabled()) {
                $outcome = 'disabled';
                $this->json(['ok' => true, 'ignored' => 'disabled'], 200);
                return;
            }

            $raw = $rawBody ?? Request::raw();
            if ($raw === '' || strlen($raw) > 1_048_576) {
                $outcome = $raw === '' ? 'empty' : 'too_large';
                $this->json(['ok' => false, 'error' => $raw === '' ? 'empty_body' : 'payload_too_large'], $raw === '' ? 400 : 413);
                return;
            }
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                $outcome = 'invalid_json';
                $this->json(['ok' => false, 'error' => 'invalid_json'], 400);
                return;
            }
            if ($trace) {
                $event = isset($payload['inline_message']) ? 'InlineMessage' : (string) (($payload['update']['type'] ?? null) ?? ($payload['type'] ?? 'unknown'));
                RubikaDiagnostics::event($event);
            }
            $updates = $driver->parseWebhookPayload($payload, $endpoint);
            if ($updates === []) {
                $this->json(['ok' => true, 'processed' => 0], 200);
                return;
            }

            $handler = new BotHandler($db, $driver);
            $processed = 0; $failed = false; $duplicates = 0;
            foreach ($updates as $upd) {
                if ($trace) RubikaDiagnostics::target($upd->chatId);
                $dedupe = $registry->beginUpdate($platform, $upd->updateKey);
                if (!$dedupe['proceed']) { $duplicates++; continue; }
                try {
                    $handler->handleNormalized($upd);
                    $registry->finishUpdate($platform, (int) $dedupe['id'], true);
                    $processed++;
                } catch (\Throwable $e) {
                    $failed = true;
                    if ($trace) RubikaDiagnostics::reason($e instanceof \PDOException ? 'database_error' : 'processing_error');
                    $safe = ErrorHandler::redact($e->getMessage());
                    $registry->finishUpdate($platform, (int) $dedupe['id'], false);
                    ErrorHandler::write('error', strtoupper($platform) . ' webhook error: ' . $safe);
                    MessengerRegistry::logError($platform, 'webhook', 'پردازش رویداد دریافتی ناموفق بود؛ جزئیات را در گزارش خطاهای سرور بررسی کنید.', 'handleUpdate', null, [], $db);
                }
            }
            $outcome = $failed ? 'handler_error' : ($processed > 0 ? 'processed' : ($duplicates > 0 ? 'duplicate' : 'ignored'));
            $this->json(['ok' => true, 'processed' => $processed], 200);
        } catch (\Throwable $e) {
            $outcome = 'handler_error';
            if ($trace) RubikaDiagnostics::reason($e instanceof \PDOException ? 'database_error' : 'processing_error');
            throw $e;
        } finally {
            if ($trace) RubikaDiagnostics::finish($outcome);
        }
    }

    /**
     * درگاه وب یکپارچه دعوت دوستان (/r/{code})
     * به کاربر دعوت‌شده امکان می‌دهد پیام‌رسان دلخواه خود (تلگرام، بله، روبیکا، ایتا) را انتخاب کند
     * و با حفظ ۱۰۰٪ کد ردیابی معرف وارد شود.
     */
    public function referralGateway(array $params = []): void
    {
        $code = trim((string) ($params['code'] ?? ''));
        $db = Database::instance();
        $users = new UserService($db);
        $cleanCode = $users->normalizeCode($code);
        $referrer = preg_match('~^[A-Z0-9]{4,24}$~', $cleanCode) ? $users->findByCode($cleanCode) : null;

        $appName = (string) SettingsService::get('app_name', 'رفرال‌بات');
        $cs = CommissionSettings::current($db);
        $welcomeReward = $cs->effectiveInviteeReward();
        $inviterReward = $cs->effectiveDirectReward();

        $registry = new MessengerRegistry($db);
        $links = $referrer !== null ? $registry->allReferralLinksForCode((string) $referrer['referral_code']) : [];

        // در صورت وجود پارامتر go=platform، ریدایرکت امن فقط به دامنه‌های مجاز پیام‌رسان
        $goPlat = strtolower(trim((string) ($_GET['go'] ?? '')));
        $goUrl = (string) ($links[$goPlat]['link'] ?? '');
        if ($goPlat !== '' && $goUrl !== '' && preg_match('~^https://(t\.me|ble\.ir|rubika\.ir|eitaa\.com)/~i', $goUrl)) {
            Request::redirect($goUrl, 302);
            return;
        }

        $refName = $referrer !== null
            ? (trim((string) (($referrer['first_name'] ?? '') . ' ' . ($referrer['last_name'] ?? ''))) ?: 'یکی از دوستان شما')
            : '';

        $bannerSvc = new InviteContentService($db);
        $defaultBanner = $bannerSvc->defaultBanner();
        $bannerUrl = $defaultBanner !== null ? $bannerSvc->bannerPublicUrl($defaultBanner) : '';

        echo View::render('miniapp/referral_gateway', [
            'title' => $appName . ' — دعوت اختصاصی',
            'skipWebAppSdk' => true,
            'appName' => $appName,
            'referrer' => $referrer,
            'refName' => $refName,
            'code' => $referrer['referral_code'] ?? $cleanCode,
            'links' => $links,
            'welcomeReward' => $welcomeReward,
            'inviterReward' => $inviterReward,
            'bannerUrl' => $bannerUrl,
        ], 'miniapp');
    }

    /** سرو امن بنرهای تبلیغاتی (/media/banner/{id}) بدون افشای مسیر فایل */
    public function banner(array $params = []): void
    {
        $bannerId = (int) ($params['id'] ?? 0);
        if ($bannerId <= 0) {
            if (!headers_sent()) {
                http_response_code(404);
            }
            return;
        }
        $svc = new InviteContentService(Database::instance());
        $banner = $svc->findBanner($bannerId, false);
        if ($banner === null) {
            if (!headers_sent()) {
                http_response_code(404);
            }
            return;
        }
        $abs = $svc->bannerFilePath($banner);
        if ($abs === null || !is_file($abs)) {
            if (!headers_sent()) {
                http_response_code(404);
            }
            return;
        }
        $info = @getimagesize($abs);
        $type = is_array($info) ? (int) ($info[2] ?? 0) : 0;
        $mime = InviteContentService::ALLOWED_IMAGE_TYPES[$type]['mime'] ?? 'image/jpeg';

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: public, max-age=86400');
            header('Content-Length: ' . (string) filesize($abs));
        }
        readfile($abs);
    }
}
