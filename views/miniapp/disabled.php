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
<div class="miniapp-disabled" style="max-width:540px;margin:0 auto;padding:48px 20px;text-align:center">
    <div style="width:76px;height:76px;border-radius:24px;background:linear-gradient(135deg,#6366f1,#06b6d4);display:flex;align-items:center;justify-content:center;font-size:2rem;color:#fff;margin:0 auto 20px">🤖</div>
    <h1 style="font-size:1.25rem;margin-bottom:10px">پنل وب غیرفعال است</h1>
    <p class="text-muted text-sm" style="line-height:2;margin-bottom:20px">
        این سامانه در حال حاضر روی حالت <b>دکمه‌ای داخل ربات</b> تنظیم شده است. تمام امکانات — استودیوی دعوت و بنر،
        مشاهده زیرمجموعه‌ها در لایه‌ها، کیف پول، برداشت وجه و اتصال پیام‌رسان‌ها — مستقیماً از طریق دکمه‌های خود ربات تلگرام و سایر پیام‌رسان‌های متصل در دسترس است.
    </p>

    <?php if ($messengerLinks !== []): ?>
        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:14px">
            <?php foreach ($messengerLinks as $ml): ?>
                <a href="<?= e($ml['url']) ?>" class="btn btn-lg btn-block" rel="noopener">
                    <?= e($ml['icon'] ?? '🤖') ?> باز کردن ربات در <?= e($ml['label']) ?>
                    <?php if (!empty($ml['username'])): ?>
                        <span class="ltr text-xs" style="opacity:.85;margin-inline-start:6px">@<?= e($ml['username']) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-warning" style="text-align:start">
            <span class="alert-icon">⚠️</span>
            <div>ربات هنوز به سامانه متصل نشده است. با مدیر سامانه تماس بگیرید.</div>
        </div>
    <?php endif; ?>

    <div class="card" style="text-align:start;margin-top:20px">
        <div class="card-header"><h2 class="card-title">امکانات در دسترس داخل ربات</h2></div>
        <div class="card-body text-sm">
            <ul style="line-height:2.1;padding-inline-start:18px;margin:0">
                <li>🔗 دریافت لینک دعوت، پیام آماده و بنر تبلیغاتی اختصاصی</li>
                <li>👥 مشاهده زیرمجموعه‌های مستقیم (لایه ۰) و غیرمستقیم (لایه‌های ۱+)</li>
                <li>💰 مشاهده موجودی کیف پول، تفکیک پورسانت و تاریخچه تراکنش‌ها</li>
                <li>🏦 ثبت و پیگیری درخواست برداشت وجه</li>
                <li>🔄 اتصال حساب بین تلگرام، بله، روبیکا و ایتا با کیف پول واحد</li>
                <li>🎁 شرکت در جشنواره‌ها، مشاهده رتبه‌بندی و پشتیبانی</li>
            </ul>
        </div>
    </div>

    <p class="text-muted" style="font-size:.75rem;margin-top:18px">
        مدیر سامانه می‌تواند هر زمان از مسیر
        <span class="ltr mono">پنل مدیریت → تنظیمات → حالت نمایش رابط کاربری</span>
        حالت مینی‌اپ یا ترکیبی را فعال کند (نیازمند HTTPS معتبر).
    </p>
</div>
