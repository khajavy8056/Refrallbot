<?php
declare(strict_types=1);

namespace RB\Services;

/** Video stays in protected storage and is never part of an update package. */
final class TrainingVideoService
{
    public const DRIVE_ID = '1bK3sYKtD5XOi7L_aTE7abuDIiiSohI5q';
    public const MAX_BYTES = 524288000; // 500 MiB
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim($directory ?? (RB_STORAGE . '/uploads'), '/\\');
    }

    public function path(): string { return $this->directory . '/panel-training.mp4'; }
    public static function previewUrl(): string { return 'https://drive.google.com/file/d/' . self::DRIVE_ID . '/preview'; }
    public static function downloadUrl(): string { return 'https://drive.google.com/uc?export=download&id=' . self::DRIVE_ID; }
    public static function viewUrl(): string { return 'https://drive.google.com/file/d/' . self::DRIVE_ID . '/view'; }

    /** Reject empty files, symlinks and non-MP4 content even when placed via hosting file manager. */
    public function hasLocalVideo(): bool
    {
        $path = $this->path();
        if (is_link($this->directory) || is_link($path) || !is_file($path) || !is_readable($path)) return false;
        $size = @filesize($path);
        return is_int($size) && $size > 12 && $size <= self::MAX_BYTES && self::isMp4($path);
    }

    public static function isMp4(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) return false;
        $head = fread($handle, 12);
        fclose($handle);
        return is_string($head) && strlen($head) === 12 && substr($head, 4, 4) === 'ftyp';
    }

    /** Store only a validated HTTP upload. A failed replacement leaves the old file intact. */
    public function upload(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'حجم فایل از سقف آپلود PHP بیشتر است؛ از راهنمای انتقال دستی به هاست استفاده کنید.'
                : 'آپلود فایل کامل نشد؛ فایل MP4 را دوباره انتخاب کنید.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? '');
        $testLocal = defined('RB_TEST_MODE') && RB_TEST_MODE && PHP_SAPI === 'cli';
        if ($tmp === '' || (!$testLocal && !is_uploaded_file($tmp)) || !is_file($tmp) || is_link($tmp)) {
            throw new \RuntimeException('فایل آپلودشده معتبر نیست.');
        }
        $size = @filesize($tmp);
        if (!is_int($size) || $size <= 12 || $size > self::MAX_BYTES || !preg_match('/\.mp4$/i', $name) || !self::isMp4($tmp)) {
            throw new \RuntimeException('فقط فایل MP4 معتبر تا حجم ۵۰۰ مگابایت پذیرفته می‌شود.');
        }
        if (is_link($this->directory) || (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory))) {
            throw new \RuntimeException('پوشه ذخیره ویدیو قابل ساخت نیست.');
        }
        if (is_link($this->path())) throw new \RuntimeException('مسیر ویدیو معتبر نیست.');
        $temporary = $this->directory . '/.panel-training-' . bin2hex(random_bytes(8)) . '.tmp';
        $moved = $testLocal ? @copy($tmp, $temporary) : @move_uploaded_file($tmp, $temporary);
        if (!$moved) throw new \RuntimeException('انتقال ویدیو به هاست ناموفق بود؛ فضای خالی و دسترسی پوشه را بررسی کنید.');
        @chmod($temporary, 0640);
        if (!@rename($temporary, $this->path())) {
            @unlink($temporary);
            throw new \RuntimeException('ذخیره نهایی ویدیو ناموفق بود؛ فایل قبلی حفظ شد.');
        }
    }

    /** Parse one HTTP byte range; multi-ranges and unsatisfiable ranges return 416. */
    public static function range(int $size, string $header): array
    {
        if ($size <= 0) return ['status' => 416, 'start' => 0, 'end' => -1, 'size' => $size];
        if ($header === '') return ['status' => 200, 'start' => 0, 'end' => $size - 1, 'size' => $size];
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) || ($m[1] === '' && $m[2] === '')) {
            return ['status' => 416, 'start' => 0, 'end' => -1, 'size' => $size];
        }
        if ($m[1] === '') {
            $suffix = (int) $m[2];
            if ($suffix <= 0) return ['status' => 416, 'start' => 0, 'end' => -1, 'size' => $size];
            $start = max(0, $size - $suffix);
            $end = $size - 1;
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
            if ($start >= $size || $end < $start) return ['status' => 416, 'start' => 0, 'end' => -1, 'size' => $size];
        }
        return ['status' => 206, 'start' => $start, 'end' => $end, 'size' => $size];
    }
}
