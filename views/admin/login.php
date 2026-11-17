<div class="mb-4" style="text-align:center">
    <div style="width:58px;height:58px;border-radius:18px;background:linear-gradient(135deg,#4f46e5,#06b6d4);display:grid;place-items:center;margin:0 auto 14px;color:#fff;font-weight:800;font-size:1.4rem;box-shadow:0 8px 24px rgba(79,70,229,.35)">R</div>
    <h1 style="font-size:1.3rem;margin-bottom:4px">ورود به پنل مدیریت</h1>
    <p class="text-muted text-sm mb-0">برای ادامه، اطلاعات حساب مدیریتی خود را وارد کنید</p>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><span class="alert-icon">⚠️</span><div><?= e($error) ?></div></div>
<?php endif; ?>

<form method="post" action="<?= url('admin/login') ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-group">
        <label class="form-label" for="username">نام کاربری</label>
        <input type="text" class="form-control" id="username" name="username" value="<?= e($username ?? '') ?>"
               required autofocus autocomplete="username" placeholder="نام کاربری مدیر">
    </div>
    <div class="form-group">
        <label class="form-label" for="password">رمز عبور</label>
        <input type="password" class="form-control" id="password" name="password"
               required autocomplete="current-password" placeholder="••••••••">
    </div>
    <button type="submit" class="btn btn-lg btn-block mt-2">ورود به پنل</button>
</form>

<div class="alert alert-info mt-4" style="font-size:.78rem">
    <span class="alert-icon">🔐</span>
    <div>پس از ۵ تلاش ناموفق، ورود از این دستگاه به مدت ۱۵ دقیقه قفل می‌شود.</div>
</div>

<p class="text-center text-xs text-muted mt-3">
    اگر مدیر ربات هستید، می‌توانید از داخل ربات با دکمهٔ «پنل مدیریت» هم وارد شوید.
</p>
