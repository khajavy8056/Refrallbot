<?php
declare(strict_types=1);

namespace RB\Messengers;

/**
 * ماتریس رسمی قابلیت‌ها، محدودیت‌ها، حالت‌های نمایش و راهنمای مرحله‌به‌مرحله پیام‌رسان‌ها
 *
 * تمام مقادیر دقیقاً بر اساس مستندات رسمی هر پیام‌رسان تنظیم شده‌اند:
 *   - Telegram : https://core.telegram.org/bots/api & https://core.telegram.org/bots/webapps
 *   - Bale     : https://docs.bale.ai & https://docs.bale.ai/miniapp
 *   - Rubika   : https://rubika.ir/botapi (Bot API v3 — https://botapi.rubika.ir/v3)
 *   - Eitaa    : https://developer.eitaa.com/docs & https://eitaayar.ir
 *
 * برای افزودن پیام‌رسان جدید در آینده کافی است مشخصات آن با register() اضافه شود.
 */
final class MessengerCapabilities
{
    public const TELEGRAM = 'telegram';
    public const BALE     = 'bale';
    public const RUBIKA   = 'rubika';
    public const EITAA    = 'eitaa';

    /** @var array<string,array<string,mixed>> */
    private static array $custom = [];

    /** @return string[] */
    public static function platforms(): array
    {
        return array_values(array_unique(array_merge(
            [self::TELEGRAM, self::BALE, self::RUBIKA, self::EITAA],
            array_keys(self::$custom)
        )));
    }

    public static function isSupported(string $platform): bool
    {
        return in_array(strtolower(trim($platform)), self::platforms(), true);
    }

    /** ثبت پیام‌رسان جدید در آینده بدون نیاز به تغییر هسته سامانه */
    public static function register(string $platform, array $definition): void
    {
        $key = strtolower(trim($platform));
        if ($key !== '') {
            self::$custom[$key] = $definition;
        }
    }

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        $builtIn = [
            self::TELEGRAM => [
                'key' => self::TELEGRAM,
                'name' => 'تلگرام',
                'name_en' => 'Telegram',
                'icon' => '✈️',
                'color' => '#229ED9',
                'docs_url' => 'https://core.telegram.org/bots/api',
                'api_base' => 'https://api.telegram.org',
                'botfather' => '@BotFather',
                'botfather_url' => 'https://t.me/BotFather',
                'token_label' => 'توکن ربات تلگرام (Bot Token)',
                'token_placeholder' => '123456789:AAH...',
                'requires_username' => false,
                'has_secondary_token' => false,
                'has_app_slug' => false,
                'user_id_type' => 'integer',
                'text_format' => 'html',
                'webapp_sdk_url' => 'https://telegram.org/js/telegram-web-app.js?59',
                'capabilities' => [
                    'incoming_webhook'    => true,
                    'polling'             => true,
                    'start_command'       => true,
                    'deep_link_referral'  => true,
                    'send_text'           => true,
                    'send_photo'          => true,
                    'reply_keyboard'      => true,
                    'inline_keyboard'     => true,
                    'request_contact'     => true,
                    'copy_text_button'    => true,
                    'share_url'           => true,
                    'channel_check_api'   => true,
                    'miniapp'             => true,
                    'menu_commands_api'   => true,
                    'chat_menu_button'    => true,
                    'profile_read_api'    => true,
                    'profile_name_api'    => true,
                    'profile_bio_api'     => true,
                    'profile_desc_api'    => true,
                    'profile_photo_api'   => true,
                ],
                'profile_limitation' => null,
                'channel_note' => 'بررسی خودکار عضویت با متد رسمی getChatMember انجام می‌شود (ربات باید ادمین کانال باشد).',
                'miniapp_note' => 'پشتیبانی کامل از Telegram Mini Apps با اعتبارسنجی رمزنگاری‌شده HMAC-SHA256 (initData) روی HTTPS.',
                'referral_link_example' => 'https://t.me/{bot_username}?start={referral_code}',
                'guide_steps' => [
                    'در پیام‌رسان تلگرام وارد ربات رسمی @BotFather شوید و دستور /newbot را ارسال کنید.',
                    'یک نام نمایشی (مثلاً ربات دعوت دوستان) و یک نام کاربری یکتا با پسوند bot (مثلاً MyReferralBot) انتخاب کنید.',
                    'توکن صادرشده به قالب 123456789:AAH... را کپی کرده و در فیلد «توکن ربات تلگرام» وارد و ذخیره نمایید.',
                    'روی دکمه «تست اتصال» کلیک کنید تا نام و مشخصات ربات به‌صورت زنده دریافت شود، سپس «ثبت Webhook» را بزنید.',
                    'برای کانال اجباری، ربات را به عنوان ادمین در کانال تلگرام اضافه کنید و برای Mini App از فعال بودن گواهی HTTPS دامنه مطمئن شوید.',
                ],
                'limitations' => [
                    'اجرای Mini App و Webhook نیازمند دامنه دارای گواهی SSL معتبر (HTTPS) است.',
                    'برای بررسی عضویت کانال اجباری، ربات باید در کانال ادمین باشد.',
                ],
            ],

            self::BALE => [
                'key' => self::BALE,
                'name' => 'بله',
                'name_en' => 'Bale',
                'icon' => '🟢',
                'color' => '#00B894',
                'docs_url' => 'https://docs.bale.ai/',
                'api_base' => 'https://tapi.bale.ai',
                'botfather' => '@botfather',
                'botfather_url' => 'https://ble.ir/botfather',
                'token_label' => 'توکن بازوی بله (Bot Token)',
                'token_placeholder' => '1234567890:ABCDEF...',
                'requires_username' => false,
                'has_secondary_token' => false,
                'has_app_slug' => false,
                'user_id_type' => 'integer',
                'text_format' => 'plain',
                'webapp_sdk_url' => 'https://tapi.bale.ai/miniapp.js',
                'capabilities' => [
                    'incoming_webhook'    => true,
                    'polling'             => true,
                    'start_command'       => true,
                    'deep_link_referral'  => true,
                    'send_text'           => true,
                    'send_photo'          => true,
                    'reply_keyboard'      => true,
                    'inline_keyboard'     => true,
                    'request_contact'     => true,
                    'copy_text_button'    => true,
                    'share_url'           => false,
                    'channel_check_api'   => true,
                    'miniapp'             => true,
                    'menu_commands_api'   => true,
                    'chat_menu_button'    => false,
                    'profile_read_api'    => true,
                    'profile_name_api'    => false,
                    'profile_bio_api'     => false,
                    'profile_desc_api'    => false,
                    'profile_photo_api'   => false,
                ],
                'profile_limitation' => 'بر اساس مستندات رسمی بله (docs.bale.ai)، خواندن مشخصات و تصویر بازو از طریق API (getMe / getChat) پشتیبانی می‌شود، اما متدهای تغییر مستقیم تصویر پروفایل، نام و بیوگرافی بازو در API عمومی ارائه نشده و تغییر آن‌ها باید از طریق بازوی رسمی @botfather در بله انجام شود. در این صفحه می‌توانید پیش‌نویس بیوگرافی و توضیحات را ذخیره و با یک کلیک برای ثبت در @botfather کپی کنید.',
                'channel_note' => 'پشتیبانی کامل از بررسی خودکار عضویت کانال با متد رسمی getChatMember (بازو باید مدیر کانال بله باشد).',
                'miniapp_note' => 'پشتیبانی کامل از مینی‌اپ بله (Bale.WebApp) از طریق دکمه شیشه‌ای، دکمه کیبورد و پیوند مستقیم ble.ir/{bot}?startapp={code} با اعتبارسنجی HMAC-SHA256.',
                'referral_link_example' => 'https://ble.ir/{bot_username}?start={referral_code}',
                'guide_steps' => [
                    'در پیام‌رسان بله وارد بازوی رسمی @botfather (https://ble.ir/botfather) شوید و گزینه ساخت بازوی جدید را انتخاب کنید.',
                    'نام نمایشی بازو و نام کاربری یکتا (با پسوند bot) را وارد کنید تا توکن احراز هویت بازو صادر شود.',
                    'توکن دریافتی را در فیلد «توکن بازوی بله» ثبت و ذخیره کنید و دکمه «تست اتصال» را بزنید.',
                    'دکمه «ثبت Webhook» را بزنید تا آدرس امن HTTPS روی سرور tapi.bale.ai تنظیم شود (در صورت نداشتن Webhook می‌توانید دریافت دوره‌ای با Cron را فعال کنید).',
                    'در صورت استفاده از مینی‌اپ اصلی یا دکمه منوی بازو، در @botfather آدرس مینی‌اپ نمایش‌داده‌شده در همین صفحه (با پارامتر ?platform=bale) را ثبت نمایید.',
                ],
                'limitations' => [
                    'تغییر تصویر پروفایل، نام و بیوگرافی بازو از طریق API عمومی امکان‌پذیر نیست و از طریق @botfather انجام می‌شود.',
                    'بله از دکمه کپی متن (copy_text) و دکمه شیشه‌ای لینک/مینی‌اپ پشتیبانی می‌کند؛ برای اشتراک‌گذاری بنر و متن، کارت قابل فوروارد با لینک اختصاصی تولید می‌شود.',
                ],
            ],

            self::RUBIKA => [
                'key' => self::RUBIKA,
                'name' => 'روبیکا',
                'name_en' => 'Rubika',
                'icon' => '🟣',
                'color' => '#8B5CF6',
                'docs_url' => 'https://rubika.ir/botapi',
                'api_base' => 'https://botapi.rubika.ir/v3',
                'botfather' => '@BotFather',
                'botfather_url' => 'https://rubika.ir/BotFather',
                'token_label' => 'توکن بات روبیکا (Bot API v3 Token)',
                'token_placeholder' => 'توکن دریافتی از @BotFather روبیکا',
                'requires_username' => false,
                'has_secondary_token' => false,
                'has_app_slug' => false,
                'user_id_type' => 'string_guid',
                'text_format' => 'plain',
                'webapp_sdk_url' => null,
                'capabilities' => [
                    'incoming_webhook'    => true,
                    'polling'             => true,
                    'start_command'       => true,
                    'deep_link_referral'  => true,
                    'send_text'           => true,
                    'send_photo'          => true,
                    'reply_keyboard'      => true,
                    'inline_keyboard'     => true,
                    'request_contact'     => true,
                    'copy_text_button'    => false,
                    'share_url'           => false,
                    'channel_check_api'   => false,
                    'miniapp'             => false,
                    'menu_commands_api'   => true,
                    'chat_menu_button'    => false,
                    'profile_read_api'    => true,
                    'profile_name_api'    => false,
                    'profile_bio_api'     => false,
                    'profile_desc_api'    => false,
                    'profile_photo_api'   => false,
                ],
                'profile_limitation' => 'بر اساس مستندات رسمی بات روبیکا (rubika.ir/botapi نسخه v3)، دریافت عنوان بات، نام کاربری، توضیحات، پیام شروع و تصویر پروفایل (avatar) از طریق getMe و getFile پشتیبانی می‌شود، اما متدی برای تغییر تصویر پروفایل یا بیوگرافی از طریق API وجود ندارد و ویرایش آن‌ها از طریق @BotFather در روبیکا انجام می‌شود.',
                'channel_note' => 'در API رسمی نسخه v3 روبیکا متد بررسی عضویت کاربر در کانال (getChatMember) ارائه نشده است؛ سیستم به‌صورت هوشمند لینک عضویت کانال را نمایش داده و با تأیید تعاملی کاربر (بدون مسدودسازی خطادار) ادامه می‌دهد.',
                'miniapp_note' => 'API رسمی بات روبیکا (v3) از دکمه Mini App / Web App پشتیبانی نمی‌کند؛ حتی اگر حالت نمایش عمومی روی «فقط Mini App» باشد، سیستم به‌صورت خودکار و ایمن منوی کامل دکمه‌های معمولی (Chat Keypad) و شیشه‌ای (Inline Keypad) را به کاربران روبیکا نمایش می‌دهد.',
                'referral_link_example' => 'https://rubika.ir/{bot_username}?st={referral_code}',
                'guide_steps' => [
                    'در پیام‌رسان روبیکا وارد بات رسمی @BotFather شوید و یک بات جدید بسازید.',
                    'نام نمایشی، نام کاربری و توضیحات اولیه بات را تنظیم کرده و توکن API نسخه ۳ (v3) را کپی نمایید.',
                    'توکن را در فیلد «توکن بات روبیکا» در همین صفحه وارد و ذخیره کنید و روی «تست اتصال» بزنید تا مشخصات بات از botapi.rubika.ir/v3/getMe دریافت شود.',
                    'روی دکمه «ثبت Endpoint (وب‌هوک)» کلیک کنید تا هر دو مسیر ReceiveUpdate (پیام‌ها و دکمه‌های کیبورد) و ReceiveInlineMessage (دکمه‌های شیشه‌ای) به‌صورت خودکار ثبت شوند.',
                    'در صورت تمایل می‌توانید همگام‌سازی دستورات (setCommands) یا دریافت دوره‌ای پیام‌ها (getUpdates از طریق Cron) را نیز فعال نمایید.',
                ],
                'limitations' => [
                    'شناسه‌های کاربری و چت در روبیکا رشته‌ای (GUID مانند u0... و b0...) هستند که در سامانه به‌صورت اختصاصی تفکیک و ذخیره می‌شوند.',
                    'Mini App در Bot API v3 روبیکا پشتیبانی نمی‌شود → جایگزین هوشمند: دکمه‌های کامل Chat Keypad و Inline Keypad.',
                    'بررسی خودکار عضویت کانال و ویرایش تصویر/بیوگرافی بات در API v3 ارائه نشده است → جایگزین هوشمند فعال است.',
                    'ارسال بنر تصویری از طریق فرآیند دو‌مرحله‌ای رسمی requestSendFile + آپلود + sendFile با کش خودکار file_id انجام می‌شود.',
                ],
            ],

            self::EITAA => [
                'key' => self::EITAA,
                'name' => 'ایتا',
                'name_en' => 'Eitaa',
                'icon' => '🟠',
                'color' => '#F97316',
                'docs_url' => 'https://developer.eitaa.com/docs/Home/',
                'api_base' => 'https://eitaayar.ir',
                'botfather' => 'سامانه ایتایار (eitaayar.ir)',
                'botfather_url' => 'https://eitaayar.ir',
                'token_label' => 'توکن برنامه / برنامک ایتا (App Token)',
                'token_placeholder' => '5768337691:AAGDAe...',
                'secondary_token_label' => 'توکن API ایتایار (اختیاری — برای تست getMe و ارسال فایل به کانال)',
                'secondary_token_placeholder' => 'bot12345:6d3e4430-...',
                'requires_username' => true,
                'username_label' => 'شناسه کاربری برنامه در ایتا (بدون @)',
                'username_placeholder' => 'my_referral_app',
                'has_secondary_token' => true,
                'has_app_slug' => true,
                'app_slug_label' => 'پیوند برنامک (Slug — مثلاً app)',
                'app_slug_placeholder' => 'app',
                'user_id_type' => 'integer',
                'text_format' => 'eitaa_markdown',
                'webapp_sdk_url' => 'https://developer.eitaa.com/eitaa-web-app.js',
                'capabilities' => [
                    'incoming_webhook'    => false,
                    'polling'             => false,
                    'start_command'       => false,
                    'deep_link_referral'  => true,
                    'send_text'           => true,
                    'send_photo'          => true, // از طریق API ایتایار (sendFile) + نمایش و اشتراک بنر در برنامک ایتا
                    'reply_keyboard'      => false,
                    'inline_keyboard'     => false,
                    'request_contact'     => true, // از طریق Eitaa.WebApp.requestContact
                    'copy_text_button'    => false,
                    'share_url'           => false,
                    'channel_check_api'   => false,
                    'miniapp'             => true,
                    'menu_commands_api'   => false,
                    'chat_menu_button'    => false,
                    'profile_read_api'    => true,
                    'profile_name_api'    => false,
                    'profile_bio_api'     => false,
                    'profile_desc_api'    => false,
                    'profile_photo_api'   => false,
                ],
                'profile_limitation' => 'بر اساس مستندات رسمی ایتا (developer.eitaa.com و eitaayar.ir)، تغییر تصویر پروفایل، نام و بیوگرافی برنامه/برنامک از طریق API ارائه نشده و مستقیماً از پنل توسعه‌دهندگان ایتایار (بخش «برنامه‌ها ← ویرایش برنامه») انجام می‌شود. پیش‌نویس بیوگرافی و توضیحات را می‌توانید در همین بخش آماده و کپی نمایید.',
                'channel_note' => 'API رسمی ایتا متد بررسی خودکار عضویت کاربر در کانال را ارائه نمی‌دهد؛ لینک کانال‌های ایتا در برنامک نمایش داده شده و بدون ایجاد خطا با تأیید کاربری/مدیریتی کار می‌کند.',
                'miniapp_note' => 'پشتیبانی کامل از «برنامک ایتا» (Eitaa.WebApp SDK رسمی developer.eitaa.com/eitaa-web-app.js) با احراز هویت امضاشده HMAC-SHA256، دریافت شماره تماس (requestContact)، مجوز ارسال پیام (requestWriteAccess)، پارامتر دعوت start_param و ارسال اعلان با eitaayar.ir/api/app/sendMessage.',
                'referral_link_example' => 'https://eitaa.com/{bot_username}/{app_slug}?startapp={referral_code}',
                'guide_steps' => [
                    'وارد پنل رسمی توسعه‌دهندگان ایتایار به نشانی https://eitaayar.ir شوید و احراز هویت توسعه‌دهنده را تکمیل کنید.',
                    'از منوی «برنامه‌ها» روی «افزودن برنامه» کلیک کرده و نام، شناسه کاربری، تصویر و بیوگرافی برنامه را ثبت نمایید.',
                    'در صفحه برنامه روی «افزودن برنامک» بزنید، پیوند برنامک (مثلاً app) را تعیین کرده و در «آدرس تارنما» آدرس HTTPS مینی‌اپ نمایش‌داده‌شده در همین صفحه (بدون ریدایرکت، با ?platform=eitaa) را قرار دهید.',
                    'توکن برنامه (App Token) و شناسه کاربری برنامه را در فیلدهای این بخش وارد و ذخیره کنید. (در صورت داشتن توکن API ایتایار کانال، می‌توانید آن را در فیلد اختیاری دوم وارد نمایید.)',
                    'کاربران ایتا با کلیک روی لینک اختصاصی برنامک (دارای ?startapp=CODE) به‌صورت خودکار احراز هویت، ثبت‌نام و به دعوت‌کننده متصل می‌شوند و اعلان‌های پاداش را در چت ایتا دریافت می‌کنند.',
                ],
                'limitations' => [
                    'ایتا فاقد Webhook دریافت پیام متنی و دکمه‌های کیبورد/شیشه‌ای در چت است؛ تعامل کامل کاربران از طریق «برنامک رسمی ایتا (Mini App)» انجام می‌شود.',
                    'اگر حالت نمایش عمومی روی «فقط دکمه‌های معمولی» باشد، سیستم به‌صورت هوشمند برنامک ایتا را به عنوان جایگزین امن فعال نگه می‌دارد تا کاربران ایتا با صفحه بسته مواجه نشوند.',
                    'ارسال پیام به کاربر در ایتا (api/app/sendMessage) نیازمند اعطای دسترسی ارسال توسط کاربر (requestWriteAccess) و اعتبار روزانه برنامه در ایتا است.',
                    'طبق مستندات رسمی ایتا، برنامک‌ها روی نسخه‌های اندروید، دسکتاپ و وب ایتا اجرا می‌شوند.',
                ],
            ],
        ];

        foreach (self::$custom as $k => $def) {
            $builtIn[$k] = array_merge($builtIn[self::TELEGRAM], $def, ['key' => $k]);
        }

        return $builtIn;
    }

    /** @return array<string,mixed> */
    public static function get(string $platform): array
    {
        $key = strtolower(trim($platform));
        $all = self::all();
        if (isset($all[$key])) {
            return $all[$key];
        }
        throw new \InvalidArgumentException('پیام‌رسان پشتیبانی‌نشده: ' . $platform);
    }

    public static function supports(string $platform, string $capability): bool
    {
        $key = strtolower(trim($platform));
        $all = self::all();
        if (!isset($all[$key])) {
            return false;
        }
        return !empty($all[$key]['capabilities'][$capability]);
    }

    public static function label(string $platform): string
    {
        $key = strtolower(trim($platform));
        $all = self::all();
        return (string) ($all[$key]['name'] ?? $platform);
    }

    public static function icon(string $platform): string
    {
        $key = strtolower(trim($platform));
        $all = self::all();
        return (string) ($all[$key]['icon'] ?? '💬');
    }

    public static function badge(string $platform): string
    {
        return self::icon($platform) . ' ' . self::label($platform);
    }

    /** عنوان استاندارد تفکیک کاربران در پنل مدیریت: «کاربران تلگرام»، «کاربران روبیکا»، «کاربران ایتا»، «کاربران بله» */
    public static function usersLabel(string $platform): string
    {
        return 'کاربران ' . self::label($platform);
    }

    public static function color(string $platform): string
    {
        $key = strtolower(trim($platform));
        $all = self::all();
        return (string) ($all[$key]['color'] ?? '#2563EB');
    }

    /**
     * آیا کانال میزبانی‌شده روی این پیام‌رسان قابلیت بررسی واقعی و غیرجعلی عضویت (از جمله میان‌پیام‌رسانی) را دارد؟
     *
     * بر اساس مستندات رسمی:
     *   - تلگرام (getChatMember) و بله (getChatMember): بله — با ربات فعال و ادمین در کانال + برای کاربران
     *     سایر پیام‌رسان‌ها از طریق اتصال تأییدشده حساب (کد یک‌بارمصرف LNK) و استعلام زنده getChatMember.
     *   - روبیکا (Bot API v3) و ایتا (EitaaYar / Mini App): خیر — API رسمی فاقد متد getChatMember است
     *     و بنابراین اعمال اجباری میان‌پیام‌رسانی بدون راستی‌آزمایی واقعی مجاز نیست.
     */
    public static function canVerifyCrossMessengerChannel(string $channelPlatform): bool
    {
        return self::supports($channelPlatform, 'channel_check_api');
    }

    /** توضیح شفاف فنی درباره امکان یا محدودیت رسمی بررسی عضویت میان‌پیام‌رسانی برای مدیر */
    public static function crossChannelNote(string $channelPlatform): string
    {
        $key = strtolower(trim($channelPlatform));
        $name = self::label($key);
        if (self::canVerifyCrossMessengerChannel($key)) {
            return 'کانال‌های ' . $name . ' از متد رسمی getChatMember پشتیبانی می‌کنند (ربات ' . $name . ' باید ادمین کانال باشد). '
                . 'در صورت الزام این کانال برای کاربران سایر پیام‌رسان‌های متصل، چون شناسه‌های کاربری پیام‌رسان‌ها مستقل است، '
                . 'کاربر یک‌بار حساب ' . $name . ' خود را با لینک تأیید امن یک‌کلیکی متصل کرده و سپس عضویت واقعی او در کانال از طریق API رسمی ' . $name . ' بررسی و تأیید می‌شود.';
        }
        return 'محدودیت رسمی API ' . $name . ': متد استعلام عضویت کاربر در کانال (مشابه getChatMember) در API رسمی ' . $name . ' ارائه نشده است؛ '
            . 'بنابراین برای جلوگیری از هرگونه بررسی صوری یا غیرواقعی، الزام میان‌پیام‌رسانی روی کانال‌های ' . $name . ' غیرفعال است و این کانال‌ها فقط به کاربران ورودی خود ' . $name . ' به شکل «نمایش لینک + تأیید کاربری» نمایش داده می‌شوند.';
    }

    /**
     * تعیین هوشمند حالت نمایش مؤثر برای یک پیام‌رسان با توجه به تنظیم عمومی مدیر و قابلیت واقعی پیام‌رسان.
     *
     * حالت‌های انتخابی مدیر ($configuredMode):
     *   - 'miniapp' : فقط Mini App
     *   - 'buttons' : فقط دکمه‌های معمولی
     *   - 'hybrid'  : هر دو حالت (Mini App + دکمه‌های معمولی)
     *
     * قوانین بازگشت ایمن (Fallback):
     *   ۱) اگر پیام‌رسان Mini App پشتیبانی نکند (مثل روبیکا) یا آدرس HTTPS نباشد:
     *      حتی در حالت «فقط Mini App» یا «هر دو»، حالت مؤثر = 'buttons' (بدون دکمه خراب).
     *   ۲) اگر پیام‌رسان دکمه چت پشتیبانی نکند ولی Mini App داشته باشد (مثل ایتا):
     *      در صورت وجود HTTPS، حتی در حالت «فقط دکمه‌های معمولی»، حالت مؤثر = 'miniapp'
     *      تا کاربران ایتا به تمام امکانات در برنامک دسترسی داشته باشند.
     *   ۳) در تلگرام و بله (که هر دو را پشتیبانی می‌کنند): دقیقاً مطابق انتخاب مدیر عمل می‌شود
     *      و در صورت نبود HTTPS به‌صورت ایمن به 'buttons' بازمی‌گردد.
     *
     * @return array{
     *   platform:string,
     *   configured:string,
     *   effective:string,
     *   show_miniapp:bool,
     *   show_buttons:bool,
     *   fallback_used:bool,
     *   reason:string
     * }
     */
    public static function resolveUiMode(string $platform, string $configuredMode, bool $hasHttpsUrl): array
    {
        $platform = strtolower(trim($platform));
        $configured = in_array($configuredMode, ['miniapp', 'buttons', 'hybrid'], true) ? $configuredMode : 'hybrid';

        $supportsMiniApp = self::supports($platform, 'miniapp');
        $supportsButtons = self::supports($platform, 'reply_keyboard') || self::supports($platform, 'inline_keyboard');
        $canRunMiniApp = $supportsMiniApp && $hasHttpsUrl;
        $pName = self::label($platform);

        // حالت ۱: پیام‌رسان فاقد Mini App است (مثلاً روبیکا)
        if (!$supportsMiniApp && $supportsButtons) {
            $fallback = ($configured === 'miniapp');
            $reason = match ($configured) {
                'miniapp' => $pName . ' از Mini App پشتیبانی نمی‌کند؛ دکمه‌های معمولی و شیشه‌ای به‌عنوان جایگزین هوشمند نمایش داده می‌شوند.',
                'hybrid'  => 'در ' . $pName . ' (فاقد Mini App)، دکمه‌های معمولی و شیشه‌ای کامل نمایش داده می‌شوند.',
                default   => 'نمایش دکمه‌های معمولی و شیشه‌ای مطابق تنظیم انتخاب‌شده.',
            };
            return [
                'platform' => $platform,
                'configured' => $configured,
                'effective' => 'buttons',
                'show_miniapp' => false,
                'show_buttons' => true,
                'fallback_used' => $fallback,
                'reason' => $reason,
            ];
        }

        // حالت ۲: پیام‌رسان فقط Mini App دارد و فاقد دکمه‌های چت است (مثلاً ایتا)
        if ($supportsMiniApp && !$supportsButtons) {
            if ($hasHttpsUrl) {
                $fallback = ($configured === 'buttons');
                $reason = $fallback
                    ? $pName . ' دکمه‌های تعاملی چت ندارد؛ برنامک (Mini App) به‌عنوان جایگزین هوشمند فعال می‌ماند تا کاربران با اختلال مواجه نشوند.'
                    : 'اجرای کامل از طریق برنامک رسمی ' . $pName . ' (Mini App) روی HTTPS.';
                return [
                    'platform' => $platform,
                    'configured' => $configured,
                    'effective' => 'miniapp',
                    'show_miniapp' => true,
                    'show_buttons' => false,
                    'fallback_used' => $fallback,
                    'reason' => $reason,
                ];
            }
            return [
                'platform' => $platform,
                'configured' => $configured,
                'effective' => 'unavailable_no_https',
                'show_miniapp' => false,
                'show_buttons' => false,
                'fallback_used' => true,
                'reason' => 'اجرای برنامک ' . $pName . ' نیازمند ثبت آدرس HTTPS معتبر برای مینی‌اپ است.',
            ];
        }

        // حالت ۳: پیام‌رسان هم Mini App و هم دکمه‌های معمولی را پشتیبانی می‌کند (تلگرام و بله)
        if ($configured === 'buttons') {
            return [
                'platform' => $platform,
                'configured' => $configured,
                'effective' => 'buttons',
                'show_miniapp' => false,
                'show_buttons' => true,
                'fallback_used' => false,
                'reason' => 'فقط دکمه‌های معمولی و شیشه‌ای ربات در ' . $pName . ' نمایش داده می‌شوند.',
            ];
        }

        if (!$canRunMiniApp) {
            return [
                'platform' => $platform,
                'configured' => $configured,
                'effective' => 'buttons',
                'show_miniapp' => false,
                'show_buttons' => true,
                'fallback_used' => true,
                'reason' => 'به دلیل تنظیم نشدن آدرس HTTPS مینی‌اپ، دکمه‌های معمولی ' . $pName . ' به‌عنوان جایگزین امن نمایش داده می‌شوند.',
            ];
        }

        if ($configured === 'miniapp') {
            return [
                'platform' => $platform,
                'configured' => $configured,
                'effective' => 'miniapp',
                'show_miniapp' => true,
                'show_buttons' => false,
                'fallback_used' => false,
                'reason' => 'تمرکز روی Mini App در ' . $pName . ' (همراه با دکمه امن باز کردن پنل کاربری).',
            ];
        }

        return [
            'platform' => $platform,
            'configured' => $configured,
            'effective' => 'hybrid',
            'show_miniapp' => true,
            'show_buttons' => true,
            'fallback_used' => false,
            'reason' => 'هر دو حالت Mini App و دکمه‌های کامل ربات در ' . $pName . ' هم‌زمان فعال هستند.',
        ];
    }

    /** ماتریس کامل حالت نمایش مؤثر برای تمام پیام‌رسان‌ها (جهت نمایش در تنظیمات عمومی) */
    public static function resolveAllUiModes(string $configuredMode, bool $hasHttpsUrl): array
    {
        $out = [];
        foreach (self::platforms() as $platform) {
            $meta = self::get($platform);
            $resolved = self::resolveUiMode($platform, $configuredMode, $hasHttpsUrl);
            $resolved['name'] = $meta['name'];
            $resolved['icon'] = $meta['icon'];
            $resolved['supports_miniapp'] = self::supports($platform, 'miniapp');
            $resolved['supports_buttons'] = self::supports($platform, 'reply_keyboard') || self::supports($platform, 'inline_keyboard');
            $out[$platform] = $resolved;
        }
        return $out;
    }

    /** تبدیل خطاهای فنی API به پیام فارسی روشن و قابل فهم (بدون افشای توکن) */
    public static function humanizeError(string $platform, string $rawError, int $httpCode = 0): string
    {
        $pName = self::label($platform);
        $lower = strtolower($rawError);

        if ($httpCode === 401 || str_contains($lower, 'unauthorized') || str_contains($lower, 'invalid token') || str_contains($lower, 'invalid_access')) {
            return 'توکن ' . $pName . ' نامعتبر است یا توسط پلتفرم ابطال شده است. لطفاً توکن صحیح را بررسی و مجدداً ثبت کنید.';
        }
        if ($httpCode === 403 || str_contains($lower, 'forbidden') || str_contains($lower, 'blocked')) {
            return 'دسترسی ربات در ' . $pName . ' محدود شده یا کاربر ارسال پیام را مسدود کرده است.';
        }
        if ($httpCode === 404 || str_contains($lower, 'not found') || str_contains($lower, 'invalid_input')) {
            return 'پاسخ معتبری از API ' . $pName . ' یافت نشد. صحت توکن یا شناسه هدف را بررسی کنید.';
        }
        if ($httpCode === 429 || str_contains($lower, 'too many requests') || str_contains($lower, 'rate')) {
            return 'تعداد درخواست‌ها به سرور ' . $pName . ' بیش از حد مجاز بوده است؛ چند لحظه صبر کنید و دوباره تلاش کنید.';
        }
        if (str_contains($lower, 'timed out') || str_contains($lower, 'could not resolve') || str_contains($lower, 'couldn\'t connect')) {
            return 'برقراری ارتباط شبکه‌ای با سرور ' . $pName . ' ناموفق بود (عدم دسترسی شبکه یا اتمام زمان انتظار هاست).';
        }
        if (str_contains($lower, 'ssl') || str_contains($lower, 'certificate')) {
            return 'خطای گواهی امنیتی SSL در ارتباط با سرور ' . $pName . ' رخ داد.';
        }
        if (str_contains($lower, 'https')) {
            return 'آدرس وب‌هوک یا مینی‌اپ باید دارای پروتکل امن HTTPS معتبر باشد.';
        }

        $clean = trim(preg_replace('~https?://[^\s]+~i', '[آدرس API]', $rawError) ?? $rawError);
        if ($clean === '') {
            return 'خطای نامشخص در ارتباط با سرور ' . $pName . ($httpCode > 0 ? ' (کد HTTP ' . fa_digits($httpCode) . ')' : '') . '.';
        }
        return mb_substr($clean, 0, 280);
    }
}
