<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\AdminAuth;
use RB\Services\AuditService;
use RB\Services\TrainingVideoService;
use RB\Support\Request;

final class TrainingController extends AdminController
{
    public function index(): void
    {
        $this->render('admin/training/index', ['title' => 'آموزش پنل']);
    }

    public function upload(): void
    {
        if (!AdminAuth::requireSuperAdmin()) { $this->fail('آپلود ویدیو فقط برای مدیر کل مجاز است.', 403); return; }
        try {
            (new TrainingVideoService())->upload(is_array($_FILES['video'] ?? null) ? $_FILES['video'] : []);
            AuditService::log('training.video_uploaded', 'training', null, [], current_admin_id());
            $this->flash('success', 'ویدیو روی هاست ذخیره شد و از این پس از خود هاست پخش می‌شود.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        Request::redirect(url('admin/training'));
    }

    /** Protected streaming and download; range support lets browsers seek without buffering 265 MB. */
    public function video(): void
    {
        $svc = new TrainingVideoService();
        if (!$svc->hasLocalVideo()) { http_response_code(404); echo 'ویدیوی محلی یافت نشد.'; return; }
        $handle = @fopen($svc->path(), 'rb');
        if ($handle === false) { http_response_code(404); return; }
        $stat = fstat($handle);
        $size = (int) ($stat['size'] ?? 0);
        $range = TrainingVideoService::range($size, (string) ($_SERVER['HTTP_RANGE'] ?? ''));
        http_response_code($range['status']);
        header('Content-Type: video/mp4');
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        if ($range['status'] === 416) {
            header('Content-Range: bytes */' . $size);
            fclose($handle);
            return;
        }
        $length = $range['end'] - $range['start'] + 1;
        if ($range['status'] === 206) header('Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $size);
        header('Content-Length: ' . $length);
        header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="panel-training.mp4"');
        // Avoid holding the PHP session lock while a long video is being streamed.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        @set_time_limit(0);
        fseek($handle, $range['start']);
        while ($length > 0 && !feof($handle)) {
            $chunk = fread($handle, min(262144, $length));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $length -= strlen($chunk);
            if (connection_aborted()) break;
        }
        fclose($handle);
    }
}
