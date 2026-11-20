<?php
declare(strict_types=1);

namespace RB\Messengers;

use RB\Services\AuditService;
use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Support\Config;
use RB\Support\Crypto;
use RB\Support\Database;
use RB\Support\ErrorHandler;
use RB\Support\Request;

/**
 * رجیستری مرکزی مدیریت پیام‌رسان‌ها (تلگرام، بله، روبیکا، ایتا و پلاگین‌های آینده)
 *
 * اصول طراحی:
 *   - توکن‌ها فقط به‌صورت رمزنگاری‌شده AES-256-GCM در پایگاه داده ذخیره می‌شوند و هرگز به کلاینت فرستاده نمی‌شوند.
 *   - فعال/غیرفعال‌سازی، خطا یا قطع هر پیام‌رسان کاملاً ایزوله است و کوچک‌ترین اثری روی سایر پیام‌رسان‌ها ندارد.
 *   - دوطرفه با telegram_settings همگام است تا ارتقا از نسخه‌های قبلی و تمام تست‌ها و مسیرهای تلگرام بدون اختلال کار کنند.
 *   - با registerDriverClass() می‌توان پیام‌رسان‌های جدید را بدون بازنویسی هسته اضافه کرد.
 */
final class MessengerRegistry
{
    /** @var array<string,class-string<MessengerDriverInterface>> */
    private static array $driverMap = [
        MessengerCapabilities::TELEGRAM => TelegramDriver::class,
        MessengerCapabilities::BALE     => BaleDriver::class,
        MessengerCapabilities::RUBIKA   => RubikaDriver::class,
        MessengerCapabilities::EITAA    => EitaaDriver::class,
    ];

    /** @var array<int,bool> کش درون‌درخواستی ensureSeeded به ازای هر نمونه Database */
    private static array $seededInstances = [];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /** ثبت درایور پیام‌رسان جدید برای توسعه‌های آینده */
    public static function registerDriverClass(string $platform, string $driverClass, array $capabilityDefinition = []): void
    {
        $key = strtolower(trim($platform));
        if ($key === '' || !is_subclass_of($driverClass, MessengerDriverInterface::class)) {
            throw new \InvalidArgumentException('کلاس درایور پیام‌رسان معتبر نیست.');
        }
        self::$driverMap[$key] = $driverClass;
        if ($capabilityDefinition !== []) {
            MessengerCapabilities::register($key, $capabilityDefinition);
        }
    }

    public static function resetCache(): void
    {
        self::$seededInstances = [];
    }

    /**
     * ایجاد اولیه ردیف‌های messenger_connections و همگام‌سازی یکپارچه با تنظیمات موجود تلگرام
     * (کاملاً Idempotent و سازگار با MySQL strict mode و SQLite)
     */
    public function ensureSeeded(): void
    {
        $oid = spl_object_id($this->db);
        if (!empty(self::$seededInstances[$oid])) {
            return;
        }
        if (!$this->db->tableExists('messenger_connections')) {
            return;
        }

        $now = now();
        $tgLegacy = $this->db->tableExists('telegram_settings')
            ? ($this->db->first('SELECT * FROM telegram_settings ORDER BY id ASC LIMIT 1') ?? [])
            : [];

        foreach (MessengerCapabilities::platforms() as $platform) {
            $meta = MessengerCapabilities::get($platform);
            $existing = $this->db->first('SELECT * FROM messenger_connections WHERE platform = :p LIMIT 1', ['p' => $platform]);

            if ($existing === null) {
                $secret = bin2hex(random_bytes(24));
                $row = [
                    'platform' => $platform,
                    'display_name' => (string) $meta['name'],
                    'is_enabled' => 0,
                    'bot_token_enc' => null,
                    'secondary_token_enc' => null,
                    'webhook_secret' => $secret,
                    'webhook_url' => null,
                    'webhook_status' => 'not_set',
                    'webhook_info' => '',
                    'polling_enabled' => 0,
                    'polling_offset' => null,
                    'app_slug' => $platform === MessengerCapabilities::EITAA ? 'app' : null,
                    'bot_id' => null,
                    'bot_username' => null,
                    'bot_name' => null,
                    'bot_short_description' => null,
                    'bot_description' => null,
                    'bot_start_message' => null,
                    'bot_share_url' => null,
                    'bot_photo_file_id' => null,
                    'bot_photo_path' => null,
                    'profile_synced_at' => null,
                    'connection_status' => 'unconfigured',
                    'last_error' => '',
                    'last_test_at' => null,
                    'last_update_at' => null,
                    'updates_received' => 0,
                    'messages_sent' => 0,
                    'messages_failed' => 0,
                    'extra_config' => '{}',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // انتقال خودکار تنظیمات ربات تلگرام در هنگام ارتقا از نسخه‌های قبلی
                if ($platform === MessengerCapabilities::TELEGRAM && $tgLegacy !== []) {
                    $hasTok = !empty($tgLegacy['bot_token_enc']);
                    $row['is_enabled'] = $hasTok ? 1 : 0;
                    $row['bot_token_enc'] = $tgLegacy['bot_token_enc'] ?? null;
                    if (!empty($tgLegacy['webhook_secret'])) {
                        $row['webhook_secret'] = (string) $tgLegacy['webhook_secret'];
                    }
                    $row['webhook_url'] = $tgLegacy['webhook_url'] ?? null;
                    $row['webhook_status'] = !empty($tgLegacy['webhook_active']) ? 'active' : 'not_set';
                    $row['webhook_info'] = (string) ($tgLegacy['webhook_info'] ?? '');
                    $row['bot_id'] = !empty($tgLegacy['bot_id']) ? (string) $tgLegacy['bot_id'] : null;
                    $row['bot_username'] = $tgLegacy['bot_username'] ?? null;
                    $row['bot_name'] = $tgLegacy['bot_name'] ?? null;
                    $row['bot_short_description'] = $tgLegacy['bot_short_description'] ?? null;
                    $row['bot_description'] = $tgLegacy['bot_description'] ?? null;
                    $row['bot_photo_file_id'] = $tgLegacy['bot_photo_file_id'] ?? null;
                    $row['bot_photo_path'] = $tgLegacy['bot_photo_path'] ?? null;
                    $row['profile_synced_at'] = $tgLegacy['profile_synced_at'] ?? null;
                    $wasConnected = (($tgLegacy['connection_status'] ?? '') === 'connected')
                        || !empty($tgLegacy['webhook_active'])
                        || ($hasTok && !empty($tgLegacy['bot_username']));
                    $row['connection_status'] = $wasConnected
                        ? 'connected'
                        : (string) ($tgLegacy['connection_status'] ?? ($hasTok ? 'configured' : 'unconfigured'));
                    $row['last_error'] = (string) ($tgLegacy['last_error'] ?? '');
                    $row['last_test_at'] = $tgLegacy['last_test_at'] ?? null;
                    $row['last_update_at'] = $tgLegacy['last_update_at'] ?? null;
                    $row['updates_received'] = (int) ($tgLegacy['updates_received'] ?? 0);
                    $row['messages_sent'] = (int) ($tgLegacy['messages_sent'] ?? 0);
                    $row['messages_failed'] = (int) ($tgLegacy['messages_failed'] ?? 0);
                }

                try {
                    $this->db->insert('messenger_connections', $row);
                } catch (\Throwable) {
                }
            } elseif ($platform === MessengerCapabilities::TELEGRAM && empty($existing['bot_token_enc']) && !empty($tgLegacy['bot_token_enc'])) {
                // اگر توکن از مسیر قدیمی TelegramController ثبت شده بود، آن را به رجیستری منتقل کن
                $this->syncFromLegacyTelegram($tgLegacy);
            }
        }

        // انتقال یک‌باره حساب‌های کاربران تلگرام فعلی به جدول user_messenger_accounts
        $this->backfillLegacyTelegramAccounts();

        self::$seededInstances[$oid] = true;
    }

    /** انتقال بدون افت داده کاربران تلگرام قبلی به جدول هویت‌های پیام‌رسان */
    public function backfillLegacyTelegramAccounts(): void
    {
        if (!$this->db->tableExists('user_messenger_accounts') || !$this->db->tableExists('users')) {
            return;
        }
        try {
            $missing = $this->db->all(
                "SELECT u.id, u.telegram_id, u.username, u.first_name, u.last_name, u.language, u.status, u.last_activity_at, u.created_at
                 FROM users u
                 LEFT JOIN user_messenger_accounts a ON a.platform = 'telegram' AND a.user_id = u.id
                 WHERE u.telegram_id > 0 AND a.id IS NULL
                 LIMIT 500"
            );
            foreach ($missing as $u) {
                $tid = (string) $u['telegram_id'];
                $blocked = (($u['status'] ?? 'active') === 'blocked') ? 1 : 0;
                try {
                    $this->db->insert('user_messenger_accounts', [
                        'user_id' => (int) $u['id'],
                        'platform' => MessengerCapabilities::TELEGRAM,
                        'external_user_id' => $tid,
                        'external_chat_id' => $tid,
                        'username' => $u['username'] ?? null,
                        'first_name' => $u['first_name'] ?? null,
                        'last_name' => $u['last_name'] ?? null,
                        'language_code' => $u['language'] ?? 'fa',
                        'can_send_message' => $blocked ? 0 : 1,
                        'is_blocked' => $blocked,
                        'is_primary' => 1,
                        'verified_via' => 'legacy_telegram',
                        'meta' => '{}',
                        'first_seen_at' => (string) ($u['created_at'] ?? now()),
                        'last_seen_at' => (string) ($u['last_activity_at'] ?? $u['created_at'] ?? now()),
                        'created_at' => (string) ($u['created_at'] ?? now()),
                        'updated_at' => now(),
                    ]);
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
        }
    }

    /** همگام‌سازی تنظیمات قدیمی telegram_settings به messenger_connections */
    public function syncFromLegacyTelegram(?array $tgRow = null): void
    {
        if (!$this->db->tableExists('messenger_connections') || !$this->db->tableExists('telegram_settings')) {
            return;
        }
        $tg = $tgRow ?? ($this->db->first('SELECT * FROM telegram_settings ORDER BY id ASC LIMIT 1') ?? []);
        if ($tg === []) {
            return;
        }
        $hasTok = !empty($tg['bot_token_enc']);
        $data = [
            'bot_token_enc' => $tg['bot_token_enc'] ?? null,
            'is_enabled' => $hasTok ? 1 : 0,
            'bot_id' => !empty($tg['bot_id']) ? (string) $tg['bot_id'] : null,
            'bot_username' => $tg['bot_username'] ?? null,
            'bot_name' => $tg['bot_name'] ?? null,
            'webhook_url' => $tg['webhook_url'] ?? null,
            'webhook_status' => !empty($tg['webhook_active']) ? 'active' : 'not_set',
            'connection_status' => (string) ($tg['connection_status'] ?? ($hasTok ? 'configured' : 'unconfigured')),
            'last_error' => (string) ($tg['last_error'] ?? ''),
            'last_test_at' => $tg['last_test_at'] ?? null,
            'updated_at' => now(),
        ];
        if (!empty($tg['webhook_secret'])) {
            $data['webhook_secret'] = (string) $tg['webhook_secret'];
        }
        foreach (['bot_short_description', 'bot_description', 'bot_photo_file_id', 'bot_photo_path', 'profile_synced_at'] as $col) {
            if (array_key_exists($col, $tg)) {
                $data[$col] = $tg[$col];
            }
        }
        $this->db->update('messenger_connections', $data, 'platform = :p', ['p' => MessengerCapabilities::TELEGRAM]);
    }

    /** همگام‌سازی معکوس از messenger_connections به telegram_settings برای سازگاری ۱۰۰٪ کدهای قدیمی */
    private function syncToLegacyTelegram(array $conn): void
    {
        if (!$this->db->tableExists('telegram_settings')) {
            return;
        }
        $data = [
            'bot_token_enc' => $conn['bot_token_enc'] ?? null,
            'webhook_secret' => $conn['webhook_secret'] ?? null,
            'webhook_url' => $conn['webhook_url'] ?? null,
            'webhook_active' => (($conn['webhook_status'] ?? '') === 'active') ? 1 : 0,
            'webhook_info' => (string) ($conn['webhook_info'] ?? ''),
            'bot_id' => (!empty($conn['bot_id']) && ctype_digit((string) $conn['bot_id'])) ? (int) $conn['bot_id'] : null,
            'bot_username' => $conn['bot_username'] ?? null,
            'bot_name' => $conn['bot_name'] ?? null,
            'connection_status' => (string) ($conn['connection_status'] ?? 'unconfigured'),
            'last_error' => (string) ($conn['last_error'] ?? ''),
            'last_test_at' => $conn['last_test_at'] ?? null,
            'updated_at' => now(),
        ];
        foreach (['bot_short_description', 'bot_description', 'bot_photo_file_id', 'bot_photo_path', 'profile_synced_at'] as $col) {
            if ($this->db->columnExists('telegram_settings', $col) && array_key_exists($col, $conn)) {
                $data[$col] = $conn[$col];
            }
        }
        $existing = $this->db->first('SELECT id FROM telegram_settings ORDER BY id ASC LIMIT 1');
        if ($existing) {
            $this->db->update('telegram_settings', $data, 'id = :id', ['id' => (int) $existing['id']]);
        } else {
            $data['created_at'] = now();
            $this->db->insert('telegram_settings', $data);
        }
        TelegramService::forgetSettingsCache();
    }

    /** دریافت ردیف خام اتصال یک پیام‌رسان (فقط استفاده داخلی سرور) */
    public function rawConnection(string $platform): array
    {
        $p = strtolower(trim($platform));
        if (!MessengerCapabilities::isSupported($p)) {
            throw new \InvalidArgumentException('پیام‌رسان نامعتبر: ' . $platform);
        }
        $this->ensureSeeded();
        if (!$this->db->tableExists('messenger_connections')) {
            return ['platform' => $p, 'is_enabled' => 0, 'connection_status' => 'unconfigured'];
        }
        $row = $this->db->first('SELECT * FROM messenger_connections WHERE platform = :p LIMIT 1', ['p' => $p]) ?? [];

        // اگر تلگرام از طریق TelegramService/تست مستقیم تغییر کرده باشد، توکن و نام کاربری را همگام بخوان
        if ($p === MessengerCapabilities::TELEGRAM && $this->db->tableExists('telegram_settings')) {
            $tg = TelegramService::settings($this->db);
            if (!empty($tg['bot_token_enc']) && ($row['bot_token_enc'] ?? null) !== $tg['bot_token_enc']) {
                $row['bot_token_enc'] = $tg['bot_token_enc'];
                $row['bot_username'] = $tg['bot_username'] ?? ($row['bot_username'] ?? null);
                $row['bot_name'] = $tg['bot_name'] ?? ($row['bot_name'] ?? null);
                $row['bot_id'] = isset($tg['bot_id']) ? (string) $tg['bot_id'] : ($row['bot_id'] ?? null);
                if (($row['connection_status'] ?? 'unconfigured') === 'unconfigured') {
                    $row['is_enabled'] = 1;
                    $row['connection_status'] = (string) ($tg['connection_status'] ?? 'connected');
                }
            } elseif (empty($row['bot_username']) && !empty($tg['bot_username'])) {
                $row['bot_username'] = $tg['bot_username'];
            }
        }

        return $row;
    }

    /**
     * دریافت وضعیت امن یک پیام‌رسان برای نمایش در پنل (بدون هیچ‌گونه توکن رمزگشایی‌شده یا رمزنگاری‌شده)
     */
    public function safeConnection(string $platform): array
    {
        $p = strtolower(trim($platform));
        $row = $this->rawConnection($p);
        $meta = MessengerCapabilities::get($p);

        $plainTok = (string) (Crypto::decrypt($row['bot_token_enc'] ?? null) ?? '');
        $plainSec = (string) (Crypto::decrypt($row['secondary_token_enc'] ?? null) ?? '');

        $pathSecret = (string) ($row['webhook_secret'] ?? '');
        if ($pathSecret !== '' && isset($row['webhook_url'])) {
            $row['webhook_url'] = str_replace($pathSecret, '***', (string) $row['webhook_url']);
        }
        unset($row['bot_token_enc'], $row['secondary_token_enc'], $row['webhook_secret']);

        $hasHttps = str_starts_with(strtolower(SettingsService::miniappBaseUrl()), 'https://');
        $uiMode = MessengerCapabilities::resolveUiMode($p, SettingsService::configuredBotUiMode(), $hasHttps);

        $row['meta'] = $meta;
        $row['has_token'] = $plainTok !== '';
        $row['masked_token'] = $plainTok !== '' ? Crypto::maskSecret($plainTok) : '';
        $row['has_secondary_token'] = $plainSec !== '';
        $row['masked_secondary_token'] = $plainSec !== '' ? Crypto::maskSecret($plainSec) : '';
        $row['is_enabled'] = !empty($row['is_enabled']);
        $row['polling_enabled'] = !empty($row['polling_enabled']);
        $row['suggested_webhook_url'] = $this->suggestedWebhookUrl($p, false);
        $row['suggested_miniapp_url'] = $this->suggestedMiniAppUrl($p);
        $row['ui_mode'] = $uiMode;
        $row['is_active_connected'] = self::isConnectionActiveAndConnected($row);
        $row['status_badge'] = self::statusBadge($row);
        $row['has_photo'] = !empty($row['bot_photo_path']) || !empty($row['bot_photo_file_id']);

        return $row;
    }

    /**
     * آیا پیام‌رسان هم «فعال» است و هم «اتصال موفق تأییدشده» دارد؟
     * (معیار واحد نمایش تنظیمات، تب‌ها و آمار اختصاصی پیام‌رسان در سراسر پنل مدیریت)
     */
    public static function isConnectionActiveAndConnected(array $conn): bool
    {
        if (empty($conn['is_enabled'])) {
            return false;
        }
        $hasToken = array_key_exists('has_token', $conn)
            ? !empty($conn['has_token'])
            : !empty($conn['bot_token_enc']);
        if (!$hasToken) {
            return false;
        }
        return (($conn['connection_status'] ?? '') === 'connected');
    }

    /** آیا پیام‌رسان مشخص هم‌اکنون فعال و با موفقیت متصل است؟ */
    public function isActiveAndConnected(string $platform): bool
    {
        $p = strtolower(trim($platform));
        if (!MessengerCapabilities::isSupported($p)) {
            return false;
        }
        return self::isConnectionActiveAndConnected($this->safeConnection($p));
    }

    /** @return array<string,array<string,mixed>> فقط پیام‌رسان‌های فعال و با موفقیت متصل‌شده */
    public function activeConnectedConnections(): array
    {
        $out = [];
        foreach ($this->allConnections() as $p => $conn) {
            if (!empty($conn['is_active_connected'])) {
                $out[$p] = $conn;
            }
        }
        return $out;
    }

    /** @return array<string,string> فقط پیام‌رسان‌های فعال و متصل‌شده [platform => name] */
    public function activeConnectedPlatforms(): array
    {
        $out = [];
        foreach ($this->activeConnectedConnections() as $p => $conn) {
            $out[$p] = (string) ($conn['meta']['name'] ?? MessengerCapabilities::label($p));
        }
        return $out;
    }

    /** دسته‌بندی استاندارد گزارش خطاهای ارتباطی پیام‌رسان‌ها */
    public const ERROR_CATEGORIES = [
        'connection'    => 'تست و برقراری اتصال',
        'webhook'       => 'ثبت/دریافت Webhook',
        'send_message'  => 'ارسال پیام یا رسانه',
        'channel_check' => 'بررسی عضویت کانال اجباری',
        'polling'       => 'دریافت دوره‌ای (Polling)',
        'profile'       => 'همگام‌سازی پروفایل ربات',
        'api'           => 'فراخوانی API',
    ];

    public static function errorCategoryLabel(string $category): string
    {
        return self::ERROR_CATEGORIES[$category] ?? 'خطای ارتباطی';
    }

    /** ثبت ایزوله و امن خطای ارتباطی پیام‌رسان بدون افشای توکن و بدون توقف جریان اصلی */
    public static function logError(
        string $platform,
        string $category,
        string $message,
        ?string $methodName = null,
        ?int $errorCode = null,
        array $context = [],
        ?Database $db = null
    ): void {
        try {
            $db ??= Database::instance();
            if (!$db->tableExists('messenger_error_logs')) {
                return;
            }
            $p = strtolower(trim($platform));
            if ($p === '') {
                return;
            }
            $cat = array_key_exists($category, self::ERROR_CATEGORIES) ? $category : 'api';
            $safeMsg = mb_substr(ErrorHandler::redact(trim($message)), 0, 1000);
            if ($safeMsg === '') {
                return;
            }
            $safeCtx = $context === []
                ? '{}'
                : mb_substr(ErrorHandler::redact((string) json_encode($context, JSON_UNESCAPED_UNICODE)), 0, 1500);

            $db->insert('messenger_error_logs', [
                'platform' => mb_substr($p, 0, 32),
                'category' => $cat,
                'method_name' => $methodName !== null ? mb_substr($methodName, 0, 80) : null,
                'error_code' => $errorCode,
                'message' => $safeMsg,
                'context' => $safeCtx,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // ثبت لاگ هرگز نباید باعث اختلال در هیچ پیام‌رسانی شود
        }
    }

    /**
     * دریافت فهرست خطاهای ارتباطی (فیلترشده روی پیام‌رسان‌های مجاز)
     * @param string[]|null $allowedPlatforms اگر آرایه داده شود، فقط همان پیام‌رسان‌ها بازگردانده می‌شوند
     * @return array<int,array<string,mixed>>
     */
    public function recentErrors(?array $allowedPlatforms = null, ?string $category = null, int $limit = 100, ?string $from = null, ?string $to = null): array
    {
        if (!$this->db->tableExists('messenger_error_logs')) {
            return [];
        }
        if ($allowedPlatforms !== null && $allowedPlatforms === []) {
            return [];
        }
        $where = [];
        $bind = [];
        if ($allowedPlatforms !== null) {
            $ph = [];
            foreach (array_values($allowedPlatforms) as $idx => $pl) {
                $k = 'mp' . $idx;
                $ph[] = ':' . $k;
                $bind[$k] = strtolower(trim((string) $pl));
            }
            $where[] = 'platform IN (' . implode(',', $ph) . ')';
        }
        if ($category !== null && $category !== '' && array_key_exists($category, self::ERROR_CATEGORIES)) {
            $where[] = 'category = :cat';
            $bind['cat'] = $category;
        }
        if ($from !== null && $from !== '') {
            $where[] = 'created_at >= :from';
            $bind['from'] = $from;
        }
        if ($to !== null && $to !== '') {
            $where[] = 'created_at <= :to';
            $bind['to'] = $to;
        }
        $sql = 'SELECT * FROM messenger_error_logs'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY id DESC LIMIT ' . max(1, min(5000, $limit));
        return $this->db->all($sql, $bind);
    }

    /** پاک‌سازی لاگ خطاهای ارتباطی (کل یا یک پیام‌رسان مشخص) */
    public function clearErrors(?string $platform = null): int
    {
        if (!$this->db->tableExists('messenger_error_logs')) {
            return 0;
        }
        if ($platform !== null && $platform !== '' && MessengerCapabilities::isSupported($platform)) {
            return $this->db->delete('messenger_error_logs', 'platform = :p', ['p' => strtolower($platform)]);
        }
        return $this->db->delete('messenger_error_logs', '1 = 1');
    }

    /** @return array<string,array<string,mixed>> تمام پیام‌رسان‌ها با اطلاعات امن برای پنل */
    public function allConnections(): array
    {
        $this->ensureSeeded();
        $out = [];
        foreach (MessengerCapabilities::platforms() as $p) {
            $out[$p] = $this->safeConnection($p);
        }
        return $out;
    }

    /** @return array<string,string> پیام‌رسان‌های فعال و پیکربندی‌شده [platform => name] */
    public function activePlatforms(): array
    {
        $out = [];
        foreach ($this->allConnections() as $p => $conn) {
            if ($conn['is_enabled'] && $conn['has_token']) {
                $out[$p] = (string) $conn['meta']['name'];
            }
        }
        return $out;
    }

    /** ساخت نمونه درایور پیام‌رسان */
    public function driver(string $platform, ?string $tokenOverride = null): MessengerDriverInterface
    {
        $p = strtolower(trim($platform));
        if (!isset(self::$driverMap[$p])) {
            throw new \InvalidArgumentException('درایور پیام‌رسان یافت نشد: ' . $platform);
        }
        $conn = $this->rawConnection($p);
        $cls = self::$driverMap[$p];
        return new $cls($conn, $this->db, $tokenOverride);
    }

    /** نشان وضعیت خوانا برای پنل مدیریت */
    public static function statusBadge(array $conn): array
    {
        $enabled = !empty($conn['is_enabled']);
        $hasToken = !empty($conn['has_token']);
        $st = (string) ($conn['connection_status'] ?? 'unconfigured');

        if (!$hasToken) {
            return ['code' => 'unconfigured', 'label' => 'پیکربندی‌نشده', 'class' => 'badge-muted'];
        }
        if (!$enabled) {
            return ['code' => 'disabled', 'label' => 'غیرفعال', 'class' => 'badge-warning'];
        }
        return match ($st) {
            'connected' => ['code' => 'connected', 'label' => 'فعال و متصل', 'class' => 'badge-success'],
            'error'     => ['code' => 'error', 'label' => 'خطا در اتصال', 'class' => 'badge-danger'],
            default     => ['code' => 'configured', 'label' => 'آماده تست اتصال', 'class' => 'badge-info'],
        };
    }

    /** آدرس پیشنهادی Webhook برای هر پیام‌رسان */
    public function suggestedWebhookUrl(string $platform, bool $includeSecret = true): string
    {
        $p = strtolower(trim($platform));
        if (!MessengerCapabilities::supports($p, 'incoming_webhook')) {
            return '';
        }
        $stored = trim((string) (SettingsService::get('webhook_base_url', '') ?: ''));
        $base = $stored !== '' ? rtrim($stored, '/') : Request::origin();
        $basePath = rtrim((string) Config::get('app.base_path', ''), '/');
        if ($basePath !== '' && !str_ends_with($base, $basePath)) {
            $base .= $basePath;
        }
        if ($p === MessengerCapabilities::TELEGRAM) {
            return $base . '/webhook/telegram';
        }
        $conn = $this->rawConnection($p);
        $secret = (string) ($conn['webhook_secret'] ?? '');
        if ($includeSecret && $secret !== '') {
            return $base . '/webhook/' . $p . '/' . rawurlencode($secret);
        }
        return $base . '/webhook/' . $p . '/{secret}';
    }

    /** آدرس پیشنهادی Mini App برای ثبت در BotFather / بله / ایتایار */
    public function suggestedMiniAppUrl(string $platform): string
    {
        $p = strtolower(trim($platform));
        if (!MessengerCapabilities::supports($p, 'miniapp')) {
            return '';
        }
        $base = SettingsService::miniappBaseUrl();
        if ($base === '') {
            $origin = Request::origin();
            $basePath = rtrim((string) Config::get('app.base_path', ''), '/');
            $base = $origin . $basePath . '/app';
        }
        if ($p === MessengerCapabilities::TELEGRAM) {
            return $base;
        }
        $sep = str_contains($base, '?') ? '&' : '?';
        return $base . $sep . 'platform=' . rawurlencode($p);
    }

    /**
     * ذخیره/ویرایش امن توکن و اطلاعات اتصال یک پیام‌رسان
     * @return array{ok:bool,message:string,test?:array}
     */
    public function saveCredentials(string $platform, array $input, ?int $adminId = null, bool $autoTest = true): array
    {
        $p = strtolower(trim($platform));
        $meta = MessengerCapabilities::get($p);
        $conn = $this->rawConnection($p);

        $newToken = trim((string) ($input['bot_token'] ?? ''));
        $newSecondary = trim((string) ($input['secondary_token'] ?? ''));
        $clearSecondary = !empty($input['clear_secondary_token']);
        $botUsername = ltrim(trim((string) ($input['bot_username'] ?? '')), '@');
        $botName = trim((string) ($input['bot_name'] ?? ''));
        $appSlug = trim((string) ($input['app_slug'] ?? ''), "/ \t\n\r\0\x0B");
        $isEnabled = array_key_exists('is_enabled', $input) ? (!empty($input['is_enabled']) ? 1 : 0) : (int) ($conn['is_enabled'] ?? 0);
        $pollingEnabled = array_key_exists('polling_enabled', $input) ? (!empty($input['polling_enabled']) ? 1 : 0) : (int) ($conn['polling_enabled'] ?? 0);

        $tokenEnc = $conn['bot_token_enc'] ?? null;
        $tokenChanged = false;
        if ($newToken !== '') {
            if (strlen($newToken) < 10 || strlen($newToken) > 256 || preg_match('~\s~', $newToken)) {
                return ['ok' => false, 'message' => 'ساختار توکن ' . $meta['name'] . ' معتبر نیست (نباید فاصله داشته باشد یا کوتاه‌تر از ۱۰ کاراکتر باشد).'];
            }
            if ($p === MessengerCapabilities::EITAA && !EitaaDriver::looksLikeValidToken($newToken)) {
                return ['ok' => false, 'message' => 'ساختار توکن ایتا معتبر نیست (نمونه صحیح: 5768337691:AAG... یا bot12345:...).'];
            }
            $tokenEnc = Crypto::encrypt($newToken);
            $tokenChanged = true;
            // وقتی ادمین توکن جدید ثبت می‌کند، اگر صریحاً غیرفعال نکرده باشد فعال شود
            if (!array_key_exists('is_enabled', $input)) {
                $isEnabled = 1;
            }
        }

        if (empty($tokenEnc) && $isEnabled === 1) {
            return ['ok' => false, 'message' => 'برای فعال‌سازی ' . $meta['name'] . ' ابتدا باید توکن معتبر آن را وارد کنید.'];
        }

        if ($botUsername !== '' && !preg_match('~^[A-Za-z0-9_]{3,64}$~', $botUsername)) {
            return ['ok' => false, 'message' => 'شناسه کاربری ربات فقط می‌تواند شامل حروف انگلیسی، عدد و زیرخط (_) باشد.'];
        }
        if ($appSlug !== '' && !preg_match('~^[A-Za-z0-9_-]{1,64}$~', $appSlug)) {
            return ['ok' => false, 'message' => 'پیوند برنامک (Slug) فقط می‌تواند شامل حروف انگلیسی، عدد، خط تیره و زیرخط باشد.'];
        }

        $secondaryEnc = $conn['secondary_token_enc'] ?? null;
        if ($clearSecondary) {
            $secondaryEnc = null;
        } elseif ($newSecondary !== '') {
            if (strlen($newSecondary) < 10 || strlen($newSecondary) > 256 || preg_match('~\s~', $newSecondary)) {
                return ['ok' => false, 'message' => 'ساختار توکن دوم (ایتایار) معتبر نیست.'];
            }
            $secondaryEnc = Crypto::encrypt($newSecondary);
        }

        $secret = (string) ($conn['webhook_secret'] ?? '');
        if ($secret === '' || $tokenChanged) {
            $secret = bin2hex(random_bytes(24));
        }

        $update = [
            'is_enabled' => $isEnabled,
            'bot_token_enc' => $tokenEnc,
            'secondary_token_enc' => $secondaryEnc,
            'webhook_secret' => $secret,
            'polling_enabled' => MessengerCapabilities::supports($p, 'polling') ? $pollingEnabled : 0,
            'connection_status' => empty($tokenEnc) ? 'unconfigured' : ($tokenChanged ? 'configured' : (string) ($conn['connection_status'] ?? 'configured')),
            'last_error' => $tokenChanged ? '' : (string) ($conn['last_error'] ?? ''),
            'updated_at' => now(),
        ];

        if ($tokenChanged) {
            // با تغییر توکن، مشخصات قبلی پاک شوند تا با ربات جدید جایگزین گردند
            $update['bot_id'] = null;
            $update['bot_photo_file_id'] = null;
            $update['webhook_status'] = 'not_set';
            $update['webhook_url'] = null;
            $update['polling_offset'] = null;
        }
        if ($botUsername !== '' || array_key_exists('bot_username', $input)) {
            if ($botUsername !== '') {
                $update['bot_username'] = $botUsername;
            } elseif ($p === MessengerCapabilities::EITAA && $tokenChanged) {
                $update['bot_username'] = null;
            }
        }
        if ($botName !== '') {
            $update['bot_name'] = mb_substr($botName, 0, 190);
        }
        if ($p === MessengerCapabilities::EITAA) {
            $update['app_slug'] = $appSlug !== '' ? $appSlug : 'app';
            $effUser = $botUsername !== '' ? $botUsername : (string) ($conn['bot_username'] ?? '');
            if ($effUser !== '') {
                $update['bot_share_url'] = 'https://eitaa.com/' . $effUser . '/' . $update['app_slug'];
            }
        }

        $this->db->update('messenger_connections', $update, 'platform = :p', ['p' => $p]);

        if ($p === MessengerCapabilities::TELEGRAM) {
            $this->syncToLegacyTelegram($this->rawConnection($p));
        }

        AuditService::log('messenger.credentials_saved', 'messenger', null, [
            'platform' => $p,
            'token_changed' => $tokenChanged,
            'is_enabled' => (bool) $isEnabled,
            'polling_enabled' => (bool) $update['polling_enabled'],
        ], $adminId);

        // در ایتا اگر فقط توکن برنامه ثبت شده باشد، ذخیره موفق با وضعیت آماده اعلام می‌شود
        if ($autoTest && $tokenChanged && $p !== MessengerCapabilities::EITAA) {
            $test = $this->testConnection($p, $adminId);
            if ($test['ok']) {
                return [
                    'ok' => true,
                    'message' => 'اطلاعات ' . $meta['name'] . ' ذخیره شد و اتصال به ربات «' . ($test['bot']['name'] ?? $test['bot']['username'] ?? '') . '» با موفقیت تأیید گردید.',
                    'test' => $test,
                ];
            }
            return [
                'ok' => true,
                'message' => 'توکن ' . $meta['name'] . ' ذخیره شد، اما تست اتصال هشدار داد: ' . ($test['error'] ?? 'خطا'),
                'test' => $test,
            ];
        }

        if ($autoTest && $tokenChanged && $p === MessengerCapabilities::EITAA) {
            // اگر توکن دوم ایتایار یا توکن bot... داده شده تست getMe را اجرا کن؛ وگرنه به عنوان برنامه برنامک آماده ثبت کن
            if ($secondaryEnc !== null || str_starts_with(strtolower($newToken), 'bot')) {
                $test = $this->testConnection($p, $adminId);
                if ($test['ok']) {
                    return [
                        'ok' => true,
                        'message' => 'اطلاعات ایتا ذخیره و اتصال به «' . ($test['bot']['name'] ?? '') . '» تأیید شد.',
                        'test' => $test,
                    ];
                }
            } else {
                $this->db->update('messenger_connections', [
                    'connection_status' => 'connected',
                    'last_error' => '',
                    'last_test_at' => now(),
                    'updated_at' => now(),
                ], 'platform = :p', ['p' => $p]);
            }
        }

        return [
            'ok' => true,
            'message' => 'تنظیمات اتصال پیام‌رسان «' . $meta['name'] . '» با موفقیت ذخیره شد.',
        ];
    }

    /** فعال یا غیرفعال‌سازی مستقل یک پیام‌رسان (بدون هیچ اثری روی دیگر پیام‌رسان‌ها) */
    public function setEnabled(string $platform, bool $enabled, ?int $adminId = null): array
    {
        $p = strtolower(trim($platform));
        $meta = MessengerCapabilities::get($p);
        $conn = $this->rawConnection($p);

        if ($enabled && empty($conn['bot_token_enc'])) {
            return ['ok' => false, 'message' => 'پیش از فعال‌سازی «' . $meta['name'] . '»، ابتدا توکن اتصال آن را ثبت کنید.'];
        }

        $this->db->update('messenger_connections', [
            'is_enabled' => $enabled ? 1 : 0,
            'updated_at' => now(),
        ], 'platform = :p', ['p' => $p]);

        AuditService::log($enabled ? 'messenger.enabled' : 'messenger.disabled', 'messenger', null, [
            'platform' => $p,
        ], $adminId);

        return [
            'ok' => true,
            'is_enabled' => $enabled,
            'message' => 'پیام‌رسان «' . $meta['name'] . '» با موفقیت ' . ($enabled ? 'فعال' : 'غیرفعال') . ' شد.',
        ];
    }

    /** حذف ایمن توکن و اطلاعات اتصال یک پیام‌رسان (با حفظ کامل سوابق کاربران و تراکنش‌ها) */
    public function deleteCredentials(string $platform, ?int $adminId = null): array
    {
        $p = strtolower(trim($platform));
        $meta = MessengerCapabilities::get($p);
        $this->ensureSeeded();

        // تلاش ایزوله برای حذف Webhook پیش از پاک کردن توکن
        try {
            $driver = $this->driver($p);
            if ($driver->isConfigured() && $driver->supports('incoming_webhook')) {
                $driver->removeWebhook();
            }
        } catch (\Throwable) {
        }

        $this->db->update('messenger_connections', [
            'is_enabled' => 0,
            'bot_token_enc' => null,
            'secondary_token_enc' => null,
            'webhook_secret' => bin2hex(random_bytes(24)),
            'webhook_url' => null,
            'webhook_status' => 'not_set',
            'webhook_info' => '',
            'polling_enabled' => 0,
            'polling_offset' => null,
            'bot_id' => null,
            'bot_username' => null,
            'bot_name' => null,
            'bot_share_url' => null,
            'bot_photo_file_id' => null,
            'connection_status' => 'unconfigured',
            'last_error' => '',
            'updated_at' => now(),
        ], 'platform = :p', ['p' => $p]);

        if ($p === MessengerCapabilities::TELEGRAM) {
            $this->syncToLegacyTelegram($this->rawConnection($p));
        }

        AuditService::log('messenger.credentials_deleted', 'messenger', null, ['platform' => $p], $adminId);

        return [
            'ok' => true,
            'message' => 'توکن و اتصال «' . $meta['name'] . '» به‌صورت امن حذف و غیرفعال شد (تمام سوابق کاربران و مالی دست‌نخورده باقی ماند).',
        ];
    }

    /** تست مستقل اتصال به یک پیام‌رسان و ذخیره مشخصات ربات متصل‌شده */
    public function testConnection(string $platform, ?int $adminId = null): array
    {
        $p = strtolower(trim($platform));
        $meta = MessengerCapabilities::get($p);
        $conn = $this->rawConnection($p);
        $driver = $this->driver($p);

        if (!$driver->isConfigured()) {
            return ['ok' => false, 'error' => 'توکن «' . $meta['name'] . '» ثبت نشده است.'];
        }

        try {
            $res = $driver->testConnection();
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'error' => MessengerCapabilities::humanizeError($p, ErrorHandler::redact($e->getMessage()))];
        }

        if (!empty($res['ok']) && is_array($res['bot'] ?? null)) {
            $bot = $res['bot'];
            $update = [
                'bot_id' => ($bot['id'] ?? '') !== '' ? (string) $bot['id'] : ($conn['bot_id'] ?? null),
                'bot_username' => ($bot['username'] ?? '') !== '' ? (string) $bot['username'] : ($conn['bot_username'] ?? null),
                'bot_name' => ($bot['name'] ?? '') !== '' ? (string) $bot['name'] : ($conn['bot_name'] ?? null),
                'bot_share_url' => ($bot['share_url'] ?? '') !== '' ? (string) $bot['share_url'] : ($conn['bot_share_url'] ?? null),
                'connection_status' => 'connected',
                'last_error' => '',
                'last_test_at' => now(),
                'updated_at' => now(),
            ];
            if (!empty($bot['description'])) {
                $update['bot_description'] = mb_substr((string) $bot['description'], 0, 512);
            }
            if (!empty($bot['start_message'])) {
                $update['bot_start_message'] = mb_substr((string) $bot['start_message'], 0, 512);
            }
            if (!empty($bot['photo_file_id'])) {
                $update['bot_photo_file_id'] = (string) $bot['photo_file_id'];
            }
            $this->db->update('messenger_connections', $update, 'platform = :p', ['p' => $p]);
            if ($p === MessengerCapabilities::TELEGRAM) {
                $this->syncToLegacyTelegram($this->rawConnection($p));
            }
            if ($adminId !== null) {
                AuditService::log('messenger.test_ok', 'messenger', null, [
                    'platform' => $p,
                    'bot_username' => $update['bot_username'],
                    'bot_name' => $update['bot_name'],
                ], $adminId);
            }
            return [
                'ok' => true,
                'bot' => $bot,
                'message' => 'اتصال به «' . $meta['name'] . '» برقرار است — ربات: ' . ($update['bot_name'] ?: '—')
                    . (!empty($update['bot_username']) ? ' (@' . $update['bot_username'] . ')' : ''),
            ];
        }

        $err = mb_substr((string) ($res['error'] ?? 'خطای نامشخص در تست اتصال'), 0, 500);
        $this->db->update('messenger_connections', [
            'connection_status' => 'error',
            'last_error' => $err,
            'last_test_at' => now(),
            'updated_at' => now(),
        ], 'platform = :p', ['p' => $p]);

        self::logError($p, 'connection', $err, 'getMe', null, [], $this->db);

        if ($p === MessengerCapabilities::TELEGRAM) {
            $this->syncToLegacyTelegram($this->rawConnection($p));
        }

        return ['ok' => false, 'error' => $err];
    }

    /** ثبت Webhook / Endpoint پیام‌رسان */
    public function registerWebhook(string $platform, ?string $customUrl = null, ?int $adminId = null): array
    {
        $p = strtolower(trim($platform));
        $meta = MessengerCapabilities::get($p);
        if (!MessengerCapabilities::supports($p, 'incoming_webhook')) {
            return ['ok' => false, 'message' => (string) ($meta['name'] . ' از Webhook چت استفاده نمی‌کند؛ اتصال از طریق برنامک انجام می‌شود.')];
        }
        $conn = $this->rawConnection($p);
        $driver = $this->driver($p);
        if (!$driver->isConfigured()) {
            return ['ok' => false, 'message' => 'ابتدا توکن «' . $meta['name'] . '» را ثبت کنید.'];
        }

        $secret = (string) ($conn['webhook_secret'] ?? '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(24));
            $this->db->update('messenger_connections', ['webhook_secret' => $secret], 'platform = :p', ['p' => $p]);
        }

        $url = trim((string) $customUrl);
        if ($url === '') {
            $url = $this->suggestedWebhookUrl($p, true);
        }

        if (in_array($p, [MessengerCapabilities::RUBIKA, MessengerCapabilities::BALE], true)) {
            // The admin form shows a redacted URL. On re-submission, remove
            // all trailing placeholders before the driver appends the real
            // secret. Older broken registrations may contain several masks.
            $url = rtrim($url, '/');
            $url = (string) preg_replace('~(?:/(?:\*{3}|%2a%2a%2a|\{secret\}|%7bsecret%7d))+$~i', '', $url);
            if (str_contains($url, '***') || stripos($url, '%2a%2a%2a') !== false || stripos($url, '{secret}') !== false || stripos($url, '%7bsecret%7d') !== false) {
                return ['ok' => false, 'message' => 'آدرس وب‌هوک شامل بخش مخفی نامعتبر است. آدرس پیشنهادی را بازنشانی کنید و دوباره ثبت کنید.'];
            }
        }

        $res = $driver->registerWebhook($url, $secret);
        $ok = !empty($res['ok']);
        $savedUrl = (string) ($res['url'] ?? $url);
        // سکرت مسیر را در نمایش عمومی جایگزین کنیم تا در صفحه نمایش افشا نشود
        $displayUrl = ($p !== MessengerCapabilities::TELEGRAM && $secret !== '')
            ? str_replace($secret, '***', $savedUrl)
            : $savedUrl;

        $whErr = $ok ? '' : mb_substr((string) ($res['description'] ?? 'ثبت Webhook ناموفق بود.'), 0, 500);
        $this->db->update('messenger_connections', [
            'webhook_url' => $displayUrl,
            'webhook_status' => $ok ? 'active' : 'error',
            'last_error' => $whErr,
            'updated_at' => now(),
        ], 'platform = :p', ['p' => $p]);

        if (!$ok && $whErr !== '') {
            self::logError($p, 'webhook', $whErr, 'setWebhook', null, [], $this->db);
        }

        if ($p === MessengerCapabilities::TELEGRAM) {
            $this->syncToLegacyTelegram($this->rawConnection($p));
        }

        AuditService::log('messenger.webhook_set', 'messenger', null, ['platform' => $p, 'ok' => $ok], $adminId);

        return [
            'ok' => $ok,
            'message' => (string) ($res['description'] ?? ($ok ? 'Webhook ثبت شد.' : 'ثبت Webhook ناموفق بود.')),
        ];
    }

    /** حذف Webhook پیام‌رسان */
    public function removeWebhook(string $platform, ?int $adminId = null): array
    {
        $p = strtolower(trim($platform));
        $driver = $this->driver($p);
        $res = $driver->removeWebhook();
        $this->db->update('messenger_connections', [
            'webhook_status' => 'disabled',
            'updated_at' => now(),
        ], 'platform = :p', ['p' => $p]);
        if ($p === MessengerCapabilities::TELEGRAM) {
            $this->syncToLegacyTelegram($this->rawConnection($p));
        }
        AuditService::log('messenger.webhook_removed', 'messenger', null, ['platform' => $p], $adminId);
        return ['ok' => !empty($res['ok']), 'message' => 'Webhook غیرفعال شد.'];
    }

    /** همگام‌سازی دستورات ربات برای پیام‌رسان */
    public function syncCommands(string $platform, ?int $adminId = null): array
    {
        $p = strtolower(trim($platform));
        $driver = $this->driver($p);
        if (!$driver->supports('menu_commands_api')) {
            return ['ok' => false, 'message' => MessengerCapabilities::label($p) . ' از متد ثبت دستورات پشتیبانی نمی‌کند.'];
        }
        if (!$driver->isConfigured()) {
            return ['ok' => false, 'message' => 'ابتدا توکن پیام‌رسان را ثبت کنید.'];
        }
        $commands = [
            ['command' => 'start',     'description' => 'شروع و منوی اصلی'],
            ['command' => 'invite',    'description' => 'دریافت لینک، پیام و بنر دعوت'],
            ['command' => 'wallet',    'description' => 'کیف پول و موجودی'],
            ['command' => 'referrals', 'description' => 'زیرمجموعه‌ها و درآمد لایه‌ای'],
            ['command' => 'withdraw',  'description' => 'ثبت درخواست برداشت'],
            ['command' => 'link',      'description' => 'اتصال امن حساب در سایر پیام‌رسان‌ها'],
            ['command' => 'support',   'description' => 'پشتیبانی و تیکت'],
            ['command' => 'help',      'description' => 'راهنمای کسب درآمد'],
        ];
        $res = $driver->syncCommands($commands);
        AuditService::log('messenger.commands_synced', 'messenger', null, ['platform' => $p, 'ok' => !empty($res['ok'])], $adminId);
        return [
            'ok' => !empty($res['ok']),
            'message' => !empty($res['ok'])
                ? 'فهرست دستورات در «' . MessengerCapabilities::label($p) . '» با موفقیت همگام‌سازی شد.'
                : (string) ($res['description'] ?? 'خطا در همگام‌سازی دستورات'),
        ];
    }

    /** بررسی رمزنگاری‌شده توکن مخفی مسیر Webhook (Timing-safe) */
    public function verifyWebhookSecret(string $platform, string $providedSecret): bool
    {
        if ($providedSecret === '') {
            return false;
        }
        $conn = $this->rawConnection($platform);
        $expected = (string) ($conn['webhook_secret'] ?? '');
        return $expected !== '' && hash_equals($expected, $providedSecret);
    }

    /** ضدتکرار آپدیت‌های پیام‌رسان‌ها (بله، روبیکا و ...) با قفل اتمیک پایگاه داده */
    public function beginUpdate(string $platform, string $updateKey): array
    {
        $p = strtolower(trim($platform));
        $key = mb_substr(trim($updateKey), 0, 140);
        if ($key === '' || !$this->db->tableExists('messenger_processed_updates')) {
            return ['proceed' => true, 'id' => 0];
        }
        try {
            $id = $this->db->insert('messenger_processed_updates', [
                'platform' => $p,
                'update_key' => $key,
                'status' => 'processing',
                'created_at' => now(),
                'processed_at' => null,
            ]);
            return ['proceed' => true, 'id' => $id];
        } catch (\Throwable) {
            $existing = $this->db->first(
                'SELECT id, status, created_at FROM messenger_processed_updates WHERE platform = :p AND update_key = :k LIMIT 1',
                ['p' => $p, 'k' => $key]
            );
            if ($existing === null) {
                return ['proceed' => true, 'id' => 0];
            }
            if (($existing['status'] ?? '') === 'failed') {
                $this->db->update('messenger_processed_updates', ['status' => 'processing'], 'id = :id', ['id' => (int) $existing['id']]);
                return ['proceed' => true, 'id' => (int) $existing['id']];
            }
            return ['proceed' => false, 'id' => (int) $existing['id']];
        }
    }

    public function finishUpdate(string $platform, int $dedupeId, bool $ok): void
    {
        if ($dedupeId > 0 && $this->db->tableExists('messenger_processed_updates')) {
            try {
                $this->db->update('messenger_processed_updates', [
                    'status' => $ok ? 'done' : 'failed',
                    'processed_at' => $ok ? now() : null,
                ], 'id = :id', ['id' => $dedupeId]);
            } catch (\Throwable) {
            }
        }
        if ($ok && $this->db->tableExists('messenger_connections')) {
            try {
                $this->db->run(
                    'UPDATE messenger_connections SET updates_received = updates_received + 1, last_update_at = :now, updated_at = :now WHERE platform = :p',
                    ['now' => now(), 'p' => strtolower(trim($platform))]
                );
            } catch (\Throwable) {
            }
        }
    }

    /** همگام‌سازی پروفایل و ظاهر ربات از API رسمی پیام‌رسان */
    public function syncProfile(string $platform, ?int $adminId = null): array
    {
        $p = strtolower(trim($platform));
        $conn = $this->rawConnection($p);
        $driver = $this->driver($p);
        if (!$driver->isConfigured()) {
            return ['ok' => false, 'error' => 'ابتدا توکن «' . MessengerCapabilities::label($p) . '» را ثبت کنید.'];
        }
        $res = $driver->fetchProfile();
        if (empty($res['ok']) || !is_array($res['profile'] ?? null)) {
            return ['ok' => false, 'error' => (string) ($res['error'] ?? 'همگام‌سازی پروفایل ناموفق بود.')];
        }
        $prof = $res['profile'];
        $upd = [
            'connection_status' => 'connected',
            'last_error' => '',
            'profile_synced_at' => now(),
            'last_test_at' => now(),
            'updated_at' => now(),
        ];
        foreach (['bot_id', 'bot_username', 'bot_name', 'bot_short_description', 'bot_description', 'bot_start_message', 'bot_share_url', 'bot_photo_file_id'] as $col) {
            if (array_key_exists($col, $prof) && $prof[$col] !== null && $prof[$col] !== '') {
                $upd[$col] = (string) $prof[$col];
            }
        }
        $this->db->update('messenger_connections', $upd, 'platform = :p', ['p' => $p]);
        if ($p === MessengerCapabilities::TELEGRAM) {
            $this->syncToLegacyTelegram($this->rawConnection($p));
        }
        AuditService::log('messenger.profile_synced', 'messenger', null, ['platform' => $p], $adminId);
        return ['ok' => true, 'profile' => $prof];
    }

    /** ذخیره مشخصات و بیوگرافی ربات برای هر پیام‌رسان (و اعمال زنده در API در صورت پشتیبانی) */
    public function saveProfile(string $platform, string $name, string $shortDesc, string $description, bool $applyToApi = true, ?int $adminId = null): array
    {
        $p = strtolower(trim($platform));
        $meta = MessengerCapabilities::get($p);
        $this->ensureSeeded();

        $name = mb_substr(trim($name), 0, 64);
        $shortDesc = mb_substr(trim($shortDesc), 0, 120);
        $description = mb_substr(trim($description), 0, 512);

        $upd = [
            'bot_short_description' => $shortDesc,
            'bot_description' => $description,
            'updated_at' => now(),
        ];
        if ($name !== '') {
            $upd['bot_name'] = $name;
        }
        $this->db->update('messenger_connections', $upd, 'platform = :p', ['p' => $p]);

        $driver = $this->driver($p);
        $canMutate = $driver->supports('profile_name_api') || $driver->supports('profile_bio_api') || $driver->supports('profile_desc_api');
        $applied = false;
        $warnings = [];

        if ($applyToApi && $canMutate && $driver->isConfigured()) {
            $push = $driver->pushProfile($name, $shortDesc, $description);
            if (!empty($push['updated'])) {
                $applied = true;
                $this->db->update('messenger_connections', ['profile_synced_at' => now()], 'platform = :p', ['p' => $p]);
            }
            if (!empty($push['errors']) && is_array($push['errors'])) {
                $warnings = $push['errors'];
            }
        }

        if ($p === MessengerCapabilities::TELEGRAM) {
            SettingsService::set('bot_bio_text', $shortDesc, 'telegram');
            SettingsService::set('bot_description_text', $description, 'telegram');
            $this->syncToLegacyTelegram($this->rawConnection($p));
        }

        AuditService::log('messenger.profile_saved', 'messenger', null, [
            'platform' => $p,
            'applied_to_api' => $applied,
            'supported_by_api' => $canMutate,
        ], $adminId);

        $message = $applied
            ? '✅ مشخصات و بیوگرافی ربات در پنل ذخیره شد و مستقیماً در «' . $meta['name'] . '» اعمال گردید.'
            : ($canMutate
                ? '✅ مشخصات و بیوگرافی ربات «' . $meta['name'] . '» در پنل ذخیره شد.'
                : '✅ مشخصات و پیش‌نویس بیوگرافی «' . $meta['name'] . '» در پنل ذخیره شد. برای اعمال در پیام‌رسان، متن را در ' . $meta['botfather'] . ' ثبت نمایید.');

        return [
            'ok' => $warnings === [],
            'applied' => $applied,
            'api_supported' => $canMutate,
            'warnings' => $warnings,
            'message' => $warnings === [] ? $message : ($message . ' | هشدار: ' . implode('؛ ', $warnings)),
        ];
    }

    /** ساخت لینک درگاه وب دعوت چندپیام‌رسانی (/r/{code}) */
    public function buildLandingLink(string $referralCode, ?string $preferredPlatform = null): string
    {
        $origin = trim((string) (SettingsService::get('webhook_base_url', '') ?: ''));
        if ($origin === '') {
            $origin = Request::origin();
        }
        $origin = rtrim($origin, '/');
        $basePath = rtrim((string) Config::get('app.base_path', ''), '/');
        if ($basePath !== '' && !str_ends_with($origin, $basePath)) {
            $origin .= $basePath;
        }
        $url = $origin . '/r/' . rawurlencode(trim($referralCode));
        if ($preferredPlatform !== null && $preferredPlatform !== '') {
            $url .= '?via=' . rawurlencode($preferredPlatform);
        }
        return $url;
    }

    /** ساخت لینک یکتای دعوت کاربر در یک پیام‌رسان مشخص (با تضمین پارامتر ردیابی) */
    public function buildReferralLink(string $platform, string $referralCode): string
    {
        $p = strtolower(trim($platform));
        if (!MessengerCapabilities::isSupported($p)) {
            $p = MessengerCapabilities::TELEGRAM;
        }
        $fallback = $this->buildLandingLink($referralCode, $p);
        return $this->driver($p)->buildReferralLink($referralCode, $fallback);
    }

    /**
     * تمام لینک‌های اختصاصی دعوت یک کاربر برای پیام‌رسان‌های فعال
     * @return array<string,array{platform:string,name:string,icon:string,link:string,miniapp_link:string}>
     */
    public function allReferralLinksForCode(string $referralCode, bool $includeUnconfiguredCurrent = false, ?string $currentPlatform = null): array
    {
        $this->ensureSeeded();
        $links = [];
        foreach (MessengerCapabilities::platforms() as $p) {
            $conn = $this->safeConnection($p);
            $isActive = $conn['is_enabled'] && ($conn['has_token'] || !empty($conn['bot_username']));
            if (!$isActive && !($includeUnconfiguredCurrent && $currentPlatform === $p)) {
                continue;
            }
            $driver = $this->driver($p);
            $link = $driver->buildReferralLink($referralCode, $this->buildLandingLink($referralCode, $p));
            $miniLink = '';
            if ($driver instanceof BaleDriver) {
                $miniLink = $driver->buildDirectMiniAppLink($referralCode);
            } elseif ($p === MessengerCapabilities::TELEGRAM && !empty($conn['bot_username']) && SettingsService::miniappActive()) {
                $miniLink = 'https://t.me/' . $conn['bot_username'] . '?startapp=' . rawurlencode($referralCode);
            }
            $links[$p] = [
                'platform' => $p,
                'name' => (string) $conn['meta']['name'],
                'icon' => (string) $conn['meta']['icon'],
                'color' => (string) $conn['meta']['color'],
                'link' => $link,
                'miniapp_link' => $miniLink,
            ];
        }
        return $links;
    }

    /**
     * ارسال امن و ایزوله اعلان به یک کاربر مرکزی (روی پیام‌رسان ترجیحی یا پیام‌رسان اصلی او)
     *
     * خطای هیچ پیام‌رسانی باعث شکست تراکنش مالی یا اثر روی سایر پیام‌رسان‌ها نمی‌شود.
     */
    public function notifyUser(int $userId, string $htmlMessage, ?string $preferredPlatform = null, array $options = []): bool
    {
        try {
            $accounts = [];
            if ($this->db->tableExists('user_messenger_accounts')) {
                $accounts = $this->db->all(
                    'SELECT * FROM user_messenger_accounts WHERE user_id = :u AND is_blocked = 0 AND can_send_message = 1 ORDER BY is_primary DESC, last_seen_at DESC',
                    ['u' => $userId]
                );
            }

            // اولویت با پیام‌رسان درخواستی (مثلاً پیام‌رسانی که رویداد در آن رخ داده)
            if ($preferredPlatform !== null && $accounts !== []) {
                usort($accounts, static function (array $a, array $b) use ($preferredPlatform): int {
                    $aPref = ($a['platform'] === $preferredPlatform) ? 0 : 1;
                    $bPref = ($b['platform'] === $preferredPlatform) ? 0 : 1;
                    return $aPref <=> $bPref;
                });
            }

            foreach ($accounts as $acc) {
                $p = (string) $acc['platform'];
                $chatId = (string) ($acc['external_chat_id'] ?: $acc['external_user_id']);
                if ($chatId === '') {
                    continue;
                }
                $driver = $this->driver($p);
                // برای سازگاری با تست‌هایی که fakeTransport روی TelegramService تنظیم کرده‌اند
                $canSend = $driver->isEnabled()
                    || ($p === MessengerCapabilities::TELEGRAM && ($driver->isConfigured() || TelegramService::$fakeTransport !== null));
                if (!$canSend) {
                    continue;
                }
                $res = $driver->sendMessage($chatId, $htmlMessage, $options);
                $driver->logMessage($userId, $chatId, 'out', 'notification', ['text' => mb_substr(AbstractMessengerDriver::htmlToPlain($htmlMessage), 0, 300)], $res['ok'] ? 'ok' : 'error', $res['message_id'] ?? null);
                if (!empty($res['ok'])) {
                    return true;
                }
            }

            // بازگشت امن برای کاربران قدیمی تلگرام پیش از backfill
            $user = $this->db->first('SELECT telegram_id, status FROM users WHERE id = :id', ['id' => $userId]);
            if ($user && (int) ($user['telegram_id'] ?? 0) > 0 && ($user['status'] ?? 'active') !== 'blocked') {
                $tgDriver = $this->driver(MessengerCapabilities::TELEGRAM);
                if ($tgDriver->isConfigured() || TelegramService::$fakeTransport !== null) {
                    $res = $tgDriver->sendMessage((int) $user['telegram_id'], $htmlMessage, $options);
                    return !empty($res['ok']);
                }
            }
        } catch (\Throwable $e) {
            ErrorHandler::write('NOTIFY', 'isolated notify error user=' . $userId . ': ' . ErrorHandler::redact($e->getMessage()));
        }
        return false;
    }
}
