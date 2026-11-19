<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\AdminAuth;
use RB\Services\AuditService;
use RB\Services\UserLevelService;
use RB\Support\Database;
use RB\Support\Money;
use RB\Support\Request;

final class UserLevelController extends AdminController
{
    private function authorized(): bool
    {
        if (AdminAuth::requireSuperAdmin()) return true;
        $this->fail('فقط مدیر کل به تنظیمات سطح‌بندی دسترسی دارد.', 403);
        return false;
    }

    public function index(): void
    {
        if (!$this->authorized()) return;
        $db = Database::instance();
        $svc = new UserLevelService($db);
        $levels = $db->all('SELECT * FROM user_levels WHERE deleted = 0 ORDER BY min_invites, id');
        $filter = max(0, (int) ($_GET['level'] ?? 0));
        $page = max(1, min(1000000, (int) ($_GET['page'] ?? 1)));
        // Bounded report; the rank shown is computed with the same rules as payouts.
        $rows = $db->all('SELECT id, first_name, last_name, username FROM users WHERE status <> \'blocked\' ORDER BY id DESC LIMIT 100 OFFSET ' . (($page - 1) * 100));
        $users = [];
        foreach ($rows as $row) {
            $profile = $svc->profile((int) $row['id']);
            if ($filter && (int) ($profile['current']['id'] ?? 0) !== $filter) continue;
            $row['level_profile'] = $profile;
            $users[] = $row;
        }
        $this->render('admin/user_levels/index', [
            'title' => 'سطح‌بندی کاربران', 'levels' => $levels,
            'config' => $svc->config() ?? ['enabled' => 0, 'budget' => 0, 'spent' => 0],
            'users' => $users, 'page' => $page, 'filter' => $filter,
            'payments' => $db->all('SELECT l.name, p.kind, COUNT(*) AS count, SUM(p.amount) AS total FROM user_level_payments p JOIN user_levels l ON l.id = p.level_id GROUP BY l.id, l.name, p.kind ORDER BY l.id DESC'),
        ]);
    }

    public function config(): void
    {
        if (!$this->authorized()) return;
        $db = Database::instance();
        try {
            $budget = Money::parseToman($_POST['budget'] ?? null);
            if ($budget === null || $budget < 0) throw new \InvalidArgumentException('بودجه معتبر نیست.');
            $enabled = !empty($_POST['enabled']);
            if ($enabled && $budget <= 0) throw new \InvalidArgumentException('برای فعال‌سازی بودجه کل مثبت الزامی است.');
            $db->transaction(function () use ($db, $budget, $enabled) {
                $config = (new UserLevelService($db))->config(true);
                if ($config && $budget < (int) $config['spent']) throw new \InvalidArgumentException('بودجه نمی‌تواند کمتر از مبلغ مصرف‌شده باشد.');
                if ($config) $db->update('user_level_config', ['budget' => $budget, 'enabled' => (int) $enabled, 'updated_at' => now()], 'id = :id', ['id' => 1]);
                else $db->insert('user_level_config', ['id' => 1, 'budget' => $budget, 'spent' => 0, 'enabled' => (int) $enabled, 'created_at' => now(), 'updated_at' => now()]);
                AuditService::log('settings.user_levels', 'user_level_config', 1, ['budget' => $budget, 'enabled' => $enabled], current_admin_id());
            });
            $this->redirect('admin/user-levels', 'تنظیمات ذخیره شد.');
        } catch (\RB\Support\RedirectException $e) { throw $e; } catch (\Throwable $e) { $this->redirect('admin/user-levels', null, $e->getMessage()); }
    }

    public function save(): void
    {
        if (!$this->authorized()) return;
        $db = Database::instance();
        try {
            $id = max(0, (int) ($_POST['id'] ?? 0));
            $name = trim((string) ($_POST['name'] ?? ''));
            $icon = trim((string) ($_POST['icon'] ?? ''));
            $badge = trim((string) ($_POST['badge'] ?? ''));
            $color = trim((string) ($_POST['color'] ?? ''));
            $rawMin = en_digits(trim((string) ($_POST['min_invites'] ?? '')));
            $reward = Money::parseToman($_POST['invite_reward'] ?? null);
            $promotion = Money::parseToman($_POST['promotion_reward'] ?? null);
            $cap = Money::parseToman($_POST['user_cap'] ?? null);
            if ($name === '' || mb_strlen($name) > 100 || $icon === '' || mb_strlen($icon) > 32 || mb_strlen($badge) > 100 || !preg_match('/^#[0-9a-fA-F]{6}$/', $color) || !ctype_digit($rawMin) || (int) $rawMin < 1 || (int) $rawMin > 100000000 || $reward === null || $promotion === null || $cap === null || $reward < 0 || $promotion < 0 || $cap <= 0) {
                throw new \InvalidArgumentException('نام، آیکون، رنگ، تعداد دعوت و مبالغ معتبر و سقف مثبت هر کاربر الزامی است.');
            }
            $data = ['name' => $name, 'icon' => $icon, 'badge' => $badge, 'color' => $color, 'min_invites' => (int) $rawMin, 'invite_reward' => $reward, 'promotion_reward' => $promotion, 'user_cap' => $cap, 'benefits' => mb_substr(trim((string) ($_POST['benefits'] ?? '')), 0, 2000), 'enabled' => !empty($_POST['enabled']) ? 1 : 0, 'updated_at' => now()];
            $db->transaction(function () use ($db, $id, $data) {
                // Serialize admin edits with payouts if the config exists.
                (new UserLevelService($db))->config(true);
                $duplicate = $db->value('SELECT id FROM user_levels WHERE min_invites = :m AND deleted = 0 AND id <> :id', ['m' => $data['min_invites'], 'id' => $id]);
                if ($duplicate) throw new \InvalidArgumentException('سطح دیگری با این تعداد دعوت وجود دارد.');
                if ($id) {
                    if (!$db->value('SELECT id FROM user_levels WHERE id = :id AND deleted = 0', ['id' => $id])) throw new \InvalidArgumentException('سطح یافت نشد.');
                    $db->update('user_levels', $data, 'id = :id', ['id' => $id]);
                    $savedId = $id;
                } else {
                    $savedId = $db->insert('user_levels', $data + ['deleted' => 0, 'created_at' => now()]);
                }
                AuditService::log('settings.user_level_saved', 'user_level', $savedId, $data, current_admin_id());
            });
            $this->redirect('admin/user-levels', 'سطح ذخیره شد.');
        } catch (\RB\Support\RedirectException $e) { throw $e; } catch (\Throwable $e) { $this->redirect('admin/user-levels', null, $e->getMessage()); }
    }

    public function delete(): void
    {
        if (!$this->authorized()) return;
        $db = Database::instance();
        $id = max(0, (int) ($_POST['id'] ?? 0));
        $db->transaction(function () use ($db, $id) {
            (new UserLevelService($db))->config(true);
            $db->update('user_levels', ['deleted' => 1, 'enabled' => 0, 'updated_at' => now()], 'id = :id', ['id' => $id]);
            AuditService::log('settings.user_level_deleted', 'user_level', $id, [], current_admin_id());
        });
        $this->redirect('admin/user-levels', 'سطح حذف شد؛ سوابق مالی حفظ شدند.');
    }
}
