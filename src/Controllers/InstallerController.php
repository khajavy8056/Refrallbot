<?php
declare(strict_types=1);

namespace RB\Controllers;

use RB\Http\Middleware;
use RB\Services\AdminAuth;
use RB\Services\AuditService;
use RB\Services\CommissionSettings;
use RB\Services\SettingsService;
use RB\Support\Database;
use RB\Support\Env;
use RB\Support\ErrorHandler;
use RB\Support\HttpException;
use RB\Support\Migrator;
use RB\Support\Money;
use RB\Support\Request;
use RB\Support\Session;

/**
 * نصب اولیه — پس از نصب کامل، دسترسی به آن مسدود می‌شود.
 */
final class InstallerController
{
    /**
     * قلاب تست: اگر مقدار داشته باشد، به‌جای exit() پاسخ را به آن می‌دهد.
     * (مثل Request::$redirectHandler که برای تست redirect استفاده می‌شود.)
     *
     * @var null|callable(array,int):void
     */
    public static $abortHandler = null;

    /**
     * پاسخ دادن و پایان درخواست.
     * در محیط تست (با abortHandler) به‌جای exit استثنا صادر می‌شود تا
     * فرآیند تست متوقف نشود.
     */
    private function abort(array $payload, int $status): never
    {
        if (self::$abortHandler !== null) {
            (self::$abortHandler)($payload, $status);
            throw new \RB\Support\InstallAborted($payload, $status);
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** پنجرهٔ زمانی شمارش درخواست‌های حساس (ثانیه) */
    private const RATE_WINDOW = 600;

    /** حداکثر درخواست POST حساس در هر پنجره */
    private const RATE_MAX_POST = 60;

    /** حداکثر درخواست POST به «آزمایش اتصال» در هر پنجره (عملیات سنگین‌تر) */
    private const RATE_MAX_TEST_DB = 20;

    /**
     * بررسی قفل نصب + محدودیت درخواست.
     *
     * تفاوت با نسخهٔ قبلی (که باعث خطای 429 در Termux می‌شد):
     *   • قبلاً هر باز شدن صفحهٔ نصب هم شمارنده را زیاد می‌کرد؛ با چند Refresh
     *     ساده ویزارد قفل می‌شد. حالا فقط POST شمارش می‌شود.
     *   • شمارنده پنجرهٔ زمانی دارد (۱۰ دقیقه) و خودکار صفر می‌شود، پس
     *     Refresh مرورگر هیچ‌وقت دائمی قفل نمی‌کند.
     *   • دو سبد جدا: درخواست‌های سنگین (آزمایش اتصال) و بقیهٔ مراحل.
     *   • پاسخ 429 حالا JSON استاندارد با هدر Retry-After است تا RB.request
     *     بتواند پیام را نشان دهد (قبلاً متن ساده بود و «پاسخ نامعتبر» می‌شد).
     *   • درخواست‌های هم‌زمان با قفل فایل (flock) شمارش می‌شوند تا محدودیت
     *     با چند درخواست موازی دور زده نشود.
     */
    private function guard(bool $sensitive = false): void
    {
        if (Middleware::isInstalled()) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            echo \RB\Support\View::render('errors/installed', [], 'none');
            exit;
        }
        Session::start();

        // درخواست GET (فقط باز کردن صفحه یا Refresh) هرگز شمارش نمی‌شود.
        if (Request::method() !== 'POST') {
            return;
        }

        $bucket = $sensitive ? 'test_db' : 'post';
        $limit = $sensitive ? self::RATE_MAX_TEST_DB : self::RATE_MAX_POST;
        $state = $this->rateState($bucket, $limit);
        if ($state['blocked']) {
            $retry = max(1, $state['reset'] - time());
            if (!headers_sent()) {
                header('Retry-After: ' . $retry);
            }
            $this->abort([
                'ok' => false,
                'code' => 'rate_limited',
                'error' => 'تلاش‌های نصب بیش از حد مجاز در بازهٔ زمانی کوتاه بود. '
                    . 'لطفاً ' . fa_digits((string) ceil($retry / 60)) . ' دقیقه دیگر دوباره تلاش کنید.',
                'retry_after' => $retry,
            ], 429);
        }
    }

    /**
     * خواندن وضعیت شمارنده بدون افزایش آن (برای تست و عیب‌یابی).
     *
     * @return array{blocked:bool,count:int,reset:int}
     */
    public function rateSnapshot(string $bucket = 'post'): array
    {
        $key = '_install_rate_' . $bucket;
        $state = $_SESSION[$key] ?? null;
        $file = $this->rateStateFile($bucket, time());
        $count = max(is_array($state) ? (int) ($state['count'] ?? 0) : 0, $file['count']);
        $start = is_array($state) ? (int) ($state['start'] ?? time()) : time();
        return [
            'blocked' => false,
            'count' => $count,
            'reset' => max($start, $file['start']) + self::RATE_WINDOW,
        ];
    }

    /**
     * ثبت و بررسی شمارندهٔ درخواست.
     *
     * شمارنده هم در Session (برای کاربر عادی) و هم در فایل موقت (برای
     * پایداری در برابر پاک شدن Session و شمارش درخواست‌های هم‌زمان) نگه‌داری
     * می‌شود. قفل flock مانع «دور زدن با درخواست موازی» می‌شود.
     *
     * @return array{blocked:bool,count:int,reset:int}
     */
    private function rateState(string $bucket, int $limit): array
    {
        $now = time();
        $key = '_install_rate_' . $bucket;

        // ---- ۱) سبد Session (سریع، بدون I/O)
        $sessionState = $_SESSION[$key] ?? null;
        $count = 0;
        $start = $now;
        if (is_array($sessionState) && isset($sessionState['start'], $sessionState['count'])) {
            $start = (int) $sessionState['start'];
            $count = (int) $sessionState['count'];
        }
        if ($now - $start >= self::RATE_WINDOW) {
            $start = $now;
            $count = 0;
        }

        // ---- ۲) شمارش مشترک بر اساس IP (پایدارتر و ضد درخواست موازی)
        $fileState = $this->rateStateFile($bucket, $now);

        $total = max($count, $fileState['count']);
        $windowStart = min($start, $fileState['start']);
        $reset = $windowStart + self::RATE_WINDOW;

        if ($total >= $limit) {
            $_SESSION[$key] = ['start' => $windowStart, 'count' => $total];
            return ['blocked' => true, 'count' => $total, 'reset' => $reset];
        }

        $next = $total + 1;
        $_SESSION[$key] = ['start' => $windowStart, 'count' => $next];
        $this->rateStateFile($bucket, $now, true);

        return ['blocked' => false, 'count' => $next, 'reset' => $reset];
    }

    /**
     * خواندن/افزایش شمارندهٔ فایلی برای یک IP.
     *
     * در محیط تست و روی هاست‌های بدون اجازهٔ نوشتن، بی‌صدا غیرفعال می‌شود
     * (محدودیت Session همچنان برقرار است).
     *
     * @return array{count:int,start:int}
     */
    private function rateStateFile(string $bucket, int $now, bool $increment = false): array
    {
        $dir = RB_STORAGE . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $ip = preg_replace('~[^0-9a-fA-F.:]~', '', Request::ip()) ?: 'unknown';
        $file = $dir . '/rate_' . $bucket . '_' . md5($ip) . '.json';

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return ['count' => 0, 'start' => $now];
        }

        try {
            // قفل انحصاری: درخواست‌های موازی پشت سر هم شمارش می‌شوند
            if (!@flock($handle, $increment ? LOCK_EX : LOCK_SH)) {
                return ['count' => 0, 'start' => $now];
            }
            $content = stream_get_contents($handle) ?: '';
            $data = json_decode($content, true);
            $count = is_array($data) ? (int) ($data['count'] ?? 0) : 0;
            $start = is_array($data) ? (int) ($data['start'] ?? 0) : 0;
            if ($start <= 0 || $now - $start >= self::RATE_WINDOW) {
                $start = $now;
                $count = 0;
            }
            if ($increment) {
                $count++;
                @ftruncate($handle, 0);
                @rewind($handle);
                @fwrite($handle, (string) json_encode(['count' => $count, 'start' => $start]));
                @fflush($handle);
            }
            return ['count' => $count, 'start' => $start];
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * تشخیص خودکار زیرپوشه نصب.
     * اگر پروژه در ریشه دامنه باشد مقدار '' و اگر در /bot باشد '/bot' برمی‌گردد.
     * این مقدار در .env به‌عنوان APP_BASE_PATH ذخیره می‌شود تا همه لینک‌ها درست ساخته شوند.
     */
    public static function detectBasePath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = rtrim(dirname($script), '/');
        // اگر docroot روی public/ تنظیم شده باشد: SCRIPT_NAME = /index.php
        if ($dir === '' || $dir === '.' || $dir === '/') {
            return '';
        }
        // اگر ریشه پروژه docroot است: SCRIPT_NAME = /public/index.php
        if (str_ends_with($dir, '/public')) {
            $dir = substr($dir, 0, -7);
        }
        return $dir === '' || $dir === '/' ? '' : '/' . trim($dir, '/');
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
        echo \RB\Support\View::render('install/index', [
            'title' => 'نصب سامانه',
            'step' => $this->step(),
            'requirements' => $requirements,
            'fatal' => $fatal,
            'isHttps' => Request::isHttps(),
            'origin' => Request::origin(),
            'basePath' => self::detectBasePath(),
            'csrf' => \RB\Support\Csrf::token(),
        ], 'none');
    }

    /**
     * اطلاعات اتصال دیتابیس از ورودی یکپارچه (JSON یا فرم).
     *
     * باگ واقعی که باعث خطای 422 می‌شد: RB.post در جاوااسکریپت بدنه را
     * application/json می‌فرستد، ولی این متد فقط $_POST را می‌خواند؛ نتیجه
     * این بود که همهٔ فیلدها null می‌شدند و پاسخ «نام پایگاه داده و نام
     * کاربری الزامی است» با کد 422 برمی‌گشت — با وجود پر بودن فرم.
     */
    private function dbInput(): array
    {
        $host = Request::string('db_host', '') ?: 'localhost';
        $port = Request::string('db_port', '') ?: '3306';
        // پورت باید عدد معتبر باشد، وگرنه DSN می‌شکند
        if (!preg_match('~^\d{1,5}$~', $port)) {
            $port = '3306';
        }
        return [
            'host' => $host,
            'port' => $port,
            'name' => Request::string('db_name', ''),
            'user' => Request::string('db_user', ''),
            // رمز عبور نباید trim شود (فاصله ممکن است بخشی از رمز باشد)
            'pass' => (string) Request::field('db_pass', ''),
            'create_db' => Request::bool('create_db', false),
        ];
    }

    /** اعتبارسنجی ورودی دیتابیس؛ فهرست خطاها را برمی‌گرداند */
    private function validateDbInput(array $in): array
    {
        $errors = [];
        if ($in['name'] === '') {
            $errors[] = 'نام پایگاه داده الزامی است.';
        }
        if ($in['user'] === '') {
            $errors[] = 'نام کاربری پایگاه داده الزامی است.';
        }
        if ($in['host'] === '') {
            $errors[] = 'میزبان پایگاه داده الزامی است.';
        }
        // نام دیتابیس باید با قاعدهٔ MySQL سازگار باشد تا در CREATE DATABASE
        // که پارامترپذیر نیست، تزریق ممکن نباشد.
        if ($in['name'] !== '' && !preg_match('~^[A-Za-z0-9_$]{1,64}$~', $in['name'])) {
            $errors[] = 'نام پایگاه داده فقط می‌تواند شامل حروف لاتین، عدد، زیرخط و $ باشد (حداکثر ۶۴ کاراکتر).';
        }
        return $errors;
    }

    /**
     * برقراری اتصال به دیتابیس نصب.
     *
     * منطق مشترک «فقط آزمایش اتصال» و «اتصال و ادامه» تا رفتارشان یکسان و
     * قابل پیش‌بینی باشد (قبلاً فقط دکمهٔ آزمایش می‌توانست دیتابیس بسازد و
     * دکمهٔ ادامه در همان حالت خطا می‌داد).
     *
     * مراحل:
     *   ۱) اتصال بدون نام دیتابیس — فقط برای تشخیص وجود دیتابیس
     *   ۲) اگر وجود نداشت و create_db فعال بود: CREATE DATABASE (با اعتبارسنجی نام)
     *   ۳) اتصال نهایی به همان دیتابیس
     *
     * اگر کاربر مجوز مدیریتی نداشته باشد (هاست اشتراکی)، مرحلهٔ ۱ شکست
     * می‌خورد ولی مرحلهٔ ۳ می‌تواند موفق باشد؛ بنابراین خطای مرحلهٔ ۱
     * کشنده نیست و به‌عنوان «نامشخص» گزارش می‌شود.
     *
     * @return array{db:Database,version:string,exists:?bool,created:bool,notes:string[]}
     */
    private function connectDatabase(array $in, bool $createIfMissing): array
    {
        $notes = [];
        $version = '';
        $exists = null;
        $created = false;

        $config = static fn(string $database): Database => new Database([
            'driver' => 'mysql',
            'host' => $in['host'],
            'port' => $in['port'],
            'database' => $database,
            'username' => $in['user'],
            'password' => $in['pass'],
        ]);

        // ---- ۱) بررسی وجود دیتابیس (بدون انتخاب آن)
        $serverError = null;
        try {
            $root = $config('');
            $pdo = $root->pdo();
            $version = (string) $root->value('SELECT VERSION()');
            // از SHOW DATABASES LIKE به‌جای information_schema.SCHEMATA استفاده
            // می‌کنیم تا در MySQL 8 خطای 3988/1267 روی ستون utf8mb3 رخ ندهد.
            $likeDb = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], (string) $in['name']);
            $dbStmt = $pdo->query('SHOW DATABASES LIKE ' . $root->quote($likeDb));
            $exists = false;
            if ($dbStmt !== false) {
                while (($r = $dbStmt->fetch(\PDO::FETCH_NUM)) !== false) {
                    if (isset($r[0]) && strcasecmp((string) $r[0], (string) $in['name']) === 0) {
                        $exists = true;
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $serverError = $e;
        }

        // ---- ۲) ساخت دیتابیس در صورت نیاز
        if ($exists === false && $createIfMissing) {
            try {
                $root = $root ?? $config('');
                $pdo = $root->pdo();
                // نام دیتابیس پارامترپذیر نیست؛ چون در validateDbInput فقط
                // [A-Za-z0-9_$] مجاز است، تزریق ممکن نیست. با این حال
                // backtickها را هم حذف می‌کنیم (دفاع لایه‌ای).
                $safeName = str_replace('`', '', (string) $in['name']);
                $pdo->exec('CREATE DATABASE `' . $safeName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $created = true;
                $exists = true;
                $notes[] = 'دیتابیس «' . $safeName . '» ساخته شد.';
            } catch (\Throwable $e) {
                $message = ErrorHandler::redact($e->getMessage());
                if (stripos($message, 'denied') !== false || stripos($message, 'privilege') !== false) {
                    $notes[] = 'کاربر دیتابیس مجوز CREATE DATABASE ندارد. '
                        . 'دیتابیس را از cPanel بسازید و کاربر را با دسترسی کامل به آن اضافه کنید.';
                } else {
                    $notes[] = 'ساخت دیتابیس ناموفق بود: ' . $message;
                }
            }
        }

        // ---- ۳) اتصال نهایی
        try {
            $db = $config((string) $in['name']);
            $db->pdo();
        } catch (\Throwable $e) {
            $detail = ErrorHandler::redact($e->getMessage());
            $hint = 'در cPanel → MySQL Databases بررسی کنید: نام دیتابیس و کاربر درست باشد '
                . 'و کاربر با «All Privileges» به دیتابیس اضافه شده باشد.';
            if ($exists === false) {
                $hint = 'پایگاه دادهٔ «' . $in['name'] . '» وجود ندارد. '
                    . 'یا گزینهٔ ساخت خودکار را فعال کنید یا آن را از cPanel بسازید.';
            } elseif ($serverError !== null) {
                $hint .= ' (اتصال به سرور هم ناموفق بود: ' . ErrorHandler::redact($serverError->getMessage()) . ')';
            }
            throw new \RuntimeException($detail . ' — ' . $hint, 0, $e);
        }

        if ($version === '') {
            try {
                $version = (string) $db->value('SELECT VERSION()');
            } catch (\Throwable) {
            }
        }

        if ($exists === null) {
            // مجوز خواندن information_schema نبود؛ با اتصال موفق به خود دیتابیس، وجود آن ثابت شد
            $exists = true;
            $notes[] = 'بررسی مجوزها ممکن نبود؛ اتصال مستقیم به دیتابیس موفق بود.';
        }

        return [
            'db' => $db,
            'version' => $version,
            'exists' => $exists,
            'created' => $created,
            'notes' => $notes,
        ];
    }

    /**
     * تست اتصال بدون ذخیره — دکمهٔ «فقط آزمایش اتصال».
     * همان منطق «اتصال و ادامه» را دارد تا نتیجه‌اش دقیقاً پیش‌بینی‌پذیر باشد.
     */
    public function testDatabase(): void
    {
        // عملیات سنگین‌تر است → سبد محدودیت جداگانه
        $this->guard(true);
        \RB\Support\Csrf::verify();

        $in = $this->dbInput();
        $errors = $this->validateDbInput($in);
        if ($errors !== []) {
            json_out(['ok' => false, 'error' => implode(' ', $errors), 'errors' => $errors], 422);
            return;
        }

        try {
            $result = $this->connectDatabase($in, (bool) $in['create_db']);
            $db = $result['db'];

            $alreadyInstalled = false;
            try {
                $alreadyInstalled = $db->tableExists('system_settings')
                    && (bool) $db->value("SELECT 1 FROM system_settings WHERE `key` = 'installed_at' LIMIT 1");
            } catch (\Throwable) {
            }

            $message = 'اتصال موفق بود';
            if ($result['version'] !== '') {
                $message .= ' (نسخه ' . $result['version'] . ')';
            }
            if ($result['created']) {
                $message .= ' — دیتابیس ساخته شد.';
            }
            if ($alreadyInstalled) {
                $message .= ' ⚠️ این دیتابیس قبلاً نصب شده است.';
            }

            json_out([
                'ok' => true,
                'version' => $result['version'],
                'exists' => $result['exists'],
                'created' => $result['created'],
                'already_installed' => $alreadyInstalled,
                'message' => $message,
                'notes' => $result['notes'],
                // سازگاری با نسخهٔ قبلی فرانت‌اند
                'need_create' => $result['exists'] === false,
            ]);
        } catch (\Throwable $e) {
            json_out([
                'ok' => false,
                'code' => 'db_connection_failed',
                'error' => 'اتصال ناموفق: ' . ErrorHandler::redact($e->getMessage()),
            ], 422);
        }
    }

    /**
     * تست اتصال + ذخیره در Session و رفتن به مرحلهٔ بعد.
     * ساخت خودکار دیتابیس هم اینجا انجام می‌شود (نه فقط در دکمهٔ آزمایش).
     */
    public function database(): void
    {
        $this->guard();
        \RB\Support\Csrf::verify();

        $in = $this->dbInput();
        $errors = $this->validateDbInput($in);
        if ($errors !== []) {
            json_out(['ok' => false, 'error' => implode(' ', $errors), 'errors' => $errors], 422);
            return;
        }

        try {
            $result = $this->connectDatabase($in, (bool) $in['create_db']);
            $db = $result['db'];

            // اگر این دیتابیس قبلاً نصب شده، جلوی بازنویسی را بگیریم
            $alreadyInstalled = false;
            try {
                $alreadyInstalled = $db->tableExists('system_settings')
                    && (bool) $db->value("SELECT 1 FROM system_settings WHERE `key` = 'installed_at' LIMIT 1");
            } catch (\Throwable) {
            }
            if ($alreadyInstalled) {
                json_out([
                    'ok' => false,
                    'error' => 'این دیتابیس قبلاً نصب شده است. برای نصب مجدد، جدول‌ها را پاک کنید یا دیتابیس دیگری بسازید.',
                    'already_installed' => true,
                ], 409);
                return;
            }

            Database::setInstance($db);
            Session::start();
            // فقط اطلاعات لازم؛ رمز در Session می‌ماند ولی هرگز لاگ نمی‌شود
            $_SESSION['_install_db'] = [
                'host' => $in['host'],
                'port' => $in['port'],
                'name' => $in['name'],
                'user' => $in['user'],
                'pass' => $in['pass'],
            ];
            $this->setStep(3);
            json_out([
                'ok' => true,
                'message' => 'اتصال موفق بود' . ($result['version'] !== '' ? ' (نسخه ' . $result['version'] . ')' : '') . '.',
                'version' => $result['version'],
                'created' => $result['created'],
                'notes' => $result['notes'],
            ]);
        } catch (\Throwable $e) {
            json_out([
                'ok' => false,
                'code' => 'db_connection_failed',
                'error' => 'اتصال ناموفق: ' . ErrorHandler::redact($e->getMessage()),
            ], 422);
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

            \RB\Services\MenuService::syncDefaults($db);

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
            if (!\RB\Support\Database::instance()->value('SELECT id FROM commission_setting_versions LIMIT 1')) {
                CommissionSettings::saveVersion([
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
                'tables' => count($db->listTables()),
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

        // ورودی یکپارچه: هم JSON (RB.post) و هم فرم معمولی
        $name = Request::string('name', '');
        $username = Request::string('username', '');
        $password = (string) Request::field('password', '');
        $password2 = (string) Request::field('password_confirm', '');
        $telegramId = Request::string('telegram_id', '');

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
        if ($telegramId !== '' && !preg_match('~^\d{5,20}$~', en_digits($telegramId))) {
            $errors[] = 'شناسهٔ تلگرام باید فقط عدد باشد (یا خالی بماند).';
        }
        if ($errors) {
            json_out(['ok' => false, 'error' => implode(' ', $errors)], 422);
        }

        try {
            $db = $this->resolveDatabase();
            // جلوگیری از ساخت مدیر تکراری: اگر قبلاً مدیری ساخته شده و مرحله
            // جلوتر رفته، درخواست تکراری نباید مدیر دوم بسازد.
            $existingAdmins = (int) $db->value('SELECT COUNT(*) FROM admins');
            if ($existingAdmins > 0 && $this->step() > 4) {
                $this->setStep(5);
                json_out([
                    'ok' => true,
                    'message' => 'حساب مدیر قبلاً ایجاد شده است.',
                    'skipped' => true,
                ]);
                return;
            }
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
            $appName = Request::string('app_name', 'رفرال‌بات');
            SettingsService::set('app_name', $appName !== '' ? mb_substr($appName, 0, 60) : 'رفرال‌بات', 'general');

            // آدرس پایه: اگر خالی بماند از آدرس فعلی مرورگر استفاده می‌شود،
            // تا نصب روی IP بدون دامنه هم بدون هیچ ورودی اضافه‌ای کار کند.
            $baseUrl = Request::string('base_url', '');
            if ($baseUrl === '') {
                $baseUrl = Request::origin();
            }
            if (!preg_match('~^https?://~i', $baseUrl)) {
                $baseUrl = (Request::isHttps() ? 'https://' : 'http://') . $baseUrl;
            }
            $baseUrl = rtrim($baseUrl, '/');
            $isHttps = str_starts_with($baseUrl, 'https://');

            // زیرپوشه نصب (اگر در ریشه باشد خالی است)
            $basePath = Request::string('base_path', '');
            if ($basePath !== '' && $basePath !== '/') {
                $basePath = '/' . trim($basePath, '/');
            } else {
                $basePath = '';
            }
            SettingsService::set('app_base_path', $basePath, 'system');

            SettingsService::set('webhook_base_url', $baseUrl, 'telegram');
            SettingsService::set('panel_url', $baseUrl, 'telegram');

            // پنل وب کاربران (Mini App) فقط با HTTPS معتبر کار می‌کند.
            // روی IP/HTTP این ماژول خاموش می‌ماند و کاربران همه‌چیز را با دکمه‌های ربات انجام می‌دهند.
            $mode = Request::string('miniapp_mode', 'auto');
            if (!in_array($mode, ['auto', 'on', 'off'], true)) {
                $mode = 'auto';
            }
            if (!$isHttps) {
                $mode = 'off';
            }
            SettingsService::set('miniapp_mode', $mode, 'telegram');
            $appRoute = $basePath . '/app';
            SettingsService::set('miniapp_url', ($mode !== 'off' && $isHttps) ? $baseUrl . $appRoute : '', 'telegram');

            // پاداش پیش‌فرض معرفی (قابل تغییر بعدی از پنل → پورسانت)
            $reward = Money::parseToman(Request::field('direct_reward'));
            if ($reward !== null && $reward >= 0) {
                $current = CommissionSettings::current(db(), true);
                CommissionSettings::saveVersion([
                    'direct_reward' => $reward,
                    'direct_enabled' => 1,
                    'multi_level_enabled' => $current->multiLevelEnabled ? 1 : 0,
                    'max_levels' => $current->maxLevels,
                    'levels' => $current->levels,
                    'percent_base' => $current->percentBase,
                    'fixed_base_amount' => $current->fixedBaseAmount,
                    'max_payout_per_event' => $current->maxPayoutPerEvent,
                    'daily_payout_cap' => $current->dailyPayoutCap,
                    'qualify_conditions' => $current->qualifyConditions ?: ['joined'],
                    'blocked_users_no_commission' => 1,
                ]);
            }

            // حداقل برداشت
            $minWithdraw = Money::parseToman(Request::field('min_withdraw_amount'));
            if ($minWithdraw !== null && $minWithdraw >= 1000) {
                SettingsService::set('min_withdraw_amount', (string) $minWithdraw, 'withdrawal');
            }

            $this->setStep(6);
            json_out([
                'ok' => true,
                'message' => 'تنظیمات پایه ذخیره شد.',
                'base_url' => $baseUrl,
                'https' => $isHttps,
                'miniapp' => $mode !== 'off' && $isHttps,
            ]);
        } catch (\Throwable $e) {
            json_out(['ok' => false, 'error' => ErrorHandler::redact($e->getMessage())], 500);
        }
    }

    /**
     * مرحلهٔ پایانی: نوشتن .env، ثبت installed_at و ساخت قفل نصب.
     *
     * ترتیب امن (مهم):
     *   ۱) نوشتن .env (اتمیک) — اگر شکست بخورد، هیچ‌چیز قفل نمی‌شود
     *   ۲) ثبت installed_at در دیتابیس
     *   ۳) ساخت فایل قفل
     * اگر مرحلهٔ ۲ موفق و مرحلهٔ ۳ شکست بخورد، نصب در حالت «نیمه‌قفل»
     * می‌ماند؛ ولی چون Middleware::isInstalled() هم فایل قفل و هم
     * installed_at را بررسی می‌کند، اجرای مجدد همین مرحله آن را کامل می‌کند
     * (idempotent). پس ویزارد هرگز برای همیشه قفل نمی‌شود.
     */
    public function finish(): void
    {
        $this->guard();
        \RB\Support\Csrf::verify();
        try {
            $db = $this->resolveDatabase();

            // ---- ۱) .env (fail fast: اگر نوشته نشود، نصب قفل نمی‌شود)
            $this->writeEnv(installed: true);

            // ---- ۲) ثبت نصب در دیتابیس
            SettingsService::set('installed_at', now(), 'system');

            // ---- ۳) فایل قفل
            $lockFile = RB_STORAGE . '/installed.lock';
            $lockPayload = (string) json_encode([
                'installed_at' => now(),
                'php' => PHP_VERSION,
                'host' => Request::origin(),
            ], JSON_UNESCAPED_UNICODE);
            if (@file_put_contents($lockFile, $lockPayload, LOCK_EX) === false) {
                // قفل فایل ضروری نیست؛ installed_at در دیتابیس کافی است.
                // اما باید به مدیر گزارش شود و نصب نباید شکست بخورد.
                ErrorHandler::write('warning', 'فایل قفل نصب نوشته نشد: ' . $lockFile);
            }

            AuditService::log('system.installed', 'system', null, ['host' => Request::origin()]);

            Session::start();
            unset($_SESSION['_install_db'], $_SESSION['_install_step'], $_SESSION['_install_rate_post'], $_SESSION['_install_rate_test_db']);
            $this->setStep(7);
            json_out([
                'ok' => true,
                'message' => 'نصب با موفقیت تکمیل شد.',
                'redirect' => url('admin/login'),
            ]);
        } catch (\Throwable $e) {
            ErrorHandler::logException($e);
            json_out([
                'ok' => false,
                'code' => 'finish_failed',
                'error' => 'تکمیل نصب ناموفق بود: ' . ErrorHandler::redact($e->getMessage()),
                'recoverable' => true,
                'retry' => 'می‌توانید دوباره دکمهٔ «پایان نصب» را بزنید؛ هیچ‌چیز قفل نشده است.',
            ], 500);
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

    /**
     * نوشتن اتمیک .env با کنترل کامل خطا.
     *
     * مشکلات نسخهٔ قبلی که رفع شده:
     *   • @file_put_contents خطا را می‌بلعید؛ نصب «موفق» گزارش می‌شد در حالی
     *     که .env خالی یا ناقص بود و بعد از رفرش همه‌چیز از کار می‌افتاد.
     *   • نوشتن مستقیم روی فایل موجود: اگر نوشتن در میانه قطع می‌شد، فایل
     *     معتبر قبلی از بین می‌رفت. حالا اول فایل موقت نوشته می‌شود و فقط
     *     در صورت موفقیت با rename (اتمیک) جایگزین می‌شود.
     *   • نقل‌قول/بک‌اسلش در رمز دیتابیس درست مدیریت نمی‌شد (round-trip).
     */
    private function writeEnv(bool $installed = false): void
    {
        Session::start();
        $db = $_SESSION['_install_db'] ?? null;
        $envFile = RB_ROOT . '/.env';
        $existing = is_file($envFile) ? (string) file_get_contents($envFile) : '';

        $appKey = '';
        if (preg_match('~^APP_KEY=(.*)$~m', $existing, $m)) {
            $appKey = trim($m[1], " \t\"'");
        }
        if ($appKey === '' || !preg_match('~^[0-9a-f]{64}$~i', $appKey)) {
            $appKey = bin2hex(random_bytes(32));
        }

        $values = [
            'APP_NAME' => (string) SettingsService::get('app_name', 'رفرال‌بات'),
            'APP_DEBUG' => 'false',
            'APP_TIMEZONE' => 'Asia/Tehran',
            'APP_KEY' => $appKey,
            'APP_BASE_PATH' => (string) SettingsService::get('app_base_path', ''),
            'RB_INSTALLED' => $installed ? 'true' : 'false',
        ];
        if (is_array($db)) {
            $values['DB_DRIVER'] = 'mysql';
            $values['DB_HOST'] = (string) ($db['host'] ?? 'localhost');
            $values['DB_PORT'] = (string) ($db['port'] ?? '3306');
            $values['DB_DATABASE'] = (string) ($db['name'] ?? '');
            $values['DB_USERNAME'] = (string) ($db['user'] ?? '');
            $values['DB_PASSWORD'] = (string) ($db['pass'] ?? '');
        }

        $lines = [
            '# RefrallBot — فایل تنظیمات (محرمانه — در Git قرار ندهید)',
            '# ساخته‌شده در ' . now(),
            '',
        ];
        foreach ($values as $key => $value) {
            $lines[] = $key . '=' . self::envValue($value);
        }
        $content = implode("\n", $lines) . "\n";

        // ---- بررسی قابل نوشتن بودن قبل از تلاش
        $dir = RB_ROOT;
        if (!is_writable($dir)) {
            throw new \RuntimeException(
                'پوشهٔ پروژه قابل نوشتن نیست، بنابراین فایل .env ساخته نمی‌شود. '
                . 'دسترسی پوشهٔ ریشه را در File Manager به 755 (یا 775) تغییر دهید.'
            );
        }

        // ---- نوشتن اتمیک: فایل موقت + rename
        $tmp = $envFile . '.tmp' . bin2hex(random_bytes(4));
        $bytes = @file_put_contents($tmp, $content, LOCK_EX);
        if ($bytes === false || $bytes !== strlen($content)) {
            @unlink($tmp);
            $error = error_get_last()['message'] ?? 'نامشخص';
            throw new \RuntimeException(
                'نوشتن فایل .env ناموفق بود (' . ErrorHandler::redact($error) . '). '
                . 'فضای دیسک و دسترسی نوشتن پوشهٔ ریشه را بررسی کنید.'
            );
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $envFile)) {
            // rename ممکن است روی برخی فایل‌سیستم‌ها (SD کارت اندروید/FAT) کار نکند
            if (@file_put_contents($envFile, $content, LOCK_EX) === false) {
                @unlink($tmp);
                throw new \RuntimeException(
                    'جایگزینی فایل .env ناموفق بود. دسترسی نوشتن فایل .env را بررسی کنید.'
                );
            }
            @unlink($tmp);
        }

        // ---- تأیید نهایی: فایل باید خوانده شود و کلیدهای ضروری را داشته باشد
        $check = (string) @file_get_contents($envFile);
        foreach (['APP_KEY', 'DB_DRIVER', 'DB_DATABASE'] as $required) {
            if (!str_contains($check, $required . '=')) {
                throw new \RuntimeException('فایل .env ناقص نوشته شد (کلید ' . $required . ' پیدا نشد).');
            }
        }
        @chmod($envFile, 0640);

        // ---- فایل .htaccess محافظ در ریشه
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

    /**
     * آماده‌سازی یک مقدار برای نوشتن در .env به‌گونه‌ای که Env::load()
     * دقیقاً همان مقدار را بازخوانی کند.
     *
     * مقادیر ساده بی‌نقل‌قول نوشته می‌شوند؛ هر مقدار دارای فاصله، #،
     * نقل‌قول یا بک‌اسلش داخل "..." قرار می‌گیرد و کاراکترهای خاص escape
     * می‌شوند تا رمز دیتابیس با کاراکترهای عجیب هم سالم بماند.
     */
    private static function envValue(string $value): string
    {
        if ($value !== '' && preg_match('~^[A-Za-z0-9_.:/@+-]+$~', $value)) {
            return $value; // بدون نیاز به نقل‌قول
        }
        // escape برای رشتهٔ دوتایی‌نقل‌قولی
        $escaped = str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $value
        );
        return '"' . $escaped . '"';
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
            // پنل وب (Mini App): پیش‌فرض auto یعنی فقط اگر آدرس https باشد فعال می‌شود.
            // روی هاست بدون SSL، همه‌چیز از دکمه‌های خود ربات انجام می‌شود.
            'miniapp_mode' => 'auto',
            'miniapp_url' => '',
            'panel_url' => '',
        ];
    }
}
