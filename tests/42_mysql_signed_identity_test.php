<?php
declare(strict_types=1);
// Opt-in: only use a disposable, empty MySQL database. Never point at production.
require_once __DIR__ . '/lib.php';
T::suite('ارتقای شناسه و پردازش روبیکا روی MySQL واقعی');
// GitHub-hosted Ubuntu runners include a stopped MySQL service. Enable it only
// on those disposable runners; normal local runs remain explicit opt-in.
if (getenv('RB_MYSQL_INTEGRATION') !== '1' && getenv('GITHUB_ACTIONS') === 'true' && str_starts_with((string)getenv('ImageOS'), 'ubuntu')) {
    exec('sudo systemctl start mysql.service 2>&1', $startup, $exitCode);
    if ($exitCode !== 0) throw new RuntimeException('Could not start CI MySQL: ' . implode(' ', $startup));
    $admin = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'root', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $admin->exec('CREATE DATABASE refrallbot_test_disposable CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    putenv('RB_MYSQL_INTEGRATION=1');
    putenv('RB_MYSQL_DATABASE=refrallbot_test_disposable');
}
if (getenv('RB_MYSQL_INTEGRATION') !== '1') {
    echo "SKIP: requires disposable MySQL database (RB_MYSQL_INTEGRATION=1).\n";
    exit(T::summary());
}
require_once dirname(__DIR__) . '/src/bootstrap.php';
use RB\Support\Database;
use RB\Support\Migrator;
use RB\Support\SchemaBuilder;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Services\BroadcastService;
use RB\Bot\BotSession;
use RB\Messengers\AbstractMessengerDriver;
use RB\Messengers\MessengerRegistry;
use RB\Controllers\Webhook\MessengerWebhookController;

$name = (string)getenv('RB_MYSQL_DATABASE');
if ($name !== 'refrallbot_test_disposable') throw new RuntimeException('Disposable test database required');
$db = new Database(['driver'=>'mysql','host'=>getenv('RB_MYSQL_HOST') ?: '127.0.0.1', 'port'=>'3306', 'database'=>$name,'username'=>getenv('RB_MYSQL_USER') ?: 'root','password'=>getenv('RB_MYSQL_PASSWORD') ?: 'root']);
Database::setInstance($db);
$pdo=$db->pdo();
if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn() !== 0) throw new RuntimeException('Test database must be empty');
$tmp=sys_get_temp_dir().'/rb-mysql-migrations-'.bin2hex(random_bytes(6)); mkdir($tmp);
foreach (glob(RB_ROOT.'/database/migrations/*.php') as $f) if (basename($f) < '011_') copy($f,$tmp.'/'.basename($f));
(new Migrator($db,$tmp))->run();
foreach (['users','bot_sessions','broadcast_targets'] as $t) $pdo->exec("ALTER TABLE `{$t}` MODIFY `telegram_id` BIGINT UNSIGNED NOT NULL");
$users=new UserService($db);
$old=$users->registerFromTelegram(['id'=>760045001,'first_name'=>'Old'])['user'];
(new WalletService($db))->credit((int)$old['id'],'adjustment',12000,['idempotency_key'=>'mysql-signed-old']);
(new BotSession($db))->setStep((int)$old['telegram_id'],(int)$old['id'],'old_step');
// Strict mode reproduces the production error before migration.
$threw=false;
try { $users->registerFromMessenger('rubika',['id'=>'u0Before','chat_id'=>'b0Before']); } catch (PDOException $e) { $threw=(int)($e->errorInfo[1]??0)===1264; }
T::ok($threw,'تعریف قدیمی UNSIGNED همان خطای ۱۲۶۴ را بازتولید می‌کند');
// Refuse to narrow unusually large unsigned IDs, before changing any table.
$pdo->exec("INSERT INTO bot_sessions (telegram_id, created_at) VALUES (9223372036854775808, NOW())");
$schema=new SchemaBuilder('mysql'); (require RB_ROOT.'/database/migrations/011_signed_messenger_sentinel.php')($schema);
T::throws(static fn()=>$schema->runAfter($db),'پیش از تغییر، شناسه خارج از محدوده متوقف می‌کند','bot_sessions.telegram_id');
T::ok(str_contains(strtolower((string)$pdo->query("SHOW COLUMNS FROM users LIKE 'telegram_id'")->fetch(PDO::FETCH_ASSOC)['Type']),'unsigned'),'در صورت مقدار خارج از بازه هیچ جدولی تغییر نکرد');
$pdo->exec('DELETE FROM bot_sessions WHERE telegram_id = 9223372036854775808');
copy(RB_ROOT.'/database/migrations/011_signed_messenger_sentinel.php',$tmp.'/011_signed_messenger_sentinel.php');
$upgrade=(new Migrator($db,$tmp))->run();
T::eq(['011_signed_messenger_sentinel.php'],$upgrade['applied'],'migration ارتقا اعمال شد');
T::eq([],(new Migrator($db,$tmp))->run()['applied'],'اجرای مجدد ایمن است');
foreach (['users','bot_sessions','broadcast_targets'] as $t) {
 $col=$pdo->query("SHOW COLUMNS FROM `{$t}` LIKE 'telegram_id'")->fetch(PDO::FETCH_ASSOC);
 T::ok((bool)preg_match('/^bigint(?:\(\d+\))?$/i',(string)$col['Type']),"{$t} شناسه signed دارد");
}
T::eq(12000,(int)$db->value('SELECT wallet_balance FROM users WHERE id = :id',['id'=>$old['id']]),'موجودی قدیمی حفظ شد');
T::eq(760045001,(int)$db->value('SELECT telegram_id FROM users WHERE id = :id',['id'=>$old['id']]),'شناسه تلگرام قدیمی حفظ شد');
T::eq('old_step',(new BotSession($db))->get(760045001)['step']??null,'نشست قدیمی حفظ شد');
$calls=[];
AbstractMessengerDriver::setFakeTransport('rubika', static function(string $method,array $params) use (&$calls):array {
 $calls[]=[$method,$params];
 if ($method==='getMe') return ['status'=>'OK','data'=>['bot'=>['bot_id'=>'b0Bot','username'=>'mysql_test_bot']]];
 if ($method==='getChat') return ['status'=>'OK','data'=>['chat'=>['user_id'=>'u0Real','first_name'=>'Test']]];
 return ['status'=>'OK','data'=>['message_id'=>'out-'.count($calls)]];
});
$registry=new MessengerRegistry($db); $registry->saveCredentials('rubika',['bot_token'=>'RubikaFakeTokenTestOnly_0123456789','is_enabled'=>1]);
$secret=(string)$registry->rawConnection('rubika')['webhook_secret'];
$controller=new MessengerWebhookController();
$invoke=static function(array $payload) use($controller,$secret):array {ob_start();$controller->processPlatformWebhook('rubika',$secret,'receiveUpdate',json_encode($payload));return json_decode((string)ob_get_clean(),true) ?: [];};
$r=$invoke(['update'=>['type'=>'StartedBot','chat_id'=>'b0Real']]);
T::eq(1,$r['processed']??null,'رویداد StartedBot پس از ارتقا پردازش شد');
$user=$users->findByMessenger('rubika','u0Real');
T::ok($user!==null && (int)$user['telegram_id']<0,'حساب روبیکا با شناسه منفی ساخته شد');
T::ok(count(array_filter($calls,fn($c)=>$c[0]==='sendMessage'))>0,'پاسخ شروع به API ساختگی ارسال شد');
if ($user!==null) {
 (new BotSession($db))->setStep((int)$user['telegram_id'],(int)$user['id'],'rubika_step');
 T::eq('rubika_step',(new BotSession($db))->get((int)$user['telegram_id'])['step']??null,'نشست چندمرحله‌ای روبیکا شناسه منفی را می‌پذیرد');
 $campaign=(new BroadcastService($db))->createCampaign(['title'=>'test','message_text'=>'test','segment'=>'all','segment_params'=>[]],null);
 T::eq(1,(int)$db->value('SELECT COUNT(*) FROM broadcast_targets WHERE campaign_id = :c AND user_id = :u',['c'=>$campaign['id'],'u'=>$user['id']]),'صف ارسال همگانی کاربر روبیکا ذخیره شد');
}
$r=$invoke(['update'=>['type'=>'NewMessage','chat_id'=>'b0Real','new_message'=>['message_id'=>'msg-wallet','sender_type'=>'User','sender_id'=>'u0Real','text'=>'','aux_data'=>['button_id'=>'menu:wallet']]]]);
T::eq(1,$r['processed']??null,'کلیک دکمه با دیتابیس واقعی پردازش شد');
T::ok(str_contains((string)end($calls)[1]['text'],'کیف پول'),'پاسخ دکمه به transport ساختگی ارسال شد');
foreach (glob($tmp.'/*.php') as $f) unlink($f); rmdir($tmp);
exit(T::summary());
