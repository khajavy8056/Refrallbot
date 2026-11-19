<?php
declare(strict_types=1);

namespace RB\Services;

/** Optional, independent transfer: never makes a software update fail. */
final class TrainingDownloadService
{
    public const SIZE = 277471460;
    public const SHA256 = '498cfcc8feead5e337dad678b8b450c30d055f1c26843f0035a2ee2110047565';
    private string $dir;
    private int $expectedSize;
    private string $expectedHash;
    private $transport;

    /** Transport and expected file overrides are reserved for isolated tests. */
    public function __construct(?string $dir = null, ?callable $transport = null, ?int $expectedSize = null, ?string $expectedHash = null)
    {
        if (($dir !== null || $transport !== null || $expectedSize !== null || $expectedHash !== null) && !(defined('RB_TEST_MODE') && RB_TEST_MODE && PHP_SAPI === 'cli')) {
            throw new \RuntimeException('تنظیمات انتقال نامعتبر است.');
        }
        $this->dir = rtrim($dir ?? RB_STORAGE . '/uploads', '/\\');
        $this->transport = $transport;
        $this->expectedSize = $expectedSize ?? self::SIZE;
        $this->expectedHash = $expectedHash ?? self::SHA256;
    }

    private function file(string $name): string { return $this->dir . '/' . $name; }
    private function stateFile(): string { return $this->file('.panel-training-download.json'); }
    private function video(): TrainingVideoService { return new TrainingVideoService($this->dir); }

    public function status(): array
    {
        if ($this->video()->hasLocalVideo()) return ['state'=>'done', 'bytes'=>$this->expectedSize, 'total'=>$this->expectedSize];
        $path = $this->stateFile();
        $data = !is_link($this->dir) && !is_link($path) && is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        if (!is_array($data) || !in_array($data['state'] ?? '', ['running','error'], true)) return ['state'=>'idle', 'bytes'=>0, 'total'=>$this->expectedSize];
        if ($data['state'] === 'running' && time() - (int) ($data['updated'] ?? 0) > 180) {
            return ['state'=>'error', 'bytes'=>(int) ($data['bytes'] ?? 0), 'total'=>$this->expectedSize, 'message'=>'انتقال متوقف شد؛ دوباره تلاش کنید.'];
        }
        return ['state'=>$data['state'], 'bytes'=>max(0, min($this->expectedSize, (int) ($data['bytes'] ?? 0))), 'total'=>$this->expectedSize, 'message'=>(string) ($data['message'] ?? '')];
    }

    private function saveState(string $state, int $bytes, string $message = ''): void
    {
        $path = $this->stateFile();
        if (is_link($path)) throw new \RuntimeException('مسیر وضعیت انتقال معتبر نیست.');
        $tmp = $this->file('.panel-training-state-' . bin2hex(random_bytes(6)) . '.tmp');
        $json = json_encode(['state'=>$state,'bytes'=>$bytes,'updated'=>time(),'message'=>$message], JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('ثبت وضعیت انتقال ممکن نشد.');
        }
    }

    /** Locks protect concurrent administrators; download remains private until complete and verified. */
    public function download(): array
    {
        if ($this->video()->hasLocalVideo()) return $this->status();
        if (is_link($this->dir) || (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir))) throw new \RuntimeException('پوشه storage/uploads قابل ساخت نیست.');
        $lockPath = $this->file('.panel-training-download.lock');
        $partPath = $this->file('.panel-training-download.part');
        if (is_link($lockPath) || is_link($partPath) || is_link($this->video()->path())) throw new \RuntimeException('مسیر ذخیره ویدیو معتبر نیست.');
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) throw new \RuntimeException('پوشه ذخیره ویدیو قابل نوشتن نیست.');
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return $this->status(); }
        $out = null;
        try {
            if ($this->video()->hasLocalVideo()) return $this->status();
            if (function_exists('disk_free_space')) {
                $free = @disk_free_space($this->dir);
                if ($free !== false && $free < $this->expectedSize + 10485760) throw new \RuntimeException('فضای خالی هاست برای ویدیو کافی نیست.');
            }
            $out = @fopen($partPath, 'wb');
            if ($out === false) throw new \RuntimeException('ساخت فایل موقت ویدیو ممکن نشد.');
            @chmod($partPath, 0640);
            $this->saveState('running', 0);
            $bytes = 0;
            $lastUpdate = 0;
            $write = function (string $chunk) use ($out, &$bytes): int {
                $length = strlen($chunk);
                if ($bytes + $length > $this->expectedSize) return 0;
                $written = fwrite($out, $chunk);
                if ($written === false) return 0;
                $bytes += $written;
                return $written;
            };
            $progress = function () use (&$bytes, &$lastUpdate): void {
                if (time() !== $lastUpdate) { $lastUpdate = time(); $this->saveState('running', $bytes); }
            };
            $url = 'https://drive.usercontent.google.com/download?id=' . TrainingVideoService::DRIVE_ID . '&export=download&confirm=t';
            for ($i = 0; $i < 6; $i++) {
                if (!self::safeUrl($url)) throw new \RuntimeException('مسیر دانلود Drive معتبر نیست.');
                ftruncate($out, 0); rewind($out); $bytes = 0;
                $response = $this->transport ? ($this->transport)($url, $write, $progress) : $this->request($url, $write, $progress);
                $status = (int) ($response['status'] ?? 0);
                if (in_array($status, [301,302,303,307,308], true) && !empty($response['location'])) {
                    $next = (string) $response['location'];
                    if (str_starts_with($next, '/') && !str_starts_with($next, '//')) {
                        $parts = parse_url($url);
                        $next = 'https://' . ($parts['host'] ?? '') . $next;
                    }
                    $url = $next;
                    continue;
                }
                if (!empty($response['error']) || $status !== 200) throw new \RuntimeException('دریافت از Google Drive ممکن نشد؛ دسترسی عمومی فایل، اتصال هاست و محدودیت دانلود گوگل را بررسی کنید.');
                // Some Drive downloads return a virus-scan confirmation form. Only
                // take its opaque token; never follow an arbitrary form action.
                if ($bytes > 0 && $bytes <= 65536) {
                    fflush($out);
                    $confirmation = self::confirmationUrl((string) @file_get_contents($partPath, false, null, 0, 65536));
                    if ($confirmation !== null && $confirmation !== $url) { $url = $confirmation; continue; }
                }
                break;
            }
            fflush($out); fclose($out); $out = null;
            clearstatcache(true, $partPath);
            if (($status ?? 0) !== 200 || $bytes !== $this->expectedSize || !TrainingVideoService::isMp4($partPath) || !hash_equals($this->expectedHash, (string) @hash_file('sha256', $partPath))) {
                throw new \RuntimeException('فایل دریافتی ناقص یا نامعتبر است؛ دسترسی فایل Drive و فضای هاست را بررسی کنید.');
            }
            // An administrator may have uploaded a video while the download ran.
            // A same-filesystem hard link publishes atomically without overwriting it.
            if (is_link($this->video()->path())) throw new \RuntimeException('مسیر ویدیو معتبر نیست.');
            $published = @link($partPath, $this->video()->path());
            // PHP-WASM's virtual filesystem does not implement hard links.
            if (!$published && defined('RB_TEST_MODE') && RB_TEST_MODE && PHP_SAPI === 'cli' && PHP_OS_FAMILY === 'Unknown' && !file_exists($this->video()->path())) {
                $published = @rename($partPath, $this->video()->path());
            }
            if (!$published && !$this->video()->hasLocalVideo()) {
                throw new \RuntimeException('ذخیره نهایی ویدیو ممکن نشد؛ دسترسی پوشه را بررسی کنید.');
            }
            @unlink($partPath);
            @unlink($this->stateFile());
            return $this->status();
        } catch (\Throwable $e) {
            if (is_resource($out)) fclose($out);
            @unlink($partPath);
            try { $this->saveState('error', 0, $e->getMessage()); } catch (\Throwable) {}
            return ['state'=>'error', 'bytes'=>0, 'total'=>$this->expectedSize, 'message'=>$e->getMessage()];
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public static function confirmationUrl(string $html): ?string
    {
        if (!preg_match_all('/<input\b[^>]*>/i', $html, $inputs)) return null;
        $uuid = null;
        foreach ($inputs[0] as $input) {
            if (preg_match('/\bname\s*=\s*["\']uuid["\']/i', $input)
                && preg_match('/\bvalue\s*=\s*["\']([a-z0-9_-]{1,160})["\']/i', $input, $value)) {
                $uuid = $value[1]; break;
            }
        }
        if ($uuid === null) return null;
        return 'https://drive.usercontent.google.com/download?' . http_build_query([
            'id'=>TrainingVideoService::DRIVE_ID, 'export'=>'download', 'confirm'=>'t', 'uuid'=>$uuid,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function safeUrl(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['port'])) return false;
        $host = strtolower((string) ($p['host'] ?? ''));
        return $host === 'drive.google.com' || $host === 'drive.usercontent.google.com' || str_ends_with($host, '.googleusercontent.com');
    }

    private function request(string $url, callable $write, callable $progress): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('برای انتقال خودکار، افزونه cURL باید روی هاست فعال باشد.');
        $ch = curl_init($url); $location = '';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>20, CURLOPT_TIMEOUT=>3600,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_USERAGENT=>'RefrallBot training downloader',
            CURLOPT_HEADERFUNCTION=>static function ($ch, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION=>static fn($ch, string $chunk): int => $write($chunk),
            CURLOPT_NOPROGRESS=>false,
            CURLOPT_PROGRESSFUNCTION=>static function () use ($progress): int { $progress(); return 0; },
        ]);
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $result === false;
        unset($ch);
        return ['status'=>$status, 'location'=>$location, 'error'=>$error];
    }
}
