<?php
declare(strict_types=1);
/**
 * تست سناریو ۲۲: رفع خطای MySQL 8 شماره 3988
 * (SQLSTATE[HY000]: General error: 3988 Conversion from collation utf8mb3_general_ci
 *  into utf8mb4_general_ci impossible for parameter) در مرحلهٔ نصب و اجرای روزمره.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Controllers\InstallerController;
use RB\Services\MenuService;
use RB\Services\SettingsService;
use RB\Support\Database;
use RB\Support\SchemaBuilder;

T::suite('سناریو ۲۲: رفع خطای Collation 3988 MySQL 8 و تضمین کامل utf8mb4');

/**
 * شبیه‌ساز PDO مای‌اس‌کیوال برای بازتولید دقیق رفتار هاست‌های cPanel/DirectAdmin با MySQL 8
 */
final class FakeMysql3988Pdo extends \PDO
{
    /** @var string[] */
    public array $execLog = [];
    /** @var string[] */
    public array $queryLog = [];
    /** @var array<int,mixed> */
    public array $attrs = [\PDO::ATTR_EMULATE_PREPARES => false];
    public string $clientCollation = 'utf8mb3_general_ci';
    public bool $binaryPsAlways3988 = false;
    public string $dbCollation = 'utf8mb3_general_ci';
    /** @var array<string,string> */
    public array $tableCollations = [
        'system_settings' => 'utf8mb3_general_ci',
        'bot_menus' => 'utf8mb4_general_ci',
        'users' => 'utf8mb4_unicode_ci',
        'wp_posts' => 'utf8mb3_general_ci', // جدول بیگانه در دیتابیس مشترک — نباید تغییر کند
    ];
    private \PDO $inner;

    public function __construct()
    {
        $this->inner = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->inner->exec('CREATE TABLE `system_settings` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `key` TEXT UNIQUE, `value` TEXT, `group_name` TEXT, `created_at` TEXT, `updated_at` TEXT)');
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->attrs[$attribute] = $value;
        return true;
    }

    public function exec(string $statement): int|false
    {
        $this->execLog[] = $statement;
        if (stripos($statement, "SET NAMES 'utf8mb4'") === 0 || stripos($statement, 'SET SESSION character_set_client') === 0) {
            $this->clientCollation = 'utf8mb4_unicode_ci';
            return 0;
        }
        if (preg_match('~^ALTER DATABASE `([^`]+)` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci$~i', $statement)) {
            $this->dbCollation = 'utf8mb4_unicode_ci';
            return 0;
        }
        if (preg_match('~^ALTER TABLE `([^`]+)` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci$~i', $statement, $m)) {
            $this->tableCollations[$m[1]] = 'utf8mb4_unicode_ci';
            return 0;
        }
        if (stripos($statement, 'SET FOREIGN_KEY_CHECKS') === 0) {
            return 0;
        }
        return $this->inner->exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $this->queryLog[] = $query;
        if ($query === 'SELECT @@collation_database') {
            return $this->inner->query("SELECT '" . $this->dbCollation . "'");
        }
        if ($query === 'SHOW TABLE STATUS') {
            $unions = [];
            foreach ($this->tableCollations as $name => $coll) {
                $unions[] = "SELECT '{$name}' AS Name, '{$coll}' AS Collation";
            }
            return $this->inner->query(implode(' UNION ALL ', $unions));
        }
        if (str_starts_with($query, 'SHOW FULL COLUMNS FROM ')) {
            return $this->inner->query("SELECT 'id' AS Field, NULL AS Collation UNION ALL SELECT 'name' AS Field, 'utf8mb4_unicode_ci' AS Collation");
        }
        if ($query === "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") {
            $unions = [];
            foreach (array_keys($this->tableCollations) as $name) {
                $unions[] = "SELECT '{$name}', 'BASE TABLE'";
            }
            return $this->inner->query(implode(' UNION ALL ', $unions));
        }
        if (preg_match("~^SHOW TABLES LIKE '([^']+)'$~", $query, $m)) {
            $needle = str_replace(['\\_', '\\%'], ['_', '%'], $m[1]);
            foreach (array_keys($this->tableCollations) as $name) {
                if (strcasecmp($name, $needle) === 0) {
                    return $this->inner->query("SELECT '{$name}'");
                }
            }
            return $this->inner->query("SELECT 'x' WHERE 0");
        }
        if (preg_match('~^SHOW INDEX FROM `([^`]+)`$~', $query, $m)) {
            if ($m[1] === 'users') {
                return $this->inner->query("SELECT 'PRIMARY' AS Key_name UNION ALL SELECT 'idx_users_status_2' AS Key_name");
            }
            return $this->inner->query("SELECT 'x' AS Key_name WHERE 0");
        }
        return $this->inner->query($query);
    }

    public function quote(string $string, int $type = \PDO::PARAM_STR): string|false
    {
        return $this->inner->quote($string, $type);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->inner->lastInsertId($name);
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $emulated = (bool) ($this->attrs[\PDO::ATTR_EMULATE_PREPARES] ?? false);

        // بازتولید دقیق خطای 3988 وقتی character_set_client روی utf8mb3_general_ci است
        if ($this->clientCollation === 'utf8mb3_general_ci') {
            throw self::makeMysqlError(
                3988,
                'SQLSTATE[HY000]: General error: 3988 Conversion from collation utf8mb3_general_ci into utf8mb4_general_ci impossible for parameter'
            );
        }
        // بازتولید خطای 3988 کلاینت باینری (libmysqlclient 8.0.26+ / ProxySQL) که فقط با Emulated Prepares حل می‌شود
        if ($this->binaryPsAlways3988 && !$emulated) {
            throw self::makeMysqlError(
                3988,
                'SQLSTATE[HY000]: General error: 3988 Conversion from collation utf8mb3_general_ci into utf8mb4_general_ci impossible for parameter'
            );
        }
        // بازتولید خطای جدول قدیمی utf8mb3 در INSERT
        if (str_contains($query, '`system_settings`') && str_starts_with(trim($query), 'INSERT')
            && ($this->tableCollations['system_settings'] ?? '') === 'utf8mb3_general_ci') {
            throw self::makeMysqlError(
                3988,
                'SQLSTATE[HY000]: General error: 3988 Conversion from collation utf8mb4_unicode_ci into utf8mb3_general_ci impossible for parameter'
            );
        }

        return $this->inner->prepare($query);
    }

    public static function makeMysqlError(int $driverCode, string $message): \PDOException
    {
        $e = new \PDOException($message);
        $e->errorInfo = ['HY000', $driverCode, $message];
        return $e;
    }
}

$makeMysqlDb = static function (FakeMysql3988Pdo $fake): Database {
    $db = new Database([
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => '3306',
        'database' => 'refrall_test',
        'username' => 'user',
        'password' => 'pass',
    ]);
    $ref = new \ReflectionProperty(Database::class, 'pdo');
    $ref->setValue($db, $fake);
    return $db;
};

// ۱) بررسی ایستای کل کد: هیچ کوئری مستقیمی به information_schema (با ستون‌های utf8mb3) باقی نمانده باشد
$srcFiles = array_merge(
    glob(dirname(__DIR__) . '/src/*.php') ?: [],
    glob(dirname(__DIR__) . '/src/*/*.php') ?: [],
    glob(dirname(__DIR__) . '/src/*/*/*.php') ?: []
);
$infoSchemaHits = [];
foreach ($srcFiles as $file) {
    $code = (string) file_get_contents($file);
    foreach (token_get_all($code) as $tok) {
        if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING
            && stripos($tok[1], 'information_schema.') !== false) {
            $infoSchemaHits[] = basename($file) . ':' . $tok[2] . ' => ' . $tok[1];
        }
    }
}
T::eq([], $infoSchemaHits, 'هیچ کوئری مستقیمی به information_schema (منشأ خطای utf8mb3 در MySQL 8) در src/ وجود ندارد');

// ۲) همهٔ جدول‌های Migration (از جمله migrations) با CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ساخته می‌شوند
foreach (['001_initial_schema.php', '002_telegram_idempotency.php'] as $migFile) {
    $sb = new SchemaBuilder('mysql');
    $fn = require dirname(__DIR__) . '/database/migrations/' . $migFile;
    $fn($sb);
    foreach ($sb->statements() as $sql) {
        if (str_starts_with($sql, 'CREATE TABLE')) {
            T::ok(
                str_contains($sql, 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
                "جدول در {$migFile} دارای CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci است"
            );
        }
    }
}
$migratorCode = (string) file_get_contents(dirname(__DIR__) . '/src/Support/Migrator.php');
T::ok(
    str_contains($migratorCode, 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
    'جدول اضطراری migrations در Migrator.php دارای COLLATE=utf8mb4_unicode_ci است'
);
$dbCode = (string) file_get_contents(dirname(__DIR__) . '/src/Support/Database.php');
T::ok(
    str_contains($dbCode, 'MYSQL_ATTR_INIT_COMMAND') && str_contains($dbCode, "SET NAMES '%s' COLLATE '%s'"),
    'Database::pdo هم MYSQL_ATTR_INIT_COMMAND و هم SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci را اعمال می‌کند'
);

// ۳) تشخیص دقیق خطای 3988، 1267 و 1366
$err3988 = FakeMysql3988Pdo::makeMysqlError(
    3988,
    'SQLSTATE[HY000]: General error: 3988 Conversion from collation utf8mb3_general_ci into utf8mb4_general_ci impossible for parameter'
);
T::eq(true, Database::isCollationOrCharsetError($err3988), 'خطای دقیق 3988 تشخیص داده می‌شود');
$err1267 = FakeMysql3988Pdo::makeMysqlError(1267, 'Illegal mix of collations (utf8mb3_general_ci,IMPLICIT) and (utf8mb4_general_ci,COERCIBLE)');
T::eq(true, Database::isCollationOrCharsetError($err1267), 'خطای 1267 تشخیص داده می‌شود');
$err1366 = FakeMysql3988Pdo::makeMysqlError(1366, "Incorrect string value: '\\xF0\\x9F\\x93\\x8C' for column 'value'");
T::eq(true, Database::isCollationOrCharsetError($err1366), 'خطای 1366 ایموجی ۴ بایتی تشخیص داده می‌شود');
$errSyntax = FakeMysql3988Pdo::makeMysqlError(1064, 'You have an error in your SQL syntax');
T::eq(false, Database::isCollationOrCharsetError($errSyntax), 'خطای عادی SQL به اشتباه خطای charset تشخیص داده نمی‌شود');

// ۴) بازیابی خودکار از خطای 3988 ناشی از utf8mb3_general_ci نشست + جدول utf8mb3 هنگام درج ایموجی ۴ بایتی
$fake1 = new FakeMysql3988Pdo();
$fake1->clientCollation = 'utf8mb3_general_ci';
$fake1->tableCollations['system_settings'] = 'utf8mb4_unicode_ci';
$db1 = $makeMysqlDb($fake1);
$id1 = $db1->insert('system_settings', [
    'key' => 'start_rules_text',
    'value' => "📌 قوانین:\n• آزمایش ایموجی ۴ بایتی 🎉",
    'group_name' => 'seed',
    'created_at' => '2026-11-18 12:00:00',
    'updated_at' => '2026-11-18 12:00:00',
]);
T::ok($id1 > 0, 'درج تنظیم دارای ایموجی ۴ بایتی پس از بازیابی خودکار از خطای 3988 موفق شد');
T::eq('utf8mb4_unicode_ci', $fake1->clientCollation, 'نشست به utf8mb4_unicode_ci ارتقا یافت');
T::eq(false, $fake1->attrs[\PDO::ATTR_EMULATE_PREPARES], 'وقتی SET NAMES مشکل را حل کند، Prepared بومی حفظ می‌شود');
T::eq(
    "📌 قوانین:\n• آزمایش ایموجی ۴ بایتی 🎉",
    $db1->value('SELECT `value` FROM `system_settings` WHERE `key` = :k', ['k' => 'start_rules_text']),
    'متن فارسی و ایموجی ۴ بایتی بدون خرابی ذخیره و بازخوانی شد'
);

// ۵) بازیابی خودکار وقتی پروتکل باینری کلاینت C همچنان 3988 می‌دهد (عقب‌نشینی امن به Emulated Prepares)
$fake2 = new FakeMysql3988Pdo();
$fake2->clientCollation = 'utf8mb4_unicode_ci';
$fake2->tableCollations['system_settings'] = 'utf8mb4_general_ci';
$fake2->binaryPsAlways3988 = true;
$db2 = $makeMysqlDb($fake2);
$id2 = $db2->insert('system_settings', [
    'key' => 'guide_text',
    'value' => '📖 راهنمای استفاده 👤 🔗',
    'group_name' => 'seed',
    'created_at' => '2026-11-18 12:00:00',
    'updated_at' => '2026-11-18 12:00:00',
]);
T::ok($id2 > 0, 'در صورت خطای 3988 پایدار در پروتکل باینری، عقب‌نشینی به Emulated Prepares درج را موفق کرد');
T::eq(true, $fake2->attrs[\PDO::ATTR_EMULATE_PREPARES], 'ATTR_EMULATE_PREPARES پس از SET NAMES utf8mb4 فعال شد');

// ۶) تبدیل خودکار جدول قدیمی utf8mb3 و حفظ جدول‌های بیگانه در دیتابیس مشترک
$fake3 = new FakeMysql3988Pdo();
$fake3->clientCollation = 'utf8mb4_unicode_ci';
$db3 = $makeMysqlDb($fake3);
$db3->ensureDatabaseUtf8mb4();
T::eq('utf8mb4_unicode_ci', $fake3->dbCollation, 'collation پیش‌فرض دیتابیس به utf8mb4_unicode_ci تبدیل شد');

$db3->ensureTablesUtf8mb4(['system_settings', 'bot_menus', 'users']);
T::eq('utf8mb4_unicode_ci', $fake3->tableCollations['system_settings'], 'جدول utf8mb3_general_ci پروژه به utf8mb4_unicode_ci تبدیل شد');
T::eq('utf8mb4_unicode_ci', $fake3->tableCollations['bot_menus'], 'جدول utf8mb4_general_ci پروژه به utf8mb4_unicode_ci یکسان شد');
T::eq('utf8mb3_general_ci', $fake3->tableCollations['wp_posts'], 'جدول بیگانه (wp_posts) در دیتابیس مشترک دست‌نخورده ماند');

// ۷) متدهای متادیتا (tableExists / indexExists / listTables) بدون information_schema کار می‌کنند
T::eq(true, $db3->tableExists('system_settings'), 'tableExists با SHOW TABLES LIKE جدول موجود را تشخیص داد');
T::eq(false, $db3->tableExists('nonexistent_tbl'), 'tableExists جدول ناموجود را false برگرداند');
T::eq(true, $db3->indexExists('users', 'idx_users_status_2'), 'indexExists با SHOW INDEX ایندکس موجود را تشخیص داد');
T::eq(false, $db3->indexExists('users', 'idx_missing'), 'indexExists ایندکس ناموجود را false برگرداند');
T::ok(in_array('system_settings', $db3->listTables(), true), 'listTables با SHOW FULL TABLES فهرست جدول‌ها را برگرداند');

// ۸) ذخیره و بازخوانی واقعی همهٔ تنظیمات پیش‌فرض نصب و منوهای ایموجی‌دار
foreach (InstallerController::defaultSettings() as $k => $v) {
    SettingsService::set($k, $v, 'seed');
}
MenuService::syncDefaults(Database::instance());
T::ok(str_contains((string) SettingsService::get('start_rules_text'), '📌'), 'ایموجی start_rules_text در مرحلهٔ نصب سالم ذخیره شد');
T::ok(str_contains((string) SettingsService::get('guide_text'), '📖'), 'ایموجی guide_text در مرحلهٔ نصب سالم ذخیره شد');
T::eq(count(MenuService::DEFAULTS), (int) Database::instance()->value('SELECT COUNT(*) FROM bot_menus'), 'همهٔ منوهای پیش‌فرض ربات با ایموجی سالم ثبت شدند');

exit(T::summary());
