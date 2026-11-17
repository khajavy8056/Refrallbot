<?php
declare(strict_types=1);

namespace RB\Http;

use RB\Services\AdminAuth;
use RB\Support\Request;
use RB\Support\Session;
use RB\Support\View;

/**
 * کلاس پایه کنترلرها
 */
abstract class Controller
{
    protected function view(string $view, array $data = [], string $layout = 'app'): void
    {
        View::share('currentAdmin', AdminAuth::current());
        View::share('flashMessages', $this->pullFlash());
        View::share('csrfToken', \RB\Support\Csrf::token());
        View::share('currentPath', Request::path());
        echo View::render($view, $data, $layout);
    }

    protected function json(array $data, int $status = 200): void
    {
        json_out($data, $status);
    }

    protected function ok(string $message, array $extra = []): void
    {
        $this->json(array_merge(['ok' => true, 'message' => $message], $extra));
    }

    protected function fail(string $error, int $status = 422, array $extra = []): void
    {
        $this->json(array_merge(['ok' => false, 'error' => $error], $extra), $status);
    }

    protected function redirect(string $path, ?string $success = null, ?string $error = null): void
    {
        if ($success !== null) {
            $this->flash('success', $success);
        }
        if ($error !== null) {
            $this->flash('error', $error);
        }
        Request::redirect(url($path));
    }

    protected function back(?string $success = null, ?string $error = null): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? url('admin');
        if ($success !== null) {
            $this->flash('success', $success);
        }
        if ($error !== null) {
            $this->flash('error', $error);
        }
        Request::redirect($referer);
    }

    protected function flash(string $type, string $message): void
    {
        Session::start();
        $flash = $_SESSION['_flash'] ?? [];
        $flash[] = ['type' => $type, 'message' => $message];
        $_SESSION['_flash'] = $flash;
    }

    protected function pullFlash(): array
    {
        Session::start();
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($flash) ? $flash : [];
    }

    protected function wantsJson(): bool
    {
        // تست‌های خودکار می‌توانند همان مسیر HTTP را با هدر آزمایشی شبیه‌سازی کنند
        if (isset($_SERVER['HTTP_X_RB_EXPECT_JSON'])) {
            return true;
        }
        return Request::isAjax()
            || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
            || str_starts_with(Request::path(), '/app/api/')
            || str_starts_with(Request::path(), '/admin/api/');
    }

    /** اجرای یک عملیات با پاسخ JSON یا Redirect خودکار */
    protected function respond(callable $action, string $successMessage, string $redirectTo): void
    {
        try {
            $result = $action();
            if ($this->wantsJson()) {
                $this->ok($successMessage, is_array($result) ? $result : []);
                return;
            }
            $this->redirect($redirectTo, $successMessage);
        } catch (\Throwable $e) {
            if ($this->wantsJson()) {
                $this->fail($e->getMessage());
                return;
            }
            $this->back(null, $e->getMessage());
        }
    }

    protected function page(): int
    {
        $page = (int) ($_GET['page'] ?? 1);
        return max(1, $page);
    }

    protected function perPage(): int
    {
        $value = (int) ($_GET['per_page'] ?? \RB\Support\Config::get('app.per_page', 20));
        $allowed = \RB\Support\Config::get('app.per_page_choices', [10, 20, 50, 100]);
        return in_array($value, $allowed, true) ? $value : 20;
    }

    protected function filters(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = trim((string) ($_GET[$key] ?? ''));
            if ($value !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    protected function dateFilter(string $key): ?string
    {
        $value = trim((string) ($_GET[$key] ?? ''));
        if ($value === '') {
            return null;
        }
        $value = en_digits($value);
        // پشتیبانی از تاریخ شمسی
        if (preg_match('~^\d{4}/\d{1,2}/\d{1,2}$~', $value)) {
            return \RB\Support\Jalali::jalaliStringToGregorian($value);
        }
        if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $value)) {
            return $value;
        }
        return null;
    }
}
