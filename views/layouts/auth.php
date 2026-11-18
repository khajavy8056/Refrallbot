<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="dark" data-theme-scope="admin">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="#0b1120" id="meta-theme-color">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'ورود') ?> — <?= e(setting('app_name', 'رفرال‌بات')) ?></title>
    <script>
    (function(){try{var t=localStorage.getItem('rb_admin_theme');if(t!=='light'&&t!=='dark'){t='dark';}document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','dark');}})();
    </script>
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>?v=<?= e(RB_VERSION) ?>">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-visual">
        <div class="brand-logo" style="width:52px;height:52px;font-size:1.35rem;margin-bottom:22px;position:relative">R</div>
        <h1>سامانه مدیریت رفرال و پورسانت</h1>
        <p>پلتفرم حرفه‌ای زیرمجموعه‌گیری چندلایه، کیف پول و تحلیل زنده رشد اعضا — یکپارچه با ربات تلگرام و مینی‌اپ اختصاصی.</p>
        <div class="auth-features">
            <div class="auth-feature"><div class="auth-feature-icon">🕸</div><div>سیستم پورسانت چندلایه تا ۸ سطح با محاسبه آنی و دقیق</div></div>
            <div class="auth-feature"><div class="auth-feature-icon">📊</div><div>نمودارهای تحلیلی رشد اعضا، قیف رفرال و دفتر ثبت مالی</div></div>
            <div class="auth-feature"><div class="auth-feature-icon">🤖</div><div>سوییچ آنی بین حالت مینی‌اپ گرافیکی و حالت دکمه‌ای ربات</div></div>
            <div class="auth-feature"><div class="auth-feature-icon">🛡</div><div>امنیت چندلایه، ضدتقلب و به‌روزرسانی مستقیم از گیت‌هاب</div></div>
        </div>
    </div>
    <div class="auth-panel">
        <button type="button" class="theme-toggle auth-theme-btn" data-theme-toggle title="تغییر حالت تاریک / روشن" aria-label="تغییر تم">
            <span class="theme-icon-dark">🌙</span>
            <span class="theme-icon-light">☀️</span>
            <span class="theme-label" data-theme-label>روشن</span>
        </button>
        <div class="auth-card">
            <?= $content ?>
        </div>
    </div>
</div>
<div class="toasts"></div>
<script src="<?= asset('js/app.js') ?>?v=<?= e(RB_VERSION) ?>"></script>
</body>
</html>
