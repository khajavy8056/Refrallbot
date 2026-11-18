<?php $botUsername = $botUsername ?? ''; ?>
<div class="miniapp-disabled" style="max-width:520px;margin:0 auto;padding:48px 20px;text-align:center">
    <div style="width:76px;height:76px;border-radius:24px;background:linear-gradient(135deg,#6366f1,#06b6d4);display:flex;align-items:center;justify-content:center;font-size:2rem;color:#fff;margin:0 auto 20px">🤖</div>
    <h1 style="font-size:1.25rem;margin-bottom:10px">پنل وب غیرفعال است</h1>
    <p class="text-muted text-sm" style="line-height:2;margin-bottom:20px">
        این سامانه فعلاً از پنل وب استفاده نمی‌کند. تمام کارها — مشاهده موجودی،
        لینک دعوت، درآمد، برداشت و تاریخچه — از طریق <b>دکمه‌های خود ربات تلگرام</b> انجام می‌شود.
    </p>

    <?php if ($botUsername !== ''): ?>
        <a href="https://t.me/<?= e($botUsername) ?>" class="btn btn-lg btn-block mb-2" rel="noopener">🤖 رفتن به ربات @<?= e($botUsername) ?></a>
    <?php else: ?>
        <div class="alert alert-warning" style="text-align:start">
            <span class="alert-icon">⚠️</span>
            <div>ربات هنوز به سامانه متصل نشده است. با مدیر سامانه تماس بگیرید.</div>
        </div>
    <?php endif; ?>

    <div class="card" style="text-align:start;margin-top:20px">
        <div class="card-header"><h2 class="card-title">در ربات چه کاری می‌توانم انجام دهم؟</h2></div>
        <div class="card-body text-sm">
            <ul style="line-height:2.1;padding-inline-start:18px;margin:0">
                <li>🔗 گرفتن لینک دعوت اختصاصی و ارسال برای دوستان</li>
                <li>👥 دیدن زیرمجموعه‌های مستقیم و کل شبکه</li>
                <li>💰 مشاهده درآمد، کیف پول و تاریخچه تراکنش‌ها</li>
                <li>🏦 ثبت درخواست برداشت وجه</li>
                <li>🎁 شرکت در جشنواره‌ها و مشاهده رتبه‌بندی</li>
                <li>☎️ ارتباط با پشتیبانی</li>
            </ul>
        </div>
    </div>

    <p class="text-muted" style="font-size:.75rem;margin-top:18px">
        مدیر سامانه می‌تواند این صفحه را از مسیر
        <span class="ltr mono">پنل مدیریت → تنظیمات → شرایط ورود</span>
        فعال کند (نیازمند HTTPS معتبر).
    </p>
</div>
