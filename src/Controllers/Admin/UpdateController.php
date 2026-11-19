<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\AdminAuth;
use RB\Services\SettingsService;
use RB\Services\UpdateService;
use RB\Support\Request;

/**
 * کنترلر پنل «به‌روزرسانی سامانه» — دسترسی انحصاری مدیر کل (superadmin)
 */
final class UpdateController extends AdminController
{
    private function service(): UpdateService
    {
        return new UpdateService();
    }

    public function index(): void
    {
        if (!AdminAuth::requireSuperAdmin()) {
            $this->flash('error', 'بخش به‌روزرسانی سامانه فقط برای «مدیر کل» مجاز است.');
            Request::redirect(url('admin'));
            return;
        }

        $service = $this->service();
        $current = $service->currentVersion();
        $latest = UpdateService::cachedLatest();
        if ($latest !== null && !empty($latest['version'])) {
            $latest['has_update'] = UpdateService::isNewer((string) $latest['version'], $current);
        }

        $this->render('admin/update/index', [
            'title' => 'به‌روزرسانی سامانه',
            'currentVersion' => $current,
            'latest' => $latest,
            'hasUpdate' => (bool) ($latest['has_update'] ?? false),
            'repo' => UpdateService::repo(),
            'channel' => UpdateService::channel(),
            'autoCheck' => UpdateService::autoCheckEnabled(),
            'maskedToken' => UpdateService::maskedGithubToken(),
            'lastCheckAt' => (string) (SettingsService::get('update_last_check_at', '') ?? ''),
            'lastError' => (string) (SettingsService::get('update_last_error', '') ?? ''),
            'lastAppliedAt' => (string) (SettingsService::get('update_last_applied_at', '') ?? ''),
            'lastAppliedFrom' => (string) (SettingsService::get('update_last_applied_from', '') ?? ''),
            'lastAppliedTo' => (string) (SettingsService::get('update_last_applied_to', '') ?? ''),
            'checks' => $service->environmentChecks(),
            'backups' => $service->listFileBackups(),
            'history' => $service->history(),
            'maxUploadMb' => (int) round(UpdateService::MAX_PACKAGE_BYTES / 1048576),
        ]);
    }

    /** بررسی آخرین نسخه در گیت‌هاب */
    public function check(): void
    {
        if (!AdminAuth::requireSuperAdmin()) {
            $this->fail('دسترسی کافی ندارید. این عملیات فقط برای مدیر کل مجاز است.', 403);
            return;
        }

        $result = $this->service()->check(current_admin_id());
        if (!$result['ok']) {
            $this->fail((string) ($result['error'] ?? 'بررسی نسخه ناموفق بود.'), 422);
            return;
        }

        $latestVer = (string) ($result['latest']['version'] ?? '');
        $msg = !empty($result['has_update'])
            ? 'نسخهٔ جدید ' . $latestVer . ' در دسترس است.'
            : 'سامانه به‌روز است (نسخهٔ ' . ($result['current'] ?? '') . ').';

        $this->ok($msg, [
            'current' => $result['current'] ?? '',
            'latest' => $result['latest'] ?? null,
            'has_update' => (bool) ($result['has_update'] ?? false),
            'reload' => true,
        ]);
    }

    /** دانلود و نصب خودکار از GitHub Releases */
    public function installGithub(): void
    {
        if (!AdminAuth::requireSuperAdmin()) {
            $this->fail('دسترسی کافی ندارید. این عملیات فقط برای مدیر کل مجاز است.', 403);
            return;
        }

        @set_time_limit(300);
        $tag = Request::string('tag', '');
        $result = $this->service()->installFromGithub($tag !== '' ? $tag : null, current_admin_id());

        if (!$result['ok']) {
            $this->fail((string) ($result['error'] ?? 'نصب نسخهٔ جدید ناموفق بود.'), 422, [
                'backup' => $result['backup'] ?? null,
                'restored' => $result['restored'] ?? false,
                'warnings' => $result['warnings'] ?? [],
            ]);
            return;
        }

        $migCount = count((array) ($result['migrations_applied'] ?? []));
        $msg = 'سامانه با موفقیت از نسخهٔ ' . ($result['from'] ?? '') . ' به ' . ($result['to'] ?? '') . ' به‌روزرسانی شد'
            . ($migCount > 0 ? ' (' . fa_digits($migCount) . ' تغییر پایگاه داده اعمال شد).' : '.');

        $this->ok($msg, [
            'from' => $result['from'] ?? '',
            'to' => $result['to'] ?? '',
            'files_copied' => $result['files_copied'] ?? 0,
            'migrations_applied' => $result['migrations_applied'] ?? [],
            'backup' => $result['backup'] ?? null,
            'reload' => true,
        ]);
    }

    /** آپلود و نصب بستهٔ ZIP */
    public function upload(): void
    {
        if (!AdminAuth::requireSuperAdmin()) {
            $this->fail('دسترسی کافی ندارید. این عملیات فقط برای مدیر کل مجاز است.', 403);
            return;
        }

        if (!isset($_FILES['package']) || !is_array($_FILES['package'])) {
            $this->fail('فایل بستهٔ ZIP ارسال نشده است. لطفاً فایل .zip را انتخاب کنید.', 422);
            return;
        }

        @set_time_limit(300);
        $result = $this->service()->installFromUpload($_FILES['package'], current_admin_id());

        if (!$result['ok']) {
            $this->fail((string) ($result['error'] ?? 'اعمال بستهٔ ZIP ناموفق بود.'), 422, [
                'backup' => $result['backup'] ?? null,
                'restored' => $result['restored'] ?? false,
                'warnings' => $result['warnings'] ?? [],
            ]);
            return;
        }

        $migCount = count((array) ($result['migrations_applied'] ?? []));
        $msg = 'بستهٔ نسخهٔ ' . ($result['to'] ?? '') . ' با موفقیت نصب شد'
            . ($migCount > 0 ? ' (' . fa_digits($migCount) . ' تغییر پایگاه داده اعمال شد).' : '.');

        $this->ok($msg, [
            'from' => $result['from'] ?? '',
            'to' => $result['to'] ?? '',
            'files_copied' => $result['files_copied'] ?? 0,
            'migrations_applied' => $result['migrations_applied'] ?? [],
            'backup' => $result['backup'] ?? null,
            'reload' => true,
        ]);
    }

    /** بازگردانی فایل‌ها از نسخهٔ پشتیبان پیش از به‌روزرسانی */
    public function restore(): void
    {
        if (!AdminAuth::requireSuperAdmin()) {
            $this->fail('دسترسی کافی ندارید. این عملیات فقط برای مدیر کل مجاز است.', 403);
            return;
        }

        $backupName = Request::string('backup', '');
        if ($backupName === '') {
            $this->fail('نام فایل پشتیبان مشخص نشده است.', 422);
            return;
        }

        @set_time_limit(180);
        $result = $this->service()->restoreFiles($backupName, null, [], current_admin_id());
        if (!$result['ok']) {
            $this->fail((string) ($result['error'] ?? 'بازگردانی فایل‌ها ناموفق بود.'), 422);
            return;
        }

        $this->ok(
            'فایل‌های سامانه با موفقیت بازگردانی شدند (نسخهٔ فعال: ' . ($result['version'] ?? '') . ').',
            [
                'version' => $result['version'] ?? '',
                'restored_files' => $result['restored_files'] ?? 0,
                'reload' => true,
            ]
        );
    }

    /** ذخیرهٔ تنظیمات به‌روزرسانی (مخزن، کانال، بررسی خودکار روزانه، توکن اختیاری) */
    public function saveSettings(): void
    {
        if (!AdminAuth::requireSuperAdmin()) {
            $this->fail('دسترسی کافی ندارید. این عملیات فقط برای مدیر کل مجاز است.', 403);
            return;
        }

        $data = Request::input();
        $result = UpdateService::saveSettings([
            'repo' => Request::string('repo', UpdateService::DEFAULT_REPO, $data),
            'channel' => Request::string('channel', 'stable', $data),
            'auto_check' => Request::bool('auto_check', false, $data),
            'github_token' => Request::string('github_token', '', $data),
            'clear_token' => Request::bool('clear_token', false, $data),
        ], current_admin_id());

        if (!$result['ok']) {
            $this->fail((string) ($result['error'] ?? 'ذخیره تنظیمات ناموفق بود.'), 422);
            return;
        }

        $this->ok('تنظیمات به‌روزرسانی ذخیره شد.', ['reload' => true]);
    }
}
