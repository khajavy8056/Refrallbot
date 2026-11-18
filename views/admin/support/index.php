<?php
$statusLabels = ['open' => 'باز', 'answered' => 'پاسخ داده‌شده', 'closed' => 'بسته'];
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">پشتیبانی کاربران</h1>
        <p class="text-muted text-sm mb-0">پیام‌های ارسالی از ربات و پنل کاربری — پاسخ از طریق ربات تلگرام به کاربر ارسال می‌شود</p>
    </div>
    <div class="badge badge-<?= $openCount > 0 ? 'warning' : 'success' ?>"><?= fa_digits($openCount) ?> پیام در انتظار</div>
</div>

<div class="flex gap-1 wrap mb-3">
    <?php foreach (['open' => 'باز', 'answered' => 'پاسخ داده‌شده', 'closed' => 'بسته', 'all' => 'همه'] as $key => $label): ?>
        <a href="<?= url('admin/support?status=' . $key) ?>" class="btn btn-sm <?= $statusFilter === $key ? '' : 'btn-secondary' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if (empty($messages)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-icon">☎️</div>
            <div class="empty-title">پیامی در این وضعیت وجود ندارد</div>
            <div class="empty-text">کاربران می‌توانند از منوی «پشتیبانی» در ربات یا پنل کاربری پیام ارسال کنند.</div>
        </div>
    </div>
<?php else: ?>
    <div class="flex flex-col gap-3">
        <?php foreach ($messages as $m): ?>
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2 class="card-title">
                            <?= e(trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''))) ?>
                        </h2>
                        <span class="card-subtitle">
                            شناسه تلگرام: <span class="ltr"><?= e((string) $m['telegram_id']) ?></span>
                            · <?= fa_date($m['created_at']) ?>
                        </span>
                    </div>
                    <div class="flex gap-1 items-center">
                        <span class="badge badge-<?= $m['status'] === 'open' ? 'warning' : 'success' ?>"><?= e($statusLabels[$m['status']] ?? (string) $m['status']) ?></span>
                        <span class="badge badge-<?= $m['direction'] === 'in' ? 'info' : 'brand' ?>"><?= $m['direction'] === 'in' ? 'از کاربر' : 'پاسخ مدیر' ?></span>
                        <a href="<?= url('admin/users/' . $m['user_id']) ?>" class="btn btn-sm btn-secondary">پروفایل</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($m['subject'])): ?>
                        <div class="font-bold mb-1"><?= e((string) $m['subject']) ?></div>
                    <?php endif; ?>
                    <div style="white-space:pre-wrap;line-height:1.9;font-size:.87rem;background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:12px"><?= e((string) $m['message']) ?></div>

                    <?php if ($m['direction'] === 'in' && $m['status'] !== 'closed'): ?>
                        <form method="post" action="<?= url('admin/support/' . $m['id'] . '/reply') ?>" data-ajax class="mt-2">
                            <?= csrf_field() ?>
                            <div class="form-group">
                                <label class="form-label">پاسخ (از طریق ربات به کاربر ارسال می‌شود)</label>
                                <textarea name="message" class="form-control" rows="3" required maxlength="3000" placeholder="متن پاسخ…"></textarea>
                            </div>
                            <button class="btn" type="submit">📤 ارسال پاسخ</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
