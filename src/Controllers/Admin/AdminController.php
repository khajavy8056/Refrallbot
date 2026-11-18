<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController as BaseAdminController;
use RB\Services\AdminAuth;
use RB\Services\AuditService;
use RB\Support\Database;
use RB\Support\Request;

final class AdminController extends BaseAdminController
{
    public function index(): void
    {
        if (!AdminAuth::requireSuperAdmin()) {
            $this->flash('error', 'مدیریت حساب‌های مدیر فقط برای «مدیر کل» مجاز است.');
            Request::redirect(url('admin'));
            return;
        }
        $db = Database::instance();
        $this->render('admin/admins/index', [
            'title' => 'مدیران سامانه',
            'admins' => $db->all('SELECT a.*, r.name AS role_name, r.slug AS role_slug FROM admins a LEFT JOIN admin_roles r ON r.id = a.role_id ORDER BY a.id'),
        ]);
    }

    public function store(): void
    {
        if (!AdminAuth::requireSuperAdmin()) {
            $this->fail('دسترسی کافی ندارید.', 403);
            return;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $telegramId = trim((string) ($_POST['telegram_user_id'] ?? ''));
        $roleId = (int) ($_POST['role_id'] ?? 0);

        if (mb_strlen($name) < 3) {
            $this->fail('نام باید حداقل ۳ کاراکتر باشد.');
            return;
        }
        if (!preg_match('~^[a-zA-Z0-9_.]{4,40}$~', $username)) {
            $this->fail('نام کاربری نامعتبر است (۴ تا ۴۰ کاراکتر لاتین).');
            return;
        }
        if (strlen($password) < 10 || !preg_match('~[A-Z]~', $password) || !preg_match('~[a-z]~', $password) || !preg_match('~\d~', $password)) {
            $this->fail('رمز عبور باید حداقل ۱۰ کاراکتر و شامل حروف بزرگ، کوچک و عدد باشد.');
            return;
        }
        $db = Database::instance();
        if ($db->value('SELECT id FROM admins WHERE username = :u', ['u' => $username])) {
            $this->fail('این نام کاربری قبلاً ثبت شده است.');
            return;
        }
        if ($roleId <= 0) {
            $roleId = (int) $db->value("SELECT id FROM admin_roles WHERE slug = 'superadmin'");
        }
        $id = $db->insert('admins', [
            'name' => mb_substr($name, 0, 110),
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role_id' => $roleId,
            'telegram_user_id' => $telegramId !== '' ? (int) en_digits($telegramId) : null,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AuditService::log('admin.created', 'admin', $id, ['username' => $username], current_admin_id());
        $this->ok('مدیر جدید ایجاد شد.', ['reload' => true]);
    }

    public function changePassword(array $params): void
    {
        $id = (int) $params['id'];
        $current = AdminAuth::current();
        if (!AdminAuth::requireSuperAdmin() && (int) ($current['id'] ?? 0) !== $id) {
            $this->fail('دسترسی کافی ندارید.', 403);
            return;
        }
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 10 || !preg_match('~[A-Z]~', $password) || !preg_match('~[a-z]~', $password) || !preg_match('~\d~', $password)) {
            $this->fail('رمز عبور باید حداقل ۱۰ کاراکتر و شامل حروف بزرگ، کوچک و عدد باشد.');
            return;
        }
        Database::instance()->update('admins', [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $id]);
        AuditService::log('admin.password_changed', 'admin', $id, [], current_admin_id());
        $this->ok('رمز عبور تغییر کرد.', ['reload' => true]);
    }

    public function toggle(array $params): void
    {
        if (!AdminAuth::requireSuperAdmin()) {
            $this->fail('دسترسی کافی ندارید.', 403);
            return;
        }
        $id = (int) $params['id'];
        if ($id === (int) (current_admin_id() ?? 0)) {
            $this->fail('نمی‌توانید حساب خودتان را غیرفعال کنید.');
            return;
        }
        $db = Database::instance();
        $admin = $db->first('SELECT is_active FROM admins WHERE id = :id', ['id' => $id]);
        if ($admin === null) {
            $this->fail('مدیر یافت نشد.', 404);
            return;
        }
        $db->update('admins', ['is_active' => (int) $admin['is_active'] ? 0 : 1, 'updated_at' => now()], 'id = :id', ['id' => $id]);
        AuditService::log('admin.updated', 'admin', $id, ['is_active' => !(int) $admin['is_active']], current_admin_id());
        $this->ok('وضعیت مدیر تغییر کرد.', ['reload' => true]);
    }
}
