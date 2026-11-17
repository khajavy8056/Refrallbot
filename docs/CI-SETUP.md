<div dir="rtl">

# راه‌اندازی CI (GitHub Actions)

## چرا دستی؟

توکن GitHub این پروژه مجوز `workflows` ندارد، پس نمی‌تواند فایل زیر `.github/workflows/` بسازد یا تغییر دهد (خطای `refusing to allow a GitHub App to create or update workflow`). بنابراین فایل آماده در دو جا نگه داشته شده:

| مسیر | چیست |
|---|---|
| `.github/tests-workflow.yml` | همان محتوا، در مسیر مجاز برای گیت، تا با کد هم‌نسخه بماند |
| `docs/ci-tests.yml` | همان محتوا برای کپی‌کردن راحت |

## سه راه برای فعال‌سازی

### ۱) از رابط وب GitHub (ساده‌ترین، بدون گیت محلی)

1. به مخزن بروید → **Add file** → **Create new file**
2. نام: `.github/workflows/tests.yml`
3. محتوای `docs/ci-tests.yml` را کامل کپی و جای‌گذاری کنید (به‌جز بلوک کامنت بالای فایل، که فقط توضیح است)
4. **Commit changes** — همین. اولین اجرا خودکار شروع می‌شود.

### ۲) از خط فرمان

```bash
mkdir -p .github/workflows
git mv .github/tests-workflow.yml .github/workflows/tests.yml
git commit -m "ci: فعال‌سازی تست‌های خودکار"
git push
```

### ۳) بدون فایل workflow (اجرای دستی)

تست‌ها هیچ وابستگی به CI ندارند:

```bash
php tests/run_all.php      # ۱۱ سناریو + تست‌های تکمیلی
php tests/_lint.php       # سینتکس ۱۲۷ فایل
php tests/_route.php      # دود ۲۵ مسیر
php tests/_actions.php    # دود ۱۶ عملیات POST پنل
```

## سه job فایل

| job | چه می‌کند |
|---|---|
| `php-tests` | روی PHP 8.1/8.2/8.3: `php -l` همه فایل‌ها، `_lint.php`، سپس `run_all.php` |
| `http-smoke` | دیتابیس SQLite را seed می‌کند، `php -S` را بالا می‌آورد، ۱۴ مسیر + ۵ فایل استاتیک + ۴ مسیر حساس + لاگ خطا را بررسی می‌کند |
| `security-scan` | نبود `.env`، توکن واقعی ربات، کلید خصوصی، رمز پیش‌فرض |

## دو نکته که اگر بدانید، عیب‌یابی سریع‌تر است

1. **`/install` در تست دود انتظار `403` دارد، نه `200`.** ویزارد نصب پس از نصب کامل قفل می‌شود (`storage/installed.lock` + `installed_at`) و همان کد ۴۰۳ را برمی‌گرداند. چون `_seed_http.php` وضعیت نصب‌شده را می‌سازد، این انتظار درست است.

2. **`set -o pipefail` لازم است.** بدون آن، `php tests/run_all.php | tee file` همیشه با کد ۰ برمی‌گردد حتی اگر PHP شکست بخورد، و CI سبز می‌شود در حالی که تست‌ها شکست خورده‌اند.

## اگر job شکست خورد

| نشانه | علت محتمل |
|---|---|
| `PHP Parse error` در مرحله سینتکس | نام فایل را نشان می‌دهد؛ همان فایل را اصلاح کنید |
| `✘ انتظار HTTP ... برای /install` | seed اجرا نشده یا `installed_at` نوشته نشده |
| `✘ فایل استاتیک ... خالی است` | فونت یا CSS در مخزن کامل نیست |
| `✘ خطای PHP در لاگ سرور` | لاگ ۶۰ خط آخر در همان مرحله چاپ می‌شود |
| job سبز شد ولی تستی شکست خورد | `pipefail` حذف شده است |

</div>
