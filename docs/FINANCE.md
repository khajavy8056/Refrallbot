<div dir="rtl">

# مدل مالی

## اصل‌ها

1. **واحد پول: تومان، عدد صحیح.** هیچ `float` در مسیر پول نیست. تبدیل ریال → تومان فقط در لایه نمایش/ورودی انجام می‌شود (`Money::rialToToman`).
2. **دفتر فقط-افزودنی.** هیچ تراکنشی حذف یا ویرایش نمی‌شود. اصلاح = ثبت تراکنش معکوس.
3. **موجودی مشتق است.** `wallets.balance` و `users.wallet_balance` کش خواندنی هستند؛ مقدار معتبر از `SUM` دفتر می‌آید.
4. **هر تراکنش کلید یکتاسازی دارد.**`idempotency_key` یکتاست؛ اجرای دوباره اثر مالی ندارد.
5. **snapshot تنظیمات** در `meta` هر تراکنش ذخیره می‌شود؛ تغییر بعدی درصدها روی گذشته اثر ندارد.

## انواع تراکنش

| نوع | جهت | وضعیت | توضیح |
|---|---|---|---|
| `commission_direct` | credit | completed | پاداش معرفی مستقیم |
| `commission_level` | credit | completed | پورسانت لایه‌ای |
| `campaign_reward` | credit | completed | جایزه جشنواره |
| `adjustment` | credit/debit | completed | تعدیل دستی مدیر (با لاگ) |
| `withdrawal_reserve` | debit | completed | رزرو مبلغ برداشت |
| `withdrawal_paid` | — | info | ثبت تسویه (موجودی از قبل کم شده) |
| `withdrawal_release` | credit | completed | بازگشت مبلغ درخواست رد/لغو‌شده |
| `reversal` | معکوس | completed | اصلاح تراکنش اشتباه |

> چرا `withdrawal_paid` با وضعیت `info`؟ چون پول در مرحله «رزرو» از موجودی کسر شده. اگر در پرداخت دوباره debit بزنیم، دوبار کم می‌شود. این دقیقاً همان باگ کلاسیکی است که در پیاده‌سازی‌های آماتور باعث منفی شدن موجودی می‌شود.

## فرمول پورسانت

تنظیمات نسخه‌دار:

```
percent_base = direct_reward | fixed_base | event_amount
base = (percent_base === 'fixed_base') ? fixed_base_amount : direct_reward
commission(level) = base × percent(level) / 100      (تقسیم صحیح، floor)
```

- لایه غیرفعال → پورسانت **صفر** (به لایه بالاتر منتقل نمی‌شود).
- `max_payout_per_event` → سقف مجموع پرداخت یک رویداد.
- `daily_payout_cap` → سقف روزانه هر ذی‌نفع.
- مجموع درصدهای فعال نباید از ۱۰۰ بیشتر شود (اعتبارسنجی هنگام ذخیره).

محاسبه بدون خطای اعشاری:

```php
$numerator = $amount * Money::toMicro($percent);   // صحیح
$quotient  = intdiv($numerator, 100 * 10000);
```

## برداشت

```
موجودی = SUM(credit) − SUM(debit)  [فقط status=completed]
```

مراحل:

| مرحله | تغییر موجودی | تغییر pending | تغییر total_withdrawn |
|---|---|---|---|
| `create` (رزرو) | −amount | +amount | — |
| `approve` | — | — | — |
| `markPaid` | — | −amount | +amount |
| `reject`/`cancel` | +amount | −amount | — |

قواعد:

- حداقل مبلغ از `min_withdraw_amount` (پیش‌فرض ۳۰٬۰۰۰ تومان، قابل تغییر).
- حداکثر تعداد درخواست باز از `max_open_withdrawals`.
- سقف روزانه از `max_daily_withdrawal` (۰ = بدون سقف).
- شماره کارت با الگوریتم **لان** اعتبارسنجی می‌شود؛ ذخیره‌شده رمزنگاری‌شده (`v1:` + AES-256-GCM) و فقط ۴ رقم آخر به‌صورت متن ساده.
- استفاده مجدد یک کارت توسط چند کاربر → رویداد امنیتی `card_reuse`.

## کنترل صحت

```php
$wallet->reconcile($userId);
// ['ledger' => 50000, 'wallet' => 50000, 'user' => 50000, 'match' => true]

$wallet->rebuildBalance($userId);   // بازسازی از دفتر
```

صفحه جزئیات کاربر در پنل، عدم تطابق را به‌صورت هشدار قرمز نشان می‌دهد.

## آزمون‌های مالی

- `04_withdrawal_test.php` — چرخه کامل برداشت (۴۴ بررسی)
- `03_multilevel_test.php` — پورسانت چندلایه و نسخه‌بندی (۲۷ بررسی)
- `10_e2e_integrity_test.php` — سناریوی کامل + `SUM(credit) − SUM(debit) = SUM(balance)` (۳۷ بررسی)

</div>
