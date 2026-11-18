<?php $botUsername = $botUsername ?? ''; ?>
<div class="dash" style="max-width:520px;margin:0 auto;padding:40px 18px;text-align:center">
    <div style="width:72px;height:72px;border-radius:22px;background:linear-gradient(135deg,#4f46e5,#06b6d4);display:flex;align-items:center;justify-content:center;font-size:2rem;color:#fff;margin:0 auto 18px">R</div>
    <h1 style="margin-bottom:6px"><?= e($appName) ?></h1>
    <p class="text-muted text-sm">پنل کاربری این سامانه فقط از طریق ربات تلگرام قابل استفاده است.</p>

    <?php if ($botUsername !== ''): ?>
        <a href="https://t.me/<?= e($botUsername) ?>" class="btn btn-lg btn-block mb-2" rel="noopener">🤖 ورود از طریق ربات @<?= e($botUsername) ?></a>
    <?php else: ?>
        <div class="alert alert-warning" style="text-align:start">
            <span class="alert-icon">⚠️</span>
            <div>ربات تلگرام هنوز متصل نشده است. مدیر سامانه باید از پنل مدیریت، توکن ربات را ثبت کند.</div>
        </div>
    <?php endif; ?>

    <div class="card" style="text-align:start;margin-top:18px">
        <div class="card-header"><h2 class="card-title">راهنمای استفاده</h2></div>
        <div class="card-body text-sm">
            <ol style="line-height:2;padding-inline-start:18px">
                <li>در ربات روی «شروع» بزنید.</li>
                <li>شماره تماس خود را با دکمه «ارسال شماره» ثبت کنید.</li>
                <li>لینک دعوت اختصاصی خود را برای دوستان بفرستید.</li>
                <li>از منوی «پنل کاربری» وارد داشبورد شوید.</li>
            </ol>
            <?php if (!empty($miniappUrl)): ?>
                <div class="form-hint">آدرس پنل: <span class="ltr mono"><?= e($miniappUrl) ?></span></div>
            <?php endif; ?>
        </div>
    </div>
</div>
