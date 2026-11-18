<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Crypto;
use RB\Support\Database;
use RB\Support\ErrorHandler;
use RB\Support\Migrator;

/**
 * سرویس به‌روزرسانی امن سامانه (از GitHub Releases یا بستهٔ ZIP آفلاین)
 *
 * اصول امنیتی و پایداری:
 *   ۱) فقط مسیرهای مجاز (ALLOWED_PATHS) کپی می‌شوند؛ .env و storage/ هرگز لمس نمی‌شوند.
 *   ۲) هر بسته باید دارای VERSION معتبر و CHECKSUMS.sha256 کامل باشد؛ فایل بدون هش یا هش نامنطبق رد می‌شود.
 *   ۳) در برابر Zip Slip (../ و مسیر مطلق)، Symlink و Zip Bomb محافظت کامل انجام می‌شود.
 *   ۴) دانلود خودکار فقط از دامنه‌های رسمی GitHub روی HTTPS با تأیید گواهی SSL مجاز است.
 *   ۵) پیش از هر تغییر، پشتیبان دیتابیس (شامل جدول migrations) و پشتیبان ZIP فایل‌ها گرفته می‌شود.
 *   ۶) در صورت شکست کپی فایل‌ها یا شکست Migration، فایل‌های قبلی به‌صورت خودکار بازگردانی می‌شوند.
 *   ۷) قفل انحصاری جلوی اجرای هم‌زمان دو به‌روزرسانی را می‌گیرد و پوشهٔ موقت در پایان پاک‌سازی می‌شود.
 */
final class UpdateService
{
    /** مسیرهای مجاز برای به‌روزرسانی (شامل VERSION تا حلقهٔ به‌روزرسانی رخ ندهد) */
    public const ALLOWED_PATHS = [
        'src/', 'views/', 'public/', 'bin/', 'docs/', 'database/migrations/',
        'install.php', 'index.php', 'VERSION', 'README.md',
    ];

    /** فایل‌های متادیتای داخل بسته که بررسی می‌شوند ولی در ریشهٔ پروژه کپی نمی‌شوند */
    public const PACKAGE_META_FILES = [
        'CHECKSUMS.sha256',
        'update.json',
    ];

    /** دامنه‌های مجاز برای دانلود بسته از GitHub (جلوگیری از SSRF) */
    public const ALLOWED_DOWNLOAD_HOSTS = [
        'github.com',
        'api.github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
        'codeload.github.com',
    ];

    public const DEFAULT_REPO = 'khajavy8056/Refrallbot';
    public const GITHUB_API = 'https://api.github.com';
    public const CHECKSUM_FILE = 'CHECKSUMS.sha256';
    public const MANIFEST_FILE = 'update.json';

    public const MAX_PACKAGE_BYTES = 52428800;    // 50 MB
    public const MAX_EXTRACTED_BYTES = 125829120; // 120 MB
    public const MAX_EXTRACTED_FILES = 5000;
    public const LOCK_TTL = 900;                  // ۱۵ دقیقه
    public const MAX_BACKUPS_KEEP = 10;
    public const MAX_HISTORY_ITEMS = 25;

    /**
     * ترنسپورت جایگزین HTTP برای تست خودکار (در محیط تولید همیشه null است).
     * @var null|callable(string $url, array $headers, bool $binary): array{status:int,body:string,error:?string}
     */
    public static $fakeHttp = null;

    /**
     * شبیه‌ساز خطای نوشتن فایل در تست خودکار (فقط در RB_TEST_MODE اثر دارد).
     * @var null|callable(string $relativePath, string $targetPath): bool
     */
    public static $testCopyHook = null;

    private Database $db;
    private string $targetRoot;
    private string $storageRoot;
    /** @var resource|null */
    private $lockHandle = null;
    private ?string $lockPath = null;

    public function __construct(?Database $db = null, ?string $targetRoot = null, ?string $storageRoot = null)
    {
        $this->db = $db ?? Database::instance();
        $this->targetRoot = rtrim($targetRoot ?? RB_ROOT, '/\\');
        $this->storageRoot = rtrim($storageRoot ?? RB_STORAGE, '/\\');
    }

    // ================================================================ نسخه و تنظیمات

    /** خواندن نسخهٔ نصب‌شدهٔ فعلی از فایل VERSION (بدون کش، تا بلافاصله پس از آپدیت به‌روز باشد) */
    public function currentVersion(?string $root = null): string
    {
        $base = rtrim($root ?? $this->targetRoot, '/\\');
        $file = $base . '/VERSION';
        if (is_file($file)) {
            $raw = self::normalizeVersion((string) @file_get_contents($file));
            if (self::isValidVersion($raw)) {
                return $raw;
            }
        }
        return defined('RB_VERSION') ? self::normalizeVersion((string) RB_VERSION) : '1.0.0';
    }

    public static function normalizeVersion(string $version): string
    {
        $v = trim($version);
        if ($v !== '' && ($v[0] === 'v' || $v[0] === 'V')) {
            $v = substr($v, 1);
        }
        return trim($v);
    }

    public static function isValidVersion(string $version): bool
    {
        $v = self::normalizeVersion($version);
        return (bool) preg_match('~^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$~', $v);
    }

    public static function compareVersions(string $a, string $b): int
    {
        return (int) version_compare(self::normalizeVersion($a), self::normalizeVersion($b));
    }

    public static function isNewer(string $candidate, string $current): bool
    {
        if (!self::isValidVersion($candidate) || !self::isValidVersion($current)) {
            return false;
        }
        return self::compareVersions($candidate, $current) > 0;
    }

    public static function repo(): string
    {
        $repo = trim((string) (SettingsService::get('update_repo', self::DEFAULT_REPO) ?? self::DEFAULT_REPO));
        return self::isValidRepo($repo) ? $repo : self::DEFAULT_REPO;
    }

    public static function isValidRepo(string $repo): bool
    {
        return (bool) preg_match('~^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$~', $repo)
            && !str_contains($repo, '..');
    }

    public static function channel(): string
    {
        $ch = (string) (SettingsService::get('update_channel', 'stable') ?? 'stable');
        return in_array($ch, ['stable', 'prerelease'], true) ? $ch : 'stable';
    }

    public static function autoCheckEnabled(): bool
    {
        return SettingsService::bool('update_auto_check', false);
    }

    public static function githubToken(): string
    {
        $enc = (string) (SettingsService::get('update_github_token_enc', '') ?? '');
        if ($enc === '') {
            return '';
        }
        return (string) (Crypto::decrypt($enc) ?? '');
    }

    public static function maskedGithubToken(): ?string
    {
        $plain = self::githubToken();
        return $plain !== '' ? Crypto::maskSecret($plain) : null;
    }

    /** آیا بر اساس آخرین بررسی کش‌شده، نسخهٔ جدیدتری نسبت به VERSION فعلی موجود است؟ */
    public static function hasCachedUpdate(?string $currentVersion = null): bool
    {
        $latest = self::cachedLatest();
        if ($latest === null || empty($latest['version'])) {
            return false;
        }
        $current = $currentVersion ?? (new self())->currentVersion();
        return self::isNewer((string) $latest['version'], $current);
    }

    public static function cachedLatest(): ?array
    {
        $raw = (string) (SettingsService::get('update_latest_json', '') ?? '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** ذخیرهٔ تنظیمات به‌روزرسانی */
    public static function saveSettings(array $input, ?int $adminId = null): array
    {
        $repo = trim((string) ($input['repo'] ?? self::DEFAULT_REPO));
        $repo = preg_replace('~^https?://github\.com/~i', '', $repo) ?? $repo;
        $repo = trim($repo, "/ \t\n\r\0\x0B");
        $repo = preg_replace('~\.git$~i', '', $repo) ?? $repo;

        if ($repo === '') {
            $repo = self::DEFAULT_REPO;
        }
        if (!self::isValidRepo($repo)) {
            return ['ok' => false, 'error' => 'قالب مخزن گیت‌هاب معتبر نیست. نمونه صحیح: owner/repo'];
        }

        $channel = (string) ($input['channel'] ?? 'stable');
        if (!in_array($channel, ['stable', 'prerelease'], true)) {
            return ['ok' => false, 'error' => 'کانال انتشار انتخاب‌شده نامعتبر است.'];
        }

        $autoCheck = !empty($input['auto_check']) && !in_array((string) $input['auto_check'], ['0', 'false', 'off'], true);
        $oldRepo = self::repo();
        $oldChannel = self::channel();

        SettingsService::set('update_repo', $repo, 'update');
        SettingsService::set('update_channel', $channel, 'update');
        SettingsService::set('update_auto_check', $autoCheck ? '1' : '0', 'update');

        if (!empty($input['clear_token']) && !in_array((string) $input['clear_token'], ['0', 'false', 'off'], true)) {
            SettingsService::set('update_github_token_enc', '', 'update');
        } else {
            $token = trim((string) ($input['github_token'] ?? ''));
            if ($token !== '') {
                if (strlen($token) < 16 || strlen($token) > 255 || preg_match('~\s~', $token)) {
                    return ['ok' => false, 'error' => 'قالب توکن گیت‌هاب معتبر نیست.'];
                }
                SettingsService::set('update_github_token_enc', (string) Crypto::encrypt($token), 'update');
            }
        }

        // اگر مخزن یا کانال عوض شد، کش نسخهٔ قبلی را پاک کن تا اشتباه نمایش داده نشود
        if ($oldRepo !== $repo || $oldChannel !== $channel) {
            SettingsService::set('update_latest_json', '', 'update');
            SettingsService::set('update_last_error', '', 'update');
        }

        try {
            AuditService::log('update.settings_updated', 'update', null, [
                'repo' => $repo,
                'channel' => $channel,
                'auto_check' => $autoCheck,
            ], $adminId);
        } catch (\Throwable) {
        }

        return ['ok' => true, 'repo' => $repo, 'channel' => $channel, 'auto_check' => $autoCheck];
    }

    /** بررسی خودکار روزانه (حداکثر یک بار در هر ۲۴ ساعت؛ خطا هرگز داشبورد را از کار نمی‌اندازد) */
    public function autoCheckIfDue(): void
    {
        if (!self::autoCheckEnabled()) {
            return;
        }
        $last = (string) (SettingsService::get('update_last_check_at') ?? '');
        if ($last !== '' && (time() - (int) strtotime($last)) < 86400) {
            return;
        }
        try {
            $this->check();
        } catch (\Throwable $e) {
            ErrorHandler::logException($e);
        }
    }

    // ================================================================ بررسی پیش‌نیازهای سرور

    /** @return array<string,array{ok:bool,label:string,detail:string}> */
    public function environmentChecks(): array
    {
        $zipOk = class_exists(\ZipArchive::class);
        $httpOk = function_exists('curl_init') || (bool) ini_get('allow_url_fopen');
        $sslOk = extension_loaded('openssl');
        $storageWritable = is_dir($this->storageRoot) && is_writable($this->storageRoot);
        $rootWritable = is_dir($this->targetRoot) && is_writable($this->targetRoot);
        $srcWritable = !is_dir($this->targetRoot . '/src') || is_writable($this->targetRoot . '/src');

        return [
            'php' => [
                'ok' => PHP_VERSION_ID >= 80100,
                'label' => 'نسخه PHP',
                'detail' => PHP_VERSION,
            ],
            'zip' => [
                'ok' => $zipOk,
                'label' => 'افزونه ZipArchive',
                'detail' => $zipOk ? 'فعال' : 'غیرفعال (از پنل هاست php-zip را فعال کنید)',
            ],
            'http' => [
                'ok' => $httpOk && $sslOk,
                'label' => 'ارتباط امن HTTPS',
                'detail' => ($httpOk && $sslOk)
                    ? (function_exists('curl_init') ? 'cURL + OpenSSL' : 'Stream + OpenSSL')
                    : 'نیازمند cURL و OpenSSL (آپلود دستی ZIP همچنان کار می‌کند)',
            ],
            'storage' => [
                'ok' => $storageWritable,
                'label' => 'نوشتن در storage/',
                'detail' => $storageWritable ? 'قابل نوشتن' : 'عدم دسترسی نوشتن (پشتیبان و فایل موقت)',
            ],
            'root' => [
                'ok' => $rootWritable && $srcWritable,
                'label' => 'نوشتن در فایل‌های برنامه',
                'detail' => ($rootWritable && $srcWritable) ? 'قابل نوشتن' : 'دسترسی نوشتن ریشه یا src/ محدود است',
            ],
        ];
    }

    // ================================================================ بررسی GitHub Releases

    /**
     * بررسی آخرین نسخهٔ منتشرشده در GitHub Releases
     * @return array{ok:bool,current?:string,latest?:array,has_update?:bool,error?:string}
     */
    public function check(?int $adminId = null): array
    {
        $repo = self::repo();
        $channel = self::channel();
        $current = $this->currentVersion();

        // ثبت زمان بررسی قبل از درخواست تا در صورت خطا، بررسی خودکار روزانه هر بار تکرار نشود
        SettingsService::set('update_last_check_at', now(), 'update');

        if (!self::isValidRepo($repo)) {
            $err = 'نام مخزن گیت‌هاب معتبر نیست.';
            SettingsService::set('update_last_error', $err, 'update');
            return ['ok' => false, 'error' => $err];
        }

        $endpoint = $channel === 'prerelease'
            ? self::GITHUB_API . '/repos/' . $repo . '/releases?per_page=10'
            : self::GITHUB_API . '/repos/' . $repo . '/releases/latest';

        $response = $this->httpGet($endpoint, false);
        if ($response['error'] !== null) {
            $err = 'خطا در اتصال به گیت‌هاب: ' . ErrorHandler::redact($response['error'])
                . ' (می‌توانید بسته را به‌صورت دستی با فایل ZIP نصب کنید.)';
            SettingsService::set('update_last_error', mb_substr($err, 0, 500), 'update');
            return ['ok' => false, 'error' => $err];
        }

        $status = $response['status'];
        if ($status === 404) {
            $err = 'هنوز نسخهٔ رسمی (Release) در مخزن «' . $repo . '» منتشر نشده یا مخزن خصوصی است.';
            SettingsService::set('update_last_error', $err, 'update');
            return ['ok' => false, 'error' => $err];
        }
        if ($status === 401) {
            $err = 'توکن دسترسی گیت‌هاب نامعتبر یا منقضی است.';
            SettingsService::set('update_last_error', $err, 'update');
            return ['ok' => false, 'error' => $err];
        }
        if ($status === 403 || $status === 429) {
            $err = 'محدودیت نرخ درخواست GitHub API اعمال شده است. کمی بعد تلاش کنید یا در تنظیمات توکن گیت‌هاب ثبت کنید.';
            SettingsService::set('update_last_error', $err, 'update');
            return ['ok' => false, 'error' => $err];
        }
        if ($status < 200 || $status >= 300) {
            $err = 'پاسخ غیرمنتظره از گیت‌هاب (کد HTTP ' . $status . ').';
            SettingsService::set('update_last_error', $err, 'update');
            return ['ok' => false, 'error' => $err];
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            $err = 'پاسخ دریافتی از گیت‌هاب معتبر نیست.';
            SettingsService::set('update_last_error', $err, 'update');
            return ['ok' => false, 'error' => $err];
        }

        $release = null;
        if ($channel === 'prerelease') {
            foreach ($decoded as $item) {
                if (is_array($item) && empty($item['draft']) && !empty($item['tag_name'])) {
                    $release = $item;
                    break;
                }
            }
            if ($release === null) {
                $err = 'هیچ نسخهٔ منتشرشده‌ای در این مخزن یافت نشد.';
                SettingsService::set('update_last_error', $err, 'update');
                return ['ok' => false, 'error' => $err];
            }
        } else {
            $release = $decoded;
        }

        $tag = trim((string) ($release['tag_name'] ?? ''));
        $version = self::normalizeVersion($tag);
        if (!self::isValidVersion($version)) {
            $err = 'برچسب نسخهٔ منتشرشده («' . $tag . '») قالب استاندارد X.Y.Z ندارد.';
            SettingsService::set('update_last_error', $err, 'update');
            return ['ok' => false, 'error' => $err];
        }

        // انتخاب بستهٔ رسمی ZIP از میان Assetهای پیوست‌شده به Release
        $zipAsset = null;
        $shaAsset = null;
        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            $dl = (string) ($asset['browser_download_url'] ?? '');
            if ($name === '' || $dl === '' || !self::isAllowedDownloadUrl($dl)) {
                continue;
            }
            if (str_ends_with(strtolower($name), '.zip')) {
                if ($zipAsset === null || str_contains(strtolower($name), 'refrallbot-update')) {
                    $zipAsset = $asset;
                }
            } elseif (str_ends_with(strtolower($name), '.zip.sha256') || str_ends_with(strtolower($name), '.sha256')) {
                $shaAsset = $asset;
            }
        }

        $hasUpdate = self::isNewer($version, $current);
        $info = [
            'repo' => $repo,
            'tag' => $tag,
            'version' => $version,
            'name' => mb_substr(trim((string) ($release['name'] ?? $tag)), 0, 160),
            'body' => mb_substr(trim((string) ($release['body'] ?? '')), 0, 4000),
            'prerelease' => !empty($release['prerelease']),
            'published_at' => (string) ($release['published_at'] ?? ''),
            'html_url' => (string) ($release['html_url'] ?? ('https://github.com/' . $repo . '/releases')),
            'has_official_asset' => $zipAsset !== null,
            'asset_name' => $zipAsset !== null ? (string) ($zipAsset['name'] ?? '') : null,
            'asset_url' => $zipAsset !== null ? (string) ($zipAsset['browser_download_url'] ?? '') : null,
            'asset_size' => $zipAsset !== null ? (int) ($zipAsset['size'] ?? 0) : 0,
            'sha256_url' => $shaAsset !== null ? (string) ($shaAsset['browser_download_url'] ?? '') : null,
            'has_update' => $hasUpdate,
            'checked_at' => now(),
        ];

        SettingsService::set('update_latest_json', (string) json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update');
        SettingsService::set('update_last_error', '', 'update');

        if ($adminId !== null) {
            try {
                AuditService::log('update.checked', 'update', null, [
                    'current' => $current,
                    'latest' => $version,
                    'has_update' => $hasUpdate,
                ], $adminId);
            } catch (\Throwable) {
            }
        }

        return [
            'ok' => true,
            'current' => $current,
            'latest' => $info,
            'has_update' => $hasUpdate,
        ];
    }

    /** آیا آدرس دانلود روی HTTPS و از دامنهٔ رسمی GitHub است؟ */
    public static function isAllowedDownloadUrl(string $url): bool
    {
        if (!str_starts_with($url, 'https://')) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        return in_array($host, self::ALLOWED_DOWNLOAD_HOSTS, true);
    }

    // ================================================================ نصب از گیت‌هاب و آپلود

    /** دانلود و نصب آخرین نسخهٔ رسمی از GitHub Releases */
    public function installFromGithub(?string $expectedTag = null, ?int $adminId = null, array $options = []): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'error' => 'افزونه ZipArchive در PHP فعال نیست. از کنترل‌پنل هاست php-zip را فعال کنید.'];
        }

        $latest = self::cachedLatest();
        if ($latest === null || ($expectedTag !== null && $expectedTag !== '' && ($latest['tag'] ?? '') !== $expectedTag)) {
            $checked = $this->check($adminId);
            if (!$checked['ok']) {
                return $checked;
            }
            $latest = $checked['latest'];
        }

        $targetRoot = rtrim((string) ($options['target_root'] ?? $this->targetRoot), '/\\');
        $current = $this->currentVersion($targetRoot);
        $targetVersion = (string) ($latest['version'] ?? '');

        if (!self::isValidVersion($targetVersion)) {
            return ['ok' => false, 'error' => 'نسخهٔ هدف نامعتبر است. ابتدا دوباره «بررسی نسخه جدید» را بزنید.'];
        }
        if (!self::isNewer($targetVersion, $current)) {
            return ['ok' => false, 'error' => 'سامانه هم‌اکنون روی آخرین نسخه (' . $current . ') قرار دارد.'];
        }

        $assetUrl = (string) ($latest['asset_url'] ?? '');
        if ($assetUrl === '' || empty($latest['has_official_asset'])) {
            return [
                'ok' => false,
                'error' => 'این Release فاقد بستهٔ رسمی ZIP (دارای CHECKSUMS.sha256) است. بستهٔ ساخته‌شده با bin/build-update.php را در Release پیوست کنید.',
            ];
        }
        if (!self::isAllowedDownloadUrl($assetUrl)) {
            return ['ok' => false, 'error' => 'آدرس دانلود بسته از دامنهٔ مجاز گیت‌هاب نیست و مسدود شد.'];
        }
        $declaredSize = (int) ($latest['asset_size'] ?? 0);
        if ($declaredSize > self::MAX_PACKAGE_BYTES) {
            return ['ok' => false, 'error' => 'حجم بستهٔ به‌روزرسانی بیش از سقف مجاز (۵۰ مگابایت) است.'];
        }

        $workDir = $this->workDir();
        $downloadPath = $workDir . '/github-' . bin2hex(random_bytes(8)) . '.zip';

        $dl = $this->httpGet($assetUrl, true);
        if ($dl['error'] !== null || $dl['status'] < 200 || $dl['status'] >= 300) {
            $detail = $dl['error'] ?? ('HTTP ' . $dl['status']);
            return ['ok' => false, 'error' => 'دانلود بسته از گیت‌هاب ناموفق بود: ' . ErrorHandler::redact($detail)];
        }
        if ($dl['body'] === '' || strlen($dl['body']) > self::MAX_PACKAGE_BYTES) {
            return ['ok' => false, 'error' => 'فایل دانلودشده خالی یا بزرگ‌تر از سقف مجاز است.'];
        }
        if (@file_put_contents($downloadPath, $dl['body'], LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'ذخیرهٔ بسته در پوشهٔ موقت ممکن نشد. دسترسی storage/ را بررسی کنید.'];
        }

        // اگر فایل .sha256 همراه Release منتشر شده باشد، هش کل آرشیو ZIP را هم تطبیق می‌دهیم
        $shaUrl = (string) ($latest['sha256_url'] ?? '');
        if ($shaUrl !== '' && self::isAllowedDownloadUrl($shaUrl)) {
            $shaResp = $this->httpGet($shaUrl, false);
            if ($shaResp['error'] === null && $shaResp['status'] >= 200 && $shaResp['status'] < 300) {
                if (preg_match('~\b([a-fA-F0-9]{64})\b~', $shaResp['body'], $m)) {
                    $expectedZipSha = strtolower($m[1]);
                    $actualZipSha = strtolower((string) hash_file('sha256', $downloadPath));
                    if (!hash_equals($expectedZipSha, $actualZipSha)) {
                        @unlink($downloadPath);
                        return ['ok' => false, 'error' => 'چک‌سام SHA-256 فایل ZIP دانلودشده با مقدار اعلام‌شده در Release مطابقت ندارد.'];
                    }
                }
            }
        }

        $options['expected_version'] = $targetVersion;
        $options['cleanup_zip'] = true;
        return $this->applyArchive($downloadPath, 'github', $adminId, $options);
    }

    /** اعتبارسنجی و نصب بستهٔ ZIP آپلودشده از پنل مدیریت */
    public function installFromUpload(array $file, ?int $adminId = null, array $options = []): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'error' => 'افزونه ZipArchive در PHP فعال نیست. از کنترل‌پنل هاست php-zip را فعال کنید.'];
        }

        $errCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errCode !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => match ($errCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم فایل ZIP از سقف مجاز آپلود PHP بیشتر است (upload_max_filesize / post_max_size).',
                UPLOAD_ERR_PARTIAL => 'فایل به‌طور ناقص آپلود شد. دوباره تلاش کنید.',
                UPLOAD_ERR_NO_FILE => 'هیچ فایل ZIP انتخاب نشده است.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'پوشهٔ موقت سرور برای آپلود قابل نوشتن نیست.',
                default => 'خطا در آپلود فایل (کد ' . $errCode . ').',
            }];
        }

        $originalName = trim((string) ($file['name'] ?? ''));
        if ($originalName === '' || !str_ends_with(strtolower($originalName), '.zip')) {
            return ['ok' => false, 'error' => 'فقط فایل با پسوند .zip پذیرفته می‌شود.'];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            return ['ok' => false, 'error' => 'فایل آپلودشده در سرور یافت نشد.'];
        }

        $isTestLocal = (defined('RB_TEST_MODE') || getenv('RB_TEST_MODE') === '1') && !empty($file['_test_local']);
        if (!$isTestLocal && !is_uploaded_file($tmpName)) {
            return ['ok' => false, 'error' => 'منبع فایل آپلودشده معتبر نیست.'];
        }

        $size = (int) (@filesize($tmpName) ?: 0);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'فایل ZIP انتخاب‌شده خالی است.'];
        }
        if ($size > self::MAX_PACKAGE_BYTES) {
            return ['ok' => false, 'error' => 'حجم فایل بیش از سقف مجاز (۵۰ مگابایت) است.'];
        }

        if (!self::hasZipSignature($tmpName)) {
            return ['ok' => false, 'error' => 'فایل ارسال‌شده یک آرشیو ZIP معتبر نیست.'];
        }

        $dest = $this->workDir() . '/upload-' . bin2hex(random_bytes(8)) . '.zip';
        $moved = $isTestLocal ? @copy($tmpName, $dest) : @move_uploaded_file($tmpName, $dest);
        if (!$moved || !is_file($dest)) {
            return ['ok' => false, 'error' => 'انتقال فایل آپلودشده به پوشهٔ کاری ممکن نشد.'];
        }

        $options['cleanup_zip'] = true;
        return $this->applyArchive($dest, 'upload', $adminId, $options);
    }

    // ================================================================ هستهٔ اعمال بسته

    /**
     * اعمال کامل یک آرشیو ZIP (استخراج امن → اعتبارسنجی → پشتیبان DB و فایل → کپی → Migration → بازگردانی خودکار در خطا)
     */
    public function applyArchive(string $zipPath, string $source = 'upload', ?int $adminId = null, array $options = []): array
    {
        $warnings = [];
        $targetRoot = rtrim((string) ($options['target_root'] ?? $this->targetRoot), '/\\');
        $cleanupZip = !empty($options['cleanup_zip']);

        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'error' => 'افزونه ZipArchive در PHP فعال نیست.'];
        }
        if (!is_file($zipPath)) {
            return ['ok' => false, 'error' => 'فایل بستهٔ به‌روزرسانی یافت نشد.'];
        }

        if (!$this->acquireLock()) {
            return ['ok' => false, 'error' => 'یک فرآیند به‌روزرسانی دیگر هم‌اکنون در حال اجراست. چند لحظه صبر کنید.'];
        }

        $stageDir = $this->workDir() . '/stage-' . bin2hex(random_bytes(8));
        $fromVersion = $this->currentVersion($targetRoot);

        try {
            // ۱) استخراج امن (محافظت در برابر Zip Slip، Symlink و Zip Bomb)
            $extract = $this->extractArchive($zipPath, $stageDir);
            if (!$extract['ok']) {
                return ['ok' => false, 'error' => (string) $extract['error']];
            }
            $packageRoot = (string) $extract['root'];

            // ۲) اعتبارسنجی VERSION، CHECKSUMS.sha256، مسیرهای مجاز و عدم وجود فایل محافظت‌شده
            $options['current_version'] = $fromVersion;
            $validation = $this->validatePackage($packageRoot, $options);
            if (!$validation['ok']) {
                return ['ok' => false, 'error' => (string) $validation['error']];
            }
            $toVersion = (string) $validation['version'];

            // ۳) پشتیبان کامل پایگاه داده (شامل جدول migrations)
            $backup = $this->backupDatabase($fromVersion);
            if (!$backup['ok']) {
                return [
                    'ok' => false,
                    'error' => 'به‌روزرسانی متوقف شد چون پشتیبان‌گیری از پایگاه داده شکست خورد: ' . (string) $backup['error'],
                    'backup_ok' => false,
                ];
            }

            // ۴) پشتیبان ZIP فایل‌های فعلی (برای بازگردانی خودکار یا دستی)
            $filesBackup = $this->backupFiles($targetRoot, $fromVersion);
            if (!$filesBackup['ok']) {
                return [
                    'ok' => false,
                    'error' => 'به‌روزرسانی متوقف شد چون پشتیبان‌گیری از فایل‌ها شکست خورد: ' . (string) $filesBackup['error'],
                    'backup' => $backup['file'] ?? null,
                    'backup_ok' => false,
                ];
            }

            // ۵) کپی فایل‌های مجاز — با بازگردانی خودکار در صورت هرگونه خطا
            $copy = $this->copyFiles($packageRoot, $targetRoot);
            if (!$copy['ok']) {
                $restore = $this->restoreFiles(
                    (string) ($filesBackup['name'] ?? ''),
                    $targetRoot,
                    (array) ($copy['added'] ?? [])
                );
                $restored = (bool) ($restore['ok'] ?? false);
                $errorMsg = (string) $copy['error'] . ($restored
                    ? ' — فایل‌های قبلی بازگردانی شدند.'
                    : ' — بازگردانی خودکار ممکن نشد؛ از «بازگردانی فایل‌ها» استفاده کنید.');

                $this->recordOutcome('failed', $source, $fromVersion, $toVersion, $errorMsg, $backup['file'] ?? null, $filesBackup['name'] ?? null, $restored, $adminId);

                return [
                    'ok' => false,
                    'error' => $errorMsg,
                    'backup' => $backup['file'] ?? null,
                    'files_backup' => $filesBackup['name'] ?? null,
                    'backup_ok' => true,
                    'restored' => $restored,
                    'warnings' => $warnings,
                ];
            }

            // ۶) اجرای Migrationهای جدید — در صورت خطا، فایل‌های قبلی خودکار بازگردانی می‌شوند
            $migrationsApplied = [];
            if (empty($options['skip_migrations'])) {
                try {
                    $migDir = $targetRoot . '/database/migrations';
                    if (is_dir($migDir)) {
                        $migResult = (new Migrator($this->db, $migDir))->run();
                        $migrationsApplied = (array) ($migResult['applied'] ?? []);
                    }
                } catch (\Throwable $e) {
                    $restore = $this->restoreFiles(
                        (string) ($filesBackup['name'] ?? ''),
                        $targetRoot,
                        (array) ($copy['added'] ?? [])
                    );
                    $restored = (bool) ($restore['ok'] ?? false);
                    $errorMsg = 'خطا در اجرای Migration پایگاه داده: ' . ErrorHandler::redact($e->getMessage())
                        . ($restored
                            ? ' — فایل‌های قبلی بازگردانی شدند.'
                            : ' — بازگردانی خودکار ممکن نشد؛ از «بازگردانی فایل‌ها» استفاده کنید.');

                    $this->recordOutcome('failed', $source, $fromVersion, $toVersion, $errorMsg, $backup['file'] ?? null, $filesBackup['name'] ?? null, $restored, $adminId);

                    return [
                        'ok' => false,
                        'error' => $errorMsg,
                        'backup' => $backup['file'] ?? null,
                        'files_backup' => $filesBackup['name'] ?? null,
                        'backup_ok' => true,
                        'restored' => $restored,
                        'warnings' => $warnings,
                    ];
                }
            }

            // ۷) بازنشانی OPcache در صورت فعال بودن
            $this->resetOpcache();

            // ۸) ثبت وضعیت جدید و همگام‌سازی کش بررسی نسخه
            SettingsService::set('update_last_applied_at', now(), 'update');
            SettingsService::set('update_last_applied_from', $fromVersion, 'update');
            SettingsService::set('update_last_applied_to', $toVersion, 'update');
            SettingsService::set('update_last_error', '', 'update');

            $cached = self::cachedLatest();
            if ($cached !== null && !empty($cached['version'])) {
                $cached['has_update'] = self::isNewer((string) $cached['version'], $toVersion);
                SettingsService::set('update_latest_json', (string) json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update');
            }

            $this->pruneBackups();
            $this->recordOutcome('success', $source, $fromVersion, $toVersion, '', $backup['file'] ?? null, $filesBackup['name'] ?? null, false, $adminId);

            return [
                'ok' => true,
                'from' => $fromVersion,
                'to' => $toVersion,
                'files_copied' => (int) ($copy['count'] ?? 0),
                'migrations_applied' => $migrationsApplied,
                'backup' => $backup['file'] ?? null,
                'files_backup' => $filesBackup['name'] ?? null,
                'warnings' => $warnings,
            ];
        } finally {
            $this->removeDirectory($stageDir);
            if ($cleanupZip && is_file($zipPath) && str_starts_with($zipPath, $this->workDir() . '/')) {
                @unlink($zipPath);
            }
            $this->releaseLock();
            try {
                $this->pruneWorkDir();
            } catch (\Throwable) {
            }
        }
    }

    // ================================================================ استخراج و اعتبارسنجی بسته

    /**
     * استخراج امن فایل ZIP با مسدودسازی کامل Zip Slip، Symlink و Zip Bomb
     * @return array{ok:bool,root?:string,files?:int,error?:string}
     */
    public function extractArchive(string $zipPath, string $destDir): array
    {
        if (!is_file($zipPath)) {
            return ['ok' => false, 'error' => 'فایل آرشیو یافت نشد.'];
        }
        $fileSize = (int) (@filesize($zipPath) ?: 0);
        if ($fileSize <= 0 || $fileSize > self::MAX_PACKAGE_BYTES) {
            return ['ok' => false, 'error' => 'حجم فایل ZIP نامعتبر یا بیش از سقف مجاز است.'];
        }
        if (!self::hasZipSignature($zipPath)) {
            return ['ok' => false, 'error' => 'ساختار فایل ZIP معتبر نیست.'];
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath, \ZipArchive::RDONLY);
        if ($opened !== true) {
            return ['ok' => false, 'error' => 'باز کردن فایل ZIP ممکن نشد (آرشیو خراب یا ناسازگار است).'];
        }

        try {
            $numFiles = $zip->numFiles;
            if ($numFiles <= 0) {
                return ['ok' => false, 'error' => 'فایل ZIP خالی است.'];
            }
            if ($numFiles > self::MAX_EXTRACTED_FILES) {
                return ['ok' => false, 'error' => 'تعداد فایل‌های داخل بسته بیش از حد مجاز است.'];
            }

            // مرحلهٔ اول: بازرسی امنیتی تمام مدخل‌ها قبل از نوشتن حتی یک بایت روی دیسک
            $totalBytes = 0;
            $entries = [];
            for ($i = 0; $i < $numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    return ['ok' => false, 'error' => 'خواندن فهرست فایل‌های ZIP ناموفق بود.'];
                }
                $rawName = (string) ($stat['name'] ?? '');
                $safeRel = self::sanitizeArchiveEntryPath($rawName);
                if ($safeRel === null) {
                    return ['ok' => false, 'error' => 'مسیر ناامن در فایل ZIP شناسایی و مسدود شد (احتمال Zip Slip).'];
                }

                // تشخیص و مسدودسازی Symlink یونیکس (در ext-zip از طریق getExternalAttributesIndex خوانده می‌شود)
                $opsys = 0;
                $attr = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr)) {
                    $unixMode = ($attr >> 16) & 0xFFFF;
                    if (($unixMode & 0170000) === 0120000) {
                        return ['ok' => false, 'error' => 'وجود پیوند نمادین (Symlink) در بستهٔ به‌روزرسانی مجاز نیست.'];
                    }
                }

                $isDir = str_ends_with(str_replace('\\', '/', $rawName), '/');
                if ($isDir || $safeRel === '') {
                    continue;
                }

                $uncompressed = (int) ($stat['size'] ?? 0);
                $compressed = (int) ($stat['comp_size'] ?? 0);
                if ($uncompressed < 0 || $uncompressed > self::MAX_EXTRACTED_BYTES) {
                    return ['ok' => false, 'error' => 'حجم فایل استخراجی بیش از حد مجاز است.'];
                }
                if ($compressed > 0 && $uncompressed > 10 * 1024 * 1024 && ($uncompressed / $compressed) > 100) {
                    return ['ok' => false, 'error' => 'نسبت فشرده‌سازی مشکوک (Zip Bomb) شناسایی و مسدود شد.'];
                }
                $totalBytes += $uncompressed;
                if ($totalBytes > self::MAX_EXTRACTED_BYTES) {
                    return ['ok' => false, 'error' => 'مجموع حجم بازشدهٔ بسته بیش از سقف مجاز (۱۲۰ مگابایت) است.'];
                }

                $entries[] = ['raw' => $rawName, 'rel' => $safeRel];
            }

            if ($entries === []) {
                return ['ok' => false, 'error' => 'هیچ فایلی در آرشیو ZIP وجود ندارد.'];
            }

            if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                return ['ok' => false, 'error' => 'ساخت پوشهٔ موقت استخراج ممکن نشد.'];
            }
            $realDest = realpath($destDir);
            if ($realDest === false) {
                return ['ok' => false, 'error' => 'مسیر پوشهٔ استخراج معتبر نیست.'];
            }

            // مرحلهٔ دوم: استخراج کنترل‌شده جریانی (بدون extractTo کورکورانه)
            $writtenBytes = 0;
            foreach ($entries as $entry) {
                $outPath = $realDest . '/' . $entry['rel'];
                $parent = dirname($outPath);
                if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                    return ['ok' => false, 'error' => 'ساخت زیرپوشه در زمان استخراج ممکن نشد.'];
                }
                $realParent = realpath($parent);
                if ($realParent === false || ($realParent !== $realDest && !str_starts_with($realParent, $realDest . DIRECTORY_SEPARATOR))) {
                    return ['ok' => false, 'error' => 'خروج مسیر از پوشهٔ امن شناسایی و مسدود شد.'];
                }

                $in = $zip->getStream($entry['raw']);
                if ($in === false) {
                    return ['ok' => false, 'error' => 'استخراج فایل «' . $entry['rel'] . '» ناموفق بود (بسته آسیب دیده است).'];
                }
                $out = @fopen($outPath, 'wb');
                if ($out === false) {
                    fclose($in);
                    return ['ok' => false, 'error' => 'نوشتن فایل موقت در زمان استخراج ممکن نشد.'];
                }
                try {
                    while (!feof($in)) {
                        $chunk = fread($in, 65536);
                        if ($chunk === false) {
                            return ['ok' => false, 'error' => 'خطا در خواندن جریان فشرده.'];
                        }
                        if ($chunk === '') {
                            continue;
                        }
                        $writtenBytes += strlen($chunk);
                        if ($writtenBytes > self::MAX_EXTRACTED_BYTES) {
                            return ['ok' => false, 'error' => 'حجم واقعی استخراج‌شده از سقف مجاز فراتر رفت.'];
                        }
                        if (fwrite($out, $chunk) === false) {
                            return ['ok' => false, 'error' => 'خطا در نوشتن فایل استخراج‌شده.'];
                        }
                    }
                } finally {
                    fclose($in);
                    fclose($out);
                }
            }

            // اگر کل بسته داخل یک پوشهٔ ریشهٔ واحد (مثلاً refrallbot-1.2.0/) باشد، آن را باز کن
            $packageRoot = $this->resolvePackageRoot($realDest);
            return ['ok' => true, 'root' => $packageRoot, 'files' => count($entries)];
        } finally {
            $zip->close();
        }
    }

    /**
     * اعتبارسنجی کامل بستهٔ استخراج‌شده: VERSION + CHECKSUMS.sha256 + مسیرهای مجاز + مصونیت .env/storage
     * @return array{ok:bool,version?:string,files?:array<int,string>,manifest?:array,error?:string}
     */
    public function validatePackage(string $root, array $options = []): array
    {
        $root = rtrim($root, '/\\');
        if (!is_dir($root)) {
            return ['ok' => false, 'error' => 'پوشهٔ بستهٔ به‌روزرسانی یافت نشد.'];
        }

        // ۱) بررسی وجود فایل CHECKSUMS.sha256 (اجباری)
        $checksumPath = $root . '/' . self::CHECKSUM_FILE;
        if (!is_file($checksumPath)) {
            return ['ok' => false, 'error' => 'بسته فاقد فایل ' . self::CHECKSUM_FILE . ' است و به دلایل امنیتی رد شد.'];
        }

        // ۲) پارس و اعتبارسنجی خطوط CHECKSUMS.sha256
        // نکته مهم: هرگز از ltrim($rel, './') استفاده نشود چون نقطهٔ ابتدای «.env» را حذف می‌کند!
        $lines = file($checksumPath, FILE_IGNORE_NEW_LINES) ?: [];
        $checksums = [];
        foreach ($lines as $idx => $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('~^([a-fA-F0-9]{64})\s+\*?(.+)$~', $line, $m)) {
                return ['ok' => false, 'error' => 'قالب فایل CHECKSUMS.sha256 در خط ' . ($idx + 1) . ' معتبر نیست.'];
            }
            $rel = str_replace('\\', '/', trim($m[2]));
            $rel = preg_replace('~^\./~', '', $rel) ?? $rel;
            $rel = ltrim($rel, '/');

            if ($rel === '' || str_contains($rel, "\0") || self::hasTraversalSegment($rel)) {
                return ['ok' => false, 'error' => 'مسیر نامعتبر در CHECKSUMS.sha256 شناسایی شد.'];
            }
            if ($rel === self::CHECKSUM_FILE || $rel === self::MANIFEST_FILE) {
                continue;
            }
            if (self::isProtectedPath($rel)) {
                return ['ok' => false, 'error' => 'بسته شامل مسیر محافظت‌شده «' . $rel . '» است و رد شد.'];
            }
            if (!self::isAllowedPath($rel)) {
                return ['ok' => false, 'error' => 'بسته شامل مسیر غیرمجاز «' . $rel . '» است و رد شد.'];
            }
            if (isset($checksums[$rel])) {
                return ['ok' => false, 'error' => 'مسیر تکراری «' . $rel . '» در CHECKSUMS.sha256 ثبت شده است.'];
            }
            $checksums[$rel] = strtolower($m[1]);
        }

        if ($checksums === []) {
            return ['ok' => false, 'error' => 'فایل CHECKSUMS.sha256 خالی است.'];
        }

        // ۳) اسکن همهٔ فایل‌های واقعی داخل بسته: هیچ فایل محافظت‌شده، غیرمجاز یا بدون چک‌سام نباید وجود داشته باشد
        $actualFiles = $this->scanRelativeFiles($root);
        foreach ($actualFiles as $rel) {
            $full = $root . '/' . $rel;
            if (is_link($full)) {
                return ['ok' => false, 'error' => 'وجود Symlink در بسته مجاز نیست: ' . $rel];
            }
            if (in_array($rel, self::PACKAGE_META_FILES, true)) {
                continue;
            }
            if (self::isProtectedPath($rel)) {
                return ['ok' => false, 'error' => 'بسته شامل فایل محافظت‌شده «' . $rel . '» است (.env و storage هرگز نباید در بسته باشند).'];
            }
            if (!self::isAllowedPath($rel)) {
                return ['ok' => false, 'error' => 'فایل غیرمجاز «' . $rel . '» در بسته یافت شد.'];
            }
            if (!isset($checksums[$rel])) {
                return ['ok' => false, 'error' => 'فایل «' . $rel . '» در CHECKSUMS.sha256 ثبت نشده است (فایل تأییدنشده).'];
            }
        }

        // ۴) تطبیق دقیق SHA-256 همهٔ فایل‌های ثبت‌شده
        foreach ($checksums as $rel => $expectedHash) {
            $full = $root . '/' . $rel;
            if (!is_file($full) || is_link($full)) {
                return ['ok' => false, 'error' => 'فایل «' . $rel . '» در CHECKSUMS.sha256 ذکر شده اما در بسته موجود نیست.'];
            }
            $actualHash = strtolower((string) hash_file('sha256', $full));
            if (!hash_equals($expectedHash, $actualHash)) {
                return ['ok' => false, 'error' => 'عدم تطابق چک‌سام SHA-256 برای فایل «' . $rel . '» (بسته دستکاری یا ناقص شده است).'];
            }
        }

        // ۵) بررسی فایل VERSION (و حضورش در CHECKSUMS.sha256)
        $versionFile = $root . '/VERSION';
        if (!is_file($versionFile) || !isset($checksums['VERSION'])) {
            return ['ok' => false, 'error' => 'فایل VERSION در بسته یا در CHECKSUMS.sha256 موجود نیست.'];
        }
        $pkgVersion = self::normalizeVersion((string) file_get_contents($versionFile));
        if (!self::isValidVersion($pkgVersion)) {
            return ['ok' => false, 'error' => 'مقدار فایل VERSION در بسته معتبر نیست.'];
        }

        if (!empty($options['expected_version'])) {
            $expectedVer = self::normalizeVersion((string) $options['expected_version']);
            if (self::compareVersions($pkgVersion, $expectedVer) !== 0) {
                return ['ok' => false, 'error' => 'نسخهٔ داخل بسته (' . $pkgVersion . ') با نسخهٔ مورد انتظار (' . $expectedVer . ') مطابقت ندارد.'];
            }
        }

        $currentVersion = (string) ($options['current_version'] ?? $this->currentVersion());
        if (empty($options['allow_downgrade']) && self::isValidVersion($currentVersion)) {
            if (self::compareVersions($pkgVersion, $currentVersion) < 0) {
                return [
                    'ok' => false,
                    'error' => 'نسخهٔ بسته (' . $pkgVersion . ') قدیمی‌تر از نسخهٔ فعلی (' . $currentVersion . ') است؛ نصب نسخهٔ قدیمی‌تر مجاز نیست.',
                ];
            }
        }

        // ۶) بررسی متادیتای اختیاری update.json (نسخه و حداقل PHP)
        $manifest = [];
        $manifestPath = $root . '/' . self::MANIFEST_FILE;
        if (is_file($manifestPath)) {
            $decoded = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'error' => 'فایل update.json داخل بسته ساختار JSON معتبری ندارد.'];
            }
            $manifest = $decoded;
            if (!empty($manifest['version'])) {
                $manifestVer = self::normalizeVersion((string) $manifest['version']);
                if (self::compareVersions($manifestVer, $pkgVersion) !== 0) {
                    return ['ok' => false, 'error' => 'نسخهٔ ثبت‌شده در update.json با فایل VERSION هم‌خوانی ندارد.'];
                }
            }
            if (!empty($manifest['min_php']) && is_string($manifest['min_php'])) {
                if (version_compare(PHP_VERSION, trim($manifest['min_php']), '<')) {
                    return [
                        'ok' => false,
                        'error' => 'این نسخه نیازمند PHP ' . trim($manifest['min_php']) . ' یا بالاتر است (نسخهٔ فعلی سرور: ' . PHP_VERSION . ').',
                    ];
                }
            }
        }

        return [
            'ok' => true,
            'version' => $pkgVersion,
            'files' => array_keys($checksums),
            'manifest' => $manifest,
        ];
    }

    // ================================================================ کپی فایل‌ها

    /**
     * کپی امن فایل‌های مجاز بسته روی مسیر هدف (پیش‌فرض: RB_ROOT؛ در تست: پوشهٔ موقت مجزا)
     *
     * @return array{ok:bool,count?:int,copied?:array<int,string>,added?:array<int,string>,error?:string}
     */
    private function copyFiles(string $root, ?string $targetRoot = null): array
    {
        $root = rtrim($root, '/\\');
        $targetRoot = rtrim($targetRoot ?? $this->targetRoot, '/\\');

        if ($targetRoot === '' || !is_dir($targetRoot)) {
            return ['ok' => false, 'error' => 'پوشهٔ مقصد برای کپی فایل‌ها وجود ندارد.', 'copied' => [], 'added' => []];
        }

        $files = $this->scanRelativeFiles($root);
        $copied = [];
        $added = [];
        $isTestMode = defined('RB_TEST_MODE') || getenv('RB_TEST_MODE') === '1';

        foreach ($files as $rel) {
            if (in_array($rel, self::PACKAGE_META_FILES, true)) {
                continue;
            }
            // حفاظت مضاعف: هرگز به .env و storage/ یا خارج از ALLOWED_PATHS دست نزن
            if (self::isProtectedPath($rel) || !self::isAllowedPath($rel)) {
                continue;
            }

            $source = $root . '/' . $rel;
            if (!is_file($source) || is_link($source)) {
                continue;
            }

            $target = $targetRoot . '/' . $rel;
            if (is_link($target)) {
                return [
                    'ok' => false,
                    'error' => 'مسیر مقصد «' . $rel . '» یک Symlink است و بازنویسی آن مجاز نیست.',
                    'copied' => $copied,
                    'added' => $added,
                ];
            }

            if ($isTestMode && self::$testCopyHook !== null) {
                $allow = (bool) (self::$testCopyHook)($rel, $target);
                if (!$allow) {
                    return [
                        'ok' => false,
                        'error' => 'خطا در کپی فایل «' . $rel . '» (عدم دسترسی نوشتن)',
                        'copied' => $copied,
                        'added' => $added,
                    ];
                }
            }

            $existedBefore = is_file($target);
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                return [
                    'ok' => false,
                    'error' => 'ساخت پوشه برای «' . $rel . '» ممکن نشد (دسترسی نوشتن را بررسی کنید)',
                    'copied' => $copied,
                    'added' => $added,
                ];
            }

            // نوشتن اتمیک با فایل موقت هم‌مسیر و rename تا فایل نیمه‌نوشته باقی نماند
            $tmpTarget = $parent . '/.rb-upd-' . bin2hex(random_bytes(6)) . '.tmp';
            if (!@copy($source, $tmpTarget)) {
                @unlink($tmpTarget);
                return [
                    'ok' => false,
                    'error' => 'نوشتن فایل «' . $rel . '» ممکن نشد (فضای دیسک یا دسترسی نوشتن را بررسی کنید)',
                    'copied' => $copied,
                    'added' => $added,
                ];
            }
            @chmod($tmpTarget, str_starts_with($rel, 'bin/') && str_ends_with($rel, '.php') ? 0755 : 0644);
            if (!@rename($tmpTarget, $target)) {
                // fallback برای فایل‌سیستم‌هایی که rename روی فایل باز را نمی‌پذیرند
                if (!@copy($tmpTarget, $target)) {
                    @unlink($tmpTarget);
                    return [
                        'ok' => false,
                        'error' => 'جایگزینی فایل «' . $rel . '» ممکن نشد',
                        'copied' => $copied,
                        'added' => $added,
                    ];
                }
                @unlink($tmpTarget);
            }

            if (!$existedBefore) {
                $added[] = $rel;
            }
            $copied[] = $rel;

            if (function_exists('opcache_invalidate') && str_ends_with($target, '.php')) {
                @opcache_invalidate($target, true);
            }
        }

        return [
            'ok' => true,
            'count' => count($copied),
            'copied' => $copied,
            'added' => $added,
        ];
    }

    // ================================================================ پشتیبان‌گیری و بازگردانی

    /**
     * پشتیبان SQL از تمام جداول دیتابیس (شامل جدول migrations تا بازگردانی باعث اجرای دوبارهٔ Migrationها نشود)
     * @return array{ok:bool,file?:string,path?:string,size?:int,error?:string}
     */
    public function backupDatabase(?string $versionLabel = null): array
    {
        $dir = $this->backupDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'پوشهٔ storage/backups قابل ساخت نیست.'];
        }

        $ver = preg_replace('~[^0-9A-Za-z._-]~', '', $versionLabel ?? $this->currentVersion()) ?: 'unknown';
        $filename = 'pre-update-db-v' . $ver . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.sql';
        $path = $dir . '/' . $filename;

        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            return ['ok' => false, 'error' => 'امکان نوشتن در پوشهٔ storage/backups وجود ندارد.'];
        }

        try {
            $tables = $this->dbTables();
            fwrite($handle, "-- RefrallBot pre-update database backup\n");
            fwrite($handle, '-- version: ' . $ver . "\n");
            fwrite($handle, '-- driver: ' . $this->db->driver() . "\n");
            fwrite($handle, '-- created: ' . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, '-- tables: ' . count($tables) . "\n\n");

            foreach ($tables as $table) {
                $qTable = $this->db->quoteIdent($table);
                $rows = $this->db->all('SELECT * FROM ' . $qTable);
                fwrite($handle, "\n-- table: {$table} (" . count($rows) . " rows)\n");
                foreach ($rows as $row) {
                    $cols = array_map([$this->db, 'quoteIdent'], array_keys($row));
                    $vals = array_map(function (mixed $v): string {
                        if ($v === null) {
                            return 'NULL';
                        }
                        return $this->db->quote((string) $v);
                    }, array_values($row));
                    fwrite($handle, 'INSERT INTO ' . $qTable . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ");\n");
                }
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($path);
            return ['ok' => false, 'error' => ErrorHandler::redact($e->getMessage())];
        }

        fclose($handle);
        @chmod($path, 0640);
        return ['ok' => true, 'file' => $filename, 'path' => $path, 'size' => (int) (@filesize($path) ?: 0)];
    }

    /** فهرست همهٔ جدول‌های دیتابیس (شامل migrations) */
    private function dbTables(): array
    {
        return $this->db->listTables();
    }

    /**
     * پشتیبان ZIP از فایل‌های مجاز فعلی (بدون .env و بدون storage/)
     * @return array{ok:bool,name?:string,path?:string,files?:int,size?:int,error?:string}
     */
    public function backupFiles(?string $sourceRoot = null, ?string $versionLabel = null): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'error' => 'افزونه ZipArchive فعال نیست.'];
        }
        $sourceRoot = rtrim($sourceRoot ?? $this->targetRoot, '/\\');
        $dir = $this->backupDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'پوشهٔ storage/backups قابل ساخت نیست.'];
        }

        $ver = preg_replace('~[^0-9A-Za-z._-]~', '', $versionLabel ?? $this->currentVersion($sourceRoot)) ?: 'unknown';
        $name = 'pre-update-files-v' . $ver . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.zip';
        $path = $dir . '/' . $name;

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'ساخت آرشیو پشتیبان فایل‌ها ممکن نشد.'];
        }

        $count = 0;
        try {
            foreach (self::ALLOWED_PATHS as $entry) {
                if (str_ends_with($entry, '/')) {
                    $subDir = $sourceRoot . '/' . rtrim($entry, '/');
                    if (!is_dir($subDir) || is_link($subDir)) {
                        continue;
                    }
                    $it = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($subDir, \FilesystemIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    foreach ($it as $file) {
                        /** @var \SplFileInfo $file */
                        if (!$file->isFile() || $file->isLink()) {
                            continue;
                        }
                        $full = str_replace('\\', '/', $file->getPathname());
                        $prefix = str_replace('\\', '/', $sourceRoot) . '/';
                        if (!str_starts_with($full, $prefix)) {
                            continue;
                        }
                        $relative = substr($full, strlen($prefix));
                        if (self::isProtectedPath($relative) || !self::isAllowedPath($relative)) {
                            continue;
                        }
                        if ($zip->addFile($file->getPathname(), $relative)) {
                            $count++;
                        }
                    }
                } else {
                    $full = $sourceRoot . '/' . $entry;
                    if (!is_file($full) || is_link($full)) {
                        continue;
                    }
                    if (self::isProtectedPath($entry) || !self::isAllowedPath($entry)) {
                        continue;
                    }
                    if ($zip->addFile($full, $entry)) {
                        $count++;
                    }
                }
            }

            // اگر پوشهٔ مبدأ خالی بود یک نشانگر متادیتا بگذار تا ZipArchive فایل معتبر بسازد
            $zip->addFromString(self::MANIFEST_FILE, (string) json_encode([
                'type' => 'pre-update-backup',
                'version' => $ver,
                'created_at' => date('c'),
                'files' => $count,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } finally {
            $zip->close();
        }

        if (!is_file($path)) {
            return ['ok' => false, 'error' => 'ذخیرهٔ نهایی فایل پشتیبان ناموفق بود.'];
        }
        @chmod($path, 0640);
        return [
            'ok' => true,
            'name' => $name,
            'path' => $path,
            'files' => $count,
            'size' => (int) (@filesize($path) ?: 0),
        ];
    }

    /**
     * بازگردانی فایل‌های سامانه از پشتیبان ZIP (بدون دست زدن به .env و storage/)
     *
     * @param string[] $removeAdded فایل‌هایی که در به‌روزرسانی ناموفق تازه اضافه شده بودند و باید حذف شوند
     * @return array{ok:bool,restored_files?:int,version?:string,error?:string}
     */
    public function restoreFiles(string $backupName, ?string $targetRoot = null, array $removeAdded = [], ?int $adminId = null): array
    {
        $clean = basename(trim($backupName));
        if ($clean === '' || $clean !== trim($backupName) || !preg_match('~^pre-update-files-[A-Za-z0-9._-]+\.zip$~', $clean)) {
            return ['ok' => false, 'error' => 'نام فایل پشتیبان معتبر نیست.'];
        }

        $zipPath = $this->backupDir() . '/' . $clean;
        if (!is_file($zipPath)) {
            return ['ok' => false, 'error' => 'فایل پشتیبان انتخاب‌شده یافت نشد.'];
        }

        $targetRoot = rtrim($targetRoot ?? $this->targetRoot, '/\\');
        $restoreStage = $this->workDir() . '/restore-' . bin2hex(random_bytes(6));

        // غیرفعال‌سازی موقت هوک خطای تست هنگام بازگردانی تا بازگردانی واقعی انجام شود
        $savedHook = self::$testCopyHook;
        self::$testCopyHook = null;

        try {
            $extract = $this->extractArchive($zipPath, $restoreStage);
            if (!$extract['ok']) {
                return ['ok' => false, 'error' => 'استخراج پشتیبان ناموفق بود: ' . (string) $extract['error']];
            }

            $copy = $this->copyFiles((string) $extract['root'], $targetRoot);
            if (!$copy['ok']) {
                return ['ok' => false, 'error' => 'بازگردانی فایل‌ها ناموفق بود: ' . (string) $copy['error']];
            }

            // حذف فایل‌های جدیدی که پیش از به‌روزرسانی ناموفق اصلاً وجود نداشتند
            $backupSet = array_flip((array) ($copy['copied'] ?? []));
            foreach ($removeAdded as $addedRel) {
                $addedRel = ltrim(str_replace('\\', '/', (string) $addedRel), '/');
                if (isset($backupSet[$addedRel]) || self::isProtectedPath($addedRel) || !self::isAllowedPath($addedRel)) {
                    continue;
                }
                $addedFull = $targetRoot . '/' . $addedRel;
                if (is_file($addedFull) && !is_link($addedFull)) {
                    @unlink($addedFull);
                }
            }

            $this->resetOpcache();
            $restoredVersion = $this->currentVersion($targetRoot);

            if ($adminId !== null) {
                $this->recordOutcome('restored', 'rollback', '', $restoredVersion, '', null, $clean, true, $adminId);
                try {
                    AuditService::log('update.restored', 'update', null, [
                        'backup' => $clean,
                        'version' => $restoredVersion,
                        'files' => (int) ($copy['count'] ?? 0),
                    ], $adminId);
                } catch (\Throwable) {
                }
            }

            return [
                'ok' => true,
                'restored_files' => (int) ($copy['count'] ?? 0),
                'version' => $restoredVersion,
            ];
        } finally {
            self::$testCopyHook = $savedHook;
            $this->removeDirectory($restoreStage);
        }
    }

    /** فهرست پشتیبان‌های فایل قابل بازگردانی */
    public function listFileBackups(): array
    {
        $dir = $this->backupDir();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/pre-update-files-*.zip') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            $version = '—';
            if (preg_match('~^pre-update-files-v([0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+?)?)-\d{8}-\d{6}~', $name, $m)) {
                $version = $m[1];
            }
            $out[] = [
                'name' => $name,
                'version' => $version,
                'size' => (int) (@filesize($path) ?: 0),
                'created_at' => date('Y-m-d H:i:s', (int) (@filemtime($path) ?: time())),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($b['created_at'] . $b['name'], $a['created_at'] . $a['name']));
        return $out;
    }

    /** تاریخچهٔ عملیات به‌روزرسانی و بازگردانی */
    public function history(): array
    {
        $raw = (string) (SettingsService::get('update_history_json', '') ?? '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * ساخت بستهٔ رسمی به‌روزرسانی ZIP همراه با VERSION، update.json، CHECKSUMS.sha256 و فایل .sha256
     *
     * @return array{ok:bool,version?:string,path?:string,sha256_file?:string,sha256?:string,files_count?:int,size?:int,error?:string}
     */
    public static function buildPackage(string $sourceRoot, string $version, string $outPath, string $minPhp = '8.1.0'): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'error' => 'افزونه ZipArchive در PHP فعال نیست.'];
        }
        $sourceRoot = rtrim($sourceRoot, '/\\');
        if (!is_dir($sourceRoot)) {
            return ['ok' => false, 'error' => 'پوشهٔ مبدأ یافت نشد: ' . $sourceRoot];
        }
        $normVer = self::normalizeVersion($version);
        if (!self::isValidVersion($normVer)) {
            return ['ok' => false, 'error' => 'شماره نسخه معتبر نیست («' . $version . '»). قالب صحیح: X.Y.Z'];
        }
        $parent = dirname($outPath);
        if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
            return ['ok' => false, 'error' => 'ساخت پوشهٔ مقصد ممکن نشد: ' . $parent];
        }

        /** @var array<string,array{type:string,value:string}> $entries */
        $entries = [];
        $sourceNorm = str_replace('\\', '/', $sourceRoot);

        foreach (self::ALLOWED_PATHS as $allowed) {
            if ($allowed === 'VERSION') {
                continue;
            }
            if (str_ends_with($allowed, '/')) {
                $dir = $sourceRoot . '/' . rtrim($allowed, '/');
                if (!is_dir($dir) || is_link($dir)) {
                    continue;
                }
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($it as $file) {
                    /** @var \SplFileInfo $file */
                    if (!$file->isFile() || $file->isLink()) {
                        continue;
                    }
                    $full = str_replace('\\', '/', $file->getPathname());
                    if (!str_starts_with($full, $sourceNorm . '/')) {
                        continue;
                    }
                    $rel = substr($full, strlen($sourceNorm) + 1);
                    $base = basename($rel);
                    if ($base === '.DS_Store' || $base === 'Thumbs.db' || str_ends_with($base, '.swp') || str_contains($base, '.rb-upd-')) {
                        continue;
                    }
                    if (self::isProtectedPath($rel) || !self::isAllowedPath($rel)) {
                        continue;
                    }
                    $entries[$rel] = ['type' => 'file', 'value' => $file->getPathname()];
                }
            } else {
                $full = $sourceRoot . '/' . $allowed;
                if (!is_file($full) || is_link($full)) {
                    continue;
                }
                if (self::isProtectedPath($allowed) || !self::isAllowedPath($allowed)) {
                    continue;
                }
                $entries[$allowed] = ['type' => 'file', 'value' => $full];
            }
        }

        $entries['VERSION'] = ['type' => 'string', 'value' => $normVer . "\n"];
        ksort($entries);

        $checksumLines = [];
        foreach ($entries as $rel => $item) {
            $hash = $item['type'] === 'string'
                ? hash('sha256', $item['value'])
                : (string) hash_file('sha256', $item['value']);
            $checksumLines[] = strtolower($hash) . '  ' . $rel;
        }
        $checksumsContent = implode("\n", $checksumLines) . "\n";
        $manifestContent = (string) json_encode([
            'name' => 'RefrallBot',
            'version' => $normVer,
            'min_php' => $minPhp,
            'files_count' => count($entries),
            'built_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        if (is_file($outPath)) {
            @unlink($outPath);
        }
        $zip = new \ZipArchive();
        if ($zip->open($outPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'ساخت فایل ZIP ممکن نشد: ' . $outPath];
        }
        foreach ($entries as $rel => $item) {
            $ok = $item['type'] === 'string'
                ? $zip->addFromString($rel, $item['value'])
                : $zip->addFile($item['value'], $rel);
            if (!$ok) {
                $zip->close();
                @unlink($outPath);
                return ['ok' => false, 'error' => 'خطا در افزودن فایل به بسته: ' . $rel];
            }
        }
        $zip->addFromString(self::CHECKSUM_FILE, $checksumsContent);
        $zip->addFromString(self::MANIFEST_FILE, $manifestContent);
        $zip->close();

        $size = is_file($outPath) ? (int) (@filesize($outPath) ?: 0) : 0;
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'فایل خروجی ZIP ساخته نشد.'];
        }

        $zipSha = strtolower((string) hash_file('sha256', $outPath));
        $shaFile = $outPath . '.sha256';
        @file_put_contents($shaFile, $zipSha . '  ' . basename($outPath) . "\n");

        return [
            'ok' => true,
            'version' => $normVer,
            'path' => $outPath,
            'sha256_file' => $shaFile,
            'sha256' => $zipSha,
            'files_count' => count($entries),
            'size' => $size,
        ];
    }

    // ================================================================ اعتبارسنجی مسیرها

    /** آیا مسیر جزو مسیرهای محافظت‌شده است که هرگز نباید بازنویسی شوند؟ */
    public static function isProtectedPath(string $rel): bool
    {
        $norm = str_replace('\\', '/', trim($rel));
        $norm = preg_replace('~^\./~', '', $norm) ?? $norm;
        $norm = trim($norm, '/');
        if ($norm === '') {
            return true;
        }
        $lower = strtolower($norm);

        // .env و همهٔ گونه‌های آن (.env.local, .env.production, ...)
        if ($lower === '.env' || str_starts_with($lower, '.env.')) {
            return true;
        }
        // storage/ و پوشه‌های مخزن/وابستگی
        foreach (['storage', '.git', '.github', 'vendor', 'node_modules'] as $dir) {
            if ($lower === $dir || str_starts_with($lower, $dir . '/')) {
                return true;
            }
        }
        // بررسی نام فایل در هر سطح از مسیر
        $base = strtolower(basename($norm));
        if ($base === '.env' || str_starts_with($base, '.env.') || $base === 'installed.lock' || $base === 'app_key.php') {
            return true;
        }
        foreach (['.sqlite', '.sqlite3', '.key', '.pem'] as $ext) {
            if (str_ends_with($lower, $ext)) {
                return true;
            }
        }
        return false;
    }

    /** آیا مسیر دقیقاً در فهرست سفید ALLOWED_PATHS قرار دارد؟ */
    public static function isAllowedPath(string $rel): bool
    {
        if ($rel === '' || str_contains($rel, "\0") || str_contains($rel, '\\')) {
            return false;
        }
        if (str_starts_with($rel, '/') || preg_match('~^[A-Za-z]:~', $rel) || self::hasTraversalSegment($rel)) {
            return false;
        }
        if (self::isProtectedPath($rel)) {
            return false;
        }
        foreach (self::ALLOWED_PATHS as $allowed) {
            if (str_ends_with($allowed, '/')) {
                if (str_starts_with($rel, $allowed) && strlen($rel) > strlen($allowed)) {
                    return true;
                }
            } elseif ($rel === $allowed) {
                return true;
            }
        }
        return false;
    }

    /** پاک‌سازی و بررسی امنیتی مسیر هر مدخل داخل آرشیو ZIP (null = ناامن) */
    public static function sanitizeArchiveEntryPath(string $raw): ?string
    {
        if ($raw === '' || str_contains($raw, "\0")) {
            return null;
        }
        $norm = str_replace('\\', '/', $raw);
        if (str_starts_with($norm, '/') || preg_match('~^[A-Za-z]:~', $norm)) {
            return null;
        }
        $parts = explode('/', $norm);
        $clean = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $clean[] = $part;
        }
        return implode('/', $clean);
    }

    private static function hasTraversalSegment(string $path): bool
    {
        foreach (explode('/', str_replace('\\', '/', $path)) as $seg) {
            if ($seg === '..') {
                return true;
            }
        }
        return false;
    }

    private static function hasZipSignature(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $magic = (string) fread($fh, 4);
        fclose($fh);
        // PK\x03\x04 (آرشیو عادی) یا PK\x05\x06 (آرشیو خالی)
        return $magic === "PK\x03\x04" || $magic === "PK\x05\x06";
    }

    // ================================================================ ابزارهای داخلی، قفل و پاک‌سازی

    public function workDir(): string
    {
        $dir = $this->storageRoot . '/updates/work';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public function backupDir(): string
    {
        $dir = $this->storageRoot . '/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** پاک‌سازی فایل‌ها و پوشه‌های موقت قدیمی در پوشهٔ کاری به‌روزرسانی */
    public function pruneWorkDir(int $maxAgeSeconds = 3600): int
    {
        $dir = $this->workDir();
        if (!is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        $cutoff = time() - max(0, $maxAgeSeconds);
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..' || $item === 'update.lock' || $item === '.htaccess' || $item === '.gitkeep') {
                continue;
            }
            $full = $dir . '/' . $item;
            $mtime = (int) (@filemtime($full) ?: 0);
            if ($mtime > 0 && $mtime > $cutoff) {
                continue;
            }
            if (is_dir($full) && !is_link($full)) {
                $this->removeDirectory($full);
                $removed++;
            } elseif (@unlink($full)) {
                $removed++;
            }
        }
        return $removed;
    }

    private function pruneBackups(): void
    {
        $dir = $this->backupDir();
        foreach (['pre-update-files-*.zip', 'pre-update-db-*.sql'] as $pattern) {
            $list = glob($dir . '/' . $pattern) ?: [];
            if (count($list) <= self::MAX_BACKUPS_KEEP) {
                continue;
            }
            usort($list, static fn(string $a, string $b): int => ((int) @filemtime($a)) <=> ((int) @filemtime($b)));
            while (count($list) > self::MAX_BACKUPS_KEEP) {
                $old = array_shift($list);
                if ($old !== null) {
                    @unlink($old);
                }
            }
        }
    }

    private function acquireLock(): bool
    {
        $path = $this->workDir() . '/update.lock';
        // پاک‌سازی قفل مردهٔ قدیمی (اگر فرآیند قبلی کرش کرده باشد)
        if (is_file($path)) {
            $age = time() - (int) (@filemtime($path) ?: time());
            if ($age > self::LOCK_TTL) {
                @unlink($path);
            }
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        @ftruncate($handle, 0);
        @fwrite($handle, (string) json_encode(['pid' => getmypid(), 'started_at' => date('c')]));
        @fflush($handle);
        $this->lockHandle = $handle;
        $this->lockPath = $path;
        return true;
    }

    private function releaseLock(): void
    {
        if (is_resource($this->lockHandle)) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
        }
        $this->lockHandle = null;
        if ($this->lockPath !== null && is_file($this->lockPath)) {
            @unlink($this->lockPath);
        }
        $this->lockPath = null;
    }

    private function resolvePackageRoot(string $extractedDir): string
    {
        if (is_file($extractedDir . '/VERSION') || is_file($extractedDir . '/' . self::CHECKSUM_FILE)) {
            return $extractedDir;
        }
        $items = array_values(array_filter(
            scandir($extractedDir) ?: [],
            static fn(string $n): bool => $n !== '.' && $n !== '..' && $n !== '__MACOSX'
        ));
        if (count($items) === 1) {
            $candidate = $extractedDir . '/' . $items[0];
            if (is_dir($candidate) && !is_link($candidate)) {
                return $candidate;
            }
        }
        return $extractedDir;
    }

    /** @return string[] مسیرهای نسبی مرتب‌شدهٔ همهٔ فایل‌های داخل یک پوشه */
    private function scanRelativeFiles(string $root): array
    {
        $root = str_replace('\\', '/', rtrim($root, '/\\'));
        $prefix = $root . '/';
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $full = str_replace('\\', '/', $item->getPathname());
            if (!str_starts_with($full, $prefix)) {
                continue;
            }
            $rel = substr($full, strlen($prefix));
            if ($rel === '' || str_starts_with($rel, '__MACOSX/') || str_ends_with($rel, '/.DS_Store') || $rel === '.DS_Store') {
                continue;
            }
            $files[] = $rel;
        }
        sort($files);
        return $files;
    }

    private function removeDirectory(string $dir): void
    {
        if ($dir === '' || !is_dir($dir) || is_link($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    private function recordOutcome(
        string $status,
        string $source,
        string $from,
        string $to,
        string $error,
        ?string $dbBackup,
        ?string $filesBackup,
        bool $restored,
        ?int $adminId
    ): void {
        try {
            $history = $this->history();
            array_unshift($history, [
                'at' => now(),
                'status' => $status,
                'source' => $source,
                'from' => $from,
                'to' => $to,
                'db_backup' => $dbBackup,
                'files_backup' => $filesBackup,
                'restored' => $restored,
                'error' => $error !== '' ? mb_substr($error, 0, 400) : null,
                'admin_id' => $adminId,
            ]);
            $history = array_slice($history, 0, self::MAX_HISTORY_ITEMS);
            SettingsService::set('update_history_json', (string) json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update');

            if ($status === 'success') {
                AuditService::log('update.applied', 'update', null, [
                    'source' => $source, 'from' => $from, 'to' => $to, 'backup' => $dbBackup,
                ], $adminId);
            } elseif ($status === 'failed') {
                AuditService::log('update.failed', 'update', null, [
                    'source' => $source, 'from' => $from, 'to' => $to, 'restored' => $restored, 'error' => mb_substr($error, 0, 250),
                ], $adminId);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * درخواست GET امن به GitHub (با تأیید کامل SSL و پشتیبانی از ترنسپورت تست)
     * @return array{status:int,body:string,error:?string}
     */
    private function httpGet(string $url, bool $binary): array
    {
        $headers = [
            'User-Agent: RefrallBot/' . $this->currentVersion(),
            'Accept: ' . ($binary ? 'application/octet-stream' : 'application/vnd.github+json'),
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        $token = self::githubToken();
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        if (self::$fakeHttp !== null) {
            /** @var callable $fake */
            $fake = self::$fakeHttp;
            $res = $fake($url, $headers, $binary);
            return [
                'status' => (int) ($res['status'] ?? 0),
                'body' => (string) ($res['body'] ?? ''),
                'error' => isset($res['error']) && $res['error'] !== null ? (string) $res['error'] : null,
            ];
        }

        if (!self::isAllowedDownloadUrl($url)) {
            return ['status' => 0, 'body' => '', 'error' => 'دامنهٔ مقصد مجاز نیست.'];
        }

        $timeout = $binary ? 90 : 15;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
                $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
            }
            curl_setopt_array($ch, $options);
            $body = curl_exec($ch);
            $err = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            if ($body === false) {
                return ['status' => $status, 'body' => '', 'error' => $err !== '' ? $err : 'عدم برقراری ارتباط'];
            }
            if ($effectiveUrl !== '' && !self::isAllowedDownloadUrl($effectiveUrl)) {
                return ['status' => 0, 'body' => '', 'error' => 'تغییر مسیر به دامنهٔ غیرمجاز مسدود شد.'];
            }
            return ['status' => $status, 'body' => (string) $body, 'error' => null];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $timeout,
                'follow_location' => 1,
                'max_redirects' => 5,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $http_response_header = [];
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
            if (preg_match('~^Location:\s*(\S+)~i', $line, $lm)) {
                if (!self::isAllowedDownloadUrl(trim($lm[1]))) {
                    return ['status' => 0, 'body' => '', 'error' => 'تغییر مسیر به دامنهٔ غیرمجاز مسدود شد.'];
                }
            }
        }
        if ($body === false) {
            $lastErr = error_get_last()['message'] ?? 'اتصال برقرار نشد';
            return ['status' => $status, 'body' => '', 'error' => $lastErr];
        }
        return ['status' => $status > 0 ? $status : 200, 'body' => $body, 'error' => null];
    }
}
