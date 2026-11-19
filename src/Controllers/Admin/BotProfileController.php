<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\AuditService;
use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Support\Database;
use RB\Support\Request;

/**
 * مدیریت مستقل مشخصات ظاهری، بیوگرافی (Bio)، تصویر پروفایل و وضعیت ربات تلگرام
 */
final class BotProfileController extends AdminController
{
    private const MAX_PHOTO_BYTES = 5_242_880; // 5 MB
    private const ALLOWED_IMAGE_TYPES = [
        IMAGETYPE_JPEG => ['mime' => 'image/jpeg', 'ext' => 'jpg'],
        IMAGETYPE_PNG  => ['mime' => 'image/png',  'ext' => 'png'],
        IMAGETYPE_WEBP => ['mime' => 'image/webp', 'ext' => 'webp'],
    ];

    public function index(): void
    {
        $db = Database::instance();
        TelegramService::forgetSettingsCache();
        $tg = new TelegramService(null, $db);
        $settings = TelegramService::settings($db);

        // در اولین مراجعه پس از اتصال ربات، اطلاعات پروفایل را یک‌بار از تلگرام همگام کن
        if ($tg->isConfigured()
            && ($settings['connection_status'] ?? '') === 'connected'
            && empty($settings['profile_synced_at'])
            && $db->columnExists('telegram_settings', 'profile_synced_at')
        ) {
            try {
                $this->performSync($tg, $db, $settings, false);
                TelegramService::forgetSettingsCache();
                $settings = TelegramService::settings($db);
            } catch (\Throwable) {
                // خطای شبکه هرگز مانع باز شدن صفحه مدیریت نشود
            }
        }

        $hasPhoto = $this->resolveStoredPhotoPath($settings) !== null || !empty($settings['bot_photo_file_id']);

        $this->render('admin/bot_profile/index', [
            'title' => 'پروفایل و ظاهر ربات',
            'settings' => $settings,
            'configured' => $tg->isConfigured(),
            'maskedToken' => $tg->maskedToken(),
            'hasPhoto' => $hasPhoto,
            'botUiMode' => SettingsService::configuredBotUiMode(),
            'botUiModeLabel' => SettingsService::BOT_UI_MODES[SettingsService::configuredBotUiMode()] ?? '',
            'curlAvailable' => function_exists('curl_init'),
            'gdAvailable' => function_exists('imagecreatefromstring') && function_exists('imagejpeg'),
        ]);
    }

    /** استعلام و همگام‌سازی زنده اطلاعات، نام، Bio، توضیحات و تصویر فعلی ربات از تلگرام */
    public function sync(): void
    {
        $db = Database::instance();
        $tg = new TelegramService(null, $db);
        if (!$tg->isConfigured()) {
            $this->failOrRedirect('ابتدا توکن ربات را در بخش «اتصال به ربات» ثبت کنید.');
            return;
        }

        $settings = TelegramService::settings($db);
        $res = $this->performSync($tg, $db, $settings, true);
        if (!$res['ok']) {
            $this->failOrRedirect($res['error']);
            return;
        }

        AuditService::log('telegram.profile_synced', 'telegram', null, [
            'username' => $res['data']['bot_username'] ?? '',
            'has_photo' => !empty($res['data']['bot_photo_file_id']) || !empty($res['data']['bot_photo_path']),
        ], current_admin_id());

        $msg = 'اطلاعات، بیوگرافی و تصویر فعلی ربات با موفقیت از تلگرام دریافت و به‌روزرسانی شد.';
        if ($this->wantsJson()) {
            $this->ok($msg, $res['data']);
            return;
        }
        $this->flash('success', $msg);
        Request::redirect(url('admin/bot-profile'));
    }

    /** ذخیره و اعمال نام نمایشی، بیوگرافی کوتاه (Bio) و توضیحات کامل ربات */
    public function save(): void
    {
        $db = Database::instance();
        $tg = new TelegramService(null, $db);
        $settings = TelegramService::settings($db);

        $name = trim((string) ($_POST['bot_name'] ?? ''));
        $shortDesc = trim((string) ($_POST['bot_short_description'] ?? ''));
        $fullDesc = trim((string) ($_POST['bot_description'] ?? ''));
        $applyTelegram = !isset($_POST['apply_telegram_present']) || !empty($_POST['apply_telegram']);

        if (mb_strlen($name) > 64) {
            $this->failOrRedirect('نام نمایشی ربات حداکثر می‌تواند ۶۴ کاراکتر باشد.');
            return;
        }
        if (mb_strlen($shortDesc) > 120) {
            $this->failOrRedirect('متن بیوگرافی (Bio) ربات حداکثر می‌تواند ۱۲۰ کاراکتر باشد.');
            return;
        }
        if (mb_strlen($fullDesc) > 512) {
            $this->failOrRedirect('توضیحات معرفی ربات حداکثر می‌تواند ۵۱۲ کاراکتر باشد.');
            return;
        }

        $warnings = [];
        $appliedToTelegram = false;

        if ($applyTelegram && $tg->isConfigured()) {
            $oldName = trim((string) ($settings['bot_name'] ?? ''));
            $oldShort = trim((string) ($settings['bot_short_description'] ?? ''));
            $oldDesc = trim((string) ($settings['bot_description'] ?? ''));

            // اعمال نام نمایشی در صورت تغییر و غیرخالی بودن
            if ($name !== '' && $name !== $oldName) {
                $r = $tg->setMyName($name);
                if (!($r['ok'] ?? false)) {
                    $warnings[] = 'تغییر نام ربات در تلگرام ناموفق بود: ' . $this->friendlyTelegramError($r);
                } else {
                    $appliedToTelegram = true;
                }
            }

            // اعمال بیوگرافی کوتاه (Bio / Short Description) — همیشه اعمال کن تا مطمئن شویم همگام است
            if ($shortDesc !== $oldShort || isset($_POST['bot_short_description'])) {
                $r = $tg->setMyShortDescription($shortDesc);
                if (!($r['ok'] ?? false)) {
                    $warnings[] = 'اعمال بیوگرافی (Bio) در تلگرام ناموفق بود: ' . $this->friendlyTelegramError($r);
                } else {
                    $appliedToTelegram = true;
                }
            }

            // اعمال توضیحات معرفی ربات (Description) در صورت تغییر
            if ($fullDesc !== $oldDesc) {
                $r = $tg->setMyDescription($fullDesc);
                if (!($r['ok'] ?? false)) {
                    $warnings[] = 'اعمال توضیحات معرفی در تلگرام ناموفق بود: ' . $this->friendlyTelegramError($r);
                } else {
                    $appliedToTelegram = true;
                }
            }
        }

        // ذخیره تنظیمات در پایگاه داده (هم در حالت متصل و هم پیش‌نویس آفلاین)
        $update = ['updated_at' => now()];
        if ($name !== '') {
            $update['bot_name'] = $name;
        }
        if ($db->columnExists('telegram_settings', 'bot_short_description')) {
            $update['bot_short_description'] = $shortDesc;
        }
        if ($db->columnExists('telegram_settings', 'bot_description')) {
            $update['bot_description'] = $fullDesc;
        }
        if ($appliedToTelegram && $db->columnExists('telegram_settings', 'profile_synced_at')) {
            $update['profile_synced_at'] = now();
        }

        $this->upsertTelegramSettings($db, $settings, $update);

        // ذخیره آینه‌ای در جدول settings برای دسترسی و پشتیبان‌گیری
        SettingsService::set('bot_bio_text', $shortDesc, 'telegram');
        SettingsService::set('bot_description_text', $fullDesc, 'telegram');

        AuditService::log('telegram.profile_updated', 'telegram', null, [
            'bot_name' => $name,
            'bio_length' => mb_strlen($shortDesc),
            'description_length' => mb_strlen($fullDesc),
            'applied_to_telegram' => $appliedToTelegram,
            'warnings' => $warnings,
        ], current_admin_id());

        if ($warnings !== []) {
            $msg = 'تنظیمات در پنل ذخیره شد، اما در ارتباط با تلگرام هشدار رخ داد: ' . implode(' | ', $warnings);
            if ($this->wantsJson()) {
                $this->fail($msg, 422, ['saved_locally' => true, 'warnings' => $warnings]);
                return;
            }
            $this->flash('warning', $msg);
            Request::redirect(url('admin/bot-profile'));
            return;
        }

        $msg = $appliedToTelegram
            ? '✅ مشخصات و بیوگرافی (Bio) ربات ذخیره شد و مستقیماً در تلگرام اعمال گردید.'
            : ($tg->isConfigured()
                ? '✅ مشخصات و بیوگرافی ربات در پنل مدیریت ذخیره شد.'
                : '✅ تنظیمات ذخیره شد. پس از ثبت توکن در «اتصال به ربات»، روی «ذخیره و اعمال در تلگرام» کلیک کنید.');

        if ($this->wantsJson()) {
            $this->ok($msg, [
                'applied_to_telegram' => $appliedToTelegram,
                'bot_name' => $name,
                'bot_short_description' => $shortDesc,
                'bot_description' => $fullDesc,
            ]);
            return;
        }
        $this->flash('success', $msg);
        Request::redirect(url('admin/bot-profile'));
    }

    /** آپلود، ذخیره و اعمال تصویر پروفایل ربات در تلگرام */
    public function uploadPhoto(): void
    {
        $db = Database::instance();
        $tg = new TelegramService(null, $db);
        $settings = TelegramService::settings($db);

        $file = $_FILES['photo'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $msg = match ($errCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم تصویر بیش از حد مجاز سرور است (حداکثر ۵ مگابایت).',
                UPLOAD_ERR_NO_FILE => 'لطفاً یک فایل تصویر (JPG، PNG یا WebP) انتخاب کنید.',
                default => 'آپلود فایل تصویر ناموفق بود. دوباره تلاش کنید.',
            };
            $this->failOrRedirect($msg);
            return;
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmpPath === '' || !is_file($tmpPath)) {
            $this->failOrRedirect('فایل آپلودشده در سرور یافت نشد.');
            return;
        }
        if ($size <= 0 || $size > self::MAX_PHOTO_BYTES) {
            $this->failOrRedirect('حجم تصویر پروفایل باید بین ۱ کیلوبایت تا ۵ مگابایت باشد.');
            return;
        }

        // اعتبارسنجی واقعی ساختار تصویر (جلوگیری از فایل‌های جعلی یا اسکریپت)
        $imgInfo = @getimagesize($tmpPath);
        if (!is_array($imgInfo) || count($imgInfo) < 3) {
            $this->failOrRedirect('فایل انتخاب‌شده یک تصویر معتبر نیست. فقط فرمت‌های JPG، PNG و WebP مجازند.');
            return;
        }
        [$width, $height, $imgType] = [(int) $imgInfo[0], (int) $imgInfo[1], (int) $imgInfo[2]];
        if (!isset(self::ALLOWED_IMAGE_TYPES[$imgType])) {
            $this->failOrRedirect('فرمت تصویر پشتیبانی نمی‌شود. لطفاً تصویر JPG، PNG یا WebP آپلود کنید.');
            return;
        }
        if ($width < 64 || $height < 64) {
            $this->failOrRedirect('ابعاد تصویر بسیار کوچک است. حداقل ابعاد مجاز ۱۶۰×۱۶۰ پیکسل (پیشنهادی: ۵۱۲×۵۱۲ یا ۶۴۰×۶۴۰) است.');
            return;
        }
        if ($width > 6000 || $height > 6000) {
            $this->failOrRedirect('ابعاد تصویر بسیار بزرگ است. حداکثر ابعاد مجاز ۶۰۰۰×۶۰۰۰ پیکسل است.');
            return;
        }

        $dir = $this->ensurePhotoDirectory();
        if ($dir === null) {
            $this->failOrRedirect('ایجاد پوشه ذخیره‌سازی تصویر در storage ممکن نشد (سطح دسترسی پوشه را بررسی کنید).');
            return;
        }

        // نرمال‌سازی تصویر پروفایل به JPEG مربعی استاندارد تلگرام (در صورت وجود GD)، در غیر این صورت ذخیره ایمن فایل اصلی
        $normalized = $this->prepareStaticAvatar($tmpPath, $dir, $imgType, $width, $height);
        if ($normalized === null) {
            $this->failOrRedirect('پردازش و ذخیره فایل تصویر ممکن نشد.');
            return;
        }
        [$savedAbsPath, $savedRelPath] = $normalized;

        // اعمال تصویر در تلگرام (در صورت متصل بودن ربات)
        $applied = false;
        $photoFileId = $settings['bot_photo_file_id'] ?? null;
        if ($tg->isConfigured()) {
            $apiRes = $tg->setMyProfilePhoto($savedAbsPath);
            if (!($apiRes['ok'] ?? false)) {
                @unlink($savedAbsPath);
                $this->failOrRedirect('اعمال تصویر پروفایل در تلگرام ناموفق بود: ' . $this->friendlyTelegramError($apiRes));
                return;
            }
            $applied = true;

            // دریافت شناسه تصویر جدید از تلگرام در صورت امکان
            $botId = (int) ($settings['bot_id'] ?? 0);
            if ($botId > 0) {
                $fetchedId = $this->fetchLatestBotPhotoFileId($tg, $botId);
                if ($fetchedId !== null) {
                    $photoFileId = $fetchedId;
                }
            }
        }

        // حذف تصویر قدیمی ذخیره‌شده محلی
        $oldAbs = $this->resolveStoredPhotoPath($settings);
        if ($oldAbs !== null && $oldAbs !== $savedAbsPath && is_file($oldAbs)) {
            @unlink($oldAbs);
        }

        $update = ['updated_at' => now()];
        if ($db->columnExists('telegram_settings', 'bot_photo_path')) {
            $update['bot_photo_path'] = $savedRelPath;
        }
        if ($db->columnExists('telegram_settings', 'bot_photo_file_id')) {
            $update['bot_photo_file_id'] = $photoFileId;
        }
        if ($applied && $db->columnExists('telegram_settings', 'profile_synced_at')) {
            $update['profile_synced_at'] = now();
        }
        $this->upsertTelegramSettings($db, $settings, $update);

        AuditService::log('telegram.photo_updated', 'telegram', null, [
            'path' => $savedRelPath,
            'applied_to_telegram' => $applied,
        ], current_admin_id());

        $msg = $applied
            ? '📸 تصویر پروفایل ربات با موفقیت ذخیره شد و در تلگرام تغییر یافت.'
            : '📸 تصویر پروفایل ذخیره شد. پس از اتصال توکن ربات می‌توانید آن را در تلگرام اعمال کنید.';

        if ($this->wantsJson()) {
            $this->ok($msg, [
                'applied_to_telegram' => $applied,
                'photo_url' => url('admin/bot-profile/photo') . '?t=' . time(),
            ]);
            return;
        }
        $this->flash('success', $msg);
        Request::redirect(url('admin/bot-profile'));
    }

    /** حذف تصویر پروفایل فعلی ربات */
    public function removePhoto(): void
    {
        $db = Database::instance();
        $tg = new TelegramService(null, $db);
        $settings = TelegramService::settings($db);

        $removedFromTelegram = false;
        if ($tg->isConfigured()) {
            $res = $tg->removeMyProfilePhoto();
            if (!($res['ok'] ?? false)) {
                $desc = strtolower((string) ($res['description'] ?? ''));
                // اگر ربات از قبل تصویری نداشته باشد، خطای کشنده نده و رکورد محلی را پاک کن
                if (!str_contains($desc, 'photo_not_modified') && !str_contains($desc, 'not found') && !str_contains($desc, 'no profile photo')) {
                    $this->failOrRedirect('حذف تصویر پروفایل از تلگرام ناموفق بود: ' . $this->friendlyTelegramError($res));
                    return;
                }
            } else {
                $removedFromTelegram = true;
            }
        }

        $oldAbs = $this->resolveStoredPhotoPath($settings);
        if ($oldAbs !== null && is_file($oldAbs)) {
            @unlink($oldAbs);
        }

        $update = ['updated_at' => now()];
        if ($db->columnExists('telegram_settings', 'bot_photo_path')) {
            $update['bot_photo_path'] = null;
        }
        if ($db->columnExists('telegram_settings', 'bot_photo_file_id')) {
            $update['bot_photo_file_id'] = null;
        }
        if ($removedFromTelegram && $db->columnExists('telegram_settings', 'profile_synced_at')) {
            $update['profile_synced_at'] = now();
        }
        $this->upsertTelegramSettings($db, $settings, $update);

        AuditService::log('telegram.photo_removed', 'telegram', null, [
            'removed_from_telegram' => $removedFromTelegram,
        ], current_admin_id());

        $msg = 'تصویر پروفایل ربات با موفقیت حذف شد.';
        if ($this->wantsJson()) {
            $this->ok($msg, ['removed_from_telegram' => $removedFromTelegram]);
            return;
        }
        $this->flash('success', $msg);
        Request::redirect(url('admin/bot-profile'));
    }

    /** سرو امن پیش‌نمایش تصویر پروفایل ربات فقط برای ادمین احراز هویت‌شده (بدون افشای توکن) */
    public function photo(): void
    {
        $db = Database::instance();
        $settings = TelegramService::settings($db);
        $absPath = $this->resolveStoredPhotoPath($settings);

        // اگر فایل کش محلی نیست ولی شناسه فایل تلگرام موجود است، در سمت سرور دریافت و کش کن
        if (($absPath === null || !is_file($absPath)) && !empty($settings['bot_photo_file_id'])) {
            $tg = new TelegramService(null, $db);
            if ($tg->isConfigured()) {
                $cached = $this->downloadAndCacheTelegramPhoto($tg, $db, $settings, (string) $settings['bot_photo_file_id']);
                if ($cached !== null) {
                    $absPath = $cached;
                }
            }
        }

        if ($absPath === null || !is_file($absPath)) {
            http_response_code(404);
            exit;
        }

        $info = @getimagesize($absPath);
        $imgType = is_array($info) ? (int) ($info[2] ?? 0) : 0;
        $mime = self::ALLOWED_IMAGE_TYPES[$imgType]['mime'] ?? 'image/jpeg';

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=120');
        header('Content-Length: ' . (string) filesize($absPath));
        readfile($absPath);
        exit;
    }

    // ------------------------------------------------------------------ متدهای کمکی داخلی

    /** @return array{ok:bool,error?:string,data?:array} */
    private function performSync(TelegramService $tg, Database $db, array $settings, bool $downloadAvatar): array
    {
        $me = $tg->getMe();
        if (!($me['ok'] ?? false) || !is_array($me['result'] ?? null)) {
            return ['ok' => false, 'error' => 'ارتباط با تلگرام برقرار نشد: ' . $this->friendlyTelegramError($me)];
        }
        $bot = $me['result'];
        $botId = (int) ($bot['id'] ?? 0);
        $botName = (string) ($bot['first_name'] ?? '');
        $botUsername = (string) ($bot['username'] ?? '');

        $nameRes = $tg->getMyName();
        if (($nameRes['ok'] ?? false) && isset($nameRes['result']['name']) && trim((string) $nameRes['result']['name']) !== '') {
            $botName = trim((string) $nameRes['result']['name']);
        }

        $shortDesc = (string) ($settings['bot_short_description'] ?? '');
        $shortRes = $tg->getMyShortDescription();
        if (($shortRes['ok'] ?? false) && is_array($shortRes['result'] ?? null) && array_key_exists('short_description', $shortRes['result'])) {
            $shortDesc = (string) $shortRes['result']['short_description'];
        }

        $fullDesc = (string) ($settings['bot_description'] ?? '');
        $descRes = $tg->getMyDescription();
        if (($descRes['ok'] ?? false) && is_array($descRes['result'] ?? null) && array_key_exists('description', $descRes['result'])) {
            $fullDesc = (string) $descRes['result']['description'];
        }

        $photoFileId = $botId > 0 ? $this->fetchLatestBotPhotoFileId($tg, $botId) : null;
        $update = [
            'bot_id' => $botId,
            'bot_name' => $botName,
            'bot_username' => $botUsername,
            'connection_status' => 'connected',
            'last_error' => '',
            'last_test_at' => now(),
            'updated_at' => now(),
        ];
        if ($db->columnExists('telegram_settings', 'bot_short_description')) {
            $update['bot_short_description'] = $shortDesc;
        }
        if ($db->columnExists('telegram_settings', 'bot_description')) {
            $update['bot_description'] = $fullDesc;
        }
        if ($db->columnExists('telegram_settings', 'bot_photo_file_id')) {
            $update['bot_photo_file_id'] = $photoFileId;
        }
        if ($db->columnExists('telegram_settings', 'profile_synced_at')) {
            $update['profile_synced_at'] = now();
        }

        // اگر ربات در تلگرام هیچ تصویری ندارد، فایل محلی قدیمی را پاک‌سازی کن
        if ($photoFileId === null && $db->columnExists('telegram_settings', 'bot_photo_path')) {
            $oldAbs = $this->resolveStoredPhotoPath($settings);
            if ($oldAbs !== null && is_file($oldAbs)) {
                @unlink($oldAbs);
            }
            $update['bot_photo_path'] = null;
        }

        $this->upsertTelegramSettings($db, $settings, $update);
        SettingsService::set('bot_bio_text', $shortDesc, 'telegram');
        SettingsService::set('bot_description_text', $fullDesc, 'telegram');

        $freshSettings = TelegramService::settings($db);
        if ($downloadAvatar && $photoFileId !== null) {
            $prevId = (string) ($settings['bot_photo_file_id'] ?? '');
            if ($photoFileId !== $prevId || $this->resolveStoredPhotoPath($freshSettings) === null) {
                $this->downloadAndCacheTelegramPhoto($tg, $db, $freshSettings, $photoFileId);
                $freshSettings = TelegramService::settings($db);
            }
        }

        return [
            'ok' => true,
            'data' => [
                'bot_id' => $botId,
                'bot_name' => $botName,
                'bot_username' => $botUsername,
                'bot_short_description' => $shortDesc,
                'bot_description' => $fullDesc,
                'bot_photo_file_id' => $photoFileId,
                'bot_photo_path' => $freshSettings['bot_photo_path'] ?? null,
            ],
        ];
    }

    private function fetchLatestBotPhotoFileId(TelegramService $tg, int $botId): ?string
    {
        $photos = $tg->getUserProfilePhotos($botId, 1);
        if (!($photos['ok'] ?? false)) {
            return null;
        }
        $list = $photos['result']['photos'] ?? [];
        if (!is_array($list) || $list === [] || !is_array($list[0]) || $list[0] === []) {
            return null;
        }
        // آخرین سایز آرایه بالاترین کیفیت تصویر پروفایل است
        $sizes = $list[0];
        $best = end($sizes);
        $fileId = is_array($best) ? trim((string) ($best['file_id'] ?? '')) : '';
        return $fileId !== '' ? $fileId : null;
    }

    private function downloadAndCacheTelegramPhoto(TelegramService $tg, Database $db, array $settings, string $fileId): ?string
    {
        $fileRes = $tg->getFile($fileId);
        if (!($fileRes['ok'] ?? false) || empty($fileRes['result']['file_path'])) {
            return null;
        }
        $bytes = $tg->downloadFile((string) $fileRes['result']['file_path'], self::MAX_PHOTO_BYTES);
        if ($bytes === null || $bytes === '') {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        $imgType = is_array($info) ? (int) ($info[2] ?? 0) : 0;
        if (!isset(self::ALLOWED_IMAGE_TYPES[$imgType])) {
            return null;
        }
        $dir = $this->ensurePhotoDirectory();
        if ($dir === null) {
            return null;
        }
        $ext = self::ALLOWED_IMAGE_TYPES[$imgType]['ext'];
        $filename = 'bot_avatar_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $abs = $dir . '/' . $filename;
        if (@file_put_contents($abs, $bytes, LOCK_EX) === false) {
            return null;
        }

        $oldAbs = $this->resolveStoredPhotoPath($settings);
        if ($oldAbs !== null && $oldAbs !== $abs && is_file($oldAbs)) {
            @unlink($oldAbs);
        }
        $rel = 'storage/bot_profile/' . $filename;
        if ($db->columnExists('telegram_settings', 'bot_photo_path')) {
            $this->upsertTelegramSettings($db, $settings, ['bot_photo_path' => $rel, 'updated_at' => now()]);
        }
        return $abs;
    }

    /**
     * تبدیل/برش مرکزی تصویر به JPEG مربعی استاندارد (حداکثر ۱۰۲۴×۱۰۲۴) برای سازگاری کامل با setMyProfilePhoto تلگرام
     * @return array{0:string,1:string}|null [absPath, relPath]
     */
    private function prepareStaticAvatar(string $srcPath, string $dir, int $imgType, int $width, int $height): ?array
    {
        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg') && function_exists('imagecreatetruecolor')) {
            $raw = @file_get_contents($srcPath);
            $src = is_string($raw) ? @imagecreatefromstring($raw) : false;
            if ($src !== false) {
                $side = min($width, $height);
                $srcX = (int) floor(($width - $side) / 2);
                $srcY = (int) floor(($height - $side) / 2);
                $targetSide = max(160, min(1024, $side));
                $dst = imagecreatetruecolor($targetSide, $targetSide);
                if ($dst !== false) {
                    // پس‌زمینه سفید برای تصاویر PNG شفاف
                    $white = imagecolorallocate($dst, 255, 255, 255);
                    if ($white !== false) {
                        imagefill($dst, 0, 0, $white);
                    }
                    imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $targetSide, $targetSide, $side, $side);
                    $filename = 'bot_avatar_' . bin2hex(random_bytes(8)) . '.jpg';
                    $abs = $dir . '/' . $filename;
                    $saved = @imagejpeg($dst, $abs, 92);
                    unset($dst, $src);
                    if ($saved && is_file($abs)) {
                        return [$abs, 'storage/bot_profile/' . $filename];
                    }
                }
                unset($src);
            }
        }

        // مسیر جایگزین در صورت غیرفعال بودن افزونه GD روی هاست
        $ext = self::ALLOWED_IMAGE_TYPES[$imgType]['ext'] ?? 'jpg';
        $filename = 'bot_avatar_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $abs = $dir . '/' . $filename;
        if (!@copy($srcPath, $abs)) {
            return null;
        }
        return [$abs, 'storage/bot_profile/' . $filename];
    }

    private function ensurePhotoDirectory(): ?string
    {
        $dir = RB_ROOT . '/storage/bot_profile';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        return $dir;
    }

    private function resolveStoredPhotoPath(array $settings): ?string
    {
        $rel = trim((string) ($settings['bot_photo_path'] ?? ''));
        if ($rel === '' || !str_starts_with($rel, 'storage/bot_profile/')) {
            return null;
        }
        $basename = basename($rel);
        if (!preg_match('~^bot_avatar_[a-f0-9]{16}\.(jpg|png|webp)$~', $basename)) {
            return null;
        }
        $abs = RB_ROOT . '/storage/bot_profile/' . $basename;
        return is_file($abs) ? $abs : null;
    }

    private function upsertTelegramSettings(Database $db, array $settings, array $data): void
    {
        $id = (int) ($settings['id'] ?? 0);
        if ($id > 0) {
            $db->update('telegram_settings', $data, 'id = :id', ['id' => $id]);
        } else {
            $data['last_error'] = (string) ($data['last_error'] ?? '');
            $data['webhook_info'] = (string) ($data['webhook_info'] ?? '');
            $data['created_at'] = now();
            $db->insert('telegram_settings', $data);
        }
        TelegramService::forgetSettingsCache();
    }

    private function friendlyTelegramError(array $res): string
    {
        $desc = trim((string) ($res['description'] ?? 'خطای نامشخص از سمت تلگرام'));
        $lower = strtolower($desc);
        if (str_contains($lower, 'not found') && str_contains($lower, 'method')) {
            return 'نسخه Bot API سرور تلگرام از این متد پشتیبانی نکرد (' . $desc . ').';
        }
        if (str_contains($lower, 'photo_invalid_dimensions')) {
            return 'ابعاد تصویر مورد تأیید تلگرام نیست؛ لطفاً تصویر مربعی با ابعاد حداقل ۵۱۲×۵۱۲ پیکسل انتخاب کنید.';
        }
        if (str_contains($lower, 'too many requests') || (int) ($res['error_code'] ?? 0) === 429) {
            return 'تعداد درخواست‌ها به تلگرام زیاد بوده است؛ لطفاً چند ثانیه بعد دوباره تلاش کنید.';
        }
        return $desc;
    }

    private function failOrRedirect(string $message, int $status = 422): void
    {
        if ($this->wantsJson()) {
            $this->fail($message, $status);
            return;
        }
        $this->flash('error', $message);
        Request::redirect(url('admin/bot-profile'));
    }
}
