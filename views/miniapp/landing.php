<?php
$botUsername = $botUsername ?? '';
$messengerLinks = $messengerLinks ?? [];
if ($messengerLinks === [] && $botUsername !== '') {
    $messengerLinks = [[
        'platform' => 'telegram',
        'label' => 'تلگرام',
        'icon' => '✈️',
        'username' => $botUsername,
        'url' => 'https://t.me/' . $botUsername,
    ]];
}
?>
<div class="dash" style="max-width:540px;margin:0 auto;padding:40px 18px;text-align:center">
    <div style="width:74px;height:74px;border-radius:22px;background:linear-gradient(135deg,#4f46e5,#06b6d4);display:flex;align-items:center;justify-content:center;font-size:2rem;color:#fff;margin:0 auto 18px;box-shadow:0 12px 30px rgba(79,70,229,.35)">🚀</div>
    <h1 style="margin-bottom:6px"><?= e($appName) ?></h1>
    <p class="text-muted text-sm" style="line-height:1.9;margin-bottom:18px">
        سامانه هوشمند دعوت دوستان و کسب درآمد چندپیام‌رسانی — پیام‌رسان دلخواه خود را برای ورود انتخاب کنید:
    </p>

    <?php if ($messengerLinks !== []): ?>
        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px">
            <?php foreach ($messengerLinks as $ml): ?>
                <a href="<?= e($ml['url']) ?>" class="btn btn-lg btn-block" rel="noopener">
                    <?= e($ml['icon'] ?? '🤖') ?> ورود از طریق <?= e($ml['label']) ?>
                    <?php if (!empty($ml['username'])): ?>
                        <span class="ltr text-xs" style="opacity:.85;margin-inline-start:6px">@<?= e($ml['username']) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-warning" style="text-align:start">
            <span class="alert-icon">⚠️</span>
            <div>هیچ پیام‌رسانی هنوز فعال نشده است. مدیر سامانه باید از پنل مدیریت، ربات را در بخش «پیام‌رسان‌ها» متصل کند.</div>
        </div>
    <?php endif; ?>

    <div class="card" style="text-align:start;margin-top:18px">
        <div class="card-header"><h2 class="card-title">راهنمای شروع سریع</h2></div>
        <div class="card-body text-sm">
            <ol style="line-height:2;padding-inline-start:18px;margin:0">
                <li>وارد ربات یا برنامک در پیام‌رسان مورد علاقه خود (تلگرام، بله، روبیکا یا ایتا) شوید.</li>
                <li>در صورت نیاز شماره تماس یا عضویت در کانال معرفی‌شده را تکمیل کنید.</li>
                <li>از «استودیوی دعوت» بنر یا پیام اختصاصی خود را همراه لینک دعوت برای دوستان بفرستید.</li>
                <li>پاداش معرفی مستقیم و پورسانت لایه‌ای شبکه خود را آنی در کیف پول دریافت و تسویه کنید.</li>
            </ol>
            <?php if (!empty($miniappUrl)): ?>
                <div class="form-hint mt-2">آدرس مینی‌اپ: <span class="ltr mono"><?= e($miniappUrl) ?></span></div>
            <?php endif; ?>
        </div>
    </div>
</div>
