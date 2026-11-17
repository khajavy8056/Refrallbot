<div dir="rtl">

# معماری و طراحی دیتابیس

## نمای کلی

```
مرورگر/تلگرام
      │
      ▼
public/index.php  ──► bootstrap (env، خطاها، اتصال DB)
      │
      ▼
Router (src/routes.php)  ──► Middleware (admin / csrf / installed)
      │
      ├──► Controllers/Admin/*      → views/admin/*      (پنل مدیریت، HTML)
      ├──► Controllers/MiniApp/*    → views/miniapp/*    (پنل کاربری، HTML+JSON)
      ├──► Controllers/Webhook/*    → Bot/BotHandler     (آپدیت تلگرام)
      └──► Controllers/InstallerController               (ویزارد نصب)
      │
      ▼
Services/* (منطق دامنه، همه مالیات از اینجا می‌گذرد)
      │
      ▼
Support/Database (PDO)  ──► MySQL / MariaDB   (SQLite در تست)
```

قاعده‌ی اصلی: **هیچ کنترلری مستقیماً پول جابه‌جا نمی‌کند.** همه تغییرات مالی از `WalletService` می‌گذرد که تراکنش‌ها را با کلید یکتاسازی ثبت می‌کند.

## لایه‌ها

| لایه | مسئولیت | مثال |
|---|---|---|
| `Support` | زیرساخت بدون دانش دامنه | `Database`, `Money`, `Crypto`, `Jalali`, `Csrf` |
| `Services` | قواعد کسب‌وکار + تراکنش دیتابیس | `CommissionService`, `WalletService`, `WithdrawalService` |
| `Bot` | ترجمه آپدیت تلگرام به عملیات | `BotHandler`, `BotSession` |
| `Controllers` | اعتبارسنجی ورودی + پاسخ | `Admin\UserController`, `MiniApp\DashboardApiController` |
| `views` | نمایش، فقط escape و چیدمان | — |

## جدول‌های اصلی

### کاربران و شبکه

| جدول | نقش |
|---|---|
| `users` | کاربر تلگرام؛ `telegram_id` یکتا، `referral_code` یکتا، `referred_by` (معرف مستقیم) |
| `referral_relations` | **جدول بستار**: `(ancestor_id, descendant_id, depth)` — جستجوی شبکه بدون بازگشت مکرر |
| `referral_events` | هر رویداد واجد شرایط (یکتا) — پایه پورسانت |
| `referral_commissions` | پورسانت هر ذی‌نفع در هر سطح، با snapshot تنظیمات |

`referral_relations` از `users.referred_by` بازسازی می‌شود (`rebuildRelations()`), پس همیشه قابل ترمیم است و منبع حقیقت نیست.

### پول

| جدول | نقش |
|---|---|
| `wallet_transactions` | **دفتر فقط-افزودنی**. `amount` همیشه مثبت + `direction` (credit/debit)، `balance_after`، `idempotency_key` یکتا |
| `wallets` | مانده محاسبه‌شده برای خواندن سریع (کش همگام) |
| `users.wallet_balance` | کش دوم برای مرتب‌سازی/فیلتر — با دفتر مقایسه می‌شود (`reconcile`) |
| `withdrawal_requests` | درخواست برداشت با چرخه وضعیت و `reserve_key` |
| `withdrawal_payments` | رکورد پرداخت دستی مدیر |

> موجودی از سه جا خوانده می‌شود اما **همیشه با دفتر مقایسه می‌شود**. `WalletService::reconcile()` اختلاف را نشان می‌دهد و `rebuildBalance()` از دفتر بازسازی می‌کند.

### تنظیمات

| جدول | نقش |
|---|---|
| `system_settings` | کلید/مقدار (نام سامانه، حداقل برداشت، متن‌های ربات…) |
| `commission_setting_versions` | **نسخه‌بندی**: هر تغییر یک نسخه جدید؛ تراکنش قدیمی نسخه زمان خود را نگه می‌دارد |
| `telegram_settings` | توکن رمزنگاری‌شده، راز Webhook، وضعیت اتصال |

### ارتباطات و امنیت

`telegram_channels` (کانال اجباری)، `telegram_messages` (لاگ پیام)، `bot_menus`، `broadcast_campaigns` + `broadcast_targets`، `campaigns` + `campaign_participants` + `campaign_rewards`، `notifications`، `support_messages`، `security_events`، `admin_activity_logs`، `login_attempts`.

## چرخه پورسانت

```
کاربر جدید /start CODE
   └─► UserService::registerFromTelegram
         ├─ attachReferral  → referred_by + جدول بستار
         └─ maybeQualify    → اگر شرایط برقرار بود: referral_event
               └─► CommissionService::processEvent
                     ├─ پاداش مستقیم (اگر فعال)
                     └─ برای هر لایه فعال: percent × مبنا → referral_commissions
                           └─► WalletService::credit(type=commission_level,
                                                     idempotency_key=event:user:level)
```

هر ردیف پورسانت **idempotency key** یکتا دارد؛ اجرای دوباره همان رویداد پول جدید نمی‌سازد.

## چرخه برداشت

```
create()              →  رزرو: wallet_transactions(type=withdrawal_reserve, completed, debit)
                          wallets.pending += amount
approve()             →  status = approved   (بدون حرکت پول)
markPaid()            →  settle: type=withdrawal_paid, info (بدون تغییر موجودی)
                          pending -= amount، total_withdrawn += amount
reject()/cancelByUser →  release: type=withdrawal_release, completed, credit
                          مبلغ دقیقاً به موجودی بازمی‌گردد
```

## پاسخ‌گویی و کارایی

- تمام لیست‌ها صفحه‌بندی دارند و روی ستون‌های پرکاربرد ایندکس گذاشته شده (`user_id`, `status`, `created_at`, `telegram_id`).
- شمارش‌ها با `COUNT`/`SUM` در SQL انجام می‌شود، نه در PHP.
- درخت شبکه با `LIMIT 3000` محافظت می‌شود.
- ارسال همگانی snapshot مخاطبان را در `broadcast_targets` نگه می‌دارد تا تغییر داده‌ها در میانه ارسال، لیست را عوض نکند.

</div>
