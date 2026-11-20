<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\CommissionSettings;
use RB\Services\StatsService;
use RB\Services\UserService;
use RB\Support\Database;

T::suite('تشخیص علت دعوت‌های در انتظار تأیید');
$db = Database::instance();
$users = new UserService($db);
$settings = ['direct_reward'=>10000, 'direct_enabled'=>1, 'multi_level_enabled'=>0, 'max_levels'=>1, 'qualify_conditions'=>['joined','channels','approved']];
CommissionSettings::saveVersion($settings);
$parent = $users->registerFromTelegram(['id'=>83500001,'first_name'=>'Parent'])['user'];
$one = $users->registerFromTelegram(['id'=>83500002,'first_name'=>'One'], $parent['referral_code'])['user'];
$two = $users->registerFromTelegram(['id'=>83500003,'first_name'=>'Two'], $parent['referral_code'])['user'];
$pid=(int)$parent['id']; $oneId=(int)$one['id']; $twoId=(int)$two['id'];
T::eq(2, (int)$users->find($pid)['direct_ref_count'], 'دعوت قبل از تکمیل شرایط در شمارنده ثبت می‌شود');
T::eq(0, (int)$users->find($pid)['wallet_balance'], 'دعوت ثبت‌شده به‌تنهایی پرداخت نمی‌شود');
// Simulate an earlier installation where the channel had been verified but
// manual approval prevented qualification.
$db->update('users', ['channels_verified'=>1], 'id = :id', ['id'=>$oneId]);
T::eq(1, (int)$users->find($oneId)['channels_verified'], 'عضویت کانال تأیید شده است');
T::eq('pending', $users->find($oneId)['verify_status'], 'وضعیت تأیید قدیمی در داده محفوظ می‌ماند');
T::eq(null, $users->find($oneId)['qualified_at'], 'دعوت تاریخی قبلاً پردازش نشده است');
T::eq(true, in_array('approved', $users->effectiveConditions(CommissionSettings::current($db), 'telegram'), true), 'شرط تأیید دستی در تنظیمات فعال وجود دارد');
$report=StatsService::layerFinancialReport($db);
T::eq(2, (int)$report['layers'][0]['users_count'], 'گزارش روابط ثبت‌شده همه کاربران سیستم را می‌شمارد');
T::eq(0, (int)$report['layers'][0]['qualified_count'], 'گزارش فقط افراد دارای تاریخ احراز را واجد شرایط می‌شمارد');
$settings['qualify_conditions']=['joined','channels'];
// A pending signup does not snapshot the payout rate. Qualification after an
// admin edit uses the new version, not automatically the signup-time rate.
$settings['direct_reward']=20000;
CommissionSettings::saveVersion($settings);
T::eq(null, $users->find($oneId)['qualified_at'], 'غیرفعال‌کردن شرط به‌تنهایی کاربران قدیمی را خودکار پردازش نمی‌کند');
T::eq(0, (int)$users->find($pid)['wallet_balance'], 'ذخیره تنظیمات به‌تنهایی موجودی را تغییر نمی‌دهد');
T::eq(false, $users->maybeQualify($users->find($oneId)), 'پس از غیرفعال‌کردن شرط، دعوت ثبت‌شده همچنان منتظر تأیید می‌ماند');
T::eq(true, $users->approveVerification($oneId), 'تأیید مدیر دعوت واجد سایر شرایط را پردازش می‌کند');
T::eq(20000, (int)$users->find($pid)['wallet_balance'], 'پرداخت پس از بررسی مجدد از نرخ زمان احراز استفاده می‌کند، نه نرخ ثبت‌نام');
T::eq(false, $users->maybeQualify($users->find($oneId)), 'بررسی مجدد تکراری پرداخت تکراری ندارد');
T::eq(false, $users->maybeQualify($users->find($twoId)), 'بدون تأیید کانال حتی با حذف شرط مدیر پرداخت نمی‌شود');
T::eq(20000, (int)$users->find($pid)['wallet_balance'], 'پرداخت مجدد یا پرداخت بدون شرایط انجام نشد');
// Exercise the actual /start handler: attachment to the referrer precedes
// the mandatory-channel gate, so a person can be counted without passing it.
\RB\Services\SettingsService::set('force_channel_membership', '1', 'telegram');
$db->insert('telegram_channels', ['title'=>'Test', 'chat_id'=>'@test_channel', 'invite_link'=>'https://t.me/test_channel', 'platform'=>'telegram', 'check_mode'=>'self_confirm', 'target_platforms'=>'same', 'is_active'=>1, 'sort_order'=>1, 'created_at'=>now(), 'updated_at'=>now()]);
$tg = (int)$db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
if (!$tg) $tg=$db->insert('telegram_settings', ['connection_status'=>'connected','created_at'=>now(),'updated_at'=>now()]);
$db->update('telegram_settings', ['bot_token_enc'=>\RB\Support\Crypto::encrypt('555666777:'.str_repeat('R',35)), 'bot_id'=>555666777, 'bot_username'=>'diagnosis_bot', 'connection_status'=>'connected', 'updated_at'=>now()], 'id = :id', ['id'=>$tg]);
\RB\Services\TelegramService::forgetSettingsCache();
\RB\Services\TelegramService::$fakeTransport = static fn(string $method, array $params): array => ['ok'=>true, 'result'=>['message_id'=>1]];
$bot = new \RB\Bot\BotHandler($db);
$bot->handle(['update_id'=>83500999,'message'=>['message_id'=>83500999,'date'=>time(),'from'=>['id'=>83500004,'first_name'=>'NotJoined'],'chat'=>['id'=>83500004,'type'=>'private'],'text'=>'/start '.$parent['referral_code']]]);
$notJoined=$users->findByTelegramId(83500004);
T::ok($notJoined !== null && (int)$notJoined['referred_by'] === $pid, 'در مسیر واقعی /start معرف پیش از دروازه کانال ثبت می‌شود');
T::eq(0, (int)($notJoined['channels_verified'] ?? 0), 'کاربر هنوز کانال اجباری را تأیید نکرده است');
T::eq(null, $notJoined['qualified_at'] ?? null, 'پیش از تأیید کانال پاداش آزاد نشده است');
T::eq(3, (int)$users->find($pid)['direct_ref_count'], 'با وجود عدم تأیید کانال در شمارنده دعوت قرار می‌گیرد');
\RB\Services\TelegramService::$fakeTransport = null;
exit(T::summary());
