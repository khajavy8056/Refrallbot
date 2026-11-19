<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\TrainingVideoService as Video;
use RB\Controllers\Admin\TrainingController;

T::suite('آموزش پنل و فایل ویدیوی محافظت‌شده');
$dir = sys_get_temp_dir() . '/rb-training-' . bin2hex(random_bytes(8));
mkdir($dir, 0775, true);
$service = new Video($dir);
T::eq(false, $service->hasLocalVideo(), 'در نبود فایل از Drive استفاده می‌شود');
$valid = $dir . '/source.mp4';
file_put_contents($valid, "\x00\x00\x00\x18ftypisom" . str_repeat('A', 50));
$invalid = $dir . '/bad.mp4';
file_put_contents($invalid, 'not a video, even with an mp4 name');
$service->upload(['name'=>'video.mp4','tmp_name'=>$valid,'error'=>UPLOAD_ERR_OK]);
T::ok($service->hasLocalVideo(), 'فایل MP4 معتبر در پوشه محافظت‌شده ذخیره شد');
$before = file_get_contents($service->path());
try { $service->upload(['name'=>'bad.mp4','tmp_name'=>$invalid,'error'=>UPLOAD_ERR_OK]); T::ok(false, 'فایل نامعتبر رد شود'); }
catch (RuntimeException $e) { T::ok(true, 'فایل نامعتبر رد شد'); }
T::eq($before, file_get_contents($service->path()), 'در آپلود ناموفق فایل قبلی حفظ می‌شود');
try { $service->upload(['error'=>UPLOAD_ERR_INI_SIZE]); T::ok(false, 'خطای سقف آپلود'); }
catch (RuntimeException $e) { T::ok(str_contains($e->getMessage(), 'سقف'), 'خطای سقف آپلود واضح است'); }
T::eq(['status'=>200,'start'=>0,'end'=>99,'size'=>100], Video::range(100, ''), 'دریافت کامل');
T::eq(['status'=>206,'start'=>10,'end'=>19,'size'=>100], Video::range(100, 'bytes=10-19'), 'دریافت بازه');
T::eq(['status'=>206,'start'=>90,'end'=>99,'size'=>100], Video::range(100, 'bytes=-10'), 'بازه انتهایی');
T::eq(['status'=>206,'start'=>90,'end'=>99,'size'=>100], Video::range(100, 'bytes=90-'), 'بازه باز');
T::eq(416, Video::range(100, 'bytes=100-')['status'], 'بازه خارج از فایل رد می‌شود');
T::eq(416, Video::range(100, 'bytes=0-1,3-4')['status'], 'بازه چندگانه رد می‌شود');
T::eq(416, Video::range(100, 'bytes=-0')['status'], 'بازه صفر رد می‌شود');
$routes = file_get_contents(RB_ROOT . '/src/routes.php');
T::ok(str_contains($routes, "'video'], ['admin']") && str_contains($routes, "'upload'], ['admin', 'csrf']"), 'پخش نیازمند ورود و بارگذاری نیازمند ورود و CSRF است');
T::ok(str_contains(file_get_contents(RB_ROOT . '/views/layouts/app.php'), "url('admin/training')"), 'دکمه در قالب مشترک پنل است');
unlink($service->path()); unlink($valid); unlink($invalid); rmdir($dir);
exit(T::summary());
