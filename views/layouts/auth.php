<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'ورود') ?> — <?= e(setting('app_name', 'رفرال‌بات')) ?></title>
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-visual">
        <h1>سامانه مدیریت رفرال و پورسانت</h1>
        <p>پلتفرم حرفه‌ای زیرمجموعه‌گیری چندلایه، کیف پول و مدیریت درآمد — طراحی‌شده برای اجرا روی هاست اشتراکی.</p>
        <div class="auth-features">
            <div class="auth-feature"><div class="auth-feature-icon">🕸</div><div>سیستم پورسانت چندلایه تا ۸ سطح با محاسبه دقیق</div></div>
            <div class="auth-feature"><div class="auth-feature-icon">👛</div><div>دفتر ثبت مالی (Ledger) با تطبیق‌پذیری کامل</div></div>
            <div class="auth-feature"><div class="auth-feature-icon">🤖</div><div>اتصال کامل به ربات تلگرام و Mini App</div></div>
            <div class="auth-feature"><div class="auth-feature-icon">🛡</div><div>امنیت چندلایه و گزارش کامل فعالیت مدیران</div></div>
        </div>
    </div>
    <div class="auth-panel">
        <div class="auth-card">
            <?= $content ?>
        </div>
    </div>
</div>
<div class="toasts"></div>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
