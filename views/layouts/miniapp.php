<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="theme-color" content="#4f46e5">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(setting('app_name', 'رفرال‌بات')) ?> — پنل کاربری</title>
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/miniapp.css') ?>">
    <script src="https://telegram.org/js/telegram-web-app.js?59"></script>
</head>
<body class="miniapp">
<?= $content ?>
<div class="toasts"></div>
<script src="<?= asset('js/app.js') ?>"></script>
<?= $scripts ?? '' ?>
</body>
</html>
