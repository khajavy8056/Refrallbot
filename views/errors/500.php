<div style="min-height:60vh;display:grid;place-items:center;padding:40px 20px">
  <div style="text-align:center;max-width:520px">
    <div style="font-size:3.4rem;margin-bottom:8px">⚠️</div>
    <h1 style="font-size:1.3rem">خطای داخلی سرور</h1>
    <p class="text-muted text-sm">مشکلی در پردازش درخواست رخ داد. لطفاً دوباره تلاش کنید.</p>
    <?php if (!empty($message) && \RB\Support\Env::bool('APP_DEBUG', false)): ?>
      <pre style="text-align:left;direction:ltr;background:#0f172a;color:#e2e8f0;padding:14px;border-radius:10px;overflow:auto;font-size:.75rem"><?= e($message) ?><?= !empty($trace) ? "\n\n" . e($trace) : '' ?></pre>
    <?php endif; ?>
    <a href="<?= url('/') ?>" class="btn btn-sm mt-2">بازگشت</a>
  </div>
</div>
