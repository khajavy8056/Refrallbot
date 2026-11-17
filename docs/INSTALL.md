<div dir="rtl">

# راهنمای نصب

## ۱. بررسی پیش‌نیازها

از داخل cPanel یا SSH بررسی کنید:

```bash
php -v                     # باید 8.1 یا بالاتر باشد
php -m | grep -E 'pdo|mbstring|openssl|curl'
```

اگر `pdo_mysql` نبود، از بخش «Select PHP Version → Extensions» هاست آن را فعال کنید.

## ۲. آپلود فایل‌ها

ساختار پیشنهادی روی هاست اشتراکی:

```
/home/USER/refrallbot/          ← کل پروژه
/home/USER/public_html/         ← محتویات پوشه public/
```

سپس در `.env` مقدار `APP_BASE_PATH` را خالی بگذارید (اگر روی دامنه اصلی است).

اگر فقط FTP دارید و نمی‌توانید `public` را جدا کنید، کل پروژه را در `public_html` بگذارید. فایل `.htaccess` ریشه‌ی پروژه (که همین حالا موجود است) دسترسی مستقیم به `src/`, `storage/`, `database/`, `config/`, `tests/`, `bin/` و فایل‌های `.env`/`.md`/`.json` را با ۴۰۳ مسدود می‌کند. برای اطمینان، این را هم بررسی کنید:

```bash
curl -I https://YOUR-DOMAIN/.env          # باید 403 باشد
curl -I https://YOUR-DOMAIN/storage/logs/ # باید 403 باشد
```

## ۳. ساخت دیتابیس

در cPanel → MySQL Databases یک دیتابیس و یک کاربر بسازید و به آن دسترسی کامل بدهید. مشخصات را در `.env` وارد کنید.

## ۴. فایل محیطی

```bash
cp .env.example .env
php -r "echo bin2hex(random_bytes(32));"    # APP_KEY
```

مقادیر مهم:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com
APP_KEY=<۶۴ کاراکتر هگز>
DB_DRIVER=mysql
DB_HOST=localhost
DB_DATABASE=cpaneluser_refrallbot
DB_USERNAME=cpaneluser_rb
DB_PASSWORD=...
```

> `APP_DEBUG=false` در محیط عملیاتی الزامی است؛ در حالت debug پیام خطای کامل نمایش داده می‌شود.

## ۵. دسترسی نوشتن

```bash
chmod -R 775 storage
chmod 640 .env
```

## ۶. اجرای ویزارد نصب

`http://YOUR-HOST/install` را باز کنید:

1. **بررسی نیازمندی‌ها** — نسخه PHP، افزونه‌ها، دسترسی نوشتن
2. **اتصال دیتابیس** — تست اتصال با همان مقادیر `.env`
3. **Migration** — ساخت ۲۵+ جدول (بدون حذف داده موجود)
4. **اولین مدیر** — نام کاربری و رمز قوی (حداقل ۱۰ کاراکتر، حرف بزرگ/کوچک/عدد)
5. **تنظیمات اولیه** — نام سامانه، حداقل برداشت، آدرس پایه
6. **پایان** — فایل `storage/installed.lock` ساخته و `RB_INSTALLED=true` می‌شود؛ ویزارد قفل می‌شود

پس از نصب: `http://YOUR-HOST/admin`

## ۷. فعال‌سازی Cron

```bash
php bin/install-cron.php     # نمایش دستور دقیق
```

در cPanel → Cron Jobs دو خط اضافه کنید:

```
*/2 * * * * /usr/local/bin/php /home/USER/refrallbot/bin/cron.php broadcast --quiet
0,10,20,30,40,50 * * * * /usr/local/bin/php /home/USER/refrallbot/bin/cron.php all --quiet
```

## ۸. اتصال ربات

به [`BOT-SETUP.md`](BOT-SETUP.md) مراجعه کنید.

## ۹. بررسی نهایی

- `https://YOUR-HOST/healthz` → پاسخ JSON با `"ok": true`
- ورود به پنل مدیریت
- ثبت توکن ربات و تنظیم Webhook
- ارسال `/start` به ربات از یک حساب تلگرام واقعی
- مشاهده کاربر جدید در «کاربران»

## ارتقا و بازنصب

- برای ارتقا: فقط فایل‌های `src/`, `views/`, `public/assets/`, `bin/` را جایگزین کنید. **`.env` و `storage/` را دست نزنید.**
- برای بازنصب کامل: `storage/installed.lock` را حذف و `installed_at` را از `system_settings` پاک کنید. ⚠️ این کار ویزارد نصب را باز می‌کند.

</div>
