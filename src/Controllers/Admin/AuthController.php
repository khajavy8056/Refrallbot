<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\Controller;
use RB\Services\AdminAuth;
use RB\Support\Request;
use RB\Support\Session;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        // ورود با لینک یک‌بارمصرف تلگرام
        $token = (string) ($_GET['t'] ?? '');
        if ($token !== '') {
            if (AdminAuth::attemptMagicToken($token)) {
                Request::redirect(url('admin'));
                return;
            }
            $this->flash('error', 'لینک ورود نامعتبر یا منقضی شده است.');
        }

        $this->view('admin/login', [
            'title' => 'ورود به پنل مدیریت',
            'error' => Session::pull('login_error'),
            'username' => Session::pull('login_username', ''),
        ], 'auth');
    }

    public function login(): void
    {
        \RB\Support\Csrf::verify();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $this->flash('error', 'نام کاربری و رمز عبور را وارد کنید.');
            $this->back();
            return;
        }

        $result = AdminAuth::attempt($username, $password, Request::ip());
        if (!$result['ok']) {
            $this->flash('error', $result['error']);
            $this->redirect('admin/login');
            return;
        }

        $intended = $_SESSION['_intended'] ?? null;
        unset($_SESSION['_intended']);
        $target = $intended && str_starts_with((string) $intended, '/admin') ? $intended : url('admin');
        Request::redirect($target);
    }

    public function logout(): void
    {
        AdminAuth::logout();
        Request::redirect(url('admin/login'));
    }
}
