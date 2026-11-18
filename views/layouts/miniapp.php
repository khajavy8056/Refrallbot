<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="dark" data-theme-scope="miniapp">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="#0b1120" id="meta-theme-color">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(setting('app_name', 'رفرال‌بات')) ?> — پنل کاربری</title>
    <script>
    (function(){try{var t=localStorage.getItem('rb_mini_theme');if(t!=='light'&&t!=='dark'){t='dark';}document.documentElement.setAttribute('data-theme',t);var m=document.getElementById('meta-theme-color');if(m){m.content=t==='dark'?'#0b1120':'#f4f6fb';}}catch(e){document.documentElement.setAttribute('data-theme','dark');}})();
    </script>
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>?v=<?= e(RB_VERSION) ?>">
    <link rel="stylesheet" href="<?= asset('css/miniapp.css') ?>?v=<?= e(RB_VERSION) ?>">
    <script src="https://telegram.org/js/telegram-web-app.js?59"></script>
</head>
<body class="miniapp">
<?= $content ?>
<div class="toasts"></div>
<script src="<?= asset('js/app.js') ?>?v=<?= e(RB_VERSION) ?>"></script>
<?= $scripts ?? '' ?>
</body>
</html>
