<div dir="rtl">

# راهنمای نصب

> **قبل از خرید هاست** — [`HOSTING.md`](HOSTING.md) را بخوانید: کدام پلن، چه محدودیتی
> خطرناک است، و چطور بدون خرید روی گوشی تست کنید.

## ۱. بررسی پیش‌نیازها

از داخل cPanel یا SSH بررسی کنید:

```bash
php -v                     # باید 8.1 یا بالاتر باشد
php -m | grep -E 'pdo|mbstring|openssl|curl'
```

اگر `pdo_mysql` نبود، از بخش «Select PHP Version → Extensions» هاست آن را فعال کنید.

## ۲. آپلود فایل‌ها

**حالت ساده (توصیه‌شده):** کل پوشه‌ی پروژه را در `public_html` آپلود کنید.
فایل `.htaccess` ریشه همه‌ی درخواست‌ها را بی‌صدا به `public/index.php` می‌فرستد،
پس آدرس‌ها تمیز می‌مانند (`https://site.com/admin/login`) و دسترسی مستقیم به
`src/`, `storage/`, `database/`, `config/`, `tests/`, `bin/`, `.env`, `*.md`, `*.json`
با ۴۰۳ مسدود است.

```
/home/USER/public_html/
├── .htaccess          ← آماده است
├── install.php        ← نقطه ورود نصب
├── public/            ← front controller + assets
├── src/  views/  config/  database/  storage/  bin/
```

**حالت حرفه‌ای (docroot = public/):** کل پروژه بیرون از `public_html` و دامنه روی `public/`.
در این حالت `public/install.php` همان ویزارد را باز می‌کند و `.htaccess` ریشه اثری ندارد.

## ۳. اجرای ویزارد نصب — تک‌فایل `install.php`

```
https://YOUR-DOMAIN/install.php
```

هیچ کار دستی لازم نیست — نه `.env` بسازید، نه `APP_KEY` تولید کنید، نه چمد بدهید.
ویزارد خودش این کارها را می‌کند:

| مرحله | جزئیات |
|---|---|
| ۱ | بررسی PHP ≥ 8.1، `pdo_mysql`، `mbstring`، `openssl`، `curl`، `json`، دسترسی نوشتن `storage/` |
| ۲ | اتصال به MySQL: دکمه‌ی **«فقط آزمایش اتصال»** بدون ذخیره‌سازی، گزینه‌ی **ساخت خودکار دیتابیس** (`CREATE DATABASE`)، و هشدار اگر دیتابیس از قبل نصب شده باشد |
| ۳ | ساخت ۲۵+ جدول — قابل تکرار و بدون حذف داده |
| ۴ | ساخت **اولین مدیر کل**: نام، نام کاربری، رمز (حداقل ۱۰ کاراکتر با حرف بزرگ/کوچک/عدد؛ رمزهای رایج رد می‌شوند) |
| ۵ | نام سامانه، آدرس پایه، **زیرپوشه (خودکار تشخیص داده می‌شود)**، **پاداش هر معرفی**، **حداقل برداشت**، حالت پنل وب |
| ۶ | نوشتن `.env` (با `APP_KEY` تصادفی و `APP_BASE_PATH`)، قفل دائمی ویزارد، هدایت به `/admin/login` |

> **بدون دامنه هم کار می‌کند:** کافی است آدرس `http://SERVER-IP:PORT/install.php` را باز کنید.
> در این حالت پنل وب کاربران (Mini App) خودکار خاموش می‌شود، چون تلگرام برای Web App
> الزاماً HTTPS می‌خواهد؛ همه‌ی قابلیت‌ها از طریق دکمه‌های ربات در دسترس‌اند.

## ۴. Cron — دو راه

**راه اول (بدون crontab):** پنل مدیریت → اتصال به ربات → بخش «کرون وب» → آدرس آماده را کپی و
در Cron Jobs هاست یا هر سرویس زمان‌بندی رایگان هر ۵ دقیقه صدا بزنید:

```
https://YOUR-DOMAIN/cron/run?token=TOKEN&task=all
```

بدون توکن صحیح پاسخ `403` برمی‌گردد و هیچ کاری اجرا نمی‌شود.

**راه دوم (با crontab):**

```bash
php bin/install-cron.php     # نمایش دستور دقیق با مسیر صحیح
```

```
*/2 * * * * /usr/local/bin/php /home/USER/public_html/bin/cron.php broadcast --quiet
0,10,20,30,40,50 * * * * /usr/local/bin/php /home/USER/public_html/bin/cron.php all --quiet
```

## ۵. بررسی نهایی

- `https://YOUR-DOMAIN/healthz` → JSON با `"ok": true`
- `https://YOUR-DOMAIN/install.php` → پیام «سامانه قبلاً نصب شده است» (قفل فعال)
- `https://YOUR-DOMAIN/.env` و `.../storage/logs/` → ۴۰۳
- ورود به `/admin/login` با همان نام کاربری/رمزی که در ویزارد ساختید
- ثبت توکن ربات و تنظیم Webhook (راهنما: [`BOT-SETUP.md`](BOT-SETUP.md))
- ارسال `/start` به ربات از یک حساب تلگرام واقعی و مشاهده کاربر جدید در «کاربران»

## ۶. اتصال ربات

به [`BOT-SETUP.md`](BOT-SETUP.md) مراجعه کنید.

## ارتقا و بازنصب

- برای ارتقا: فقط فایل‌های `src/`, `views/`, `public/assets/`, `bin/` را جایگزین کنید. **`.env` و `storage/` را دست نزنید.**
- برای بازنصب کامل: `storage/installed.lock` را حذف و `installed_at` را از `system_settings` پاک کنید. ⚠️ این کار ویزارد نصب را باز می‌کند.

</div>
