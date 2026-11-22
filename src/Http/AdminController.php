<?php
declare(strict_types=1);

namespace RB\Http;

use RB\Services\AdminAuth;
use RB\Services\BroadcastService;
use RB\Support\Database;
use RB\Support\View;

/**
 * کنترلر پایه‌ی پنل مدیریت — شامل ناوبری، نشان‌های هشدار و اجرای فرصت‌طلبانه صف
 */
abstract class AdminController extends Controller
{
    protected function render(string $view, array $data = []): void
    {
        $db = Database::instance();
        BroadcastService::triggerOpportunisticRun($db, 2.5);

        $navBadges = [
            'withdrawals' => (int) $db->value(
                "SELECT COUNT(*) FROM withdrawal_requests WHERE status IN ('new','reviewing','approved','paying')"
            ),
            'support' => (int) $db->value("SELECT COUNT(*) FROM support_messages WHERE status = 'open' AND direction = 'in'"),
            'security' => (int) $db->value("SELECT COUNT(*) FROM security_events WHERE status = 'open'"),
            'update' => (AdminAuth::requireSuperAdmin() && \RB\Services\UpdateService::hasCachedUpdate()) ? 1 : 0,
        ];
        View::share('navBadges', $navBadges);
        View::share('currentAdmin', AdminAuth::current());
        View::share('flashMessages', $this->pullFlash());
        View::share('csrfToken', \RB\Support\Csrf::token());
        View::share('currentPath', \RB\Support\Request::path());
        View::share('title', $data['title'] ?? 'پنل مدیریت');
        echo View::render($view, $data, 'app');
    }
}
