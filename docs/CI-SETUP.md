<div dir="rtl">

# CI (GitHub Actions) — فعال است ✅

تست‌های خودکار در مسیر درست قرار دارند: **`.github/workflows/tests.yml`**

## چه زمانی اجرا می‌شود؟

- هر `push` روی هر شاخه
- هر Pull Request
- اجرای دستی از تب **Actions** → Tests → **Run workflow**

پس از هر push، نتیجه را در تب **Actions** ببینید؛ خروجی خلاصه‌ی تست‌ها در
**Summary** همان اجرا نمایش داده می‌شود.

## سه Job

| Job | چه می‌کند |
|---|---|
| `php-tests` | روی PHP 8.1 / 8.2 / 8.3: `php -l` همه فایل‌ها، `tests/_lint.php`، و `tests/run_all.php` (۱۴ سناریو + تست‌های تکمیلی) |
| `http-smoke` | سرور واقعی `php -S` بالا می‌آورد و با `curl` بررسی می‌کند: `/healthz` = 200 با `"ok":true`، `/admin` = 302، `/install` و `/install.php` = 403 (قفل نصب)، `/nope-404` = 404، کرون وب بدون توکن = 403 و با توکن = 200، assets سرو می‌شوند، `.env` و `src/` مسدودند، و هیچ خطای PHP در لاگ نیست |
| `security-scan` | نبود `.env` در مخزن، نبود توکن واقعی ربات، نبود کلید خصوصی، نبود رمز پیش‌فرض |

## اجرای محلی (بدون GitHub)

```bash
php tests/run_all.php        # همه‌ی سناریوها
php tests/_lint.php          # سینتکس + ارجاع نسبی کلاس‌ها
php tests/_smoke.php         # رندر همه‌ی قالب‌ها
php tests/_route.php         # اجرای واقعی مسیرهای GET
php tests/_actions.php       # اجرای واقعی عملیات POST مدیر
```

## نکته‌ی مهم درباره‌ی توکن CI

اگر روزی خواستید این فایل را با یک GitHub App تغییر دهید و خطای
`refusing to allow a GitHub App to create or update workflow` گرفتید،
یعنی توکن آن اپ مجوز `workflows` ندارد. در آن حالت فایل را از رابط وب GitHub
ویرایش کنید (یا به توکن، مجوز `workflow` بدهید). این محدودیت مربوط به توکن است،
نه به محتوای YAML.

</div>
