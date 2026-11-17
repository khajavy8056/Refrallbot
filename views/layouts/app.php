<?php
/** @var string $content */
$admin = $currentAdmin ?? null;
$path = $currentPath ?? '/';
$stats = $navStats ?? [];
$navBadges = $navBadges ?? [];
$title = $title ?? 'داشبورد';
$navItems = [
    ['group' => 'مدیریت'],
    ['path' => '/admin', 'label' => 'داشبورد', 'icon' => '📊', 'exact' => true],
    ['path' => '/admin/users', 'label' => 'کاربران', 'icon' => '👥'],
    ['path' => '/admin/network', 'label' => 'شبکه زیرمجموعه', 'icon' => '🕸'],
    ['path' => '/admin/withdrawals', 'label' => 'درخواست برداشت', 'icon' => '🏦', 'badge' => $navBadges['withdrawals'] ?? 0],
    ['group' => 'درآمد و پورسانت'],
    ['path' => '/admin/commissions', 'label' => 'تنظیمات پورسانت', 'icon' => '💰'],
    ['path' => '/admin/campaigns', 'label' => 'جشنواره‌ها', 'icon' => '🎁'],
    ['path' => '/admin/reports/transactions', 'label' => 'گزارش‌ها', 'icon' => '📈'],
    ['group' => 'ربات و ارتباطات'],
    ['path' => '/admin/telegram', 'label' => 'اتصال به ربات', 'icon' => '🤖'],
    ['path' => '/admin/channels', 'label' => 'کانال‌های اجباری', 'icon' => '📢'],
    ['path' => '/admin/broadcast', 'label' => 'پیام همگانی', 'icon' => '✉️'],
    ['path' => '/admin/support', 'label' => 'پشتیبانی', 'icon' => '☎️', 'badge' => $navBadges['support'] ?? 0],
    ['group' => 'سیستم'],
    ['path' => '/admin/settings', 'label' => 'تنظیمات', 'icon' => '⚙️'],
    ['path' => '/admin/security', 'label' => 'امنیت', 'icon' => '🛡', 'badge' => $navBadges['security'] ?? 0],
    ['path' => '/admin/admins', 'label' => 'مدیران', 'icon' => '🔑'],
    ['path' => '/admin/logs', 'label' => 'گزارش فعالیت', 'icon' => '📋'],
    ['path' => '/admin/backup', 'label' => 'پشتیبان‌گیری', 'icon' => '💾'],
];

$isActive = static function (array $item) use ($path): bool {
    if (!empty($item['exact'])) {
        return $path === $item['path'];
    }
    return $path === $item['path'] || str_starts_with($path, $item['path'] . '/');
};
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> — پنل مدیریت <?= e(setting('app_name', 'رفرال‌بات')) ?></title>
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" rx="24" fill="#4f46e5"/><text x="50" y="68" font-size="52" text-anchor="middle" fill="white" font-family="sans-serif">R</text></svg>') ?>">
</head>
<body>
<div class="layout">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="brand-logo">R</div>
            <div class="brand-text">
                <span class="brand-title"><?= e(setting('app_name', 'رفرال‌بات')) ?></span>
                <span class="brand-sub">پنل مدیریت نسخه <?= e(RB_VERSION) ?></span>
            </div>
        </div>
        <nav class="nav">
            <?php foreach ($navItems as $item): ?>
                <?php if (isset($item['group'])): ?>
                    <div class="nav-group-label"><?= e($item['group']) ?></div>
                    <?php continue; ?>
                <?php endif; ?>
                <a href="<?= url(ltrim($item['path'], '/')) ?>" class="nav-item <?= $isActive($item) ? 'active' : '' ?>">
                    <span class="nav-icon"><?= $item['icon'] ?></span>
                    <span><?= e($item['label']) ?></span>
                    <?php if (!empty($item['badge'])): ?>
                        <span class="nav-badge"><?= fa_digits((int) $item['badge']) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <div><?= e(setting('app_name', 'رفرال‌بات')) ?> &copy; <?= fa_digits(date('Y')) ?></div>
        </div>
    </aside>
    <div class="sidebar-overlay"></div>

    <div class="main">
        <header class="topbar">
            <button class="btn btn-ghost btn-icon sidebar-toggle" data-sidebar-toggle aria-label="منو">☰</button>
            <div class="topbar-title">
                <?= e($title) ?>
                <small><?= fa_date(date('Y-m-d H:i:s')) ?></small>
            </div>
            <div class="topbar-spacer"></div>

            <?php if (!empty($navBadges['withdrawals'])): ?>
                <a href="<?= url('admin/withdrawals?status=open') ?>" class="btn btn-secondary btn-sm">
                    🏦 <?= fa_digits((int) $navBadges['withdrawals']) ?> درخواست در انتظار
                </a>
            <?php endif; ?>

            <div style="display:flex;align-items:center;gap:10px;padding-inline-start:12px;border-inline-start:1px solid var(--border)">
                <div class="avatar"><?= e(mb_substr($admin['name'] ?? 'م', 0, 1)) ?></div>
                <div style="line-height:1.35">
                    <div style="font-weight:600;font-size:.84rem"><?= e($admin['name'] ?? 'مدیر') ?></div>
                    <div class="text-xs text-muted"><?= e($admin['role_name'] ?? 'مدیر') ?></div>
                </div>
                <a href="<?= url('admin/logout') ?>" class="btn btn-ghost btn-sm" title="خروج"
                   onclick="return confirm('از حساب کاربری خارج می‌شوید؟')">خروج</a>
            </div>
        </header>

        <main class="content">
            <?php foreach (($flashMessages ?? []) as $flash): ?>
                <div class="alert alert-<?= e($flash['type'] === 'error' ? 'error' : ($flash['type'] === 'success' ? 'success' : 'info')) ?>">
                    <span class="alert-icon"><?= $flash['type'] === 'error' ? '⚠️' : '✓' ?></span>
                    <div><?= e($flash['message']) ?></div>
                </div>
            <?php endforeach; ?>
            <?= $content ?>
        </main>
    </div>
</div>

<div class="toasts"></div>
<script src="<?= asset('js/app.js') ?>"></script>
<?= $scripts ?? '' ?>
</body>
</html>
