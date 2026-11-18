<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Support\Database;

T::suite('نصب و Migration');

$db = Database::instance();
T::ok($db->tableExists('users'), 'جدول users ساخته شد');
T::ok($db->tableExists('wallet_transactions'), 'جدول wallet_transactions ساخته شد');
T::ok($db->tableExists('referral_relations'), 'جدول referral_relations ساخته شد');
T::ok($db->tableExists('withdrawal_requests'), 'جدول withdrawal_requests ساخته شد');
T::ok($db->tableExists('campaigns'), 'جدول campaigns ساخته شد');
T::ok($db->tableExists('security_events'), 'جدول security_events ساخته شد');
T::ok($db->tableExists('admin_activity_logs'), 'جدول admin_activity_logs ساخته شد');

$tables = $db->column("SELECT name FROM sqlite_master WHERE type='table'");
T::ok(count($tables) >= 25, 'حداقل ۲۵ جدول ایجاد شده (تعداد: ' . count($tables) . ')');

$applied = (new RB\Support\Migrator($db))->applied();
// با افزودن Migrationهای جدید (مثل 002_telegram_idempotency) تعداد بالا می‌رود؛
// مهم این است که حداقل یکی ثبت شده و همهٔ فایل‌ها اعمال شده باشند.
T::ok(count($applied) >= 1, 'Migrationها ثبت شده‌اند (تعداد: ' . count($applied) . ')');
T::ok((bool) array_filter($applied, static fn ($m) => str_starts_with((string) $m, '001_initial_schema')), 'Migration اصلی ساخته‌ی جداول اعمال شد');

// اجرای مجدد نباید خطا بدهد
$second = (new RB\Support\Migrator($db))->run();
T::eq(0, count($second['applied']), 'اجرای مجدد Migration تغییری ایجاد نکرد');

// جدول تنظیمات
RB\Services\SettingsService::set('test_key', 'test_value');
RB\Services\SettingsService::forget();
T::eq('test_value', RB\Services\SettingsService::get('test_key'), 'تنظیمات سیستم ذخیره و خوانده می‌شود');

exit(T::summary());
