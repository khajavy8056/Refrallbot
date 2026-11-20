<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib.php';
use RB\Services\CommissionSettings;
use RB\Services\SettingsService;
use RB\Services\UserService;
use RB\Support\Database;
use RB\Support\Csrf;

T::suite('بررسی واقعی شرایط کانال در مینی‌اپ');
$db=Database::instance(); $users=new UserService($db);
SettingsService::set('force_channel_membership','1','telegram');
SettingsService::set('contact_required','1','telegram');
$db->insert('telegram_channels',['title'=>'Test','chat_id'=>'@testchannel','invite_link'=>'https://t.me/testchannel','platform'=>'telegram','check_mode'=>'self_confirm','target_platforms'=>'same','is_active'=>1,'sort_order'=>1,'created_at'=>now(),'updated_at'=>now()]);
CommissionSettings::saveVersion(['direct_reward'=>10000,'direct_enabled'=>1,'multi_level_enabled'=>0,'max_levels'=>1,'qualify_conditions'=>['channels','phone']]);
$a=$users->registerFromTelegram(['id'=>83600001,'first_name'=>'A'])['user'];
$b=$users->registerFromTelegram(['id'=>83600002,'first_name'=>'B'],$a['referral_code'])['user'];
$_SESSION['miniapp_user_id']=(int)$b['id']; $_SESSION['miniapp_auth_at']=time(); $_SESSION['miniapp_platform']='telegram'; $_SESSION['miniapp_external_id']=(string)$b['telegram_id'];
$_SERVER['REQUEST_METHOD']='POST'; $_SERVER['REQUEST_URI']='/app/api/verify-channels'; $_SERVER['HTTP_X_CSRF_TOKEN']=Csrf::token(); $_POST=[];
$call=static function (): array { ob_start(); (new \RB\Controllers\MiniApp\DashboardApiController())->verifyChannels(); return json_decode((string)ob_get_clean(), true) ?: []; };
$response=$call();
T::eq(false,(bool)($response['ok']??true),'وقتی شماره تماس اجباری ناقص است، بررسی کانال موفق اعلام نمی‌شود');
T::eq(0,(int)$users->find((int)$b['id'])['channels_verified'],'دروازه شماره تماس، کانال را تأیید نمی‌کند');
$users->setPhone((int)$b['id'],'+989121234567');
T::eq(null,$users->find((int)$b['id'])['qualified_at'],'ثبت شماره بدون تأیید کانال منجر به پاداش نمی‌شود');
SettingsService::set('force_channel_membership','0','telegram');
$response=$call();
T::eq(false,(bool)($response['ok']??true),'شرط کانال برای پاداش حتی با غیرفعال بودن دروازه عمومی راستی‌آزمایی می‌شود');
T::eq(0,(int)$users->find((int)$b['id'])['channels_verified'],'کانال بررسی‌نشده تأیید نمی‌شود');
$_POST=['self_confirm'=>1]; $response=$call();
T::eq(true,(bool)($response['ok']??false),'روش خوداظهاری تنظیم‌شده می‌تواند مطابق سیاست کانال تکمیل شود');
T::eq(10000,(int)$users->find((int)$a['id'])['wallet_balance'],'پس از تکمیل واقعی روش تعیین‌شده پاداش یک بار پرداخت می‌شود');

// API errors must not count as membership, even if no channels are returned
// in the missing-channel list by the driver.
$db->update('telegram_channels',['check_mode'=>'api'],'chat_id = :c',['c'=>'@testchannel']);
$tg=(int)$db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
if (!$tg) $tg=$db->insert('telegram_settings',['connection_status'=>'connected','created_at'=>now(),'updated_at'=>now()]);
$db->update('telegram_settings',['bot_token_enc'=>\RB\Support\Crypto::encrypt('555666777:'.str_repeat('R',35)), 'bot_id'=>555666777, 'bot_username'=>'test_bot','connection_status'=>'connected','updated_at'=>now()],'id = :id',['id'=>$tg]);
\RB\Services\TelegramService::forgetSettingsCache();
\RB\Services\TelegramService::$fakeTransport=static fn(string $method,array $params): array => ['ok'=>false,'description'=>'test API unavailable'];
$c=$users->registerFromTelegram(['id'=>83600003,'first_name'=>'C'],$a['referral_code'])['user'];
$users->setPhone((int)$c['id'],'+989121234567');
$_SESSION['miniapp_user_id']=(int)$c['id']; $_SESSION['miniapp_external_id']=(string)$c['telegram_id']; $_POST=[];
$response=$call();
T::eq(false,(bool)($response['ok']??true),'خطای API عضویت به عنوان تأیید پذیرفته نمی‌شود');
T::eq(0,(int)$users->find((int)$c['id'])['channels_verified'],'خطای API پرچم تأیید کانال را فعال نمی‌کند');
T::eq(10000,(int)$users->find((int)$a['id'])['wallet_balance'],'خطای API باعث پرداخت جدید نمی‌شود');
\RB\Services\TelegramService::$fakeTransport=null;
exit(T::summary());
