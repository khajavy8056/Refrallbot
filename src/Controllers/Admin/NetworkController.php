<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\UserService;
use RB\Support\Database;
use RB\Support\Request;

final class NetworkController extends AdminController
{
    public function index(): void
    {
        $db = Database::instance();
        $q = trim((string) ($_GET['q'] ?? ''));
        $userId = (int) ($_GET['user'] ?? 0);

        if ($userId <= 0 && $q !== '') {
            $found = $this->findUser($q);
            if ($found) {
                Request::redirect(url('admin/network/' . (int) $found['id']));
                return;
            }
        }

        // کاربران ریشه (بدون معرف) با بیشترین شبکه
        $roots = $db->all(
            'SELECT u.* FROM users u WHERE u.referred_by IS NULL AND u.total_ref_count > 0
             ORDER BY u.total_ref_count DESC LIMIT 20'
        );
        $topInviters = $db->all(
            'SELECT u.* FROM users u WHERE u.total_ref_count > 0 ORDER BY u.total_ref_count DESC LIMIT 20'
        );

        $depth = (int) ($_GET['depth'] ?? 8);
        if ($depth < 1 || $depth > 8) {
            $depth = 8;
        }

        $msgSummary = \RB\Services\StatsService::segmentedMessengerSummary($db);

        $this->render('admin/network/index', [
            'title' => 'شبکه زیرمجموعه‌ها',
            'roots' => $roots,
            'topInviters' => $topInviters,
            'q' => $q,
            'depth' => $depth,
            'activeMessengers' => $msgSummary['active'],
            'stats' => [
                'total_relations' => (int) $db->value('SELECT COUNT(*) FROM referral_relations'),
                'with_refs' => (int) $db->value('SELECT COUNT(*) FROM users WHERE referred_by IS NOT NULL'),
                'indirect_refs' => (int) $db->value('SELECT COUNT(*) FROM referral_relations WHERE depth >= 2'),
                'max_depth' => (int) $db->value('SELECT COALESCE(MAX(depth), 0) FROM referral_relations'),
                'orphans' => (int) $db->value('SELECT COUNT(*) FROM users WHERE referred_by IS NULL AND total_ref_count > 0'),
            ],
        ]);
    }

    public function show(array $params): void
    {
        $userId = (int) $params['id'];
        $users = new UserService();
        $user = $users->find($userId);
        if ($user === null) {
            $this->flash('error', 'کاربر یافت نشد.');
            Request::redirect(url('admin/network'));
            return;
        }
        $depth = (int) ($_GET['depth'] ?? 8);
        if ($depth < 1 || $depth > 8) {
            $depth = 8;
        }

        $descendants = $users->descendants($userId, $depth, 3000);
        $tree = $this->buildTree($descendants);
        $ancestors = [];
        foreach ($users->ancestors($userId, 8) as $d => $aid) {
            $ancestors[$d] = $users->find($aid);
        }

        $this->render('admin/network/show', [
            'title' => 'شبکه کاربر',
            'user' => $user,
            'tree' => $tree,
            'descendants' => $descendants,
            'ancestors' => $ancestors,
            'depth' => $depth,
        ]);
    }

    public function treeJson(array $params): void
    {
        $userId = (int) $params['id'];
        $users = new UserService();
        $depth = min(8, max(1, (int) ($_GET['depth'] ?? 8)));
        $descendants = $users->descendants($userId, $depth, 3000);
        $this->json(['ok' => true, 'nodes' => $descendants]);
    }

    /**
     * ساخت درخت از لیست اجداد/نوادگان
     * @return array<int,array>
     */
    private function buildTree(array $rows): array
    {
        // گروه‌بندی بر اساس معرف (با استفاده از referred_by در ردیف‌ها)
        $byParent = [];
        foreach ($rows as $row) {
            $byParent[(int) ($row['referred_by'] ?? 0)][] = $row;
        }
        return $byParent;
    }

    private function findUser(string $q): ?array
    {
        $db = Database::instance();
        if (ctype_digit(en_digits($q))) {
            $found = $db->first('SELECT * FROM users WHERE telegram_id = :t', ['t' => (int) en_digits($q)]);
            if ($found) {
                return $found;
            }
            $found = $db->first('SELECT * FROM users WHERE id = :i', ['i' => (int) $q]);
            if ($found) {
                return $found;
            }
        }
        $found = $db->first('SELECT * FROM users WHERE referral_code = :c', ['c' => strtoupper($q)]);
        if ($found) {
            return $found;
        }
        $fullNameExpr = $db->driver() === 'mysql'
            ? "CONCAT(first_name, ' ', COALESCE(last_name, ''))"
            : "(first_name || ' ' || COALESCE(last_name, ''))";
        return $db->first(
            "SELECT * FROM users WHERE username = :u OR {$fullNameExpr} LIKE :n LIMIT 1",
            ['u' => ltrim($q, '@'), 'n' => '%' . $q . '%']
        );
    }
}
