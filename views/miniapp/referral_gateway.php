<?php
/** @var string $appName */
/** @var string $refName */
/** @var string $code */
/** @var array<string,array> $links */
/** @var int $welcomeReward */
/** @var int $inviterReward */
/** @var ?string $bannerUrl */
$skipWebAppSdk = true;
$icons = [
    'telegram' => '✈️',
    'bale'     => '💬',
    'rubika'   => '🟣',
    'eitaa'    => '🟠',
];
?>
<div class="dash" style="max-width:540px;margin:0 auto;padding:28px 16px 44px;text-align:center">
    <?php if (!empty($bannerUrl)): ?>
        <div style="border-radius:18px;overflow:hidden;margin-bottom:18px;box-shadow:0 14px 36px rgba(0,0,0,.35);border:1px solid var(--border)">
            <img src="<?= e($bannerUrl) ?>" alt="<?= e($appName) ?>" style="width:100%;display:block;aspect-ratio:16/9;object-fit:cover">
        </div>
    <?php else: ?>
        <div style="width:74px;height:74px;border-radius:22px;background:linear-gradient(135deg,#6366f1,#06b6d4);display:flex;align-items:center;justify-content:center;font-size:2.1rem;color:#fff;margin:0 auto 16px;box-shadow:0 12px 30px rgba(99,102,241,.35)">🎁</div>
    <?php endif; ?>

    <div class="badge badge-brand no-dot" style="margin-bottom:10px;padding:6px 14px;font-size:.82rem">
        دعوت اختصاصی از طرف «<?= e($refName) ?>»
    </div>

    <h1 style="font-size:1.35rem;margin:0 0 8px"><?= e($appName) ?></h1>

    <?php if ($welcomeReward > 0): ?>
        <p class="text-sm" style="line-height:1.9;margin:0 0 18px;color:var(--text)">
            با عضویت از طریق این دعوت، <strong style="color:#10b981"><?= e(wtoman($welcomeReward)) ?></strong> هدیه خوش‌آمدگویی دریافت می‌کنید
            و با دعوت دوستان خود نیز درآمد نقدی کسب خواهید کرد!
        </p>
    <?php else: ?>
        <p class="text-muted text-sm" style="line-height:1.9;margin:0 0 18px">
            پیام‌رسان دلخواه خود را انتخاب کنید تا با ثبت خودکار کد دعوت وارد سامانه شوید و کسب درآمد را آغاز کنید:
        </p>
    <?php endif; ?>

    <div class="card" style="text-align:start;margin-bottom:14px">
        <div class="card-header"><h2 class="card-title">📲 انتخاب پیام‌رسان برای ورود فوری</h2></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
            <?php if (!empty($links)): ?>
                <?php foreach ($links as $plat => $item): ?>
                    <?php
                    $targetUrl = (string) ($item['link'] ?? ($item['url'] ?? ($item['miniapp_link'] ?? '')));
                    if ($targetUrl === '') {
                        continue;
                    }
                    $ic = (string) ($item['icon'] ?? ($icons[$plat] ?? '🤖'));
                    $lbl = (string) ($item['name'] ?? ($item['label'] ?? $plat));
                    ?>
                    <a href="<?= e($targetUrl) ?>" class="btn btn-lg btn-block" rel="noopener" style="justify-content:space-between">
                        <span><?= e($ic) ?> ورود در <?= e($lbl) ?></span>
                        <span style="font-size:.82rem;opacity:.88">شروع ←</span>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-warning">
                    <span class="alert-icon">⚠️</span>
                    <div class="text-sm">در حال حاضر هیچ پیام‌رسان فعالی برای ورود خودکار تنظیم نشده است. کد دعوت زیر را ذخیره کنید.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" style="text-align:start">
        <div class="card-body flex justify-between items-center" style="gap:10px;flex-wrap:wrap">
            <div>
                <div class="text-xs text-muted">کد دعوت اختصاصی</div>
                <strong class="ltr mono" style="font-size:1.05rem" id="gw-ref-code"><?= e($code) ?></strong>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="if(window.RB&&RB.copy){RB.copy('<?= e($code) ?>');}else if(navigator.clipboard){navigator.clipboard.writeText('<?= e($code) ?>');}">
                📋 کپی کد دعوت
            </button>
        </div>
    </div>
</div>
