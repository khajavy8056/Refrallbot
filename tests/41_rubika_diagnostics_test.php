<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib.php';
use RB\Controllers\Admin\MessengerController;
use RB\Controllers\Webhook\MessengerWebhookController;
use RB\Messengers\AbstractMessengerDriver;
use RB\Messengers\MessengerRegistry;
use RB\Services\CronService;
use RB\Services\RubikaDiagnostics;
use RB\Support\Database;

T::suite('عیب‌یابی ایمن و دقیق مسیر روبیکا');
$db=Database::instance(); $_SERVER['HTTP_HOST']='diagnostics.example.com'; $_SERVER['HTTPS']='on';
$mode='ok'; $calls=[]; $token='RubikaDiagnosticFakeTokenOnly_12345';
AbstractMessengerDriver::setFakeTransport('rubika', static function(string $method,array $params) use (&$mode,&$calls): array {
    $calls[]=[$method,$params];
    if ($method==='getMe') {
        if ($mode==='bad_http') return ['status'=>'OK','_http_code'=>500,'data'=>['bot'=>['bot_id'=>'b0fake']]];
        if ($mode==='tls') return ['_transport_ok'=>false,'_http_code'=>0,'_transport_kind'=>'tls','_error'=>'secret error details'];
        if ($mode==='bad_status') return ['status'=>'ERROR','ok'=>true,'_http_code'=>200,'dev_message'=>'bad','data'=>[]];
        return ['status'=>'OK','_http_code'=>200,'data'=>['bot'=>['bot_id'=>'b0bot','username'=>'test_bot']]];
    }
    if ($method==='updateBotEndpoints' && $mode==='setup_fail_inline' && ($params['type']??'')==='ReceiveInlineMessage') return ['status'=>'ERROR','status_det'=>'INVALID_INPUT','_http_code'=>200];
    if ($method==='getChat') {
        if ($mode==='throw') throw new RuntimeException('private payload must not be stored');
        return ['status'=>'OK','_http_code'=>200,'data'=>['chat'=>['user_id'=>'u0secretUser','first_name'=>'Test']]];
    }
    if ($method==='getUpdates') return ['status'=>'OK','_http_code'=>200,'data'=>['updates'=>[['type'=>'NewMessage','chat_id'=>'b0secretChat','new_message'=>['message_id'=>'poll-1','sender_id'=>'u0secretUser','text'=>'/wallet']]],'next_offset_id'=>'next-1']];
    if ($method==='sendMessage' && $mode==='send_fail') return ['status'=>'ERROR','status_det'=>'INVALID_INPUT','error_code'=>123,'_http_code'=>200,'dev_message'=>'TOKEN_AND_PRIVATE_TEXT','data'=>[]];
    if ($method==='sendMessage' && $mode==='missing_id') return ['status'=>'OK','_http_code'=>200,'data'=>[]];
    return ['status'=>'OK','_http_code'=>200,'data'=>['message_id'=>'out-1']];
});
$reg=new MessengerRegistry($db); $reg->saveCredentials('rubika',['bot_token'=>$token,'is_enabled'=>1]);
$test=$reg->testConnection('rubika');
T::eq(true,$test['ok'],'getMe سالم موفق گزارش می‌شود');
T::ok(str_contains($test['message'],'فقط getMe'),'نتیجه تست موفق ادعای دریافت وب‌هوک نمی‌کند');
$entry=$db->first('SELECT * FROM rubika_diagnostics ORDER BY id DESC LIMIT 1');
T::eq('api_test',$entry['source'],'تست API جدا از رویدادهای وب‌هوک ثبت شد');
T::eq(200,(int)$entry['http_code'],'کد HTTP در تست ثبت شد');
$mode='bad_http'; $bad=$reg->testConnection('rubika');
T::eq(false,$bad['ok'],'HTTP خطا با status OK موفق محسوب نمی‌شود');
$mode='bad_status'; $bad=$reg->testConnection('rubika');
T::eq(false,$bad['ok'],'status خطا حتی با ok متناقض موفق محسوب نمی‌شود');
$mode='tls'; $bad=$reg->testConnection('rubika');
T::eq(false,$bad['ok'],'خطای شبکه تست اتصال ناموفق ثبت می‌شود');
T::eq('TLS',$db->value('SELECT api_detail FROM rubika_diagnostics ORDER BY id DESC LIMIT 1'),'نوع خطای گواهی بدون متن خام ثبت شد');
$mode='ok'; $reg->testConnection('rubika');
$mode='setup_fail_inline'; $setup=$reg->registerWebhook('rubika');
T::eq(false,$setup['ok'],'خرابی ثبت یکی از endpointها موفق گزارش نمی‌شود');
$setupRows=$db->all("SELECT event_type,result FROM rubika_diagnostics WHERE source='endpoint_setup' ORDER BY id DESC LIMIT 2");
T::eq('error',$setupRows[0]['result']??null,'خطای ReceiveInlineMessage جداگانه ثبت می‌شود');
T::eq('ok',$setupRows[1]['result']??null,'موفقیت ReceiveUpdate مستقل ثبت می‌شود');
$mode='ok'; $reg->registerWebhook('rubika');
$secret=(string)$reg->rawConnection('rubika')['webhook_secret'];
$wh=new MessengerWebhookController();
$invoke=static function(string $secret, string $body) use ($wh):array { ob_start(); $wh->processPlatformWebhook('rubika',$secret,'receiveUpdate',$body); return json_decode((string)ob_get_clean(),true) ?: []; };
$last=static fn():array => $db->first('SELECT * FROM rubika_diagnostics ORDER BY id DESC LIMIT 1') ?: [];
$count=(int)$db->value('SELECT COUNT(*) FROM rubika_diagnostics');
$invoke('incorrect',$token);
T::eq($count,(int)$db->value('SELECT COUNT(*) FROM rubika_diagnostics'),'درخواست فاقد راز صحیح نمی‌تواند جدول گزارش را پر کند');
$invoke($secret,'{invalid'); T::eq('invalid_json',$last()['result'],'بدنه JSON نامعتبر تشخیص داده شد');
$invoke($secret,json_encode(['update'=>['type'=>'UnsupportedPrivatePayload','chat_id'=>'b0secretChat']]));
T::eq('ignored',$last()['result'],'رویداد پشتیبانی‌نشده قابل تشخیص است');
T::eq('Other',$last()['event_type'],'نوع ناشناس بدون ذخیره محتوای دلخواه ثبت می‌شود');
$start=['update'=>['type'=>'NewMessage','chat_id'=>'b0secretChat','new_message'=>['message_id'=>'start-1','sender_type'=>'User','sender_id'=>'u0secretUser','text'=>'/start']]];
$invoke($secret,json_encode($start)); $d=$last();
T::eq('processed',$d['result'],'پردازش موفق رویداد ثبت شد');
T::eq('NewMessage',$d['event_type'],'نوع مستند پیام ثبت شد');
T::eq('ok',$d['send_result'],'پذیرش پیام دارای شناسه در API جداگانه ثبت شد');
T::eq(200,(int)$d['http_code'],'کد HTTP ارسال ثبت شد');
$invoke($secret,json_encode($start)); T::eq('duplicate',$last()['result'],'درخواست تکراری به‌اشتباه پاسخ موفق محسوب نشد');
$legacy=['update'=>['type'=>'NewMessage','chat_id'=>'b0secretChat','new_message'=>['message_id'=>'legacy-menu','sender_type'=>'User','sender_id'=>'u0secretUser','text'=>'','aux_data'=>['button_id'=>'rk_5']]]];
$invoke($secret,json_encode($legacy)); $sent=array_values(array_filter($calls,fn($c)=>$c[0]==='sendMessage'));
T::ok(str_contains(end($sent)[1]['text'],'منوی شما به‌روزرسانی شد'),'دکمه قدیمی بدون متن منو را نوسازی می‌کند و بی‌پاسخ نمی‌ماند');
T::eq('ok',$last()['send_result'],'نوسازی منوی قدیمی در گزارش ارسال ثبت می‌شود');

$unknown=['inline_message'=>['chat_id'=>'b0privateOther','sender_id'=>'u0privateOther','message_id'=>'i1','aux_data'=>['button_id'=>'menu:wallet']]];
$invoke($secret,json_encode($unknown)); $d=$last(); $sent=array_values(array_filter($calls,fn($c)=>$c[0]==='sendMessage'));
T::eq('user_unknown',$d['reason'],'علت دکمه برای کاربر ناشناخته ثبت شد');
T::ok(str_contains(end($sent)[1]['text'],'/start'),'کلیک کاربر ناشناخته به جای سکوت راهنمای شروع می‌گیرد');
$mode='send_fail'; $start['update']['new_message']['message_id']='send-fail';
$invoke($secret,json_encode($start)); $d=$last();
T::eq('processed',$d['result'],'پایان هندلر با نتیجه ارسال اشتباه گرفته نمی‌شود');
T::eq('error',$d['send_result'],'خطای sendMessage ثبت شد');
T::eq('ERROR',$d['api_status'],'وضعیت خطای API ثبت شد');
T::eq('INVALID_INPUT',$d['api_detail'],'جزئیات خطای مجاز API ثبت می‌شود');
T::eq(123,(int)$d['api_code'],'کد عددی خطا برای تشخیص ثبت می‌شود');
$mode='missing_id'; $start['update']['new_message']['message_id']='send-missing';
$invoke($secret,json_encode($start)); T::eq('unconfirmed',$last()['send_result'],'پاسخ بدون message_id تحویل تأییدشده نیست');
$mode='throw'; $invoke($secret,json_encode(['update'=>['type'=>'StartedBot','chat_id'=>'b0Throw']]));
T::eq('handler_error',$last()['result'],'استثنا و rollback در گزارش ثبت می‌شود');
$mode='ok';
RubikaDiagnostics::begin($db,'webhook','NewMessage');
RubikaDiagnostics::target('b0targetNeverStored');
$reg->driver('rubika')->sendMessage('b0DifferentUserNeverStored', 'اعلان نامرتبط');
RubikaDiagnostics::finish('processed');
T::eq('none',$last()['send_result'],'ارسال اعلان به شخص دیگر با پاسخ به چت درخواست‌دهنده اشتباه گرفته نمی‌شود');
$db->update('messenger_connections',['polling_enabled'=>1,'polling_offset'=>null],"platform = 'rubika'");
(new CronService($db))->run('messengers');
T::ok((int)$db->value("SELECT COUNT(*) FROM rubika_diagnostics WHERE source='poll_check' AND result='ok'")>0,'اجرای getUpdates در گزارش پولینگ ثبت می‌شود');
T::ok((int)$db->value("SELECT COUNT(*) FROM rubika_diagnostics WHERE source='polling' AND result='processed'")>0,'پردازش رویداد polling ثبت می‌شود');
$role=$db->insert('admin_roles',['name'=>'Admin','slug'=>'superadmin','permissions'=>'["*"]','created_at'=>now(),'updated_at'=>now()]);
$admin=$db->insert('admins',['name'=>'Admin','username'=>'diag-admin','password_hash'=>password_hash('test',PASSWORD_DEFAULT),'role_id'=>$role,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
$_SESSION['admin_id']=$admin; $_SESSION['admin_last_seen']=time(); $_SESSION['_csrf_token']=bin2hex(random_bytes(32));
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/admin/messengers'; $_GET=['tab'=>'rubika'];
ob_start(); (new MessengerController())->index(); $html=(string)ob_get_clean();
T::ok(str_contains($html,'عیب‌یابی دریافت و پاسخ روبیکا'),'بخش گزارش در پنل نمایش داده می‌شود');
T::ok(str_contains($html,'فقط getMe'),'محدودیت تست اتصال در پنل روشن است');
preg_match('~<textarea id="rubika-safe-report"[^>]*>(.*?)</textarea>~s',$html,$m); $report=html_entity_decode($m[1]??'',ENT_QUOTES,'UTF-8');
$dump=json_encode($db->all('SELECT * FROM rubika_diagnostics'),JSON_UNESCAPED_UNICODE);
foreach ([$token,$secret,'b0secretChat','u0secretUser','TOKEN_AND_PRIVATE_TEXT','private payload must not be stored','b0targetNeverStored','b0DifferentUserNeverStored'] as $private) {
    T::eq(false,str_contains($dump,$private) || str_contains($report,$private),'اطلاعات محرمانه در گزارش ساختاریافته ذخیره نشده است');
}
for ($i=0;$i<605;$i++) { RubikaDiagnostics::begin($db,'webhook'); RubikaDiagnostics::finish('ignored'); }
T::ok((int)$db->value('SELECT COUNT(*) FROM rubika_diagnostics')<=549,'حجم گزارش خودکار محدود می‌شود');
AbstractMessengerDriver::clearFakeTransports();
exit(T::summary());
