<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Services\CronService;
use RB\Services\SettingsService;
use RB\Services\StatsService;
use RB\Support\Database;
use RB\Support\Request;

/**
 * مدیریت یکپارچه پیام‌رسان‌ها (تلگرام، بله، روبیکا، ایتا)
 * هر پیام‌رسان کاملاً ایزوله است: توکن رمزنگاری‌شده، فعال/غیرفعال مستقل، تست، وب‌هوک، پولینگ و گزارش وضعیت.
 */
final class MessengerController extends AdminController
{
    public function index(): void
    {
        $db = Database::instance();
        $registry = new MessengerRegistry($db);
        $connections = $registry->allConnections();

        // لینک وب‌هوک واقعی (شامل مسیر امن اختصاصی برای مدیر)
        $webhookUrls = [];
        foreach (MessengerCapabilities::platforms() as $p) {
            $webhookUrls[$p] = $registry->suggestedWebhookUrl($p, true);
        }

        $selected = strtolower(trim((string) ($_GET['tab'] ?? MessengerCapabilities::TELEGRAM)));
        if (!MessengerCapabilities::isSupported($selected)) {
            $selected = MessengerCapabilities::TELEGRAM;
        }

        $perfReport = StatsService::messengerPerformanceReport($db);
        $perfByPlatform = [];
        foreach ($perfReport as $row) {
            $perfByPlatform[(string) $row['platform']] = $row;
        }

        $recentErrors = !empty($connections[$selected]['is_active_connected'])
            ? $registry->recentErrors([$selected], null, 8)
            : [];

        $this->render('admin/messengers/index', [
            'title' => 'مدیریت پیام‌رسان‌ها',
            'connections' => $connections,
            'webhookUrls' => $webhookUrls,
            'selected' => $selected,
            'perfByPlatform' => $perfByPlatform,
            'recentErrors' => $recentErrors,
            'uiMode' => SettingsService::configuredBotUiMode(),
            'platformUiModes' => SettingsService::allPlatformUiModes(),
            'miniappUrl' => SettingsService::miniappBaseUrl(),
            'hasHttps' => SettingsService::hasHttpsMiniappUrl(),
            'cronToken' => CronService::webToken(true),
            'capabilityMatrix' => MessengerCapabilities::all(),
        ]);
    }

    public function save(array $params = []): void
    {
        $platform = strtolower(trim((string) ($params['platform'] ?? '')));
        if (!MessengerCapabilities::isSupported($platform)) {
            $this->reply(false, 'پیام‌رسان نامعتبر است.', 'admin/messengers');
            return;
        }

        $cleanToken = static function (string $raw): string {
            return (string) (preg_replace('~[\s\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+~u', '', $raw) ?? trim($raw));
        };

        $input = [
            'bot_token' => $cleanToken(Request::string('bot_token', '')),
            'secondary_token' => $cleanToken(Request::string('secondary_token', '')),
            'clear_secondary_token' => !empty($_POST['clear_secondary_token']),
            'bot_username' => Request::string('bot_username', ''),
            'bot_name' => Request::string('bot_name', ''),
            'app_slug' => Request::string('app_slug', ''),
            'is_enabled' => !empty($_POST['is_enabled']),
            'polling_enabled' => !empty($_POST['polling_enabled']),
        ];

        $autoTest = !isset($_POST['auto_test']) || !empty($_POST['auto_test']);
        $registry = new MessengerRegistry(Database::instance());
        $res = $registry->saveCredentials($platform, $input, current_admin_id(), $autoTest);
        $this->reply((bool) ($res['ok'] ?? false), (string) ($res['message'] ?? ''), 'admin/messengers?tab=' . $platform, $res);
    }

    public function toggle(array $params = []): void
    {
        $platform = strtolower(trim((string) ($params['platform'] ?? '')));
        if (!MessengerCapabilities::isSupported($platform)) {
            $this->reply(false, 'پیام‌رسان نامعتبر است.', 'admin/messengers');
            return;
        }
        $registry = new MessengerRegistry(Database::instance());
        $current = $registry->safeConnection($platform);
        $target = array_key_exists('enabled', $_POST) ? !empty($_POST['enabled']) : empty($current['is_enabled']);
        $res = $registry->setEnabled($platform, $target, current_admin_id());
        $this->reply((bool) ($res['ok'] ?? false), (string) ($res['message'] ?? ''), 'admin/messengers?tab=' . $platform, $res);
    }

    public function test(array $params = []): void
    {
        $platform = strtolower(trim((string) ($params['platform'] ?? '')));
        if (!MessengerCapabilities::isSupported($platform)) {
            $this->reply(false, 'پیام‌رسان نامعتبر است.', 'admin/messengers');
            return;
        }
        $res = (new MessengerRegistry(Database::instance()))->testConnection($platform, current_admin_id());
        $this->reply((bool) ($res['ok'] ?? false), (string) ($res['message'] ?? ($res['error'] ?? '')), 'admin/messengers?tab=' . $platform, $res);
    }

    public function setWebhook(array $params = []): void
    {
        $platform = strtolower(trim((string) ($params['platform'] ?? '')));
        if (!MessengerCapabilities::isSupported($platform)) {
            $this->reply(false, 'پیام‌رسان نامعتبر است.', 'admin/messengers');
            return;
        }
        $custom = trim(Request::string('webhook_url', ''));
        $res = (new MessengerRegistry(Database::instance()))->registerWebhook(
            $platform,
            $custom !== '' ? $custom : null,
            current_admin_id()
        );
        $this->reply((bool) ($res['ok'] ?? false), (string) ($res['message'] ?? ($res['error'] ?? '')), 'admin/messengers?tab=' . $platform, $res);
    }

    public function deleteWebhook(array $params = []): void
    {
        $platform = strtolower(trim((string) ($params['platform'] ?? '')));
        if (!MessengerCapabilities::isSupported($platform)) {
            $this->reply(false, 'پیام‌رسان نامعتبر است.', 'admin/messengers');
            return;
        }
        $res = (new MessengerRegistry(Database::instance()))->removeWebhook($platform, current_admin_id());
        $this->reply((bool) ($res['ok'] ?? false), (string) ($res['message'] ?? ($res['error'] ?? '')), 'admin/messengers?tab=' . $platform, $res);
    }

    public function syncCommands(array $params = []): void
    {
        $platform = strtolower(trim((string) ($params['platform'] ?? '')));
        if (!MessengerCapabilities::isSupported($platform)) {
            $this->reply(false, 'پیام‌رسان نامعتبر است.', 'admin/messengers');
            return;
        }
        $res = (new MessengerRegistry(Database::instance()))->syncCommands($platform, current_admin_id());
        $this->reply((bool) ($res['ok'] ?? false), (string) ($res['message'] ?? ($res['error'] ?? '')), 'admin/messengers?tab=' . $platform, $res);
    }

    public function disconnect(array $params = []): void
    {
        $platform = strtolower(trim((string) ($params['platform'] ?? '')));
        if (!MessengerCapabilities::isSupported($platform)) {
            $this->reply(false, 'پیام‌رسان نامعتبر است.', 'admin/messengers');
            return;
        }
        $res = (new MessengerRegistry(Database::instance()))->deleteCredentials($platform, current_admin_id());
        $this->reply((bool) ($res['ok'] ?? false), (string) ($res['message'] ?? ''), 'admin/messengers?tab=' . $platform, $res);
    }

    private function reply(bool $ok, string $message, string $redirectTo, array $extra = []): void
    {
        if ($this->wantsJson()) {
            if ($ok) {
                $this->ok($message ?: 'با موفقیت انجام شد.', $extra);
            } else {
                $this->fail($message ?: 'عملیات ناموفق بود.');
            }
            return;
        }
        if ($ok) {
            $this->redirect($redirectTo, $message ?: 'با موفقیت انجام شد.');
        } else {
            $this->redirect($redirectTo, null, $message ?: 'عملیات ناموفق بود.');
        }
    }
}
