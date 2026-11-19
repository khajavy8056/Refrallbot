<?php
$__rbPlat = strtolower(trim((string) ($platformHint ?? ($_GET['platform'] ?? ''))));
if (!in_array($__rbPlat, ['telegram', 'bale', 'eitaa'], true)) {
    $__rbUa = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (str_contains($__rbUa, 'eitaa')) {
        $__rbPlat = 'eitaa';
    } elseif (str_contains($__rbUa, 'bale')) {
        $__rbPlat = 'bale';
    } else {
        $__rbPlat = '';
    }
}
$__rbLoadSdk = !isset($skipWebAppSdk) || !$skipWebAppSdk;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="dark" data-theme-scope="miniapp" data-base="<?= e(rtrim(url(''), '/')) ?>" data-platform-hint="<?= e($__rbPlat) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="#0b1120" id="meta-theme-color">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? (setting('app_name', 'رفرال‌بات') . ' — پنل کاربری')) ?></title>
    <script>
    (function(){try{var t=localStorage.getItem('rb_mini_theme');if(t!=='light'&&t!=='dark'){t='dark';}document.documentElement.setAttribute('data-theme',t);var m=document.getElementById('meta-theme-color');if(m){m.content=t==='dark'?'#0b1120':'#f4f6fb';}}catch(e){document.documentElement.setAttribute('data-theme','dark');}})();
    </script>
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>?v=<?= e(RB_VERSION) ?>">
    <link rel="stylesheet" href="<?= asset('css/miniapp.css') ?>?v=<?= e(RB_VERSION) ?>">
    <?php if ($__rbLoadSdk): ?>
        <?php if ($__rbPlat === 'bale'): ?>
            <script src="https://tapi.bale.ai/miniapp.js?3"></script>
        <?php elseif ($__rbPlat === 'eitaa'): ?>
            <script src="https://developer.eitaa.com/eitaa-web-app.js"></script>
        <?php else: ?>
            <script src="https://telegram.org/js/telegram-web-app.js?59"></script>
        <?php endif; ?>
    <?php endif; ?>
</head>
<body class="miniapp">
<?= $content ?>
<div class="toasts"></div>
<script src="<?= asset('js/app.js') ?>?v=<?= e(RB_VERSION) ?>"></script>
<?= $scripts ?? '' ?>
</body>
</html>
