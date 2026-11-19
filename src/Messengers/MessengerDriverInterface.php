<?php
declare(strict_types=1);

namespace RB\Messengers;

/**
 * قرارداد استاندارد درایور پیام‌رسان‌ها
 *
 * منطق ثبت‌نام، دعوت، کیف پول و درآمد لایه‌ای کاملاً در هسته مرکزی قرار دارد
 * و هر پیام‌رسان تنها این قرارداد ارتباطی را مطابق مستندات رسمی خود پیاده‌سازی می‌کند.
 */
interface MessengerDriverInterface
{
    /** شناسه یکتای پیام‌رسان: telegram | bale | rubika | eitaa | ... */
    public function platform(): string;

    /** آیا توکن/اطلاعات پایه تنظیم شده است؟ */
    public function isConfigured(): bool;

    /** آیا این پیام‌رسان در پنل فعال و آماده سرویس‌دهی است؟ */
    public function isEnabled(): bool;

    /** بررسی پشتیبانی از یک قابلیت بر اساس مستندات رسمی */
    public function supports(string $capability): bool;

    /**
     * تست زنده اتصال و دریافت مشخصات ربات متصل‌شده
     * @return array{ok:bool,bot?:array{id:string,username:string,name:string,description?:string,short_description?:string,start_message?:string,share_url?:string,photo_file_id?:string},error?:string}
     */
    public function testConnection(): array;

    /**
     * ارسال پیام متنی به کاربر یا چت
     * @param array{reply_markup?:mixed,inline_rows?:array,reply_rows?:array,remove_keyboard?:bool,parse_mode?:string,disable_web_page_preview?:bool} $options
     * @return array{ok:bool,message_id?:string,description?:string,raw?:array}
     */
    public function sendMessage(int|string $chatId, string $text, array $options = []): array;

    /**
     * ارسال تصویر / بنر دعوت (با کپشن و دکمه اختیاری)
     * @param string $photoSource مسیر فایل محلی، file_id کش‌شده یا URL عمومی
     * @param array{inline_rows?:array,reply_rows?:array,reply_markup?:mixed,file_id?:?string} $options
     * @return array{ok:bool,message_id?:string,file_id?:?string,description?:string,raw?:array}
     */
    public function sendPhoto(int|string $chatId, string $photoSource, string $caption = '', array $options = []): array;

    /** ویرایش پیام متنی ارسال‌شده (در صورت عدم پشتیبانی، جایگزین امن با sendMessage) */
    public function editMessageText(int|string $chatId, int|string $messageId, string $text, array $options = []): array;

    /** پاسخ به کلیک دکمه شیشه‌ای (در پیام‌رسان‌هایی که نیاز دارند) */
    public function answerCallback(string $callbackId, string $text = '', bool $alert = false): array;

    /**
     * بررسی عضویت کاربر در کانال اجباری
     * @return array{ok:bool,member:bool,supported:bool,error?:string}
     */
    public function checkChannelMembership(array $channel, string $externalUserId): array;

    /**
     * ثبت Webhook / Endpoint دریافت پیام روی سرور پیام‌رسان
     * @return array{ok:bool,url?:string,description?:string}
     */
    public function registerWebhook(string $webhookUrl, string $secret): array;

    /** حذف Webhook / Endpoint */
    public function removeWebhook(): array;

    /**
     * دریافت آپدیت‌های جدید به روش Polling (در صورت پشتیبانی)
     * @return array{ok:bool,updates:IncomingUpdate[],next_offset:?string,error?:string}
     */
    public function fetchUpdates(?string $offset = null, int $limit = 50): array;

    /** تبدیل بدنه خام Webhook دریافتی به آرایه‌ای از IncomingUpdate استاندارد */
    public function parseWebhookPayload(array $payload, string $endpointHint = ''): array;

    /** همگام‌سازی فهرست دستورات ربات (در صورت پشتیبانی) */
    public function syncCommands(array $commands): array;

    /** ساخت لینک اختصاصی دعوت کاربر برای این پیام‌رسان */
    public function buildReferralLink(string $referralCode, string $fallbackWebUrl = ''): string;

    /** ساخت آدرس Mini App مخصوص این پیام‌رسان (خالی اگر پشتیبانی نشود) */
    public function buildMiniAppUrl(string $baseMiniAppUrl, ?string $startParam = null): string;

    /**
     * همگام‌سازی زنده مشخصات پروفایل و ظاهر ربات از API پیام‌رسان
     * @return array{ok:bool,profile?:array<string,mixed>,error?:string}
     */
    public function fetchProfile(): array;

    /**
     * به‌روزرسانی نام/بیوگرافی/توضیحات ربات در API پیام‌رسان (در صورت پشتیبانی رسمی)
     * @return array{ok:bool,supported:bool,updated?:string[],errors?:string[],message?:string}
     */
    public function pushProfile(string $name, string $shortDescription, string $description): array;

    /**
     * آپلود تصویر پروفایل ربات به API پیام‌رسان (در صورت پشتیبانی رسمی)
     * @return array{ok:bool,supported:bool,file_id?:?string,error?:string}
     */
    public function pushProfilePhoto(string $localPath, string $mimeType): array;

    /**
     * حذف تصویر پروفایل ربات از API پیام‌رسان (در صورت پشتیبانی رسمی)
     * @return array{ok:bool,supported:bool,error?:string}
     */
    public function deleteProfilePhoto(): array;

    /** دانلود فایل از سرور پیام‌رسان بر اساس file_id (برای نمایش امن آواتار ربات) */
    public function downloadFile(string $fileId): ?string;
}
