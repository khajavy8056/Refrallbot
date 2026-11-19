<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Support\Config;
use RB\Support\Database;
use RB\Support\Request;

/**
 * هسته مرکزی مدیریت کاربران، هویت‌های چندپیام‌رسانی، اتصال امن حساب و شبکه معرفی لایه‌ای
 *
 * اصول معماری نسخه ۱.۴:
 *   - کیف پول و تمام حساب‌های مالی روی شناسه یکتای داخلی (users.id) مدیریت می‌شوند.
 *   - هویت هر کاربر در هر پیام‌رسان (تلگرام، بله، روبیکا، ایتا) با شناسه اختصاصی همان پلتفرم
 *     در جدول user_messenger_accounts جداگانه ذخیره می‌شود.
 *   - هیچ حسابی هرگز صرفاً بر اساس شباهت شماره موبایل یا نام کاربری ادغام خودکار نمی‌شود؛
 *     اتصال بین‌پیام‌رسانی فقط با توکن یک‌بارمصرف رمزنگاری‌شده (LNK-...) یا تأیید صریح ادمین انجام می‌گیرد.
 *   - عمق زنجیره referral_relations تا حداقل ۹ سطح (depth=1 برای لایه ۰ مستقیم + depth=2..9 برای لایه‌های ۱..۸ غیرمستقیم) نگهداری می‌شود.
 */
final class UserService
{
    private Database $db;
    private int $maxDepth;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
        // depth=1 → لایه ۰ (دعوت مستقیم) | depth=2..9 → لایه‌های ۱ تا ۸ (دعوت غیرمستقیم)
        $configured = (int) Config::get('app.max_referral_depth', 8);
        $this->maxDepth = max(9, $configured + 1);
    }

    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    public function findByTelegramId(int|string $telegramId): ?array
    {
        $tid = (int) $telegramId;
        if ($tid <= 0) {
            return null;
        }
        return $this->db->first('SELECT * FROM users WHERE telegram_id = :t', ['t' => $tid]);
    }

    /**
     * جستجوی کاربر مرکزی بر اساس (پیام‌رسان + شناسه یکتای کاربر در آن پیام‌رسان)
     */
    public function findByMessenger(string $platform, int|string $externalUserId): ?array
    {
        $p = strtolower(trim($platform));
        $ext = trim((string) $externalUserId);
        if ($p === '' || $ext === '') {
            return null;
        }

        if ($this->db->tableExists('user_messenger_accounts')) {
            $acc = $this->db->first(
                'SELECT user_id FROM user_messenger_accounts WHERE platform = :p AND external_user_id = :e LIMIT 1',
                ['p' => $p, 'e' => $ext]
            );
            if ($acc !== null) {
                return $this->find((int) $acc['user_id']);
            }
        }

        // سازگاری کامل با کاربران تلگرام ثبت‌شده پیش از ارتقا
        if ($p === MessengerCapabilities::TELEGRAM && ctype_digit($ext)) {
            $legacy = $this->findByTelegramId((int) $ext);
            if ($legacy !== null) {
                $this->upsertMessengerAccount((int) $legacy['id'], MessengerCapabilities::TELEGRAM, [
                    'id' => $ext,
                    'chat_id' => $ext,
                    'username' => $legacy['username'] ?? null,
                    'first_name' => $legacy['first_name'] ?? null,
                    'last_name' => $legacy['last_name'] ?? null,
                    'language_code' => $legacy['language'] ?? 'fa',
                ], true, 'legacy_telegram');
                return $legacy;
            }
        }

        return null;
    }

    /** دریافت همه هویت‌های پیام‌رسانی متصل به یک کاربر مرکزی */
    public function messengerAccounts(int $userId): array
    {
        if (!$this->db->tableExists('user_messenger_accounts')) {
            $u = $this->find($userId);
            if ($u && (int) ($u['telegram_id'] ?? 0) > 0) {
                return [[
                    'platform' => MessengerCapabilities::TELEGRAM,
                    'external_user_id' => (string) $u['telegram_id'],
                    'external_chat_id' => (string) $u['telegram_id'],
                    'username' => $u['username'] ?? null,
                    'is_primary' => 1,
                    'verified_via' => 'legacy_telegram',
                ]];
            }
            return [];
        }

        $rows = $this->db->all(
            'SELECT * FROM user_messenger_accounts WHERE user_id = :u ORDER BY is_primary DESC, id ASC',
            ['u' => $userId]
        );
        if ($rows === []) {
            $u = $this->find($userId);
            if ($u && (int) ($u['telegram_id'] ?? 0) > 0) {
                $this->upsertMessengerAccount($userId, MessengerCapabilities::TELEGRAM, [
                    'id' => (string) $u['telegram_id'],
                    'chat_id' => (string) $u['telegram_id'],
                    'username' => $u['username'] ?? null,
                    'first_name' => $u['first_name'] ?? null,
                    'last_name' => $u['last_name'] ?? null,
                ], true, 'legacy_telegram');
                $rows = $this->db->all('SELECT * FROM user_messenger_accounts WHERE user_id = :u ORDER BY is_primary DESC, id ASC', ['u' => $userId]);
            }
        }
        return $rows;
    }

    /** دریافت حساب یک پیام‌رسان خاص برای کاربر */
    public function messengerAccountForPlatform(int $userId, string $platform): ?array
    {
        $p = strtolower(trim($platform));
        foreach ($this->messengerAccounts($userId) as $acc) {
            if (($acc['platform'] ?? '') === $p) {
                return $acc;
            }
        }
        return null;
    }

    public function findByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        return $this->db->first('SELECT * FROM users WHERE referral_code = :c', ['c' => $code]);
    }

    public function exists(int $id): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM users WHERE id = :id', ['id' => $id]);
    }

    /**
     * ثبت/به‌روزرسانی کاربر تلگرام (۱۰۰٪ سازگار با تمام کدها و تست‌های قبلی)
     * @return array{user:array,is_new:bool,linked?:bool}
     */
    public function registerFromTelegram(array $tgUser, ?string $startPayload = null, array $context = []): array
    {
        $telegramId = (int) ($tgUser['id'] ?? 0);
        if ($telegramId <= 0) {
            throw new \InvalidArgumentException('شناسه تلگرام نامعتبر است.');
        }
        $tgUser['id'] = (string) $telegramId;
        $tgUser['chat_id'] = (string) ($tgUser['chat_id'] ?? $telegramId);
        return $this->registerFromMessenger(MessengerCapabilities::TELEGRAM, $tgUser, $startPayload, $context);
    }

    /**
     * ثبت/به‌روزرسانی یکپارچه کاربر از هر پیام‌رسان (تلگرام، بله، روبیکا، ایتا و پلاگین‌های آینده)
     *
     * نکته ضدجعل و جداسازی هویت:
     *   - هرگز بر اساس شماره تماس یا نام کاربری دو حساب ادغام خودکار نمی‌شوند.
     *   - اگر startPayload توکن معتبر اتصال حساب (LNK_... یا LNK-...) باشد، پس از اعتبارسنجی رمزنگاری‌شده
     *     این پیام‌رسان به حساب مرکزی کاربر متصل می‌گردد تا کیف پول واحد داشته باشد.
     *
     * @return array{user:array,is_new:bool,linked?:bool,link_message?:string}
     */
    public function registerFromMessenger(
        string $platform,
        array $extUser,
        ?string $startPayload = null,
        array $context = []
    ): array {
        $p = strtolower(trim($platform));
        if (!MessengerCapabilities::isSupported($p)) {
            throw new \InvalidArgumentException('پیام‌رسان پشتیبانی‌نشده: ' . $platform);
        }
        $externalId = trim((string) ($extUser['id'] ?? ''));
        if ($externalId === '' || ($p === MessengerCapabilities::TELEGRAM && (!ctype_digit($externalId) || (int) $externalId <= 0))) {
            throw new \InvalidArgumentException('شناسه کاربری در ' . MessengerCapabilities::label($p) . ' نامعتبر است.');
        }
        $chatId = trim((string) ($extUser['chat_id'] ?? $externalId));

        // اگر پارامتر شروع یک کد اتصال یک‌بارمصرف حساب بین‌پیام‌رسانی است (LNK_... یا LNK-...)
        $trimmedStart = trim((string) ($startPayload ?? ''));
        if ($trimmedStart !== '' && preg_match('~^LNK[_-]([A-Za-z0-9]{8,32})$~i', $trimmedStart)) {
            $linkResult = $this->redeemLinkToken($trimmedStart, $p, $extUser);
            if ($linkResult['ok'] && isset($linkResult['user'])) {
                return [
                    'user' => $linkResult['user'],
                    'is_new' => false,
                    'linked' => true,
                    'link_message' => $linkResult['message'],
                ];
            }
            // اگر توکن اتصال نامعتبر/منقضی بود، آن را کد دعوت تلقی نکن
            $startPayload = null;
        }

        try {
            $out = $this->db->transaction(function () use ($p, $extUser, $externalId, $chatId, $startPayload, $context) {
                $existing = $this->findByMessenger($p, $externalId);
                $isNew = $existing === null;

                if (!$isNew) {
                    $uid = (int) $existing['id'];
                    $nextUsername = array_key_exists('username', $extUser) && $extUser['username'] !== null && (string) $extUser['username'] !== ''
                        ? mb_substr(ltrim((string) $extUser['username'], '@'), 0, 64)
                        : $existing['username'];
                    $nextFirst = array_key_exists('first_name', $extUser) && $extUser['first_name'] !== null && trim((string) $extUser['first_name']) !== ''
                        ? mb_substr(trim((string) $extUser['first_name']), 0, 120)
                        : $existing['first_name'];
                    $nextLast = array_key_exists('last_name', $extUser) && $extUser['last_name'] !== null
                        ? mb_substr((string) $extUser['last_name'], 0, 120)
                        : $existing['last_name'];
                    $nextLang = !empty($extUser['language_code'])
                        ? mb_substr((string) $extUser['language_code'], 0, 10)
                        : ($existing['language'] ?? 'fa');

                    // اگر کاربر از پیام‌رسان اصلی خود آمده مشخصات نمایشی مرکزی را تازه کن
                    $entryPlat = (string) ($existing['entry_platform'] ?? MessengerCapabilities::TELEGRAM);
                    $updUser = [
                        'last_activity_at' => now(),
                        'last_ip' => $context['ip'] ?? $existing['last_ip'],
                        'updated_at' => now(),
                    ];
                    if ($p === $entryPlat || empty($existing['first_name'])) {
                        $updUser['username'] = $nextUsername;
                        $updUser['first_name'] = $nextFirst;
                        $updUser['last_name'] = $nextLast;
                        $updUser['language'] = $nextLang;
                    }
                    $this->db->update('users', $updUser, 'id = :id', ['id' => $uid]);

                    $canSend = array_key_exists('can_send_message', $extUser) ? (bool) $extUser['can_send_message'] : null;
                    $this->upsertMessengerAccount($uid, $p, $extUser + ['chat_id' => $chatId], false, 'bot_auth', $canSend);

                    $user = $this->find($uid);
                    if ($user !== null) {
                        $this->maybeQualify($user, false, $p);
                    }
                    return ['user' => $this->find($uid) ?? $existing, 'is_new' => false];
                }

                // بررسی معرف از لینک دعوت اختصاصی
                $referrer = null;
                $sourcePlatform = !empty($context['source_platform']) && MessengerCapabilities::isSupported((string) $context['source_platform'])
                    ? strtolower((string) $context['source_platform'])
                    : $p;

                if ($startPayload !== null && trim($startPayload) !== '') {
                    $referrer = $this->findByCode($this->normalizeCode($startPayload));
                    // جلوگیری از خودمعرفی (چه روی تلگرام چه روی هر پیام‌رسان دیگر)
                    if ($referrer !== null) {
                        $sameTelegram = $p === MessengerCapabilities::TELEGRAM
                            && (int) ($referrer['telegram_id'] ?? 0) === (int) $externalId;
                        $sameLinked = $this->messengerAccountForPlatform((int) $referrer['id'], $p);
                        if ($sameTelegram || ($sameLinked !== null && (string) $sameLinked['external_user_id'] === $externalId)) {
                            $referrer = null;
                        }
                    }
                }

                // مقدار ستون telegram_id: برای کاربران واقعی تلگرام شناسه مثبت واقعی؛
                // برای کاربران سایر پیام‌رسان‌ها نگهبان منفی یکتا (<= -1,000,000,000) تا هرگز با تلگرام تداخل نکند.
                $telegramIdCol = $p === MessengerCapabilities::TELEGRAM
                    ? (int) $externalId
                    : $this->generateNonTelegramSentinelId();

                $insertRow = [
                    'telegram_id' => $telegramIdCol,
                    'username' => isset($extUser['username']) && (string) $extUser['username'] !== ''
                        ? mb_substr(ltrim((string) $extUser['username'], '@'), 0, 64)
                        : null,
                    'first_name' => isset($extUser['first_name']) ? mb_substr(trim((string) $extUser['first_name']), 0, 120) : null,
                    'last_name' => isset($extUser['last_name']) ? mb_substr(trim((string) $extUser['last_name']), 0, 120) : null,
                    'language' => mb_substr((string) ($extUser['language_code'] ?? 'fa'), 0, 10) ?: 'fa',
                    'referral_code' => $this->generateUniqueCode(),
                    'status' => 'active',
                    'verify_status' => 'pending',
                    'joined_at' => now(),
                    'last_activity_at' => now(),
                    'signup_ip' => $context['ip'] ?? Request::ip(),
                    'last_ip' => $context['ip'] ?? Request::ip(),
                    'meta' => json_encode([
                        'entry_platform' => $p,
                        'external_user_id' => $externalId,
                        'start_payload' => $startPayload,
                        'photo_url' => $extUser['photo_url'] ?? null,
                        'is_premium' => $extUser['is_premium'] ?? false,
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($this->db->columnExists('users', 'entry_platform')) {
                    $insertRow['entry_platform'] = $p;
                }
                if ($referrer !== null && $this->db->columnExists('users', 'referral_source_platform')) {
                    $insertRow['referral_source_platform'] = $sourcePlatform;
                }

                $userId = $this->db->insert('users', $insertRow);

                // ثبت هویت اختصاصی پیام‌رسان در جدول جداگانه
                $canSend = array_key_exists('can_send_message', $extUser) ? (bool) $extUser['can_send_message'] : true;
                $this->upsertMessengerAccount($userId, $p, $extUser + ['chat_id' => $chatId], true, 'signup', $canSend);

                // ساخت کیف پول مرکزی یکتا
                (new WalletService($this->db))->ensureWallet($userId);

                if ($referrer !== null && (int) $referrer['id'] !== $userId) {
                    $this->attachReferral($userId, (int) $referrer['id'], null, $sourcePlatform);
                }

                // ثبت رویداد ورود با پیام‌رسان مبدأ (idempotent)
                $eventRow = [
                    'event_key' => 'user_joined:' . $userId,
                    'type' => 'user_joined',
                    'user_id' => $userId,
                    'actor_id' => $referrer['id'] ?? null,
                    'source_user_id' => $userId,
                    'base_amount' => 0,
                    'status' => 'processed',
                    'payload' => json_encode([
                        'platform' => $p,
                        'external_user_id' => $externalId,
                        'telegram_id' => $p === MessengerCapabilities::TELEGRAM ? (int) $externalId : null,
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ];
                if ($this->db->columnExists('referral_events', 'platform')) {
                    $eventRow['platform'] = $p;
                }
                $this->db->insertIgnore('referral_events', $eventRow, 'event_key');

                $user = $this->find($userId);
                if ($user !== null) {
                    $this->maybeQualify($user, false, $p);
                }
                return ['user' => $this->find($userId) ?? $user, 'is_new' => true];
            });
        } catch (\Throwable $e) {
            if (!$this->db->inTransaction()) {
                Notifier::clearDeferred();
            }
            throw $e;
        }

        if (!$this->db->inTransaction()) {
            Notifier::flushDeferred();
        }
        return $out;
    }

    /** درج یا به‌روزرسانی امن رکورد هویت پیام‌رسان در user_messenger_accounts */
    public function upsertMessengerAccount(
        int $userId,
        string $platform,
        array $extUser,
        bool $makePrimary = false,
        string $verifiedVia = 'bot_auth',
        ?bool $canSend = null
    ): void {
        if (!$this->db->tableExists('user_messenger_accounts')) {
            return;
        }
        $p = strtolower(trim($platform));
        $externalId = trim((string) ($extUser['id'] ?? ''));
        if ($userId <= 0 || $p === '' || $externalId === '') {
            return;
        }
        $chatId = trim((string) ($extUser['chat_id'] ?? $externalId));
        $now = now();

        $existing = $this->db->first(
            'SELECT * FROM user_messenger_accounts WHERE platform = :p AND external_user_id = :e LIMIT 1',
            ['p' => $p, 'e' => $externalId]
        );

        if ($existing !== null) {
            $upd = [
                'external_chat_id' => $chatId !== '' ? $chatId : $existing['external_chat_id'],
                'last_seen_at' => $now,
                'updated_at' => $now,
            ];
            if (!empty($extUser['username'])) {
                $upd['username'] = mb_substr(ltrim((string) $extUser['username'], '@'), 0, 100);
            }
            if (!empty($extUser['first_name'])) {
                $upd['first_name'] = mb_substr(trim((string) $extUser['first_name']), 0, 120);
            }
            if (array_key_exists('last_name', $extUser) && $extUser['last_name'] !== null) {
                $upd['last_name'] = mb_substr(trim((string) $extUser['last_name']), 0, 120);
            }
            if ($canSend !== null) {
                $upd['can_send_message'] = $canSend ? 1 : 0;
                if ($canSend) {
                    $upd['is_blocked'] = 0;
                }
            }
            $this->db->update('user_messenger_accounts', $upd, 'id = :id', ['id' => (int) $existing['id']]);
            return;
        }

        try {
            $this->db->insert('user_messenger_accounts', [
                'user_id' => $userId,
                'platform' => $p,
                'external_user_id' => $externalId,
                'external_chat_id' => $chatId !== '' ? $chatId : $externalId,
                'username' => !empty($extUser['username']) ? mb_substr(ltrim((string) $extUser['username'], '@'), 0, 100) : null,
                'first_name' => !empty($extUser['first_name']) ? mb_substr(trim((string) $extUser['first_name']), 0, 120) : null,
                'last_name' => isset($extUser['last_name']) ? mb_substr(trim((string) $extUser['last_name']), 0, 120) : null,
                'language_code' => mb_substr((string) ($extUser['language_code'] ?? 'fa'), 0, 12) ?: 'fa',
                'can_send_message' => ($canSend ?? true) ? 1 : 0,
                'is_blocked' => 0,
                'is_primary' => $makePrimary ? 1 : 0,
                'verified_via' => mb_substr($verifiedVia, 0, 32),
                'meta' => '{}',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable) {
        }
    }

    /** به‌روزرسانی آخرین فعالیت کاربر در یک پیام‌رسان (و وضعیت مجوز ارسال پیام در ایتا) */
    public function touchMessengerActivity(string $platform, string $externalId, int $userId, array $extUser = [], ?bool $canSend = null): void
    {
        $this->touchActivity($userId);
        $this->upsertMessengerAccount($userId, $platform, $extUser + ['id' => $externalId], false, 'webapp_auth', $canSend);
    }

    /** علامت‌گذاری مسدودسازی/توقف ربات توسط کاربر در یک پیام‌رسان خاص (بدون اثر روی سایر پیام‌رسان‌های او) */
    public function markMessengerBlocked(string $platform, string $externalUserId, bool $blocked = true): void
    {
        if (!$this->db->tableExists('user_messenger_accounts')) {
            return;
        }
        $this->db->update('user_messenger_accounts', [
            'is_blocked' => $blocked ? 1 : 0,
            'can_send_message' => $blocked ? 0 : 1,
            'updated_at' => now(),
        ], 'platform = :p AND external_user_id = :e', [
            'p' => strtolower(trim($platform)),
            'e' => trim($externalUserId),
        ]);
    }

    // ------------------------------------------------------------------ اتصال امن و تأییدشده حساب بین پیام‌رسان‌ها

    /**
     * صدور توکن یک‌بارمصرف و زمان‌دار (۱۵ دقیقه‌ای) برای اتصال امن حساب در پیام‌رسان دیگر
     * فقط هش SHA-256 توکن در پایگاه داده ذخیره می‌شود.
     *
     * @return array{code:string,start_param:string,expires_at:string,ttl_minutes:int}
     */
    public function createLinkToken(int $userId, int $ttlSeconds = 900): array
    {
        if (!$this->exists($userId)) {
            throw new \InvalidArgumentException('کاربر یافت نشد.');
        }
        if (!$this->db->tableExists('user_link_tokens')) {
            throw new \RuntimeException('جدول اتصال حساب هنوز آماده نیست. مهاجرت پایگاه داده را اجرا کنید.');
        }

        // باطل کردن توکن‌های مصرف‌نشده قبلی همین کاربر
        $this->db->delete('user_link_tokens', 'user_id = :u AND used_at IS NULL', ['u' => $userId]);

        $rawSecret = strtoupper(bin2hex(random_bytes(6))); // ۱۲ کاراکتر هگز با آنتروپی ۴۸ بیت
        $code = 'LNK-' . $rawSecret;
        $startParam = 'LNK_' . $rawSecret;
        $hash = hash('sha256', $rawSecret);
        $expiresAt = date('Y-m-d H:i:s', time() + max(120, min(3600, $ttlSeconds)));

        $this->db->insert('user_link_tokens', [
            'user_id' => $userId,
            'token_hash' => $hash,
            'expires_at' => $expiresAt,
            'used_at' => null,
            'used_platform' => null,
            'used_external_id' => null,
            'created_at' => now(),
        ]);

        return [
            'code' => $code,
            'start_param' => $startParam,
            'expires_at' => $expiresAt,
            'ttl_minutes' => (int) round($ttlSeconds / 60),
        ];
    }

    /**
     * مصرف امن توکن یک‌بارمصرف و اتصال هویت پیام‌رسان جدید به کیف پول و حساب مرکزی کاربر.
     *
     * محافظت‌های مالی:
     *   - اگر این پیام‌رسان قبلاً به همین کاربر متصل باشد، پیام موفقیت می‌دهد.
     *   - اگر این شناسه پیام‌رسان قبلاً حساب مستقلی با تراکنش مالی یا زیرمجموعه داشته باشد،
     *     برای جلوگیری از هرگونه تراکنش تکراری یا تداخل کیف پول، ادغام خودکار را رد می‌کند.
     *   - اگر یک حساب تازه و کاملاً خالی (بدون تراکنش، بدون برداشت و بدون معرفی) باشد،
     *     به‌صورت اتمیک به حساب مرکزی اصلی متصل می‌شود و حساب خالی غیرفعال/حذف می‌گردد.
     *
     * @return array{ok:bool,user?:array,message:string}
     */
    public function redeemLinkToken(string $inputCode, string $platform, array $extUser): array
    {
        if (!$this->db->tableExists('user_link_tokens') || !$this->db->tableExists('user_messenger_accounts')) {
            return ['ok' => false, 'message' => 'امکان اتصال حساب در حال حاضر فعال نیست.'];
        }

        $p = strtolower(trim($platform));
        $externalId = trim((string) ($extUser['id'] ?? ''));
        if (!MessengerCapabilities::isSupported($p) || $externalId === '') {
            return ['ok' => false, 'message' => 'اطلاعات پیام‌رسان معتبر نیست.'];
        }

        $clean = strtoupper(trim($inputCode));
        $clean = preg_replace('~^LNK[_-]~', '', $clean) ?? $clean;
        if (!preg_match('~^[A-F0-9]{12}$~', $clean)) {
            return ['ok' => false, 'message' => 'ساختار کد اتصال معتبر نیست (نمونه صحیح: LNK-A1B2C3D4E5F6).'];
        }
        $hash = hash('sha256', $clean);

        return $this->db->transaction(function () use ($hash, $p, $externalId, $extUser): array {
            $tok = $this->db->first(
                'SELECT * FROM user_link_tokens WHERE token_hash = :h LIMIT 1',
                ['h' => $hash]
            );
            if ($tok === null) {
                return ['ok' => false, 'message' => 'کد اتصال یافت نشد یا نامعتبر است.'];
            }
            if (!empty($tok['used_at'])) {
                return ['ok' => false, 'message' => 'این کد اتصال قبلاً یک‌بار استفاده شده است. لطفاً کد جدیدی دریافت کنید.'];
            }
            if (strtotime((string) $tok['expires_at']) < time()) {
                return ['ok' => false, 'message' => 'مهلت اعتبار این کد اتصال به پایان رسیده است. لطفاً کد جدید بسازید.'];
            }

            $targetUserId = (int) $tok['user_id'];
            $targetUser = $this->find($targetUserId);
            if ($targetUser === null || ($targetUser['status'] ?? 'active') === 'blocked') {
                return ['ok' => false, 'message' => 'حساب کاربری مقصد یافت نشد یا مسدود است.'];
            }

            // آیا حساب مقصد قبلاً هویت دیگری در همین پیام‌رسان دارد؟
            $targetPlatAcc = $this->messengerAccountForPlatform($targetUserId, $p);
            if ($targetPlatAcc !== null) {
                if ((string) $targetPlatAcc['external_user_id'] === $externalId) {
                    return ['ok' => true, 'user' => $targetUser, 'message' => 'این حساب ' . MessengerCapabilities::label($p) . ' از قبل به کیف پول مرکزی شما متصل است.'];
                }
                return ['ok' => false, 'message' => 'به آن حساب کاربری قبلاً یک اکانت دیگر در ' . MessengerCapabilities::label($p) . ' متصل شده است.'];
            }

            // آیا این شناسه پیام‌رسان قبلاً حساب کاربری دیگری ساخته است؟
            $currentOwner = $this->findByMessenger($p, $externalId);
            if ($currentOwner !== null && (int) $currentOwner['id'] !== $targetUserId) {
                $oldUid = (int) $currentOwner['id'];
                if (!$this->isEmptyDisposableAccount($oldUid)) {
                    return [
                        'ok' => false,
                        'message' => 'این اکانت ' . MessengerCapabilities::label($p) . ' دارای سابقه مالی یا زیرمجموعه مستقل است و برای حفظ دقت مالی ادغام خودکار انجام نمی‌شود. در صورت نیاز با پشتیبانی هماهنگ کنید.',
                    ];
                }
                // حساب فعلی کاملاً تازه و بدون هیچ تراکنش/زیرمجموعه‌ای است → حذف امن و انتقال هویت پیام‌رسان
                $this->db->delete('user_messenger_accounts', 'user_id = :u AND platform = :p', ['u' => $oldUid, 'p' => $p]);
                $this->db->delete('referral_relations', 'descendant_id = :u OR ancestor_id = :u2', ['u' => $oldUid, 'u2' => $oldUid]);
                $this->db->delete('referral_events', 'user_id = :u AND base_amount = 0', ['u' => $oldUid]);
                $this->db->delete('wallets', 'user_id = :u', ['u' => $oldUid]);
                $this->db->delete('users', 'id = :u', ['u' => $oldUid]);
            }

            // اگر پیام‌رسان جدید تلگرام است و حساب مقصد telegram_id منفی (غیرتلگرامی) داشت، شناسه واقعی تلگرام را ثبت کن
            if ($p === MessengerCapabilities::TELEGRAM && ctype_digit($externalId) && (int) ($targetUser['telegram_id'] ?? 0) < 0) {
                $this->db->update('users', ['telegram_id' => (int) $externalId, 'updated_at' => now()], 'id = :id', ['id' => $targetUserId]);
            }

            $this->upsertMessengerAccount($targetUserId, $p, $extUser + ['id' => $externalId], false, 'one_time_token', true);

            $this->db->update('user_link_tokens', [
                'used_at' => now(),
                'used_platform' => $p,
                'used_external_id' => $externalId,
            ], 'id = :id', ['id' => (int) $tok['id']]);

            AuditService::log('user.messenger_linked', 'user', $targetUserId, [
                'platform' => $p,
                'external_user_id' => $externalId,
                'method' => 'one_time_token',
            ]);

            $fresh = $this->find($targetUserId) ?? $targetUser;
            return [
                'ok' => true,
                'user' => $fresh,
                'message' => '✅ حساب «' . MessengerCapabilities::label($p) . '» شما با موفقیت تأیید و به کیف پول و کد دعوت یکتای شما (' . $fresh['referral_code'] . ') متصل شد!',
            ];
        });
    }

    /** بررسی اینکه یک حساب هیچ سابقه مالی، پورسانت، برداشت یا معرفی ندارد */
    private function isEmptyDisposableAccount(int $userId): bool
    {
        $u = $this->find($userId);
        if ($u === null) {
            return false;
        }
        if ((int) ($u['total_earned'] ?? 0) > 0 || (int) ($u['direct_ref_count'] ?? 0) > 0 || !empty($u['referred_by']) || !empty($u['qualified_at'])) {
            return false;
        }
        $txCount = (int) $this->db->value('SELECT COUNT(*) FROM wallet_transactions WHERE user_id = :u', ['u' => $userId]);
        if ($txCount > 0) {
            return false;
        }
        $commCount = (int) $this->db->value(
            'SELECT COUNT(*) FROM referral_commissions WHERE beneficiary_id = :u OR source_user_id = :u2',
            ['u' => $userId, 'u2' => $userId]
        );
        if ($commCount > 0) {
            return false;
        }
        $wdCount = (int) $this->db->value('SELECT COUNT(*) FROM withdrawal_requests WHERE user_id = :u', ['u' => $userId]);
        if ($wdCount > 0) {
            return false;
        }
        $otherAcc = (int) $this->db->value('SELECT COUNT(*) FROM user_messenger_accounts WHERE user_id = :u', ['u' => $userId]);
        return $otherAcc <= 1;
    }

    private function generateNonTelegramSentinelId(): int
    {
        for ($i = 0; $i < 20; $i++) {
            $candidate = -1 * random_int(1_000_000_000, 9_000_000_000_000);
            $exists = $this->db->value('SELECT 1 FROM users WHERE telegram_id = :t', ['t' => $candidate]);
            if (!$exists) {
                return $candidate;
            }
        }
        throw new \RuntimeException('خطا در تولید شناسه داخلی کاربر.');
    }

    // ------------------------------------------------------------------ شبکه دعوت و محاسبه لایه‌ای

    /**
     * اتصال زیرمجموعه به معرف و ساخت زنجیره دقیق تا لایه ۸ (depth = 1..9)
     */
    public function attachReferral(int $userId, int $referrerId, ?int $adminId = null, ?string $sourcePlatform = null): void
    {
        if ($userId === $referrerId) {
            throw new \RuntimeException('کاربر نمی‌تواند معرف خودش باشد.');
        }
        try {
            $this->db->transaction(function () use ($userId, $referrerId, $adminId, $sourcePlatform) {
                $user = $this->find($userId);
                if ($user === null) {
                    throw new \RuntimeException('کاربر یافت نشد.');
                }
                if (!empty($user['referred_by'])) {
                    throw new \RuntimeException('معرف این کاربر قبلاً ثبت شده است.');
                }
                if (!$this->exists($referrerId)) {
                    throw new \RuntimeException('معرف یافت نشد.');
                }
                if ($this->isDescendant($referrerId, $userId)) {
                    throw new \RuntimeException('این ارتباط یک حلقه در شبکه ایجاد می‌کند.');
                }

                $upd = [
                    'referred_by' => $referrerId,
                    'referred_at' => now(),
                    'updated_at' => now(),
                ];
                if ($sourcePlatform !== null && $this->db->columnExists('users', 'referral_source_platform')) {
                    $upd['referral_source_platform'] = $sourcePlatform;
                }
                $this->db->update('users', $upd, 'id = :id', ['id' => $userId]);

                $this->rebuildRelations($userId);
                $this->refreshNetworkCounters([$referrerId, $userId]);

                if ($adminId === null) {
                    $freshReferrer = $this->find($referrerId);
                    $freshUser = $this->find($userId) ?? $user;
                    if ($freshReferrer !== null) {
                        Notifier::referralJoined(
                            $referrerId,
                            $freshUser,
                            (int) ($freshReferrer['direct_ref_count'] ?? 1),
                            (int) ($freshReferrer['total_ref_count'] ?? 1)
                        );
                    }
                }
            });
        } catch (\Throwable $e) {
            if (!$this->db->inTransaction()) {
                Notifier::clearDeferred();
            }
            throw $e;
        }

        if (!$this->db->inTransaction()) {
            Notifier::flushDeferred();
        }
    }

    /**
     * بازسازی کامل زنجیره اجداد یک کاربر بر اساس referred_by:
     *   depth = 1 → لایه ۰ (دعوت‌کننده مستقیم)
     *   depth = 2 → لایه ۱ (غیرمستقیم)
     *   ...
     *   depth = 9 → لایه ۸ (غیرمستقیم)
     */
    public function rebuildRelations(int $userId): int
    {
        $this->db->delete('referral_relations', 'descendant_id = :d', ['d' => $userId]);

        $chain = [];
        $current = (int) ($this->db->value('SELECT referred_by FROM users WHERE id = :id', ['id' => $userId]) ?? 0);
        $seen = [$userId => true];
        $depth = 1;
        while ($current > 0 && $depth <= $this->maxDepth && !isset($seen[$current])) {
            $seen[$current] = true;
            $chain[$depth] = $current;
            $current = (int) ($this->db->value('SELECT referred_by FROM users WHERE id = :id', ['id' => $current]) ?? 0);
            $depth++;
        }

        foreach ($chain as $d => $ancestorId) {
            $this->db->insert('referral_relations', [
                'ancestor_id' => (int) $ancestorId,
                'descendant_id' => $userId,
                'depth' => (int) $d,
                'created_at' => now(),
            ]);
        }
        $this->refreshNetworkCounters(array_values($chain));
        return count($chain);
    }

    /**
     * به‌روزرسانی شمارنده‌های شبکه (سازگار با MySQL 5.7/8.x بدون خطای 1093 و SQLite)
     * @param int[] $userIds
     */
    public function refreshNetworkCounters(array $userIds = []): void
    {
        if ($userIds === []) {
            $this->db->run('UPDATE users SET direct_ref_count = 0, total_ref_count = 0');

            $directRows = $this->db->all(
                'SELECT referred_by AS uid, COUNT(*) AS cnt FROM users WHERE referred_by IS NOT NULL GROUP BY referred_by'
            );
            foreach ($directRows as $row) {
                $uid = (int) ($row['uid'] ?? 0);
                if ($uid > 0) {
                    $this->db->update('users', ['direct_ref_count' => (int) $row['cnt']], 'id = :id', ['id' => $uid]);
                }
            }

            $totalRows = $this->db->all(
                'SELECT ancestor_id AS uid, COUNT(*) AS cnt FROM referral_relations GROUP BY ancestor_id'
            );
            foreach ($totalRows as $row) {
                $uid = (int) ($row['uid'] ?? 0);
                if ($uid > 0) {
                    $this->db->update('users', ['total_ref_count' => (int) $row['cnt']], 'id = :id', ['id' => $uid]);
                }
            }
            return;
        }

        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $direct = (int) $this->db->value('SELECT COUNT(*) FROM users WHERE referred_by = :id', ['id' => $uid]);
            $total = (int) $this->db->value('SELECT COUNT(*) FROM referral_relations WHERE ancestor_id = :id', ['id' => $uid]);
            $this->db->update('users', [
                'direct_ref_count' => $direct,
                'total_ref_count' => $total,
            ], 'id = :id', ['id' => $uid]);
        }
    }

    /**
     * @return array<int,int> [depth => ancestor_id] با depth از ۱ (لایه = depth - 1)
     */
    public function ancestors(int $userId, int $maxDepth = 9): array
    {
        $rows = $this->db->all(
            'SELECT ancestor_id, depth FROM referral_relations WHERE descendant_id = :d AND depth <= :m ORDER BY depth',
            ['d' => $userId, 'm' => $maxDepth]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['depth']] = (int) $row['ancestor_id'];
        }
        return $out;
    }

    public function isDescendant(int $candidate, int $ancestor): bool
    {
        return (bool) $this->db->value(
            'SELECT 1 FROM referral_relations WHERE ancestor_id = :a AND descendant_id = :d LIMIT 1',
            ['a' => $ancestor, 'd' => $candidate]
        );
    }

    /** درخت زیرمجموعه‌ها تا عمق مشخص (همراه شماره لایه واقعی layer = depth - 1 و پیام‌رسان ورود) */
    public function descendants(int $userId, int $maxDepth = 9, int $limit = 2000): array
    {
        $hasEntry = $this->db->columnExists('users', 'entry_platform');
        $platExpr = $hasEntry ? 'u.entry_platform, u.referral_source_platform,' : "'telegram' AS entry_platform, NULL AS referral_source_platform,";
        $rows = $this->db->all(
            "SELECT rr.depth, (rr.depth - 1) AS layer, rr.descendant_id, u.telegram_id, u.username, u.first_name, u.last_name,
                    {$platExpr} u.status, u.joined_at, u.qualified_at, u.last_activity_at, u.total_earned,
                    u.direct_ref_count, u.total_ref_count, u.verify_status, u.referred_by
             FROM referral_relations rr
             JOIN users u ON u.id = rr.descendant_id
             WHERE rr.ancestor_id = :a AND rr.depth <= :m
             ORDER BY rr.depth ASC, rr.descendant_id ASC
             LIMIT " . (int) $limit,
            ['a' => $userId, 'm' => $maxDepth]
        );
        return $rows;
    }

    /**
     * زیرمجموعه‌های یک کاربر دسته‌بندی‌شده به تفکیک لایه واقعی (لایه ۰ مستقیم + لایه‌های ۱ تا N غیرمستقیم)
     * همراه پورسانت کسب‌شده از هر زیرمجموعه
     *
     * @return array<int,array{layer:int,label:string,is_direct:bool,count:int,qualified_count:int,earned:int,pending_earned:int,users:array}>
     */
    public function downlineByLayer(int $userId, int $maxLayer = 8, int $limit = 1000): array
    {
        $rows = $this->descendants($userId, $maxLayer + 1, $limit);

        // پورسانت‌هایی که این کاربر از هر زیرمجموعه دریافت کرده است
        $commRows = $this->db->all(
            "SELECT source_user_id, level, type, status, SUM(amount) AS total
             FROM referral_commissions
             WHERE beneficiary_id = :b AND type IN ('direct', 'level')
             GROUP BY source_user_id, level, type, status",
            ['b' => $userId]
        );
        $commBySource = [];
        foreach ($commRows as $c) {
            $sid = (int) ($c['source_user_id'] ?? 0);
            $st = (string) ($c['status'] ?? '');
            $amt = (int) ($c['total'] ?? 0);
            if (!isset($commBySource[$sid])) {
                $commBySource[$sid] = ['paid' => 0, 'pending' => 0];
            }
            if (in_array($st, ['approved', 'credited'], true)) {
                $commBySource[$sid]['paid'] += $amt;
            } elseif ($st === 'pending') {
                $commBySource[$sid]['pending'] += $amt;
            }
        }

        $grouped = [];
        foreach ($rows as $r) {
            $layer = max(0, (int) $r['depth'] - 1);
            $sid = (int) $r['descendant_id'];
            $paid = $commBySource[$sid]['paid'] ?? 0;
            $pend = $commBySource[$sid]['pending'] ?? 0;
            $r['commission_paid'] = $paid;
            $r['commission_pending'] = $pend;

            if (!isset($grouped[$layer])) {
                $grouped[$layer] = [
                    'layer' => $layer,
                    'label' => $layer === 0 ? 'لایه ۰ (دعوت مستقیم)' : ('لایه ' . fa_digits($layer) . ' (غیرمستقیم)'),
                    'is_direct' => $layer === 0,
                    'count' => 0,
                    'qualified_count' => 0,
                    'earned' => 0,
                    'pending_earned' => 0,
                    'users' => [],
                ];
            }
            $grouped[$layer]['count']++;
            if (!empty($r['qualified_at'])) {
                $grouped[$layer]['qualified_count']++;
            }
            $grouped[$layer]['earned'] += $paid;
            $grouped[$layer]['pending_earned'] += $pend;
            $grouped[$layer]['users'][] = $r;
        }
        ksort($grouped);
        return $grouped;
    }

    /**
     * گزارش کامل مالی و لایه‌ای یک کاربر (دعوت مستقیم، غیرمستقیم، درآمد هر لایه، پرداخت‌شده، در انتظار و به تفکیک پیام‌رسان)
     */
    public function layerEarningsSummary(int $userId): array
    {
        $countRows = $this->db->all(
            'SELECT depth, COUNT(*) AS cnt, SUM(CASE WHEN u.qualified_at IS NOT NULL THEN 1 ELSE 0 END) AS q_cnt
             FROM referral_relations rr
             JOIN users u ON u.id = rr.descendant_id
             WHERE rr.ancestor_id = :u
             GROUP BY rr.depth
             ORDER BY rr.depth ASC',
            ['u' => $userId]
        );
        $countsByLayer = [];
        $directCount = 0;
        $indirectCount = 0;
        foreach ($countRows as $r) {
            $layer = max(0, (int) $r['depth'] - 1);
            $cnt = (int) $r['cnt'];
            $countsByLayer[$layer] = ['users' => $cnt, 'qualified' => (int) ($r['q_cnt'] ?? 0)];
            if ($layer === 0) {
                $directCount = $cnt;
            } else {
                $indirectCount += $cnt;
            }
        }

        $commRows = $this->db->all(
            "SELECT c.level, c.type, c.status, COUNT(*) AS cnt, COALESCE(SUM(c.amount), 0) AS total
             FROM referral_commissions c
             WHERE c.beneficiary_id = :u AND c.status IN ('approved', 'credited', 'pending')
             GROUP BY c.level, c.type, c.status",
            ['u' => $userId]
        );

        $layers = [];
        $directPaid = 0;
        $directPending = 0;
        $indirectPaid = 0;
        $indirectPending = 0;
        $welcomePaid = 0;
        $welcomePending = 0;

        foreach ($commRows as $c) {
            $type = (string) $c['type'];
            $lvl = (int) $c['level'];
            $st = (string) $c['status'];
            $amt = (int) $c['total'];
            $isPaid = in_array($st, ['approved', 'credited'], true);

            if ($type === 'welcome') {
                if ($isPaid) {
                    $welcomePaid += $amt;
                } else {
                    $welcomePending += $amt;
                }
                continue;
            }

            $layerKey = $type === 'direct' ? 0 : max(1, $lvl);
            if (!isset($layers[$layerKey])) {
                $layers[$layerKey] = [
                    'layer' => $layerKey,
                    'label' => $layerKey === 0 ? 'لایه ۰ (مستقیم)' : ('لایه ' . fa_digits($layerKey) . ' (غیرمستقیم)'),
                    'users_count' => $countsByLayer[$layerKey]['users'] ?? 0,
                    'qualified_count' => $countsByLayer[$layerKey]['qualified'] ?? 0,
                    'paid_amount' => 0,
                    'pending_amount' => 0,
                    'total_amount' => 0,
                ];
            }
            if ($isPaid) {
                $layers[$layerKey]['paid_amount'] += $amt;
                if ($layerKey === 0) {
                    $directPaid += $amt;
                } else {
                    $indirectPaid += $amt;
                }
            } else {
                $layers[$layerKey]['pending_amount'] += $amt;
                if ($layerKey === 0) {
                    $directPending += $amt;
                } else {
                    $indirectPending += $amt;
                }
            }
            $layers[$layerKey]['total_amount'] = $layers[$layerKey]['paid_amount'] + $layers[$layerKey]['pending_amount'];
        }

        // اضافه کردن لایه‌هایی که کاربر دارند ولی هنوز پورسانتی ثبت نشده (مثل لایه ۰ با پاداش در انتظار شرایط)
        foreach ($countsByLayer as $layerKey => $info) {
            if (!isset($layers[$layerKey])) {
                $layers[$layerKey] = [
                    'layer' => $layerKey,
                    'label' => $layerKey === 0 ? 'لایه ۰ (مستقیم)' : ('لایه ' . fa_digits($layerKey) . ' (غیرمستقیم)'),
                    'users_count' => $info['users'],
                    'qualified_count' => $info['qualified'],
                    'paid_amount' => 0,
                    'pending_amount' => 0,
                    'total_amount' => 0,
                ];
            }
        }
        ksort($layers);

        // درآمد به تفکیک پیام‌رسان مبدأ
        $byPlatform = [];
        if ($this->db->columnExists('referral_events', 'platform')) {
            $pRows = $this->db->all(
                "SELECT COALESCE(e.platform, 'telegram') AS platform,
                        COALESCE(SUM(CASE WHEN c.status IN ('approved','credited') THEN c.amount ELSE 0 END), 0) AS paid_amount,
                        COALESCE(SUM(CASE WHEN c.status = 'pending' THEN c.amount ELSE 0 END), 0) AS pending_amount,
                        COUNT(*) AS tx_count
                 FROM referral_commissions c
                 JOIN referral_events e ON e.id = c.event_id
                 WHERE c.beneficiary_id = :u AND c.status IN ('approved', 'credited', 'pending')
                 GROUP BY COALESCE(e.platform, 'telegram')",
                ['u' => $userId]
            );
            foreach ($pRows as $pr) {
                $pk = (string) $pr['platform'];
                $byPlatform[$pk] = [
                    'platform' => $pk,
                    'name' => MessengerCapabilities::label($pk),
                    'icon' => MessengerCapabilities::icon($pk),
                    'paid_amount' => (int) $pr['paid_amount'],
                    'pending_amount' => (int) $pr['pending_amount'],
                    'total_amount' => (int) $pr['paid_amount'] + (int) $pr['pending_amount'],
                    'tx_count' => (int) $pr['tx_count'],
                ];
            }
        }

        $totalPaid = $directPaid + $indirectPaid + $welcomePaid;
        $totalPending = $directPending + $indirectPending + $welcomePending;

        return [
            'direct_count' => $directCount,
            'indirect_count' => $indirectCount,
            'total_network_count' => $directCount + $indirectCount,
            'direct_paid' => $directPaid,
            'direct_pending' => $directPending,
            'indirect_paid' => $indirectPaid,
            'indirect_pending' => $indirectPending,
            'welcome_paid' => $welcomePaid,
            'welcome_pending' => $welcomePending,
            'total_paid' => $totalPaid,
            'total_pending' => $totalPending,
            'total_income' => $totalPaid + $totalPending,
            'layers' => array_values($layers),
            'by_platform' => array_values($byPlatform),
        ];
    }

    /** زیرمجموعه‌های مستقیم */
    public function directReferrals(int $userId, int $limit = 500): array
    {
        return $this->db->all(
            'SELECT * FROM users WHERE referred_by = :r ORDER BY joined_at DESC LIMIT ' . (int) $limit,
            ['r' => $userId]
        );
    }

    /**
     * فهرست مؤثر شرایط لازم پیش از پرداخت پاداش دعوت (متناسب با پیام‌رسان کاربر)
     * @return string[]
     */
    public function effectiveConditions(?CommissionSettings $settings = null, ?string $platform = null): array
    {
        $settings ??= CommissionSettings::current($this->db);
        $raw = $settings->qualifyConditions ?: ['joined'];
        $normalized = [];
        foreach ($raw as $cond) {
            $key = match ((string) $cond) {
                'contact', 'phone' => 'phone',
                'channel', 'channels' => 'channels',
                'profile' => 'profile',
                'profile_complete' => 'profile_complete',
                'approved', 'manual_approval' => 'approved',
                'activity' => 'activity',
                'joined' => 'joined',
                default => (string) $cond,
            };
            if ($key !== '' && !in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }
        if ($normalized === []) {
            $normalized[] = 'joined';
        }

        $plat = $platform !== null && $platform !== '' ? strtolower($platform) : MessengerCapabilities::TELEGRAM;

        if (SettingsService::bool('contact_required', false) && !in_array('phone', $normalized, true)) {
            if (MessengerCapabilities::supports($plat, 'request_contact')) {
                $normalized[] = 'phone';
            }
        }
        if (SettingsService::bool('force_channel_membership', false) && !in_array('channels', $normalized, true)) {
            $membership = new MembershipService($this->db);
            $channels = $membership->activeChannels($plat);
            if ($channels !== []) {
                if ($plat === MessengerCapabilities::TELEGRAM) {
                    $tg = new TelegramService(null, $this->db);
                    if ($tg->isConfigured()) {
                        $normalized[] = 'channels';
                    }
                } else {
                    $driver = (new MessengerRegistry($this->db))->driver($plat);
                    if ($driver->isConfigured()) {
                        $normalized[] = 'channels';
                    }
                }
            }
        }

        // اگر در پیام‌رسان کاربر هیچ کانال فعالی تعریف نشده و API بررسی کانال هم ندارد، شرط channels باعث قفل شدن نشود
        if ($platform !== null && $plat !== MessengerCapabilities::TELEGRAM && in_array('channels', $normalized, true)) {
            $membership = new MembershipService($this->db);
            if ($membership->activeChannels($plat) === []) {
                $normalized = array_values(array_filter($normalized, static fn(string $c): bool => $c !== 'channels'));
                if ($normalized === []) {
                    $normalized[] = 'joined';
                }
            }
        }

        return $normalized;
    }

    /**
     * چک‌لیست پویای شرایط تکمیل ثبت‌نام و دریافت پاداش دعوت
     * @return array<int,array{key:string,label:string,done:bool}>
     */
    public function qualificationChecklist(array $user, ?CommissionSettings $settings = null): array
    {
        $settings ??= CommissionSettings::current($this->db);
        $plat = (string) ($user['entry_platform'] ?? MessengerCapabilities::TELEGRAM);
        $conditions = $this->effectiveConditions($settings, !empty($user['id']) ? $plat : null);
        $membership = new MembershipService($this->db);
        $activeChannels = $membership->activeChannels($plat);
        $channelNames = array_values(array_filter(array_map(
            static fn(array $c): string => trim((string) ($c['title'] ?? '')),
            $activeChannels
        )));
        $minMsgs = max(0, (int) SettingsService::get('qualify_min_activity_messages', '0'));

        $items = [];
        foreach ($conditions as $cond) {
            $done = $this->meetsConditions($user, [$cond]);
            $label = match ($cond) {
                'joined' => 'شروع ربات و ثبت اولیه دعوت',
                'profile' => 'تکمیل پروفایل کاربری (نام و نام خانوادگی)',
                'profile_complete' => 'تکمیل نام پروفایل کاربری',
                'phone', 'contact' => 'ثبت و تأیید شماره تماس',
                'channels', 'channel' => $channelNames !== []
                    ? 'عضویت در کانال اجباری (' . implode('، ', array_slice($channelNames, 0, 3)) . (count($channelNames) > 3 ? ' و ...' : '') . ') و تأیید عضویت'
                    : 'عضویت در کانال‌های اجباری و تأیید عضویت',
                'approved', 'manual_approval' => 'بررسی و تأیید عضویت توسط مدیریت',
                'activity' => $minMsgs > 0
                    ? 'ثبت حداقل ' . fa_digits($minMsgs) . ' پیام فعالیت در ربات'
                    : 'فعالیت در ربات',
                default => '',
            };
            if ($label !== '') {
                $items[] = ['key' => $cond, 'label' => $label, 'done' => $done];
            }
        }
        return $items;
    }

    /** @return array<int,array{key:string,label:string,done:bool}> */
    public function pendingRequirements(array $user, ?CommissionSettings $settings = null): array
    {
        return array_values(array_filter(
            $this->qualificationChecklist($user, $settings),
            static fn(array $item): bool => !$item['done']
        ));
    }

    /**
     * بررسی شرایط واجد شرایط شدن معرفی و پرداخت دقیق پاداش:
     *   - لایه ۰ (معرف مستقیم): فقط و فقط پاداش مستقیم ثابت (بدون هیچ پرداخت تکراری لایه ۱)
     *   - کاربر دعوت‌شده: پاداش خوش‌آمدگویی (در صورت فعال بودن)
     *   - لایه‌های ۱ تا N (معرف‌های غیرمستقیم): دقیقاً بر اساس زنجیره واقعی دعوت
     */
    public function maybeQualify(array $user, bool $force = false, ?string $platform = null): bool
    {
        if (empty($user['referred_by'])) {
            return false;
        }
        $userId = (int) $user['id'];
        $referrerId = (int) $user['referred_by'];
        if ($userId <= 0 || $referrerId <= 0 || $userId === $referrerId) {
            return false;
        }
        if (!empty($user['qualified_at']) && !$force) {
            return false;
        }
        if (($user['status'] ?? 'active') === 'blocked') {
            return false;
        }

        $sourcePlatform = $platform
            ?? (!empty($user['referral_source_platform']) ? (string) $user['referral_source_platform'] : null)
            ?? (!empty($user['entry_platform']) ? (string) $user['entry_platform'] : MessengerCapabilities::TELEGRAM);

        $settings = CommissionSettings::current($this->db, true);
        $conditions = $this->effectiveConditions($settings, $sourcePlatform);
        if (!$this->meetsConditions($user, $conditions)) {
            return false;
        }

        $stmt = $this->db->run(
            'UPDATE users SET qualified_at = :now, updated_at = :now2 WHERE id = :id AND qualified_at IS NULL',
            ['now' => now(), 'now2' => now(), 'id' => $userId]
        );
        if (!$force && $stmt->rowCount() === 0) {
            return false;
        }

        $service = new CommissionService($this->db, $settings);
        $result = $service->processEvent([
            'event_key' => 'referral_qualified:' . $userId,
            'type' => 'referral_qualified',
            'user_id' => $userId,
            'actor_id' => $referrerId,
            'platform' => $sourcePlatform,
            'base_amount' => $settings->directReward,
            'invitee_amount' => $settings->inviteeReward,
            'applies_direct' => true,
            'applies_invitee' => true,
            'payload' => [
                'platform' => $sourcePlatform,
                'referred_by' => $referrerId,
                'conditions' => $conditions,
                'direct_reward' => $settings->effectiveDirectReward(),
                'invitee_reward' => $settings->effectiveInviteeReward(),
                'telegram_id' => (int) ($user['telegram_id'] ?? 0) > 0 ? (int) $user['telegram_id'] : null,
            ],
        ]);

        if (!$result['duplicated'] && $result['total'] > 0) {
            Notifier::referralCommission($user, $result, $settings);
        }
        SecurityService::evaluateUser($userId);
        return true;
    }

    public function meetsConditions(array $user, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $ok = match ((string) $condition) {
                'joined' => true,
                'profile' => trim((string) ($user['first_name'] ?? '')) !== '' && trim((string) ($user['last_name'] ?? '')) !== '',
                'profile_complete' => trim((string) ($user['first_name'] ?? '')) !== '',
                'phone', 'contact' => trim((string) ($user['phone'] ?? '')) !== '',
                'channels', 'channel' => (bool) ($user['channels_verified'] ?? false),
                'approved', 'manual_approval' => (string) ($user['verify_status'] ?? '') === 'verified',
                'activity' => $this->hasActivity((int) ($user['id'] ?? 0)),
                default => true,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    public function approveVerification(int $userId, ?int $adminId = null): bool
    {
        $user = $this->find($userId);
        if ($user === null) {
            throw new \InvalidArgumentException('کاربر یافت نشد.');
        }
        $this->db->update('users', [
            'verify_status' => 'verified',
            'updated_at' => now(),
        ], 'id = :id', ['id' => $userId]);
        AuditService::log('user.verified', 'user', $userId, [
            'previous' => $user['verify_status'] ?? 'pending',
            'to' => 'verified',
        ], $adminId);

        $fresh = $this->find($userId);
        $qualified = false;
        if ($fresh !== null) {
            $qualified = $this->maybeQualify($fresh);
            if (!$this->db->inTransaction()) {
                Notifier::flushDeferred();
            }
        }
        return $qualified;
    }

    public function updateProfile(int $userId, string $firstName, string $lastName): bool
    {
        $firstName = mb_substr(trim($firstName), 0, 120);
        $lastName = mb_substr(trim($lastName), 0, 120);
        if ($firstName === '') {
            throw new \InvalidArgumentException('وارد کردن نام الزامی است.');
        }
        $this->db->update('users', [
            'first_name' => $firstName,
            'last_name' => $lastName !== '' ? $lastName : null,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $userId]);

        $fresh = $this->find($userId);
        $qualified = false;
        if ($fresh !== null) {
            $qualified = $this->maybeQualify($fresh);
            if (!$this->db->inTransaction()) {
                Notifier::flushDeferred();
            }
        }
        return $qualified;
    }

    private function hasActivity(int $userId): bool
    {
        $minMessages = (int) SettingsService::get('qualify_min_activity_messages', '0');
        if ($minMessages <= 0) {
            return true;
        }
        $count = (int) $this->db->value(
            "SELECT COUNT(*) FROM telegram_messages WHERE user_id = :u AND direction = 'in'",
            ['u' => $userId]
        );
        return $count >= $minMessages;
    }

    public function generateUniqueCode(): string
    {
        for ($i = 0; $i < 12; $i++) {
            $code = strtoupper(substr(str_replace(['0', 'O', 'I', '1'], ['A', 'B', 'C', 'D'], bin2hex(random_bytes(4))), 0, 8));
            $exists = $this->db->value('SELECT 1 FROM users WHERE referral_code = :c', ['c' => $code]);
            if (!$exists) {
                return $code;
            }
        }
        return strtoupper(bin2hex(random_bytes(6)));
    }

    public function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (str_starts_with($code, 'REF_')) {
            $code = substr($code, 4);
        }
        return $code;
    }

    public function touchActivity(int $userId, ?string $ip = null): void
    {
        $this->db->run(
            'UPDATE users SET last_activity_at = :now, last_ip = COALESCE(:ip, last_ip) WHERE id = :id',
            ['now' => now(), 'ip' => $ip, 'id' => $userId]
        );
    }

    public function setStatus(int $userId, string $status, ?int $adminId = null, string $reason = ''): void
    {
        if (!in_array($status, ['active', 'blocked', 'suspicious'], true)) {
            throw new \InvalidArgumentException('وضعیت نامعتبر است.');
        }
        $before = $this->find($userId);
        $this->db->update('users', ['status' => $status, 'updated_at' => now()], 'id = :id', ['id' => $userId]);
        AuditService::log('user.status_changed', 'user', $userId, [
            'from' => $before['status'] ?? null,
            'to' => $status,
            'reason' => $reason,
        ], $adminId);
    }

    public function setPhone(int $userId, string $phone): void
    {
        // نکته امنیتی: ثبت شماره تماس صرفاً روی همان کاربر مرکزی اعمال می‌شود و هرگز باعث ادغام خودکار با حساب دیگر نمی‌شود.
        $this->db->update('users', [
            'phone' => $phone,
            'contact_verified' => 1,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $userId]);
        $user = $this->find($userId);
        if ($user) {
            $this->maybeQualify($user);
            if (!$this->db->inTransaction()) {
                Notifier::flushDeferred();
            }
        }
    }

    public function markChannelsVerified(int $userId): void
    {
        $this->db->update('users', ['channels_verified' => 1, 'updated_at' => now()], 'id = :id', ['id' => $userId]);
        $user = $this->find($userId);
        if ($user) {
            $this->maybeQualify($user);
            if (!$this->db->inTransaction()) {
                Notifier::flushDeferred();
            }
        }
    }

    public function stats(int $userId): array
    {
        $user = $this->find($userId);
        if ($user === null) {
            return [];
        }
        $wallet = (new WalletService($this->db))->summary($userId);
        $suspicious = (new CommissionSettingsService($this->db))->isSuspicious($userId);
        $direct = (int) $user['direct_ref_count'];
        $total = (int) $user['total_ref_count'];
        return [
            'user' => $user,
            'wallet' => $wallet,
            'direct_refs' => $direct,
            'indirect_refs' => max(0, $total - $direct),
            'total_refs' => $total,
            'level' => $this->depthFromRoot($userId),
            'rank' => RankingService::rankOf($userId),
            'suspicious' => $suspicious,
            'messengers' => $this->messengerAccounts($userId),
        ];
    }

    public function depthFromRoot(int $userId, int $max = 9): int
    {
        $row = $this->db->first(
            'SELECT MAX(depth) AS d FROM referral_relations WHERE descendant_id = :u',
            ['u' => $userId]
        );
        return (int) ($row['d'] ?? 0);
    }

    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = [];
        $params = [];
        $hasMessengerTable = $this->db->tableExists('user_messenger_accounts');
        $hasEntryCol = $this->db->columnExists('users', 'entry_platform');

        if (!empty($filters['q'])) {
            $q = '%' . trim((string) $filters['q']) . '%';
            $extraSearch = $hasMessengerTable
                ? ' OR EXISTS (SELECT 1 FROM user_messenger_accounts ma WHERE ma.user_id = u.id AND (ma.external_user_id LIKE :q7 OR ma.username LIKE :q8))'
                : '';
            $where[] = '(u.first_name LIKE :q1 OR u.last_name LIKE :q2 OR u.username LIKE :q3 OR u.phone LIKE :q4 OR (u.telegram_id > 0 AND CAST(u.telegram_id AS CHAR) LIKE :q5) OR u.referral_code LIKE :q6' . $extraSearch . ')';
            $params += ['q1' => $q, 'q2' => $q, 'q3' => $q, 'q4' => $q, 'q5' => $q, 'q6' => $q];
            if ($hasMessengerTable) {
                $params += ['q7' => $q, 'q8' => $q];
            }
        }
        if (!empty($filters['platform']) && MessengerCapabilities::isSupported((string) $filters['platform'])) {
            $p = strtolower((string) $filters['platform']);
            if ($hasEntryCol && $hasMessengerTable) {
                $where[] = '(u.entry_platform = :plat OR EXISTS (SELECT 1 FROM user_messenger_accounts mp WHERE mp.user_id = u.id AND mp.platform = :plat2))';
                $params['plat'] = $p;
                $params['plat2'] = $p;
            } elseif ($hasEntryCol) {
                $where[] = 'u.entry_platform = :plat';
                $params['plat'] = $p;
            }
        }
        if (!empty($filters['status'])) {
            $where[] = 'u.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'u.joined_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'u.joined_at <= :to';
            $params['to'] = $filters['to'] . ' 23:59:59';
        }
        if (!empty($filters['has_refs'])) {
            $where[] = 'u.direct_ref_count > 0';
        }
        if (!empty($filters['has_income'])) {
            $where[] = 'u.total_earned > 0';
        }
        if (!empty($filters['with_phone'])) {
            $where[] = "u.phone IS NOT NULL AND u.phone <> ''";
        }
        if (!empty($filters['referred_by'])) {
            $where[] = 'u.referred_by = :rb';
            $params['rb'] = (int) $filters['referred_by'];
        }
        if (!empty($filters['suspicious'])) {
            $where[] = 'u.status = :susp';
            $params['susp'] = 'suspicious';
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $this->db->value("SELECT COUNT(*) FROM users u {$whereSql}", $params);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->all(
            "SELECT u.*, r.first_name AS referrer_first_name, r.username AS referrer_username, r.referral_code AS referrer_code
             FROM users u LEFT JOIN users r ON r.id = u.referred_by
             {$whereSql}
             ORDER BY u.id DESC LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
            $params
        );

        // ضمیمه کردن فهرست پیام‌رسان‌های متصل هر کاربر بدون کوئری N+1
        if ($hasMessengerTable && $rows !== []) {
            $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
            $in = implode(',', $ids);
            $accRows = $this->db->all("SELECT user_id, platform, external_user_id, username, is_primary FROM user_messenger_accounts WHERE user_id IN ({$in}) ORDER BY is_primary DESC, id ASC");
            $map = [];
            foreach ($accRows as $a) {
                $map[(int) $a['user_id']][] = $a;
            }
            foreach ($rows as &$r) {
                $r['linked_messengers'] = $map[(int) $r['id']] ?? [];
            }
            unset($r);
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** تغییر معرف با ثبت سابقه (فقط مدیر) */
    public function changeReferrer(int $userId, ?int $newReferrerId, string $reason, ?int $adminId = null): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new \RuntimeException('برای تغییر معرف، ثبت دلیل (حداقل ۵ کاراکتر) الزامی است.');
        }
        $this->db->transaction(function () use ($userId, $newReferrerId, $reason, $adminId) {
            $user = $this->find($userId);
            if ($user === null) {
                throw new \RuntimeException('کاربر یافت نشد.');
            }
            $oldReferrer = $user['referred_by'] ? (int) $user['referred_by'] : null;
            if ($newReferrerId !== null) {
                if ($newReferrerId === $userId) {
                    throw new \RuntimeException('کاربر نمی‌تواند معرف خودش باشد.');
                }
                if (!$this->exists($newReferrerId)) {
                    throw new \RuntimeException('معرف جدید یافت نشد.');
                }
                if ($this->isDescendant($newReferrerId, $userId)) {
                    throw new \RuntimeException('این تغییر یک حلقه در شبکه ایجاد می‌کند.');
                }
            }

            $this->db->update('users', [
                'referred_by' => $newReferrerId,
                'referred_at' => $newReferrerId ? now() : null,
                'updated_at' => now(),
            ], 'id = :id', ['id' => $userId]);

            $this->rebuildRelations($userId);
            foreach ($this->db->column(
                'SELECT descendant_id FROM referral_relations WHERE ancestor_id = :a',
                ['a' => $userId]
            ) as $descendantId) {
                $this->rebuildRelations((int) $descendantId);
            }
            $this->refreshNetworkCounters();

            AuditService::log('user.referrer_changed', 'user', $userId, [
                'old_referrer' => $oldReferrer,
                'new_referrer' => $newReferrerId,
                'reason' => $reason,
            ], $adminId);
        });
    }
}
