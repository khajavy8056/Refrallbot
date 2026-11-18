<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;
use RB\Support\Money;

/**
 * جشنواره‌ها و کمپین‌های تشویقی
 */
final class CampaignService
{
    public const TYPES = [
        'invite_count' => 'تعداد دعوت مشخص',
        'commission_boost' => 'افزایش درصد پورسانت',
        'top_inviters' => 'جایزه کاربران برتر',
    ];

    public function __construct(private ?Database $db = null)
    {
        $this->db ??= Database::instance();
    }

    /** @return array<int,array> */
    public function activeCampaigns(): array
    {
        return $this->db->all(
            'SELECT * FROM campaigns
             WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= :now) AND (ends_at IS NULL OR ends_at >= :now2)
             ORDER BY id DESC',
            ['now' => now(), 'now2' => now()]
        );
    }

    public function progressFor(array $campaign, int $userId): array
    {
        $participant = $this->db->first(
            'SELECT * FROM campaign_participants WHERE campaign_id = :c AND user_id = :u',
            ['c' => (int) $campaign['id'], 'u' => $userId]
        );
        $target = max(1, (int) $campaign['required_invites']);
        $progress = $participant ? (int) $participant['progress'] : $this->currentProgress($campaign, $userId);
        return [
            'progress' => $progress,
            'target' => $target,
            'percent' => min(100, (int) round($progress / $target * 100)),
            'status' => $participant['status'] ?? 'active',
            'rewarded' => (int) ($participant['rewarded_amount'] ?? 0),
        ];
    }

    /** پیشرفت فعلی کاربر در بازه کمپین */
    public function currentProgress(array $campaign, int $userId): int
    {
        $start = !empty($campaign['starts_at']) ? (string) $campaign['starts_at'] : '1970-01-01 00:00:00';
        $end = !empty($campaign['ends_at']) ? (string) $campaign['ends_at'] : now();
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM users WHERE referred_by = :u AND joined_at BETWEEN :s AND :e',
            ['u' => $userId, 's' => $start, 'e' => $end]
        );
    }

    /**
     * بررسی و پرداخت جوایز همه کمپین‌های فعال
     * @return int تعداد جوایز پرداخت‌شده
     */
    public function processRewards(): int
    {
        $granted = 0;
        foreach ($this->activeCampaigns() as $campaign) {
            if ($campaign['type'] !== 'invite_count' || (int) $campaign['fixed_reward'] <= 0) {
                continue;
            }
            $candidates = $this->db->all(
                'SELECT referred_by AS user_id, COUNT(*) AS c FROM users
                 WHERE referred_by IS NOT NULL AND joined_at BETWEEN :s AND :e
                 GROUP BY referred_by HAVING COUNT(*) >= :req',
                [
                    's' => $campaign['starts_at'] ?: '1970-01-01 00:00:00',
                    'e' => $campaign['ends_at'] ?: now(),
                    'req' => (int) $campaign['required_invites'],
                ]
            );
            $winners = 0;
            $maxWinners = (int) $campaign['max_winners'];
            foreach ($candidates as $row) {
                if ($maxWinners > 0 && $winners >= $maxWinners) {
                    break;
                }
                $already = (int) $this->db->value(
                    'SELECT COALESCE(rewarded_amount, 0) FROM campaign_participants WHERE campaign_id = :c AND user_id = :u',
                    ['c' => (int) $campaign['id'], 'u' => (int) $row['user_id']]
                );
                if ($already > 0) {
                    continue;
                }
                try {
                    $this->grant($campaign, (int) $row['user_id'], (int) $campaign['fixed_reward'], 'fixed', (int) $row['c']);
                    $granted++;
                    $winners++;
                } catch (\Throwable $e) {
                    \RB\Support\ErrorHandler::logException($e);
                }
            }
            // به‌روزرسانی پیشرفت شرکت‌کنندگان
            foreach ($this->db->all(
                'SELECT DISTINCT referred_by FROM users WHERE referred_by IS NOT NULL AND joined_at BETWEEN :s AND :e',
                ['s' => $campaign['starts_at'] ?: '1970-01-01 00:00:00', 'e' => $campaign['ends_at'] ?: now()]
            ) as $row) {
                $this->upsertParticipant($campaign, (int) $row['referred_by']);
            }
        }
        return $granted;
    }

    public function upsertParticipant(array $campaign, int $userId): void
    {
        $progress = $this->currentProgress($campaign, $userId);
        $existing = $this->db->first(
            'SELECT * FROM campaign_participants WHERE campaign_id = :c AND user_id = :u',
            ['c' => (int) $campaign['id'], 'u' => $userId]
        );
        if ($existing) {
            $this->db->update('campaign_participants', [
                'progress' => $progress,
                'updated_at' => now(),
            ], 'id = :id', ['id' => (int) $existing['id']]);
            return;
        }
        $this->db->insert('campaign_participants', [
            'campaign_id' => (int) $campaign['id'],
            'user_id' => $userId,
            'progress' => $progress,
            'status' => 'active',
            'rewarded_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** پرداخت جایزه با ثبت در دفتر مالی */
    public function grant(array $campaign, int $userId, int $amount, string $type = 'fixed', int $progress = 0, ?int $adminId = null): void
    {
        if ($amount <= 0) {
            throw new \RuntimeException('مبلغ جایزه باید بزرگ‌تر از صفر باشد.');
        }
        $this->db->transaction(function () use ($campaign, $userId, $amount, $type, $progress, $adminId) {
            $exists = $this->db->value(
                'SELECT id FROM campaign_rewards WHERE campaign_id = :c AND user_id = :u AND type = :t',
                ['c' => (int) $campaign['id'], 'u' => $userId, 't' => $type]
            );
            if ($exists) {
                throw new \RuntimeException('جایزه این کمپین قبلاً به این کاربر پرداخت شده است.');
            }

            $wallet = new WalletService($this->db);
            $txId = $wallet->credit($userId, 'campaign_reward', $amount, [
                'source_type' => 'campaign',
                'source_id' => (int) $campaign['id'],
                'description' => 'جایزه کمپین: ' . mb_substr((string) $campaign['title'], 0, 100),
                'idempotency_key' => 'campaign:' . $campaign['id'] . ':' . $userId . ':' . $type,
                'created_by_admin_id' => $adminId,
                'meta' => ['campaign_id' => (int) $campaign['id'], 'type' => $type],
            ]);
            if ($txId === null) {
                throw new \RuntimeException('این جایزه قبلاً ثبت شده است.');
            }

            $this->db->insert('campaign_rewards', [
                'campaign_id' => (int) $campaign['id'],
                'user_id' => $userId,
                'amount' => $amount,
                'type' => $type,
                'note' => 'جایزه کمپین با ' . $progress . ' دعوت',
                'transaction_id' => $txId,
                'admin_id' => $adminId,
                'granted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $participant = $this->db->first(
                'SELECT id FROM campaign_participants WHERE campaign_id = :c AND user_id = :u',
                ['c' => (int) $campaign['id'], 'u' => $userId]
            );
            if ($participant) {
                $this->db->update('campaign_participants', [
                    'rewarded_amount' => $amount,
                    'status' => 'winner',
                    'progress' => $progress,
                    'updated_at' => now(),
                ], 'id = :id', ['id' => (int) $participant['id']]);
            } else {
                $this->db->insert('campaign_participants', [
                    'campaign_id' => (int) $campaign['id'],
                    'user_id' => $userId,
                    'progress' => $progress,
                    'status' => 'winner',
                    'rewarded_amount' => $amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->db->run(
                'UPDATE users SET campaign_earned = campaign_earned + :a, total_earned = total_earned + :a2 WHERE id = :u',
                ['a' => $amount, 'a2' => $amount, 'u' => $userId]
            );

            Notifier::toUser($userId, '🎁 جایزه کمپین', 'شما در کمپین «' . $campaign['title'] . '» برنده شدید و مبلغ '
                . wtoman($amount) . ' به کیف پول شما اضافه شد.', 'campaign');
        });
    }

    /** درصد افزایش پورسانت فعال (برای کمپین‌های commission_boost) */
    public function activeBoostPercent(): string
    {
        $total = 0;
        foreach ($this->activeCampaigns() as $campaign) {
            if ($campaign['type'] === 'commission_boost' && (float) $campaign['percent_bonus'] > 0) {
                $total += Money::toMicro((string) $campaign['percent_bonus']);
            }
        }
        return Money::fromMicro($total);
    }

    public function stats(int $campaignId): array
    {
        $participants = (int) $this->db->value('SELECT COUNT(*) FROM campaign_participants WHERE campaign_id = :c', ['c' => $campaignId]);
        $winners = (int) $this->db->value("SELECT COUNT(*) FROM campaign_participants WHERE campaign_id = :c AND status = 'winner'", ['c' => $campaignId]);
        $paid = (int) $this->db->value('SELECT COALESCE(SUM(amount),0) FROM campaign_rewards WHERE campaign_id = :c', ['c' => $campaignId]);
        return ['participants' => $participants, 'winners' => $winners, 'paid' => $paid];
    }
}
