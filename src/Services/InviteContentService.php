<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Messengers\AbstractMessengerDriver;
use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Support\Config;
use RB\Support\Database;
use RB\Support\Request;

/**
 * استودیو مدیریت پیام‌های آماده دعوت و بنرهای تبلیغاتی (مشترک بین تمام پیام‌رسان‌ها و مینی‌اپ)
 *
 * امکانات:
 *   - مدیریت چند پیام آماده دعوت (افزودن، ویرایش، حذف، فعال/غیرفعال، انتخاب پیش‌فرض، متغیرهای پویا و ایموجی)
 *   - مدیریت بنرهای تبلیغاتی گرافیکی (بنرهای استودیویی پیش‌فرض + آپلود تصویر اختصاصی مدیر، فعال/غیرفعال، پیش‌فرض، کپشن)
 *   - تضمین ۱۰۰٪ درج لینک یکتای ردیابی دعوت کاربر در تمام ۴ حالت اشتراک‌گذاری (متن، بنر، بنر+کپشن، لینک مستقیم)
 *   - کش هوشمند file_id بنرها به تفکیک هر پیام‌رسان برای ارسال فوری و بدون آپلود تکراری
 */
final class InviteContentService
{
    public const MAX_BANNER_BYTES = 5_242_880; // 5 MB

    public const ALLOWED_IMAGE_TYPES = [
        IMAGETYPE_JPEG => ['mime' => 'image/jpeg', 'ext' => 'jpg'],
        IMAGETYPE_PNG  => ['mime' => 'image/png',  'ext' => 'png'],
        IMAGETYPE_WEBP => ['mime' => 'image/webp', 'ext' => 'webp'],
    ];

    /** فهرست متغیرهای پویای قابل استفاده در پیام‌های آماده و کپشن بنرها */
    public const PLACEHOLDERS = [
        '{invite_link}'    => 'لینک اختصاصی دعوت کاربر (متناسب با هر پیام‌رسان)',
        '{referral_code}'  => 'کد یکتای دعوت کاربر',
        '{inviter_reward}' => 'مبلغ پاداش دعوت مستقیم (لایه ۰ — دعوت‌کننده)',
        '{invitee_reward}' => 'مبلغ هدیه خوش‌آمدگویی (کاربر دعوت‌شده)',
        '{total_reward}'   => 'مجموع پاداش دعوت‌کننده و دعوت‌شده',
        '{app_name}'       => 'نام سامانه / ربات',
        '{messenger}'      => 'نام پیام‌رسان (تلگرام، بله، روبیکا، ایتا)',
        '{name}'           => 'نام کاربر دعوت‌کننده',
        '{max_levels}'     => 'تعداد لایه‌های فعال درآمد غیرمستقیم',
        '{min_withdraw}'   => 'حداقل مبلغ قابل برداشت از کیف پول',
        '{requirements}'   => 'شرایط فعال‌سازی پاداش دعوت',
    ];

    /** @var array<int,bool> */
    private static array $seeded = [];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    public static function resetCache(): void
    {
        self::$seeded = [];
    }

    /** ایجاد خودکار و یک‌باره پیام‌های آماده و بنرهای گرافیکی پیش‌فرض */
    public function ensureSeeded(): void
    {
        $oid = spl_object_id($this->db);
        if (!empty(self::$seeded[$oid])) {
            return;
        }

        $now = now();

        if ($this->db->tableExists('invite_messages')) {
            $count = (int) $this->db->value('SELECT COUNT(*) FROM invite_messages');
            if ($count === 0) {
                $defaults = [
                    [
                        'title' => '🎁 دعوت دوستانه با هدیه دوطرفه (پیش‌فرض)',
                        'body' => "سلام دوست خوبم! 👋✨\n"
                            . "تازه عضو <b>{app_name}</b> شدم؛ با دعوت دوستان هم تو جایزه می‌گیری هم من! 🎁\n\n"
                            . "✅ هدیه خوش‌آمدگویی تو: <b>{invitee_reward}</b>\n"
                            . "💰 پاداش هر دعوت موفق: <b>{inviter_reward}</b>\n"
                            . "🏦 تسویه سریع به حساب بانکی (حداقل برداشت: {min_withdraw})\n\n"
                            . "همین الان از لینک اختصاصی من وارد شو و هدیه‌ات رو فعال کن 👇\n"
                            . "{invite_link}\n\n"
                            . "🔑 کد دعوت: <code>{referral_code}</code>",
                        'is_default' => 1,
                        'sort_order' => 1,
                    ],
                    [
                        'title' => '🚀 کسب درآمد روزانه و چندلایه',
                        'body' => "🔥 دنبال یه راه ساده و واقعی برای کسب درآمد آنلاین هستی؟\n\n"
                            . "در <b>{app_name}</b> به ازای هر دوستی که دعوت کنی <b>{inviter_reward}</b> مستقیم به کیف پولت واریز می‌شه و از فعالیت زیرمجموعه‌هات هم تا <b>{max_levels} لایه</b> پورسانت می‌گیری! 💎📈\n"
                            . "تازه خودت هم در شروع <b>{invitee_reward}</b> هدیه خوش‌آمدگویی می‌گیری!\n\n"
                            . "🔗 شروع رایگان با لینک اختصاصی:\n{invite_link}",
                        'is_default' => 0,
                        'sort_order' => 2,
                    ],
                    [
                        'title' => '⚡ پیام کوتاه و سریع (مناسب استوری و فوروارد)',
                        'body' => "🎁 بیا تو <b>{app_name}</b> و بلافاصله پس از ثبت‌نام <b>{invitee_reward}</b> هدیه بگیر! با دعوت هر دوست هم <b>{inviter_reward}</b> پاداش نقدی می‌گیری 💸👇\n\n{invite_link}",
                        'is_default' => 0,
                        'sort_order' => 3,
                    ],
                    [
                        'title' => '🌟 دعوت ویژه گروه‌ها و کانال‌ها',
                        'body' => "📣 <b>فرصت ویژه کسب درآمد از دعوت دوستان در {app_name}</b>\n\n"
                            . "• ثبت‌نام کاملاً رایگان در کمتر از ۱ دقیقه\n"
                            . "• <b>{invitee_reward}</b> هدیه ورود برای اعضای جدید\n"
                            . "• <b>{inviter_reward}</b> پاداش نقدی برای هر معرفی مستقیم\n"
                            . "• کیف پول شفاف و برداشت آسان\n\n"
                            . "👇 جهت عضویت و دریافت هدیه کلیک کنید:\n{invite_link}",
                        'is_default' => 0,
                        'sort_order' => 4,
                    ],
                ];
                foreach ($defaults as $m) {
                    try {
                        $this->db->insert('invite_messages', [
                            'title' => $m['title'],
                            'body' => $m['body'],
                            'is_enabled' => 1,
                            'is_default' => $m['is_default'],
                            'sort_order' => $m['sort_order'],
                            'share_count' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    } catch (\Throwable) {
                    }
                }
            }
        }

        if ($this->db->tableExists('invite_banners')) {
            $bCount = (int) $this->db->value('SELECT COUNT(*) FROM invite_banners');
            if ($bCount === 0) {
                $defaultBanners = [
                    [
                        'title' => '💰 کیف پول طلایی و پاداش نقدی (پیش‌فرض)',
                        'image_path' => 'assets/img/banners/invite-gold-wallet.jpg',
                        'caption_template' => "💰 <b>با دعوت دوستانت به {app_name} درآمد نقدی کسب کن!</b>\n\n"
                            . "🎁 هدیه خوش‌آمدگویی تو: <b>{invitee_reward}</b>\n"
                            . "💎 پاداش هر دعوت موفق: <b>{inviter_reward}</b>\n\n"
                            . "👇 همین حالا از لینک اختصاصی وارد شو:\n{invite_link}",
                        'is_default' => 1,
                        'sort_order' => 1,
                    ],
                    [
                        'title' => '🎁 جعبه هدیه دوطرفه دعوت دوستان',
                        'image_path' => 'assets/img/banners/invite-gift-friends.jpg',
                        'caption_template' => "🎁 <b>یک دعوت، دو برنده در {app_name}!</b>\n\n"
                            . "با عضویت از لینک زیر، <b>{invitee_reward}</b> هدیه ورود می‌گیری و با دعوت هر دوست <b>{inviter_reward}</b> پاداش نقدی دریافت می‌کنی ✨\n\n"
                            . "🔗 لینک ورود و دریافت هدیه:\n{invite_link}",
                        'is_default' => 0,
                        'sort_order' => 2,
                    ],
                    [
                        'title' => '🌐 شبکه هوشمند درآمد چندلایه',
                        'image_path' => 'assets/img/banners/invite-network-income.jpg',
                        'caption_template' => "🌐 <b>شبکه دعوت خودت رو بساز و چندلایه درآمد بگیر!</b>\n\n"
                            . "✅ پاداش دعوت مستقیم: <b>{inviter_reward}</b>\n"
                            . "📈 پورسانت غیرمستقیم تا <b>{max_levels} لایه</b> واقعی\n"
                            . "🎁 هدیه شروع برای تو: <b>{invitee_reward}</b>\n\n"
                            . "🚀 شروع با لینک اختصاصی:\n{invite_link}",
                        'is_default' => 0,
                        'sort_order' => 3,
                    ],
                ];
                foreach ($defaultBanners as $b) {
                    try {
                        $this->db->insert('invite_banners', [
                            'title' => $b['title'],
                            'image_path' => $b['image_path'],
                            'caption_template' => $b['caption_template'],
                            'is_builtin' => 1,
                            'is_enabled' => 1,
                            'is_default' => $b['is_default'],
                            'sort_order' => $b['sort_order'],
                            'share_count' => 0,
                            'cached_file_ids' => '{}',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    } catch (\Throwable) {
                    }
                }
            }
        }

        self::$seeded[$oid] = true;
    }

    // ------------------------------------------------------------------ پیام‌های آماده دعوت

    public function listMessages(bool $onlyEnabled = false): array
    {
        $this->ensureSeeded();
        if (!$this->db->tableExists('invite_messages')) {
            return [];
        }
        $sql = 'SELECT * FROM invite_messages '
            . ($onlyEnabled ? 'WHERE is_enabled = 1 ' : '')
            . 'ORDER BY is_default DESC, sort_order ASC, id ASC';
        return $this->db->all($sql);
    }

    public function findMessage(int $id, bool $onlyEnabled = false): ?array
    {
        $this->ensureSeeded();
        if ($id <= 0 || !$this->db->tableExists('invite_messages')) {
            return null;
        }
        $sql = 'SELECT * FROM invite_messages WHERE id = :id' . ($onlyEnabled ? ' AND is_enabled = 1' : '') . ' LIMIT 1';
        return $this->db->first($sql, ['id' => $id]);
    }

    public function defaultMessage(): ?array
    {
        $list = $this->listMessages(true);
        return $list[0] ?? null;
    }

    public function createMessage(array $data, ?int $adminId = null): int
    {
        $this->ensureSeeded();
        $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 160);
        $body = trim((string) ($data['body'] ?? ''));
        if ($title === '' || $body === '') {
            throw new \InvalidArgumentException('عنوان و متن پیام آماده دعوت الزامی است.');
        }
        if (mb_strlen($body) > 3500) {
            throw new \InvalidArgumentException('طول متن پیام دعوت حداکثر ۳۵۰۰ کاراکتر است.');
        }
        $isEnabled = !isset($data['is_enabled']) || !empty($data['is_enabled']) ? 1 : 0;
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        $sortOrder = max(0, min(9999, (int) ($data['sort_order'] ?? 10)));

        if ($isDefault === 1) {
            $isEnabled = 1;
            $this->db->run('UPDATE invite_messages SET is_default = 0');
        }

        $id = $this->db->insert('invite_messages', [
            'title' => $title,
            'body' => $body,
            'is_enabled' => $isEnabled,
            'is_default' => $isDefault,
            'sort_order' => $sortOrder,
            'share_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ensureOneDefault('invite_messages');
        AuditService::log('invite_message.created', 'invite_message', $id, ['title' => $title], $adminId);
        return $id;
    }

    public function updateMessage(int $id, array $data, ?int $adminId = null): void
    {
        $existing = $this->findMessage($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('پیام دعوت یافت نشد.');
        }
        $title = mb_substr(trim((string) ($data['title'] ?? $existing['title'])), 0, 160);
        $body = trim((string) ($data['body'] ?? $existing['body']));
        if ($title === '' || $body === '') {
            throw new \InvalidArgumentException('عنوان و متن پیام دعوت نمی‌تواند خالی باشد.');
        }
        if (mb_strlen($body) > 3500) {
            throw new \InvalidArgumentException('طول متن پیام دعوت حداکثر ۳۵۰۰ کاراکتر است.');
        }
        $isEnabled = array_key_exists('is_enabled', $data) ? (!empty($data['is_enabled']) ? 1 : 0) : (int) $existing['is_enabled'];
        $isDefault = array_key_exists('is_default', $data) ? (!empty($data['is_default']) ? 1 : 0) : (int) $existing['is_default'];
        $sortOrder = array_key_exists('sort_order', $data) ? max(0, min(9999, (int) $data['sort_order'])) : (int) $existing['sort_order'];

        if ($isDefault === 1) {
            $isEnabled = 1;
            $this->db->run('UPDATE invite_messages SET is_default = 0 WHERE id <> :id', ['id' => $id]);
        }

        $this->db->update('invite_messages', [
            'title' => $title,
            'body' => $body,
            'is_enabled' => $isEnabled,
            'is_default' => $isDefault,
            'sort_order' => $sortOrder,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $id]);

        $this->ensureOneDefault('invite_messages');
        AuditService::log('invite_message.updated', 'invite_message', $id, ['title' => $title], $adminId);
    }

    public function toggleMessage(int $id, ?int $adminId = null): bool
    {
        $msg = $this->findMessage($id);
        if ($msg === null) {
            throw new \InvalidArgumentException('پیام دعوت یافت نشد.');
        }
        $next = ((int) $msg['is_enabled']) ? 0 : 1;
        $upd = ['is_enabled' => $next, 'updated_at' => now()];
        if ($next === 0) {
            $upd['is_default'] = 0;
        }
        $this->db->update('invite_messages', $upd, 'id = :id', ['id' => $id]);
        $this->ensureOneDefault('invite_messages');
        AuditService::log('invite_message.toggled', 'invite_message', $id, ['is_enabled' => (bool) $next], $adminId);
        return (bool) $next;
    }

    public function setDefaultMessage(int $id, ?int $adminId = null): void
    {
        if ($this->findMessage($id) === null) {
            throw new \InvalidArgumentException('پیام دعوت یافت نشد.');
        }
        $this->db->run('UPDATE invite_messages SET is_default = 0');
        $this->db->update('invite_messages', ['is_default' => 1, 'is_enabled' => 1, 'updated_at' => now()], 'id = :id', ['id' => $id]);
        AuditService::log('invite_message.default_set', 'invite_message', $id, [], $adminId);
    }

    public function deleteMessage(int $id, ?int $adminId = null): void
    {
        $msg = $this->findMessage($id);
        if ($msg === null) {
            return;
        }
        $this->db->delete('invite_messages', 'id = :id', ['id' => $id]);
        $this->ensureOneDefault('invite_messages');
        AuditService::log('invite_message.deleted', 'invite_message', $id, ['title' => $msg['title']], $adminId);
    }

    // ------------------------------------------------------------------ بنرهای تبلیغاتی دعوت

    public function listBanners(bool $onlyEnabled = false): array
    {
        $this->ensureSeeded();
        if (!$this->db->tableExists('invite_banners')) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT * FROM invite_banners '
            . ($onlyEnabled ? 'WHERE is_enabled = 1 ' : '')
            . 'ORDER BY is_default DESC, sort_order ASC, id ASC'
        );
        foreach ($rows as &$row) {
            $row['image_url'] = $this->bannerPublicUrl($row);
            $row['file_exists'] = $this->bannerFilePath($row) !== null;
        }
        unset($row);
        return $rows;
    }

    public function findBanner(int $id, bool $onlyEnabled = false): ?array
    {
        $this->ensureSeeded();
        if ($id <= 0 || !$this->db->tableExists('invite_banners')) {
            return null;
        }
        $sql = 'SELECT * FROM invite_banners WHERE id = :id' . ($onlyEnabled ? ' AND is_enabled = 1' : '') . ' LIMIT 1';
        $row = $this->db->first($sql, ['id' => $id]);
        if ($row === null) {
            return null;
        }
        $row['image_url'] = $this->bannerPublicUrl($row);
        $row['file_exists'] = $this->bannerFilePath($row) !== null;
        return $row;
    }

    public function defaultBanner(): ?array
    {
        foreach ($this->listBanners(true) as $b) {
            if (!empty($b['file_exists'])) {
                return $b;
            }
        }
        return null;
    }

    /** مسیر فیزیکی امن فایل تصویر بنر روی سرور */
    public function bannerFilePath(array $banner): ?string
    {
        $rel = ltrim(trim((string) ($banner['image_path'] ?? '')), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return null;
        }
        if (str_starts_with($rel, 'assets/img/banners/')) {
            $abs = RB_ROOT . '/public/' . $rel;
            return is_file($abs) ? $abs : null;
        }
        if (str_starts_with($rel, 'storage/invite_banners/')) {
            $base = basename($rel);
            if (!preg_match('~^banner_[a-f0-9]{16}\.(jpg|png|webp)$~', $base)) {
                return null;
            }
            $abs = RB_ROOT . '/storage/invite_banners/' . $base;
            return is_file($abs) ? $abs : null;
        }
        return null;
    }

    /** آدرس عمومی امن بنر برای نمایش در مینی‌اپ، پنل و اشتراک‌گذاری */
    public function bannerPublicUrl(array $banner, bool $absolute = false): string
    {
        $rel = ltrim(trim((string) ($banner['image_path'] ?? '')), '/');
        $path = str_starts_with($rel, 'assets/img/banners/')
            ? asset($rel)
            : url('media/banner/' . (int) ($banner['id'] ?? 0));

        if (!$absolute || preg_match('~^https?://~i', $path)) {
            return $path;
        }
        $origin = rtrim(trim((string) (SettingsService::get('webhook_base_url', '') ?: Request::origin())), '/');
        $basePath = rtrim((string) Config::get('app.base_path', ''), '/');
        if ($basePath !== '' && str_ends_with($origin, $basePath) && str_starts_with($path, $basePath . '/')) {
            $origin = substr($origin, 0, -strlen($basePath));
        }
        return $origin . '/' . ltrim($path, '/');
    }

    /** آپلود و ذخیره امن تصویر اختصاصی بنر تبلیغاتی */
    public function storeUploadedBannerImage(array $file): string
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException(match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم تصویر بنر بیش از سقف مجاز است (حداکثر ۵ مگابایت).',
                UPLOAD_ERR_NO_FILE => 'لطفاً فایل تصویر بنر (JPG، PNG یا WebP) را انتخاب کنید.',
                default => 'آپلود فایل بنر ناموفق بود.',
            });
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp) || $size <= 0 || $size > self::MAX_BANNER_BYTES) {
            throw new \InvalidArgumentException('فایل تصویر بنر نامعتبر است یا حجم آن بیش از ۵ مگابایت است.');
        }
        $info = @getimagesize($tmp);
        if (!is_array($info) || count($info) < 3) {
            throw new \InvalidArgumentException('فایل انتخاب‌شده یک تصویر معتبر نیست.');
        }
        [$w, $h, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];
        if (!isset(self::ALLOWED_IMAGE_TYPES[$type])) {
            throw new \InvalidArgumentException('فقط فرمت‌های تصویری JPG، PNG و WebP پشتیبانی می‌شوند.');
        }
        if ($w < 200 || $h < 120 || $w > 6000 || $h > 6000) {
            throw new \InvalidArgumentException('ابعاد بنر باید حداقل ۲۰۰×۱۲۰ و حداکثر ۶۰۰۰×۶۰۰۰ پیکسل باشد (پیشنهادی: ۱۲۸۰×۷۲۰ یا ۱۰۸۰×۱۰۸۰).');
        }

        $dir = RB_ROOT . '/storage/invite_banners';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('امکان ایجاد پوشه ذخیره‌سازی بنرها وجود ندارد.');
        }
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
        }

        $ext = self::ALLOWED_IMAGE_TYPES[$type]['ext'];
        $name = 'banner_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $name;

        $moved = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @copy($tmp, $dest);
        if (!$moved || !is_file($dest)) {
            throw new \RuntimeException('ذخیره تصویر بنر در سرور ناموفق بود.');
        }
        @chmod($dest, 0644);
        return 'storage/invite_banners/' . $name;
    }

    public function createBanner(array $data, array $file, ?int $adminId = null): int
    {
        $this->ensureSeeded();
        $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 160);
        if ($title === '') {
            throw new \InvalidArgumentException('عنوان بنر تبلیغاتی الزامی است.');
        }
        $caption = mb_substr(trim((string) ($data['caption_template'] ?? '')), 0, 1000);
        $relPath = $this->storeUploadedBannerImage($file);

        $isEnabled = !isset($data['is_enabled']) || !empty($data['is_enabled']) ? 1 : 0;
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        $sortOrder = max(0, min(9999, (int) ($data['sort_order'] ?? 10)));

        if ($isDefault === 1) {
            $isEnabled = 1;
            $this->db->run('UPDATE invite_banners SET is_default = 0');
        }

        $id = $this->db->insert('invite_banners', [
            'title' => $title,
            'image_path' => $relPath,
            'caption_template' => $caption !== '' ? $caption : null,
            'is_builtin' => 0,
            'is_enabled' => $isEnabled,
            'is_default' => $isDefault,
            'sort_order' => $sortOrder,
            'share_count' => 0,
            'cached_file_ids' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ensureOneDefault('invite_banners');
        AuditService::log('invite_banner.created', 'invite_banner', $id, ['title' => $title, 'path' => $relPath], $adminId);
        return $id;
    }

    public function updateBanner(int $id, array $data, ?array $newFile = null, ?int $adminId = null): void
    {
        $existing = $this->findBanner($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('بنر دعوت یافت نشد.');
        }
        $title = mb_substr(trim((string) ($data['title'] ?? $existing['title'])), 0, 160);
        if ($title === '') {
            throw new \InvalidArgumentException('عنوان بنر نمی‌تواند خالی باشد.');
        }
        $caption = array_key_exists('caption_template', $data)
            ? mb_substr(trim((string) $data['caption_template']), 0, 1000)
            : (string) ($existing['caption_template'] ?? '');
        $isEnabled = array_key_exists('is_enabled', $data) ? (!empty($data['is_enabled']) ? 1 : 0) : (int) $existing['is_enabled'];
        $isDefault = array_key_exists('is_default', $data) ? (!empty($data['is_default']) ? 1 : 0) : (int) $existing['is_default'];
        $sortOrder = array_key_exists('sort_order', $data) ? max(0, min(9999, (int) $data['sort_order'])) : (int) $existing['sort_order'];

        $upd = [
            'title' => $title,
            'caption_template' => $caption !== '' ? $caption : null,
            'is_enabled' => $isEnabled,
            'is_default' => $isDefault,
            'sort_order' => $sortOrder,
            'updated_at' => now(),
        ];

        // جایگزینی اختیاری فایل تصویر
        if ($newFile !== null && (int) ($newFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $newRel = $this->storeUploadedBannerImage($newFile);
            if (str_starts_with((string) $existing['image_path'], 'storage/invite_banners/')) {
                $oldAbs = $this->bannerFilePath($existing);
                if ($oldAbs !== null && is_file($oldAbs)) {
                    @unlink($oldAbs);
                }
            }
            $upd['image_path'] = $newRel;
            $upd['is_builtin'] = 0;
            $upd['cached_file_ids'] = '{}'; // پاک‌سازی کش file_id پس از تغییر تصویر
        }

        if ($isDefault === 1) {
            $upd['is_enabled'] = 1;
            $this->db->run('UPDATE invite_banners SET is_default = 0 WHERE id <> :id', ['id' => $id]);
        }

        $this->db->update('invite_banners', $upd, 'id = :id', ['id' => $id]);
        $this->ensureOneDefault('invite_banners');
        AuditService::log('invite_banner.updated', 'invite_banner', $id, ['title' => $title], $adminId);
    }

    public function toggleBanner(int $id, ?int $adminId = null): bool
    {
        $b = $this->findBanner($id);
        if ($b === null) {
            throw new \InvalidArgumentException('بنر یافت نشد.');
        }
        $next = ((int) $b['is_enabled']) ? 0 : 1;
        $upd = ['is_enabled' => $next, 'updated_at' => now()];
        if ($next === 0) {
            $upd['is_default'] = 0;
        }
        $this->db->update('invite_banners', $upd, 'id = :id', ['id' => $id]);
        $this->ensureOneDefault('invite_banners');
        AuditService::log('invite_banner.toggled', 'invite_banner', $id, ['is_enabled' => (bool) $next], $adminId);
        return (bool) $next;
    }

    public function setDefaultBanner(int $id, ?int $adminId = null): void
    {
        if ($this->findBanner($id) === null) {
            throw new \InvalidArgumentException('بنر یافت نشد.');
        }
        $this->db->run('UPDATE invite_banners SET is_default = 0');
        $this->db->update('invite_banners', ['is_default' => 1, 'is_enabled' => 1, 'updated_at' => now()], 'id = :id', ['id' => $id]);
        AuditService::log('invite_banner.default_set', 'invite_banner', $id, [], $adminId);
    }

    public function deleteBanner(int $id, ?int $adminId = null): void
    {
        $b = $this->findBanner($id);
        if ($b === null) {
            return;
        }
        if (empty($b['is_builtin']) && str_starts_with((string) ($b['image_path'] ?? ''), 'storage/invite_banners/')) {
            $abs = $this->bannerFilePath($b);
            if ($abs !== null && is_file($abs)) {
                @unlink($abs);
            }
        }
        $this->db->delete('invite_banners', 'id = :id', ['id' => $id]);
        $this->ensureOneDefault('invite_banners');
        AuditService::log('invite_banner.deleted', 'invite_banner', $id, ['title' => $b['title']], $adminId);
    }

    /** دریافت و ذخیره file_id کش‌شده بنر به ازای هر پیام‌رسان */
    public function cachedFileId(array $banner, string $platform): ?string
    {
        $map = json_decode((string) ($banner['cached_file_ids'] ?? '{}'), true);
        if (!is_array($map)) {
            return null;
        }
        $val = trim((string) ($map[strtolower($platform)] ?? ''));
        return $val !== '' ? $val : null;
    }

    public function rememberFileId(int $bannerId, string $platform, string $fileId): void
    {
        if ($bannerId <= 0 || $fileId === '') {
            return;
        }
        $b = $this->findBanner($bannerId);
        if ($b === null) {
            return;
        }
        $map = json_decode((string) ($b['cached_file_ids'] ?? '{}'), true);
        if (!is_array($map)) {
            $map = [];
        }
        $map[strtolower($platform)] = $fileId;
        $this->db->update('invite_banners', [
            'cached_file_ids' => (string) json_encode($map, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $bannerId]);
    }

    public function recordShare(string $type, int $id): void
    {
        $table = $type === 'banner' ? 'invite_banners' : 'invite_messages';
        if ($id <= 0 || !$this->db->tableExists($table)) {
            return;
        }
        try {
            $this->db->run("UPDATE {$table} SET share_count = share_count + 1 WHERE id = :id", ['id' => $id]);
        } catch (\Throwable) {
        }
    }

    // ------------------------------------------------------------------ تولید محتوای اشتراک‌گذاری با لینک یکتای تضمینی

    /**
     * تولید متن نهایی یک پیام آماده برای کاربر و پیام‌رسان مشخص.
     * تضمین ۱۰۰٪: لینک اختصاصی دعوت کاربر همیشه در متن خروجی وجود دارد.
     */
    public function renderMessage(
        array $message,
        array $user,
        string $inviteLink,
        string $platform = MessengerCapabilities::TELEGRAM,
        bool $asHtml = true,
        ?CommissionSettings $settings = null
    ): string {
        $settings ??= CommissionSettings::current($this->db);
        $template = trim((string) ($message['body'] ?? ''));
        return $this->renderTemplateWithGuaranteedLink($template, $user, $inviteLink, $platform, $asHtml, $settings);
    }

    /**
     * تولید کپشن نهایی بنر تبلیغاتی برای دو حالت:
     *   - $fullCaption = true  : بنر همراه کپشن کامل دعوت + لینک یکتا
     *   - $fullCaption = false : بنر با امضای کوتاه لینک یکتای دعوت (برای ردیابی قطعی دعوت)
     */
    public function renderBannerCaption(
        array $banner,
        array $user,
        string $inviteLink,
        string $platform = MessengerCapabilities::TELEGRAM,
        bool $fullCaption = true,
        bool $asHtml = true,
        ?CommissionSettings $settings = null,
        ?array $overrideMessage = null
    ): string {
        $settings ??= CommissionSettings::current($this->db);
        if (!$fullCaption) {
            $appName = (string) SettingsService::get('app_name', 'رفرال‌بات');
            if ($inviteLink === '') {
                return $asHtml ? ('🎁 <b>' . e($appName) . '</b> — کد دعوت: <code>' . e((string) ($user['referral_code'] ?? '')) . '</code>')
                    : ('🎁 ' . $appName . ' — کد دعوت: ' . ($user['referral_code'] ?? ''));
            }
            return $asHtml
                ? ('🎁 <b>' . e($appName) . "</b>\n🔗 لینک اختصاصی عضویت:\n" . e($inviteLink))
                : ('🎁 ' . $appName . "\n🔗 لینک اختصاصی عضویت:\n" . $inviteLink);
        }

        $tpl = '';
        if ($overrideMessage !== null && !empty($overrideMessage['body'])) {
            $tpl = trim((string) $overrideMessage['body']);
        } elseif (!empty($banner['caption_template'])) {
            $tpl = trim((string) $banner['caption_template']);
        } elseif (($defMsg = $this->defaultMessage()) !== null) {
            $tpl = trim((string) $defMsg['body']);
        }

        $rendered = $this->renderTemplateWithGuaranteedLink($tpl, $user, $inviteLink, $platform, $asHtml, $settings);
        // رعایت سقف ۱۰۲۴ کاراکتر کپشن تصویر در تلگرام و بله بدون قطع شدن لینک دعوت
        if (mb_strlen($rendered) > 1000) {
            $plain = AbstractMessengerDriver::htmlToPlain($rendered);
            $cut = mb_substr($plain, 0, 820) . "…";
            if ($inviteLink !== '' && !str_contains($cut, $inviteLink)) {
                $cut .= "\n\n🔗 " . $inviteLink;
            }
            return $asHtml ? e($cut) : $cut;
        }
        return $rendered;
    }

    /** جایگذاری متغیرها و تضمین درج لینک یکتای دعوت در خروجی */
    public function renderTemplateWithGuaranteedLink(
        string $template,
        array $user,
        string $inviteLink,
        string $platform,
        bool $asHtml,
        CommissionSettings $settings
    ): string {
        $pLabel = MessengerCapabilities::label($platform);
        $tpl = str_replace(['{messenger}', '[پیام‌رسان]'], $asHtml ? e($pLabel) : $pLabel, $template);

        $rendered = ReferralMessageService::replaceVariables($tpl, $user, $inviteLink, null, $settings, $this->db, $asHtml);
        if (!$asHtml) {
            $rendered = AbstractMessengerDriver::htmlToPlain($rendered);
        }

        if ($inviteLink !== '') {
            $checkTarget = $asHtml ? e($inviteLink) : $inviteLink;
            if (!str_contains($rendered, $inviteLink) && !str_contains($rendered, $checkTarget)) {
                $rendered = rtrim($rendered) . "\n\n🔗 " . ($asHtml ? e($inviteLink) : $inviteLink);
            }
        }
        return trim($rendered);
    }

    /**
     * بسته کامل «استودیو دعوت» برای کاربر در مینی‌اپ (شامل تمام پیام‌های فعال، بنرهای فعال و لینک تمام پیام‌رسان‌ها)
     */
    public function buildUserStudioPayload(array $user, string $currentPlatform): array
    {
        $this->ensureSeeded();
        $settings = CommissionSettings::current($this->db);
        $registry = new MessengerRegistry($this->db);
        $code = (string) ($user['referral_code'] ?? '');

        $primaryLink = $registry->buildReferralLink($currentPlatform, $code);
        $platformLinks = $registry->allReferralLinksForCode($code, true, $currentPlatform);
        $landingLink = $registry->buildLandingLink($code, $currentPlatform);

        $messages = [];
        foreach ($this->listMessages(true) as $m) {
            $plain = $this->renderMessage($m, $user, $primaryLink, $currentPlatform, false, $settings);
            // نسخه بدون لینک انتهایی برای دکمه اشتراک تلگرام (چون t.me/share/url لینک را جداگانه می‌گیرد)
            $shareTextOnly = trim(str_replace($primaryLink, '', $plain));
            $messages[] = [
                'id' => (int) $m['id'],
                'title' => (string) $m['title'],
                'is_default' => (bool) $m['is_default'],
                'text' => $plain,
                'share_text' => $shareTextOnly !== '' ? $shareTextOnly : $plain,
            ];
        }

        $banners = [];
        foreach ($this->listBanners(true) as $b) {
            if (empty($b['file_exists'])) {
                continue;
            }
            $banners[] = [
                'id' => (int) $b['id'],
                'title' => (string) $b['title'],
                'is_default' => (bool) $b['is_default'],
                'image_url' => $this->bannerPublicUrl($b, false),
                'absolute_url' => $this->bannerPublicUrl($b, true),
                'caption' => $this->renderBannerCaption($b, $user, $primaryLink, $currentPlatform, true, false, $settings),
                'short_caption' => $this->renderBannerCaption($b, $user, $primaryLink, $currentPlatform, false, false, $settings),
            ];
        }

        return [
            'current_platform' => $currentPlatform,
            'current_platform_name' => MessengerCapabilities::label($currentPlatform),
            'referral_code' => $code,
            'primary_link' => $primaryLink,
            'landing_link' => $landingLink,
            'supports_tg_share' => $currentPlatform === MessengerCapabilities::TELEGRAM,
            'supports_bot_send' => MessengerCapabilities::supports($currentPlatform, 'send_photo')
                && $currentPlatform !== MessengerCapabilities::EITAA,
            'platform_links' => array_values($platformLinks),
            'messages' => $messages,
            'banners' => $banners,
        ];
    }

    private function ensureOneDefault(string $table): void
    {
        if (!$this->db->tableExists($table)) {
            return;
        }
        $hasDefault = (int) $this->db->value("SELECT COUNT(*) FROM {$table} WHERE is_default = 1 AND is_enabled = 1");
        if ($hasDefault === 0) {
            $first = $this->db->first("SELECT id FROM {$table} WHERE is_enabled = 1 ORDER BY sort_order ASC, id ASC LIMIT 1");
            if ($first) {
                $this->db->update($table, ['is_default' => 1], 'id = :id', ['id' => (int) $first['id']]);
            }
        }
    }
}
