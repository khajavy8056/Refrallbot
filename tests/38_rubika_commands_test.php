<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib.php';
use RB\Messengers\AbstractMessengerDriver;
use RB\Messengers\MessengerRegistry;
use RB\Controllers\Webhook\MessengerWebhookController;
use RB\Services\UserService;
use RB\Support\Database;

T::suite('دستورات و دکمه‌های واقعی روبیکا از وب‌هوک');
$db=Database::instance(); $_SERVER['HTTP_HOST']='bot.example.com'; $_SERVER['HTTPS']='on';
$calls=[]; $chatFails=false;
AbstractMessengerDriver::setFakeTransport('rubika', static function (string $method, array $params) use (&$calls, &$chatFails): array {
    $calls[]=[$method,$params];
    if ($method==='getMe') return ['status'=>'OK','data'=>['bot'=>['bot_id'=>'b0bot','username'=>'test_bot','bot_title'=>'Test']]];
    if ($method==='getChat') return $chatFails ? ['status'=>'ERROR','dev_message'=>'unavailable'] : ['status'=>'OK','data'=>['chat'=>['user_id'=>'u0Actual','first_name'=>'Test']]];
    return ['status'=>'OK','data'=>['message_id'=>'out-'.count($calls)]];
});
$registry=new MessengerRegistry($db);
$save=$registry->saveCredentials('rubika',['bot_token'=>'RubikaFakeTokenTestOnly_0123456789','is_enabled'=>1]);
T::eq(true,$save['ok'],'اتصال ساختگی روبیکا فعال است');
$reg=$registry->registerWebhook('rubika');
T::eq(true,$reg['ok'],'هر دو وب‌هوک روبیکا ثبت می‌شوند');
$endpoints=array_values(array_filter($calls,fn($c)=>$c[0]==='updateBotEndpoints'));
T::eq(['ReceiveUpdate','ReceiveInlineMessage'],array_column(array_column($endpoints,1),'type'),'نوع‌های وب‌هوک مطابق API رسمی هستند');
$secret=(string)$registry->rawConnection('rubika')['webhook_secret'];
$controller=new MessengerWebhookController();
$invoke=static function(array $payload) use ($controller,$secret): array { ob_start(); $controller->processPlatformWebhook('rubika',$secret,'receiveUpdate',json_encode($payload)); return json_decode((string)ob_get_clean(),true) ?: []; };
$users=new UserService($db); $chat='b0ActualChat';
$r=$invoke(['update'=>['type'=>'StartedBot','chat_id'=>$chat]]);
T::eq(1,$r['processed'],'رویداد شروع بدون sender پردازش می‌شود');
$user=$users->findByMessenger('rubika','u0Actual');
T::ok($user!==null,'شناسه واقعی از getChat گرفته شده و کاربر واقعی ثبت شد');
T::eq(null,$users->findByMessenger('rubika',$chat),'شناسه چت به اشتباه به حساب دوم تبدیل نشد');
$sent=array_values(array_filter($calls,fn($c)=>$c[0]==='sendMessage'));
T::eq($chat,$sent[0][1]['chat_id']??null,'پاسخ به شناسه چت ارسال می‌شود نه شناسه کاربر');
T::eq('New',$sent[0][1]['chat_keypad_type']??null,'منوی رسمی روبیکا در پاسخ شروع ارسال می‌شود');
$buttons=[];foreach(($sent[0][1]['chat_keypad']['rows']??[]) as $row) foreach($row['buttons'] as $button) $buttons[]=$button;
T::ok(in_array('menu:wallet',array_column($buttons,'id'),true),'دکمه کیف پول شناسه عملیاتی پایدار دارد');
T::eq(false,array_key_exists('on_time_keyboard',$sent[0][1]['chat_keypad']??[]),'فیلد نامستند از کی‌پد حذف شد');
$before=count($sent);
$invoke(['update'=>['type'=>'StartedBot','chat_id'=>$chat]]);
$sent=array_values(array_filter($calls,fn($c)=>$c[0]==='sendMessage'));
T::eq($before+1,count($sent),'شروع دوباره بدون شناسه پیام برای همیشه حذف نمی‌شود');
$invoke(['update'=>['type'=>'NewMessage','chat_id'=>$chat,'new_message'=>['message_id'=>'m1','sender_type'=>'User','sender_id'=>'u0Actual','text'=>'/wallet']]]);
$sent=array_values(array_filter($calls,fn($c)=>$c[0]==='sendMessage'));
T::ok(str_contains((string)end($sent)[1]['text'],'کیف پول'),'دستور کیف پول پاسخ دریافت می‌کند');
$invoke(['update'=>['type'=>'NewMessage','chat_id'=>$chat,'new_message'=>['message_id'=>'m2','sender_type'=>'User','sender_id'=>'u0Actual','text'=>'','aux_data'=>['button_id'=>'menu:wallet']]]]);
$sent=array_values(array_filter($calls,fn($c)=>$c[0]==='sendMessage'));
T::ok(str_contains((string)end($sent)[1]['text'],'کیف پول'),'کلیک دکمه با شناسه و بدون متن پاسخ می‌دهد');
$last=count($sent);
$invoke(['update'=>['type'=>'NewMessage','chat_id'=>$chat,'new_message'=>['message_id'=>'m2','sender_type'=>'User','sender_id'=>'u0Actual','text'=>'','aux_data'=>['button_id'=>'menu:wallet']]]]);
T::eq($last,count(array_filter($calls,fn($c)=>$c[0]==='sendMessage')),'بازارسال همان پیام دارای شناسه تکراری نادیده گرفته شد');
$inline=['inline_message'=>['sender_id'=>'u0Actual','chat_id'=>$chat,'message_id'=>'inline1','aux_data'=>['button_id'=>'menu:wallet']]];
$invoke($inline); $invoke($inline);
$sent2=array_values(array_filter($calls,fn($c)=>$c[0]==='sendMessage'));
T::eq($last+2,count($sent2),'دو کلیک مستقل یک دکمه inline هر دو پاسخ می‌گیرند');
$invoke(['update'=>['type'=>'StoppedBot','chat_id'=>$chat]]);
T::eq(1,(int)$db->value("SELECT is_blocked FROM user_messenger_accounts WHERE platform = 'rubika' AND external_user_id = 'u0Actual'"),'رویداد توقف بر حساب واقعی صاحب چت ثبت می‌شود');
$invoke(['update'=>['type'=>'StartedBot','chat_id'=>$chat]]);
T::eq(0,(int)$db->value("SELECT is_blocked FROM user_messenger_accounts WHERE platform = 'rubika' AND external_user_id = 'u0Actual'"),'شروع دوباره پس از توقف، حساب را برای پیام‌رسانی فعال می‌کند');
// Existing installations can contain a chat-ID placeholder from an old
// StartedBot. Preserve the same central user and wallet when getChat proves it.
$legacy=$users->registerFromMessenger('rubika',['id'=>'b0Legacy','first_name'=>'Legacy','chat_id'=>'b0Legacy'],null,['chat_id'=>'b0Legacy'])['user'];
(new \RB\Services\WalletService($db))->credit((int)$legacy['id'],'adjustment',3456,['idempotency_key'=>'legacy-rubika']);
// Change the fake getChat result for the legacy chat, not the existing account.
AbstractMessengerDriver::setFakeTransport('rubika', static function(string $method,array $params) use (&$calls):array {
  $calls[]=[$method,$params];
  if ($method==='getChat') return ['status'=>'OK','data'=>['chat'=>['chat_id'=>'b0Legacy','user_id'=>'u0Legacy','first_name'=>'Legacy']]];
  return ['status'=>'OK','data'=>['message_id'=>'out-'.count($calls)]];
});
$invoke(['update'=>['type'=>'NewMessage','chat_id'=>'b0Legacy','new_message'=>['message_id'=>'legacy1','sender_type'=>'User','sender_id'=>'u0Legacy','text'=>'/start']]]);
$recovered=$users->findByMessenger('rubika','u0Legacy');
T::eq((int)$legacy['id'],(int)($recovered['id']??0),'حساب قدیمی چت پس از تأیید getChat بدون ساخت حساب جدید بازیابی شد');
T::eq(3456,(int)($recovered['wallet_balance']??0),'موجودی حساب بازیابی‌شده حفظ شد');
T::eq(null,$users->findByMessenger('rubika','b0Legacy'),'شناسه چت دیگر هویت جداگانه نیست');
AbstractMessengerDriver::setFakeTransport('rubika', static function(string $method,array $params) use (&$calls,&$chatFails):array {
  $calls[]=[$method,$params];
  if ($method==='getChat') return $chatFails ? ['status'=>'ERROR','dev_message'=>'unavailable'] : ['status'=>'OK','data'=>['chat'=>['user_id'=>'u0Actual','first_name'=>'Test']]];
  return ['status'=>'OK','data'=>['message_id'=>'out-'.count($calls)]];
});
$chatFails=true;
$invoke(['update'=>['type'=>'StartedBot','chat_id'=>'b0Unknown','aux_data'=>['start_id'=>'ABC123']]]);
T::eq(null,$users->findByMessenger('rubika','b0Unknown'),'در خطای getChat حساب حدسی ساخته نمی‌شود');
$sent=array_values(array_filter($calls,fn($c)=>$c[0]==='sendMessage'));
T::ok(str_contains((string)end($sent)[1]['text'],'/start ABC123'),'در خطای getChat راهنمای شروع همراه کد دعوت ارائه می‌شود');
// Polling goes through the real cron dispatch, not just the parser.
AbstractMessengerDriver::setFakeTransport('rubika', static function(string $method,array $params) use (&$calls):array {
    $calls[]=[$method,$params];
    if ($method==='getUpdates') return ['status'=>'OK','data'=>['updates'=>[['type'=>'NewMessage','chat_id'=>'b0ActualChat','new_message'=>['message_id'=>'poll1','sender_type'=>'User','sender_id'=>'u0Actual','text'=>'/wallet']]],'next_offset_id'=>'offset-next']];
    return ['status'=>'OK','data'=>['message_id'=>'out-'.count($calls)]];
});
$driver=$registry->driver('rubika');
$driver->syncCommands([['command'=>'/start','description'=>'شروع']]);
$commands=array_values(array_filter($calls,fn($c)=>$c[0]==='setCommands'));
T::eq([['command'=>'start','description'=>'شروع']],end($commands)[1]['bot_commands']??null,'ثبت فهرست دستورات با قالب رسمی bot_commands انجام می‌شود');
$db->update('messenger_connections',['polling_enabled'=>1,'polling_offset'=>null],"platform = 'rubika'");
$beforePoll=count(array_filter($calls,fn($c)=>$c[0]==='sendMessage'));
$cron=(new \RB\Services\CronService($db))->run('messengers');
T::eq(true,$cron['ok'],'دریافت دوره‌ای اجرا شد');
T::eq($beforePoll+1,count(array_filter($calls,fn($c)=>$c[0]==='sendMessage')),'دستور polling از مسیر cron پاسخ می‌گیرد');
T::eq('offset-next',$registry->rawConnection('rubika')['polling_offset'],'نشانه ادامه polling ذخیره می‌شود');
AbstractMessengerDriver::clearFakeTransports();
exit(T::summary());
