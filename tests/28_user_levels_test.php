<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\CommissionService;
use RB\Services\CommissionSettings;
use RB\Services\UserLevelService;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Support\Database;

T::suite('سطح‌بندی: آستانه، سقف، تکرار، بازگشت و دفتر مالی');
$db = Database::instance();
CommissionSettings::saveVersion(['direct_reward'=>1000,'direct_enabled'=>1,'multi_level_enabled'=>0,'max_levels'=>1,'levels'=>[['level'=>1,'percent'=>'0','enabled'=>false]],'percent_base'=>'direct_reward','qualify_conditions'=>['joined']]);
$db->insert('user_level_config', ['id'=>1,'enabled'=>1,'budget'=>100000,'spent'=>0,'created_at'=>now(),'updated_at'=>now()]);
$levelId = $db->insert('user_levels', ['name'=>'نقره','icon'=>'🏅','badge'=>'پیشگام','color'=>'#123456','min_invites'=>2,'invite_reward'=>5000,'promotion_reward'=>2000,'user_cap'=>9000,'benefits'=>'ویژه','enabled'=>1,'deleted'=>0,'created_at'=>now(),'updated_at'=>now()]);
$settingsId = (int) $db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
if (!$settingsId) $settingsId = $db->insert('telegram_settings', ['connection_status'=>'connected','created_at'=>now(),'updated_at'=>now()]);
$db->update('telegram_settings', ['bot_token_enc'=>\RB\Support\Crypto::encrypt('777888999:' . str_repeat('R', 35)), 'bot_id'=>777888999, 'bot_username'=>'levels_test_bot', 'connection_status'=>'connected', 'updated_at'=>now()], 'id = :id', ['id'=>$settingsId]);
\RB\Services\TelegramService::forgetSettingsCache();
$outbox = [];
\RB\Services\TelegramService::$fakeTransport = static function (string $method, array $params) use (&$outbox, $db): array {
    if ($method === 'sendMessage') $outbox[] = ['text'=>(string)($params['text'] ?? ''), 'in_tx'=>$db->inTransaction()];
    return ['ok'=>true, 'result'=>['message_id'=>count($outbox)+1]];
};
$users = new UserService($db);
$a = $users->registerFromTelegram(['id'=>810001,'first_name'=>'A'])['user'];
$refs = [];
for ($i=0; $i<4; $i++) $refs[] = $users->registerFromTelegram(['id'=>810002+$i,'first_name'=>'B'], $a['referral_code'])['user'];
$uid=(int)$a['id'];
$svc=new UserLevelService($db);
T::eq(4, $svc->count($uid), 'فقط رویدادهای تأییدشده شمرده می‌شوند');
T::eq('نقره', $svc->profile($uid)['current']['name'], 'سطح از دعوت‌های معتبر مشخص می‌شود');
$amounts = array_map('intval', array_column($db->all("SELECT amount FROM wallet_transactions WHERE user_id = :u AND type = 'commission_direct' ORDER BY id", ['u'=>$uid]), 'amount'));
T::eq([1000,1000,5000,2000], $amounts, 'دعوت آستانه با نرخ قدیم و دعوت‌های بعدی با نرخ جدید و سقف فردی پرداخت می‌شوند');
T::eq(11000, (int)$users->find($uid)['wallet_balance'], 'جایزه ارتقا همراه پاداش‌ها در کیف پول است');
T::eq(9000, (int)$db->value('SELECT spent FROM user_level_config WHERE id=1'), 'مصرف بودجه برابر پرداخت‌های سطح است');
T::eq(1, (int)$db->value('SELECT COUNT(*) FROM user_level_promotions'), 'جایزه ارتقا فقط یک بار ثبت شد');
T::eq(1, (int)$db->value("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND type = 'level_promotion'", ['u'=>$uid]), 'اعلان تبریک ارتقا یک بار ثبت شد');
$promotionNotice = $db->first("SELECT * FROM notifications WHERE user_id = :u AND type = 'level_promotion'", ['u'=>$uid]);
T::ok(str_contains($promotionNotice['body'], 'نقره') && str_contains($promotionNotice['body'], '۵,۰۰۰'), 'اعلان شامل نام رتبه و پاداش جدید است');
$promotionMessages = array_values(array_filter($outbox, static fn(array $m): bool => str_contains($m['text'], 'تبریک ارتقای سطح')));
T::eq(1, count($promotionMessages), 'پیام تبریک از طریق پیام‌رسان ارسال می‌شود');
T::eq(false, $promotionMessages[0]['in_tx'] ?? true, 'پیام تبریک پس از ثبت نهایی تراکنش ارسال می‌شود');
$again=(new CommissionService($db))->processEvent(['event_key'=>'referral_qualified:'.$refs[1]['id'],'type'=>'referral_qualified','user_id'=>(int)$refs[1]['id'],'actor_id'=>$uid]);
T::eq(true, $again['duplicated'], 'رویداد تکراری پرداخت جدید ایجاد نمی‌کند');
$event=(int)$db->value('SELECT id FROM referral_events WHERE event_key = :k',['k'=>'referral_qualified:'.$refs[1]['id']]);
(new CommissionService($db))->revertEvent($event, 'آزمون');
T::eq(3, $svc->count($uid), 'دعوت برگشت‌خورده از شمارش حذف می‌شود');
T::eq(8000, (int)$users->find($uid)['wallet_balance'], 'پورسانت و جایزه ارتقای رویداد برگشت می‌خورند');
T::eq(true, (new WalletService($db))->reconcile($uid)['match'], 'کیف پول با دفتر تطبیق دارد');
T::eq(9000, (int)$db->value('SELECT spent FROM user_level_config WHERE id=1'), 'برگشت، بودجه را آزاد نمی‌کند');

// Invalidation and blocked invitees cannot advance the level; a hard budget is never overspent.
$db->update('user_level_config', ['budget'=>9000], 'id = :id', ['id'=>1]);
$extra=$users->registerFromTelegram(['id'=>810010,'first_name'=>'C'], $a['referral_code'])['user'];
T::eq(8000, (int)$users->find($uid)['wallet_balance'], 'بودجه مصرف‌شده مانع پرداخت جدید سطح می‌شود');
T::eq(9000, (int)$db->value('SELECT spent FROM user_level_config WHERE id=1'), 'بودجه کل هرگز بیش از سقف نمی‌شود');
$db->update('users', ['status'=>'blocked'], 'id = :id', ['id'=>(int)$extra['id']]);
T::eq(4, $svc->count($uid), 'مسدودشدن بعدی، تعریف قبلی دعوت تأییدشده را تغییر نمی‌دهد');
$db->update('user_level_config', ['enabled'=>0], 'id = :id', ['id'=>1]);
$users->registerFromTelegram(['id'=>810011,'first_name'=>'D'], $a['referral_code']);
T::eq(9000, (int)$users->find($uid)['wallet_balance'], 'در حالت غیرفعال رفتار قبلی و پاداش پایه حفظ می‌شود');
T::eq(null, $svc->profile($uid), 'سطح‌بندی غیرفعال به کاربر ارائه نمی‌شود');

// Real controller actions with superadmin and redirect interception.
$role = (int) ($db->value("SELECT id FROM admin_roles WHERE slug = 'superadmin'") ?: $db->insert('admin_roles', ['name'=>'مدیر کل','slug'=>'superadmin','permissions'=>'["*"]','created_at'=>now(),'updated_at'=>now()]));
$admin = $db->insert('admins', ['name'=>'مدیر تست','username'=>'level_admin','password_hash'=>password_hash('StrongPass123', PASSWORD_DEFAULT),'role_id'=>$role,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
$_SESSION['admin_id'] = $admin;
$_SESSION['admin_last_seen'] = time();
$_SERVER['REQUEST_METHOD'] = 'POST';
\RB\Support\Request::$redirectHandler = static function (): void {};
$_POST = ['name'=>'طلایی','icon'=>'⭐','badge'=>'قهرمان','color'=>'#abcdef','min_invites'=>'10','invite_reward'=>'10000','promotion_reward'=>'1000','user_cap'=>'20000','enabled'=>'1','benefits'=>'اولویت'];
try { (new \RB\Controllers\Admin\UserLevelController())->save(); } catch (\RB\Support\RedirectException) {}
T::eq(1, (int)$db->value("SELECT COUNT(*) FROM user_levels WHERE name = 'طلایی'"), 'مدیر کل می‌تواند سطح جدید ایجاد کند');
$_POST = ['enabled'=>'1','budget'=>'8000'];
try { (new \RB\Controllers\Admin\UserLevelController())->config(); } catch (\RB\Support\RedirectException) {}
T::eq(9000, (int)$db->value('SELECT budget FROM user_level_config WHERE id = 1'), 'بودجه کمتر از هزینه مصرف‌شده رد می‌شود');
\RB\Support\Request::$redirectHandler = null;
exit(T::summary());
