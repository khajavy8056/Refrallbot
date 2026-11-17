<div dir="rtl">

# عیب‌یابی

## ۵۰۰ Internal Server Error

1. `storage/logs/app-YYYY-MM-DD.log` را ببینید (آخرین بخش فایل مهم است).
2. اگر خالی بود، لاگ PHP هاست را بررسی کنید (`error_log`).
3. `APP_DEBUG=true` را **موقتاً** فعال کنید تا پیام دقیق را ببینید، بعد حتماً برگردانید.

## صفحه سفید

- اغلب `APP_KEY` خالی یا `storage/` بدون دسترسی نوشتن است.
- `php -r "require 'src/bootstrap.php'; echo 'ok';"` را اجرا کنید.

## ویزارد نصب باز نمی‌شود / به /install می‌پرد

`Middleware::isInstalled()` مقدار `system_settings.installed_at` یا `RB_INSTALLED=true` را بررسی می‌کند.

```sql
SELECT * FROM system_settings WHERE `key` = 'installed_at';
```

اگر مقدار دارد ولی می‌خواهید بازنصب کنید: ردیف را حذف و `storage/installed.lock` را پاک کنید.

## ورود به پنل ممکن نیست

- نام کاربری/رمز را در ویزارد نصب ساختید — **هیچ رمز پیش‌فرضی در پروژه وجود ندارد.**
- اگر رمز را فراموش کردید، در دیتابیس:
  ```sql
  UPDATE admins SET password_hash = '<hash>' WHERE username = 'youruser';
  ```
  که `<hash>` = `php -r "echo password_hash('NewPass123!', PASSWORD_DEFAULT);"`
- اگر «حساب قفل است» دیدید: `login_attempts` را برای آن نام کاربری/آی‌پی پاک کنید.

## ربات جواب نمی‌دهد

| بررسی | دستور/محل |
|---|---|
| توکن درست است؟ | پنل → اتصال به ربات → «تست توکن» |
| Webhook ست شده؟ | `https://api.telegram.org/bot<TOKEN>/getWebhookInfo` |
| خطای آخر چیست؟ | در همان پاسخ، فیلد `last_error_message` |
| سرور در دسترس است؟ | `curl https://YOUR-DOMAIN/healthz` |
| لاگ | `storage/logs/app-*.log` و جدول `telegram_messages` |

خطای رایج: Webhook روی HTTP ست شده؛ تلگرام فقط HTTPS با گواهی معتبر می‌پذیرد.

## Mini App باز نمی‌شود

- `APP_URL` باید `https` باشد.
- باید از داخل تلگرام باز شود (نه مرورگر معمولی) — وگرنه `initData` وجود ندارد.
- ساعت سرور باید درست باشد (اختلاف > ۲۴ ساعت → امضا رد می‌شود).

## پیام همگانی ارسال نمی‌شود

1. Cron اجرا می‌شود؟ `php bin/cron.php broadcast` را دستی اجرا کنید.
2. صف خالی است؟ `SELECT COUNT(*) FROM broadcast_targets WHERE status='pending';`
3. خطای `429`؟ `BROADCAST_DELAY_MS` را افزایش دهید.
4. کاربران بلاک‌کرده ربات را بلاک کرده‌اند → status=`blocked`، طبیعی است.

## «موجودی نامنطبق» در پروفایل کاربر

یعنی `wallets.balance` یا `users.wallet_balance` با مجموع دفتر یکی نیست. علت: تغییر دستی دیتابیس یا خطای میانی.

```php
$wallet->reconcile($userId);      // نمایش اختلاف
$wallet->rebuildBalance($userId); // بازسازی از دفتر
```

دفتر (ledger) همیشه منبع حقیقت است.

## پورسانت پرداخت نشد

- لایه مربوطه فعال است؟
- کاربر معرف بلاک نیست؟
- سقف `max_payout_per_event` یا `daily_payout_cap` پر نشده؟
- رویداد واجد شرایط شده؟ (`referral_events` را ببینید)
- از نسخه‌بندی پورسانت، درصد زمان رویداد اعمال می‌شود — اگر بعد از رویداد درصد را تغییر داده‌اید، روی رویداد قبلی اثر ندارد.

## خطای «هیچ درخواست بازی ندارید» یا برداشت رد می‌شود

- موجودی کافی نیست (`balance ≥ amount`).
- حداقل مبلغ: پیش‌فرض ۳۰٬۰۰۰ تومان.
- درخواست باز قبلی دارید (`max_open_withdrawals`).
- شماره کارت با الگوریتم لان نامعتبر است.

## فونت فارسی بارگذاری نمی‌شود

فایل‌های `public/assets/fonts/*.woff2` باید موجود و از مسیر `/assets/fonts/` قابل دسترسی باشند. در صورت نصب در زیرپوشه، `APP_BASE_PATH` را تنظیم کنید.

</div>
