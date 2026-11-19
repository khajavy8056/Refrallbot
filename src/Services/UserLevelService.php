<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;

/** Rank is derived from qualifying, unreverted events, not the raw referral counter. */
final class UserLevelService
{
    public function __construct(private Database $db) {}

    public function config(bool $lock = false): ?array
    {
        if (!$this->db->tableExists('user_level_config')) return null;
        $sql = 'SELECT * FROM user_level_config WHERE id = 1';
        if ($lock && !$this->db->isSqlite()) $sql .= ' FOR UPDATE';
        return $this->db->first($sql);
    }

    public function levels(bool $lock = false): array
    {
        $sql = 'SELECT * FROM user_levels WHERE enabled = 1 AND deleted = 0 ORDER BY min_invites ASC, id ASC';
        if ($lock && !$this->db->isSqlite()) $sql .= ' FOR UPDATE';
        return $this->db->all($sql);
    }

    public function count(int $userId, bool $lock = false, ?int $excludeEvent = null): int
    {
        // Use the existing qualification event; do not redefine referral success.
        $sql = "SELECT e.id, e.event_key, e.user_id FROM referral_events e
                WHERE e.type = 'referral_qualified' AND e.status = 'processed' AND e.actor_id = :a";
        if ($lock && !$this->db->isSqlite()) $sql .= ' FOR UPDATE';
        $seen = [];
        foreach ($this->db->all($sql, ['a' => $userId]) as $row) {
            if ($excludeEvent !== null && (int) $row['id'] === $excludeEvent) continue;
            if ((string) $row['event_key'] === 'referral_qualified:' . (int) $row['user_id']) {
                $seen[(int) $row['user_id']] = true;
            }
        }
        return count($seen);
    }

    public static function atCount(array $levels, int $count): ?array
    {
        $selected = null;
        foreach ($levels as $level) {
            if ((int) $level['min_invites'] <= $count) $selected = $level;
        }
        return $selected;
    }

    public function profile(int $userId): ?array
    {
        $config = $this->config();
        if (!$config || empty($config['enabled'])) return null;
        $levels = $this->levels();
        $count = $this->count($userId);
        $current = self::atCount($levels, $count);
        $next = null;
        foreach ($levels as $level) {
            if ((int) $level['min_invites'] > $count) { $next = $level; break; }
        }
        $reward = null;
        if ($current) {
            $spent = (int) $this->db->value('SELECT COALESCE(SUM(amount), 0) FROM user_level_payments WHERE level_id = :l AND user_id = :u', ['l' => (int) $current['id'], 'u' => $userId]);
            $reward = max(0, min((int) $current['invite_reward'], max(0, (int) $current['user_cap'] - $spent), max(0, (int) $config['budget'] - (int) $config['spent'])));
        }
        return [
            'next_invite_reward' => $reward,
            'count' => $count,
            'current' => $current ? array_intersect_key($current, array_flip(['id','name','icon','badge','color','min_invites','invite_reward','benefits'])) : null,
            'next' => $next ? ['name' => $next['name'], 'min_invites' => (int) $next['min_invites']] : null,
        ];
    }

    /** Called inside the commission transaction; the config row serializes budget and payouts. */
    public function prepare(int $userId, int $sourceId, int $eventId, ?array $config = null): ?array
    {
        $config ??= $this->config(true);
        if (!$config || empty($config['enabled'])) return null;
        $source = $this->db->first('SELECT referred_by, qualified_at, status FROM users WHERE id = :id', ['id' => $sourceId]);
        if (!$source || (int) $source['referred_by'] !== $userId || empty($source['qualified_at']) || $source['status'] === 'blocked' || $sourceId === $userId) return null;
        $levels = $this->levels(true);
        $before = $this->count($userId, true, $eventId);
        return ['config' => $config, 'levels' => $levels, 'before' => $before, 'level' => self::atCount($levels, $before)];
    }

    public function allowance(array $ctx, array $level, int $userId, int $desired): int
    {
        $sql = 'SELECT amount FROM user_level_payments WHERE level_id = :l AND user_id = :u';
        if (!$this->db->isSqlite()) $sql .= ' FOR UPDATE';
        $spent = 0;
        foreach ($this->db->all($sql, ['l' => (int) $level['id'], 'u' => $userId]) as $payment) $spent += (int) $payment['amount'];
        return max(0, min($desired, max(0, (int) $level['user_cap'] - $spent), max(0, (int) $ctx['config']['budget'] - (int) $ctx['config']['spent'])));
    }

    public function record(array &$ctx, array $level, int $userId, int $eventId, string $kind, int $amount): void
    {
        if ($amount <= 0) return;
        $this->db->insert('user_level_payments', ['level_id' => (int) $level['id'], 'user_id' => $userId, 'event_id' => $eventId, 'kind' => $kind, 'amount' => $amount, 'created_at' => now()]);
        $this->db->run('UPDATE user_level_config SET spent = spent + :a, updated_at = :n WHERE id = 1', ['a' => $amount, 'n' => now()]);
        $ctx['config']['spent'] += $amount;
    }

    /** Only newly crossed thresholds pay, never historical ranks on activation or edit. */
    public function promote(array &$ctx, int $userId, int $eventId): void
    {
        $before = (int) $ctx['before'];
        foreach ($ctx['levels'] as $level) {
            if ((int) $level['min_invites'] !== $before + 1) continue;
            $sql = 'SELECT id FROM user_level_promotions WHERE level_id = :l AND user_id = :u';
            if (!$this->db->isSqlite()) $sql .= ' FOR UPDATE';
            $exists = $this->db->value($sql, ['l' => (int) $level['id'], 'u' => $userId]);
            if ($exists) continue;
            $user = $this->db->first('SELECT status FROM users WHERE id = :u', ['u' => $userId]);
            $amount = (!$user || $user['status'] === 'blocked') ? 0 : $this->allowance($ctx, $level, $userId, (int) $level['promotion_reward']);
            $id = $this->db->insert('user_level_promotions', ['level_id' => (int) $level['id'], 'user_id' => $userId, 'event_id' => $eventId, 'amount' => $amount, 'level_name' => $level['name'], 'created_at' => now()]);
            if ($amount > 0) {
                (new WalletService($this->db))->credit($userId, 'level_promotion', $amount, [
                    'source_type' => 'level_promotion', 'source_id' => $id,
                    'idempotency_key' => 'level_promotion:' . $level['id'] . ':' . $userId,
                    'description' => 'جایزه ارتقا به ' . $level['name'],
                    'meta' => ['event_id' => $eventId, 'level_id' => (int) $level['id'], 'level_name' => $level['name']],
                ]);
                $this->db->run('UPDATE users SET total_earned = total_earned + :a, updated_at = :n WHERE id = :u', ['a' => $amount, 'n' => now(), 'u' => $userId]);
                $this->record($ctx, $level, $userId, $eventId, 'promotion', $amount);
            }
            if ($user && $user['status'] !== 'blocked') {
                $body = 'تبریک! به سطح <b>' . e((string) $level['icon'] . ' ' . (string) $level['name']) . '</b> رسیدید.'
                    . "\n" . 'پاداش دعوت‌های جدید در این سطح تا <b>' . wtoman((int) $level['invite_reward']) . '</b> است و طبق سقف‌های تنظیم‌شده محاسبه می‌شود.';
                if ($amount > 0) $body .= "\n" . '🎁 جایزه ارتقا: <b>' . wtoman($amount) . '</b> به کیف پول شما واریز شد.';
                Notifier::defer($userId, '🎉 تبریک ارتقای سطح', $body, 'level_promotion', 'level:' . $level['id']);
            }
        }
    }
}
