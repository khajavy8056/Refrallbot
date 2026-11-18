<div dir="rtl">

# فایل CI آماده — جای‌گذاری دستی

توکن GitHub این محیط مجوز `workflows` ندارد، پس نمی‌تواند `.github/workflows/tests.yml` را تغییر دهد.
محتوای به‌روز در **`pending-tests.yml`** گذاشته شده است.

## جای‌گذاری (۳۰ ثانیه)

1. در GitHub → مخزن → مسیر `.github/workflows/tests.yml` → **Edit** (آیکن مداد)
2. کل محتوای فایل را پاک کنید
3. محتوای `.github/workflows-pending/pending-tests.yml` را جای‌گذاری کنید
4. **Commit changes**

بعد از کامیت، یک بار از تب **Actions → Tests → Run workflow** اجرا کنید.

## تفاوت با نسخه‌ی فعلی

- بررسی `/install.php` (باید ۴۰۳ باشد، چون نصب قفل شده)
- کرون وب بدون توکن → ۴۰۳
- کرون وب با توکن آزمایشی → ۲۰۰ با `"ok":true` و `"task":"maintenance"`
- توکن کرون نباید در پاسخ نشت کند
- پنل وب باید فعال باشد (در `tests/_seed_http.php` روشن شده)
- عنوان مرحله‌ی تست‌ها: ۱۴ سناریو

</div>
