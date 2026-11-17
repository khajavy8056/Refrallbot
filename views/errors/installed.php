<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>سامانه نصب شده است</title>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>"></head>
<body><div style="max-width:520px;margin:80px auto;padding:0 20px;text-align:center">
<div class="card"><div class="card-body" style="padding:40px 24px">
<div style="font-size:2.6rem;margin-bottom:12px">🔒</div>
<h1 style="font-size:1.3rem">سامانه قبلاً نصب شده است</h1>
<p class="text-muted text-sm">صفحه نصب برای جلوگیری از دسترسی غیرمجاز غیرفعال شده است.<br>
برای نصب مجدد، فایل <code>storage/installed.lock</code> را حذف کنید (فقط در صورت نیاز واقعی).</p>
<a href="<?= url('admin/login') ?>" class="btn mt-2">ورود به پنل مدیریت</a>
</div></div></div></body></html>
