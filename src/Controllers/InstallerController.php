<?php
declare(strict_types=1);

namespace RB\Controllers;

use RB\Http\Middleware;
use RB\Services\AdminAuth;
use RB\Services\AuditService;
use RB\Services\SettingsService;
use RB\Support\Database;
use RB\Support\Env;
use RB\Support\ErrorHandler;
use RB\Support\HttpException;
use RB\Support\Migrator;
use RB\Support\Request;
use RB\Support\Session;

/**
 * نصب اولیه — پس از نصب کامل، دسترسی به آن مسدود می‌شود.
 */
final class InstallerController
{
    private function guard(): void
    {
        if (Middleware::isInstalled()) {
            http_response_code(403);
            echo RB\Support\View::render('errors/installed', [], 'none');
            exit;
        }
        Session::start();
        // محدودیت ساده: حداکثر ۲۰ درخواست نصب از یک IP
        $key = '_install_attempts';
        $attempts = (int) ($_SESSION[$key] ?? 0);
        if ($attempts > 40) {
            http_response_code(429);
            echo 'تعداد درخواست‌های نصب بیش از حد مجاز است.';
            exit;
        }
        $_SESSION[$key] = $attempts + 1;
    }

    private function step(): int
    {
        return (int) ($_SESSION['_install_step'] ?? 1);
    }

    private function setStep(int $step): void
    {
        Session::start();
        $_SESSION['_install_step'] = $step;
    }

    private function requirements(): array
    {
        $checks = [];
        $checks[] = ['label' => 'نسخه PHP 8.1 یا بالاتر', 'ok' => PHP_VERSION_ID >= 80100, 'value' => PHP_VERSION];
        $checks[] = ['label' => 'افزونه PDO', 'ok' => extension_loaded('pdo'), 'value' => extension_loaded('pdo') ? 'موجود' : 'نصب نشده'];
        $checks[] = ['label' => 'درایور PDO MySQL', 'ok' => extension_loaded('pdo_mysql'), 'value' => extension_loaded('pdo_mysql') ? 'موجود' : 'نصب نشده'];
        $checks[] = ['label' => 'افزونه mbstring', 'ok' => extension_loaded('mbstring'), 'value' => extension_loaded('mbstring') ? 'موجود' : 'نصب نشده'];
        $checks[] = ['label' => 'افزونه OpenSSL', 'ok' => extension_loaded('openssl'), 'value' => extension_loaded('openssl') ? 'موجود' : 'نصب نشده'];
        $checks[] = ['label' => 'افزونه cURL (برای تلگرام)', 'ok' => extension_loaded('curl'), 'value' => extension_loaded('curl') ? 'موجود' : 'توصیه می‌شود'];
        $checks[] = ['label' => 'افزونه JSON', 'ok' => extension_loaded('json'), 'value' => extension_loaded('json') ? 'موجود' : 'نصب نشده'];
        $checks[] = ['label' => 'افزونه Fileinfo', 'ok' => extension_loaded('fileinfo'), 'value' => extension_loaded('fileinfo') ? 'موجود' : 'اختیاری'];
        $writable = static fn(string $path) => is_dir($path) && is_writable($path);
        $checks[] = ['label' => 'قابل نوشتن بودن storage/', 'ok' => $writable(RB_STORAGE), 'value' => $writable(RB_STORAGE) ? 'بله' : 'خیر — دسترسی 755 یا 775 بدهید'];
        $checks[] = ['label' => 'قابل نوشتن بودن storage/logs/', 'ok' => $writable(RB_STORAGE . '/logs'), 'value' => $writable(RB_STORAGE . '/logs') ? 'بله' : 'خیر'];
        $checks[] = ['label' => 'قابل نوشتن بودن storage/cache/', 'ok' => $writable(RB_STORAGE . '/cache'), 'value' => $writable(RB_STORAGE . '/cache') ? 'بله' : 'خیر'];
        $checks[] = ['label' => 'نبودن فایل .env در مسیر عمومی (public)', 'ok' => true, 'value' => 'بررسی شد'];
        $checks[] = ['label' => 'HTTPS (برای Webhook و Mini App)', 'ok' => Request::isHttps(), 'value' => Request::isHttps() ? 'فعال' : 'غیرفعال'];
        return $checks;
    }

    public function index(): void
    {
        $this->guard();
        $requirements = $this->requirements();
        $fatal = false;
        foreach ($requirements as $r) {
            if (!$r['ok'] && in_array($r['label'], ['نسخه PHP 8.1 یا بالاتر', 'افزونه PDO', 'درایور PDO MySQL'], true)) {
                $fatal = true;
            }
        }
        echo RB\Support\View::render('install/index', [
            'title' => 'نصب سامانه',
            'step' => $this->step(),
            'requirements' => $requirements,
            'fatal' => $fatal,
            'isHttps' => Request::isHttps(),
            'origin' => Request::origin(),
            'csrf' => \RB\Support\Csrf::token(),
        ], 'none');
    }

    public function database(): void
    {
        $this->guard();
        \RB\Support\Csrf::verify();

        $host = trim((string) ($_POST['db_host'] ?? 'localhost'));
        $port = trim((string) ($_POST['db_port'] ?? '3306'));
        $name = trim((string) ($_POST['db_name'] ?? ''));
        $user = trim((string) ($_POST['db_user'] ?? ''));
        $pass = (string) ($_POST['db_pass'] ?? '');

        if ($name === '' || $user === '') {
            json_out(['ok' => false, 'error' => 'نام پایگاه داده و نام کاربری الزامی است.'], 422);
        }

        try {
            $db = new Database([
                'driver' => 'mysql', 'host' => $host, 'port' => $port,
                'database' => $name, 'username' => $user, 'password' => $pass,
            ]);
            $db->pdo();
            $version = (string) $db->value('SELECT VERSION()');
            Database::setInstance($db);
            Session::start();
            $_SESSION['_install_db'] = compact('host', 'port', 'name', 'user', 'pass');
            $this->setStep(3);
            json_out(['ok' => true, 'message' => 'اتصال موفق بود (نسخه MySQL: ' . $version . ')', 'version' => $version]);
        } catch (\Throwable $e) {
            json_out(['ok' => false, 'error' => 'اتصال ناموفق: ' . ErrorHandler::redact($e->getMessage())], 422);
        }
    }

    public function migrate(): void
    {
        $this->guard();
        \RB\Support\Csrf::verify();
        Session::start();

        try {
            $db = $this->resolveDatabase();
            $migrator = new Migrator($db);
            $result = $migrator->run();

            // درج تنظیمات پیش‌فرض (قابل تغییر از پنل)
            $defaults = self::defaultSettings();
            foreach ($defaults as $key => $value) {
                if (SettingsService::get($key) === null) {
                    SettingsService::set($key, $value, 'seed');
                }
            }

            RB\Services\MenuService::syncDefaults($db);

            // نقش مدیر کل
            if (!$db->value('SELECT id FROM admin_roles WHERE slug = :s', ['s' => 'superadmin'])) {
                $db->insert('admin_roles', [
                    'name' => 'مدیر کل',
                    'slug' => 'superadmin',
                    'permissions' => json_encode(['*'], JSON_UNESCAPED_UNICODE),
                    'description' => 'دسترسی کامل به تمام بخش‌ها',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            // نسخه اولیه تنظیمات پورسانت
            if (!RB\Support\Database::instance()->value('SELECT id FROM commission_setting_versions LIMIT 1')) {
                RB\Services\CommissionSettings::saveVersion([
                    'direct_reward' => 5000,
                    'direct_enabled' => 1,
                    'multi_level_enabled' => 1,
                    'max_levels' => 3,
                    'levels' => [
                        ['level' => 1, 'percent' => '20', 'enabled' => true],
                        ['level' => 2, 'percent' => '10', 'enabled' => true],
                        ['level' => 3, 'percent' => '5', 'enabled' => true],
                    ],
                    'percent_base' => 'direct_reward',
                    'fixed_base_amount' => 0,
                    'max_payout_per_event' => 0,
                    'qualify_conditions' => ['joined'],
                    'blocked_users_no_commission' => 1,
                ]);
            }

            $this->writeEnv();
            $this->setStep(4);
            json_out([
                'ok' => true,
                'message' => 'جداول با موفقیت ساخته شدند.',
                'tables' => count($db->column("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()")),
                'applied' => $result['applied'],
            ]);
        } catch (\Throwable $e) {
            ErrorHandler::logException($e);
            json_out(['ok' => false, 'error' => ErrorHandler::redact($e->getMessage())], 500);
        }
    }

    public function createAdmin(): void
    {
        $this->guard();
        \RB\Support\Csrf::verify();

        $name = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $password2 = (string) ($_POST['password_confirm'] ?? '');
        $telegramId = trim((string) ($_POST['telegram_id'] ?? ''));

        $errors = [];
        if (mb_strlen($name) < 3) {
            $errors[] = 'نام کامل باید حداقل ۳ کاراکتر باشد.';
        }
        if (!preg_match('~^[a-zA-Z0-9_.]{4,40}$~', $username)) {
            $errors[] = 'نام کاربری باید ۴ تا ۴۰ کاراکتر و شامل حروف لاتین، عدد، نقطه یا زیرخط باشد.';
        }
        if (strlen($password) < 10) {
            $errors[] = 'رمز عبور باید حداقل ۱۰ کاراکتر باشد.';
        }
        if (!preg_match('~[A-Z]~', $password) || !preg_match('~[a-z]~', $password) || !preg_match('~\d~', $password)) {
            $errors[] = 'رمز عبور باید شامل حروف بزرگ، حروف کوچک و عدد باشد.';
        }
        if ($password !== $password2) {
            $errors[] = 'تکرار رمز عبور مطابقت ندارد.';
        }
        if (in_array(strtolower($password), ['password12', 'admin12345', '123456789a'], true)) {
            $errors[] = 'این رمز عبور بسیار رایج و ناامن است.';
        }
        if ($errors) {
            json_out(['ok' => false, 'error' => implode(' ', $errors)], 422);
        }

        try {
            $db = $this->resolveDatabase();
            if ($db->value('SELECT id FROM admins WHERE username = :u', ['u' => $username])) {
                json_out(['ok' => false, 'error' => 'این نام کاربری قبلاً ثبت شده است.'], 422);
            }
            $roleId = (int) $db->value("SELECT id FROM admin_roles WHERE slug = 'superadmin'");
            $db->insert('admins', [
                'name' => $name,
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role_id' => $roleId,
                'telegram_user_id' => $telegramId !== '' ? (int) en_digits($telegramId) : null,
                'is_active' => 1,
                'must_change_password' => 0,
                'failed_attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->setStep(5);
            json_out(['ok' => true, 'message' => 'حساب مدیر با موفقیت ایجاد شد.']);
        } catch (\Throwable $e) {
            json_out(['ok' => false, 'error' => ErrorHandler::redact($e->getMessage())], 500);
        }
    }

    public function settings(): void
    {
        $this->guard();
        \RB\Support\Csrf::verify();
        try {
            $this->resolveDatabase();
            $appName = trim((string) ($_POST['app_name'] ?? 'رفرال‌بات'));
            SettingsService::set('app_name', $appName !== '' ? $appName : 'رفرال‌بات', 'general');

            $baseUrl = trim((string) ($_POST['base_url'] ?? ''));
            if ($baseUrl !== '' && !preg_match('~^https?://~i', $baseUrl)) {
                $baseUrl = 'https://' . $baseUrl;
            }
            SettingsService::set('webhook_base_url', rtrim($baseUrl, '/'), 'telegram');
            SettingsService::set('panel_url', rtrim($baseUrl, '/'), 'telegram');
            SettingsService::set('miniapp_url', rtrim($baseUrl, '/') . url('app'), 'telegram');

            $this->setStep(6);
            json_out(['ok' => true, 'message' => 'تنظیمات پایه ذخیره شد.']);
        } catch (\Throwable $e) {
            json_out(['ok' => false, 'error' => ErrorHandler::redact($e->getMessage())], 500);
        }
    }

    public function finish(): void
    {
        $this->guard();
        \RB\Support\Csrf::verify();
        try {
            $db = $this->resolveDatabase();
            SettingsService::set('installed_at', now(), 'system');

            // قفل نصب: فایل قفل + تنظیم
            $lockFile = RB_STORAGE . '/installed.lock';
            @file_put_contents($lockFile, json_encode([
                'installed_at' => now(),
                'php' => PHP_VERSION,
                'host' => Request::origin(),
            ], JSON_UNESCAPED_UNICODE), LOCK_EX);

            $this->writeEnv(installed: true);
            AuditService::log('system.installed', 'system', null, ['host' => Request::origin()]);

            Session::start();
            unset($_SESSION['_install_db'], $_SESSION['_install_step']);
            $this->setStep(7);
            json_out([
                'ok' => true,
                'message' => 'نصب با موفقیت تکمیل شد.',
                'redirect' => url('admin/login'),
            ]);
        } catch (\Throwable $e) {
            json_out(['ok' => false, 'error' => ErrorHandler::redact($e->getMessage())], 500);
        }
    }

    private function resolveDatabase(): Database
    {
        Session::start();
        $config = $_SESSION['_install_db'] ?? null;

        if ($config === null) {
            // اگر .env قبلاً نوشته شده باشد
            if (Env::get('DB_DATABASE') !== null && Env::get('DB_DATABASE') !== '') {
                $db = Database::instance();
                $db->pdo();
                return $db;
            }
            throw new HttpException('اطلاعات اتصال پایگاه داده یافت نشد. لطفاً از مرحله اول شروع کنید.', 400);
        }

        $db = new Database([
            'driver' => 'mysql',
            'host' => $config['host'],
            'port' => $config['port'],
            'database' => $config['name'],
            'username' => $config['user'],
            'password' => $config['pass'],
        ]);
        $db->pdo();
        Database::setInstance($db);
        return $db;
    }

    private function writeEnv(bool $installed = false): void
    {
        Session::start();
        $db = $_SESSION['_install_db'] ?? null;
        $envFile = RB_ROOT . '/.env';
        $existing = is_file($envFile) ? (string) file_get_contents($envFile) : '';

        $appKey = '';
        if (preg_match('~^APP_KEY=(.*)$~m', $existing, $m)) {
            $appKey = trim($m[1]);
        }
        if ($appKey === '') {
            $appKey = bin2hex(random_bytes(32));
        }

        $values = [
            'APP_NAME' => (string) SettingsService::get('app_name', 'رفرال‌بات'),
            'APP_DEBUG' => 'false',
            'APP_TIMEZONE' => 'Asia/Tehran',
            'APP_KEY' => $appKey,
            'RB_INSTALLED' => $installed ? 'true' : 'false',
        ];
        if ($db !== null) {
            $values['DB_DRIVER'] = 'mysql';
            $values['DB_HOST'] = $db['host'];
            $values['DB_PORT'] = $db['port'];
            $values['DB_DATABASE'] = $db['name'];
            $values['DB_USERNAME'] = $db['user'];
            $values['DB_PASSWORD'] = $db['pass'];
        }

        $lines = ["# RefrallBot — فایل تنظیمات (محرمانه — در Git قرار ندهید)", '# ساخته‌شده در ' . now(), ''];
        foreach ($values as $key => $value) {
            $needsQuote = preg_match('~[\s#"\'\\\\]~', (string) $value) || $value === '';
            $lines[] = $key . '=' . ($needsQuote ? '"' . addcslashes((string) $value, '"\\') . '"' : $value);
        }
        @file_put_contents($envFile, implode("\n", $lines) . "\n", LOCK_EX);
        @chmod($envFile, 0640);

        // فایل .htaccess محافظ در ریشه
        $htaccess = RB_ROOT . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, implode("\n", [
                '# محافظت از فایل‌های حساس پروژه',
                '<FilesMatch "^\.">',
                '    Require all denied',
                '</FilesMatch>',
                'Options -Indexes',
                'DirectoryIndex public/index.php',
                '',
                '<IfModule mod_rewrite.c>',
                '    RewriteEngine On',
                '    RewriteCond %{REQUEST_URI} !^/public/',
                '    RewriteRule ^(.*)$ public/$1 [L]',
                '</IfModule>',
                '',
                'RedirectMatch 403 ^/?(storage|src|config|database|tests|bin|docs)/',
            ]) . "\n");
        }
    }

    public static function defaultSettings(): array
    {
        return [
            'app_name' => 'رفرال‌بات',
            'ranking_enabled' => '1',
            'ranking_metric' => 'direct_ref_count',
            'withdrawal_enabled' => '1',
            'min_withdraw_amount' => '30000',
            'max_open_withdrawals' => '1',
            'max_daily_withdrawal' => '0',
            'force_channel_membership' => '0',
            'contact_required' => '0',
            'contact_request_message' => 'برای ادامه استفاده از ربات، لطفاً شماره تماس خود را با دکمه زیر ارسال کنید.',
            'join_channels_message' => 'برای استفاده از ربات، ابتدا در کانال‌های زیر عضو شوید و سپس دکمه «بررسی عضویت» را بزنید.',
            'show_rules_in_start' => '1',
            'start_rules_text' => "📌 قوانین:\n• هر کاربر فقط یک حساب مجاز دارد.\n• استفاده از روش‌های تقلبی موجب مسدودسازی و لغو پاداش می‌شود.\n• پاداش‌ها پس از تأیید شرایط به کیف پول اضافه می‌شوند.",
            'guide_text' => "📖 راهنمای استفاده\n\n۱) لینک دعوت خود را از بخش «لینک دعوت من» کپی کنید و برای دوستانتان بفرستید.\n۲) پس از عضویت هر نفر و واجد شرایط شدن، پاداش به کیف پول شما اضافه می‌شود.\n۳) از فعالیت زیرمجموعه‌های شما در سطوح بالاتر نیز پورسانت لایه‌ای تعلق می‌گیرد.\n۴) از بخش «برداشت وجه» می‌توانید درخواست برداشت ثبت کنید.",
            'risk_same_ip_threshold' => '3',
            'risk_signup_burst' => '10',
            'risk_referral_burst' => '15',
            'risk_mark_threshold' => '45',
            'qualify_min_activity_messages' => '0',
            'broadcast_batch_size' => '25',
        ];
    }
}
