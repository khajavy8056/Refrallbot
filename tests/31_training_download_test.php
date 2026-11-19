<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\TrainingDownloadService as Download;
use RB\Services\TrainingVideoService as Video;

T::suite('انتقال ایمن ویدیوی آموزش با گزارش پیشرفت');
$dir = sys_get_temp_dir() . '/rb-training-transfer-' . bin2hex(random_bytes(6));
mkdir($dir, 0775, true);
$video = "\x00\x00\x00\x18ftypisom" . str_repeat('x', 256);
$size = strlen($video); $hash = hash('sha256', $video); $seen = [];
$fake = static function ($url, $write, $progress) use ($video, &$seen, $dir, $size, $hash): array {
    $seen[] = $url;
    $write(substr($video, 0, 100)); $progress();
    $s = (new Download($dir, null, $size, $hash))->status();
    T::eq('running', $s['state'], 'وضعیت حین دریافت قابل مشاهده است');
    T::eq(100, $s['bytes'], 'پیشرفت بر اساس بایت‌های دریافت‌شده است');
    T::eq(false, (new Video($dir))->hasLocalVideo(), 'فایل ناقص هرگز پخش نمی‌شود');
    $write(substr($video, 100)); $progress();
    return ['status'=>200];
};
$svc = new Download($dir, $fake, $size, $hash);
T::eq('idle', $svc->status()['state'], 'شروع با وضعیت آماده');
T::eq('done', $svc->download()['state'], 'دانلود پس از تطبیق اندازه و SHA256 تکمیل شد');
T::eq($video, file_get_contents($dir . '/panel-training.mp4'), 'فایل کامل به مکان محلی منتقل شد');
T::ok(str_starts_with($seen[0], 'https://drive.usercontent.google.com/'), 'آدرس منبع ثابت Drive است');
T::eq('done', $svc->download()['state'], 'فایل محلی موجود دوباره دانلود یا جایگزین نمی‌شود');
unlink($dir . '/panel-training.mp4');
$bad = new Download($dir, static function ($url, $write, $progress): array { $write('<html>login</html>'); $progress(); return ['status'=>200]; }, $size, $hash);
T::eq('error', $bad->download()['state'], 'صفحه ورود یا فایل ناقص رد می‌شود');
T::eq(false, is_file($dir . '/panel-training.mp4'), 'دانلود ناموفق به عنوان ویدیو ذخیره نمی‌شود');
T::eq('error', $bad->status()['state'], 'خطای انتقال برای نمایش باقی می‌ماند');
T::eq(false, is_file($dir . '/.panel-training-download.part'), 'فایل ناقص پاک می‌شود');
$redirect = new Download($dir, static fn()=>['status'=>302,'location'=>'https://evil.example/'], $size, $hash);
T::eq('error', $redirect->download()['state'], 'تغییر مسیر به میزبان غیرمجاز رد می‌شود');
$race = new Download($dir, static function ($url, $write, $progress) use ($video, $dir): array {
    $write($video); $progress();
    file_put_contents($dir . '/panel-training.mp4', "\x00\x00\x00\x18ftypisom" . str_repeat('m', 30));
    return ['status'=>200];
}, $size, $hash);
T::eq('done', $race->download()['state'], 'ویدیوی قرارگرفته توسط مدیر هنگام دانلود پذیرفته می‌شود');
T::ok(str_ends_with(file_get_contents($dir . '/panel-training.mp4'), str_repeat('m', 30)), 'دانلود فایل مدیر را بازنویسی نمی‌کند');
unlink($dir . '/panel-training.mp4');
T::eq(true, Download::safeUrl('https://abc.googleusercontent.com/data'), 'میزبان مورد انتظار گوگل پذیرفته است');
T::eq(false, Download::safeUrl('https://googleusercontent.com.evil.example/'), 'نام دامنه جعلی رد می‌شود');
T::eq(false, Download::safeUrl('http://drive.google.com/a'), 'اتصال غیر امن رد می‌شود');
T::eq(false, Download::safeUrl('https://user@drive.google.com/a'), 'نشانی دارای نام کاربری رد می‌شود');
$confirm = Download::confirmationUrl('<form action="https://evil.example"><input type="hidden" name="uuid" value="abc-123"></form>');
T::ok(is_string($confirm) && str_starts_with($confirm, 'https://drive.usercontent.google.com/download?') && str_contains($confirm, 'uuid=abc-123'), 'فرم تأیید گوگل تنها با آدرس ثابت و توکن امن دنبال می‌شود');
T::eq(null, Download::confirmationUrl('<input name="uuid" value="../bad">'), 'توکن نامعتبر رد می‌شود');
T::ok(Download::confirmationUrl('<input value="abc-123" name="uuid">') !== null, 'ترتیب ویژگی‌های فرم تأیید اهمیتی ندارد');
$attempts = 0;
$confirmSvc = new Download($dir, static function ($url, $write, $progress) use ($video, &$attempts): array {
    $attempts++;
    $write($attempts === 1 ? '<form><input name="uuid" value="abc-123"></form>' : $video);
    $progress(); return ['status'=>200];
}, $size, $hash);
T::eq('done', $confirmSvc->download()['state'], 'پس از صفحه تأیید، ویدیو با هش معتبر دریافت می‌شود');
T::eq(2, $attempts, 'فرم تأیید تنها یک بار دنبال شد');
unlink($dir . '/panel-training.mp4');
$routes = file_get_contents(RB_ROOT . '/src/routes.php');
T::ok(str_contains($routes, "'startDownload'], ['admin', 'csrf']") && str_contains($routes, "'downloadStatus'], ['admin']"), 'مسیرهای انتقال نیازمند ورود و شروع دارای CSRF است');
foreach (glob($dir . '/.*') ?: [] as $file) if (is_file($file)) unlink($file);
rmdir($dir);
exit(T::summary());
