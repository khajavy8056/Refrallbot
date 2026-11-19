<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;
use RB\Support\Money;

/**
 * تنظیمات نسخه‌بندی‌شده‌ی پورسانت
 * هر تغییر، یک نسخه جدید می‌سازد؛ تراکنش‌های قدیمی نسخه‌ی زمان خود را نگه می‌دارند.
 */
final class CommissionSettings
{
    public int $versionId = 0;
    public int $version = 1;
    public int $directReward = 0;
    public bool $directEnabled = true;
    public int $inviteeReward = 0;
    public bool $inviteeEnabled = false;
    public bool $multiLevelEnabled = false;
    public int $maxLevels = 3;
    /** @var array<int,array{level:int,percent:string,enabled:bool}> */
    public array $levels = [];
    public string $percentBase = 'direct_reward';
    public int $fixedBaseAmount = 0;
    public int $maxPayoutPerEvent = 0;
    public int $dailyPayoutCap = 0;
    public array $qualifyConditions = [];
    public bool $blockedUsersNoCommission = true;
    public string $rounding = 'floor';
    public string $effectiveFrom = '';

    private static ?CommissionSettings $cached = null;

    public static function current(?Database $db = null, bool $refresh = false): self
    {
        if (self::$cached !== null && !$refresh) {
            return self::$cached;
        }
        $db ??= Database::instance();

        if (!$db->tableExists('commission_setting_versions')) {
            return self::$cached = self::defaults();
        }

        $row = $db->first('SELECT * FROM commission_setting_versions ORDER BY version DESC, id DESC LIMIT 1');
        if ($row === null) {
            return self::$cached = self::defaults();
        }
        return self::$cached = self::fromRow($row);
    }

    public static function fromRow(array $row): self
    {
        $s = new self();
        $s->versionId = (int) $row['id'];
        $s->version = (int) $row['version'];
        $s->directReward = (int) $row['direct_reward'];
        $s->directEnabled = (bool) $row['direct_enabled'];
        $s->inviteeReward = (int) ($row['invitee_reward'] ?? 0);
        $s->inviteeEnabled = (bool) ($row['invitee_enabled'] ?? false);
        $s->multiLevelEnabled = (bool) $row['multi_level_enabled'];
        $s->maxLevels = max(1, min(8, (int) $row['max_levels']));
        $levels = json_decode((string) ($row['levels'] ?? '[]'), true);
        $s->levels = is_array($levels) ? $levels : [];
        $s->percentBase = (string) ($row['percent_base'] ?? 'direct_reward');
        $s->fixedBaseAmount = (int) ($row['fixed_base_amount'] ?? 0);
        $s->maxPayoutPerEvent = (int) ($row['max_payout_per_event'] ?? 0);
        $s->dailyPayoutCap = (int) ($row['daily_payout_cap'] ?? 0);
        $conditions = json_decode((string) ($row['qualify_conditions'] ?? '[]'), true);
        $s->qualifyConditions = is_array($conditions) ? $conditions : [];
        $s->blockedUsersNoCommission = (bool) ($row['blocked_users_no_commission'] ?? true);
        $s->effectiveFrom = (string) ($row['effective_from'] ?? '');
        return $s;
    }

    public static function defaults(): self
    {
        $s = new self();
        $s->levels = [
            ['level' => 1, 'percent' => '20', 'enabled' => true],
            ['level' => 2, 'percent' => '10', 'enabled' => true],
            ['level' => 3, 'percent' => '5', 'enabled' => true],
        ];
        return $s;
    }

    /** @return array{level:int,percent:string,enabled:bool}|null */
    public function level(int $level): ?array
    {
        foreach ($this->levels as $row) {
            if ((int) ($row['level'] ?? 0) === $level) {
                return [
                    'level' => $level,
                    'percent' => (string) ($row['percent'] ?? '0'),
                    'enabled' => (bool) ($row['enabled'] ?? false),
                ];
            }
        }
        return null;
    }

    /** مبنای محاسبه درصدها */
    public function percentBaseAmount(int $eventBaseAmount): int
    {
        return match ($this->percentBase) {
            'fixed_base' => $this->fixedBaseAmount,
            'event_amount' => $eventBaseAmount,
            default => $this->directReward, // direct_reward
        };
    }

    /** مجموع درصد لایه‌های فعال — برای نمایش و اعتبارسنجی */
    public function totalPercent(): string
    {
        $total = 0;
        foreach ($this->levels as $row) {
            if (!empty($row['enabled'])) {
                $total += (int) Money::toMicro((string) ($row['percent'] ?? '0'));
            }
        }
        return Money::fromMicro($total);
    }

    /** مبلغ مؤثر پاداش دعوت‌کننده (در صورت غیرفعال بودن صفر است) */
    public function effectiveDirectReward(): int
    {
        return $this->directEnabled ? max(0, $this->directReward) : 0;
    }

    /** مبلغ مؤثر پاداش دعوت‌شده / خوش‌آمدگویی (در صورت غیرفعال بودن صفر است) */
    public function effectiveInviteeReward(): int
    {
        return $this->inviteeEnabled ? max(0, $this->inviteeReward) : 0;
    }

    /**
     * ذخیره نسخه جدید تنظیمات
     */
    public static function saveVersion(array $data, ?int $adminId = null, ?Database $db = null): self
    {
        $db ??= Database::instance();
        $hasInviteeCols = $db->columnExists('commission_setting_versions', 'invitee_reward')
            && $db->columnExists('commission_setting_versions', 'invitee_enabled');

        return self::fromRow($db->transaction(function () use ($db, $data, $adminId, $hasInviteeCols) {
            $maxVersion = (int) $db->value('SELECT COALESCE(MAX(version), 0) FROM commission_setting_versions');
            $payload = [
                'version' => $maxVersion + 1,
                'direct_reward' => max(0, (int) ($data['direct_reward'] ?? 0)),
                'direct_enabled' => !empty($data['direct_enabled']) ? 1 : 0,
                'multi_level_enabled' => !empty($data['multi_level_enabled']) ? 1 : 0,
                'max_levels' => max(1, min(8, (int) ($data['max_levels'] ?? 1))),
                'levels' => json_encode($data['levels'] ?? [], JSON_UNESCAPED_UNICODE),
                'percent_base' => (string) ($data['percent_base'] ?? 'direct_reward'),
                'fixed_base_amount' => max(0, (int) ($data['fixed_base_amount'] ?? 0)),
                'max_payout_per_event' => max(0, (int) ($data['max_payout_per_event'] ?? 0)),
                'daily_payout_cap' => max(0, (int) ($data['daily_payout_cap'] ?? 0)),
                'qualify_conditions' => json_encode($data['qualify_conditions'] ?? [], JSON_UNESCAPED_UNICODE),
                'blocked_users_no_commission' => !empty($data['blocked_users_no_commission']) ? 1 : 0,
                'created_by' => $adminId,
                'effective_from' => $data['effective_from'] ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($hasInviteeCols) {
                $payload['invitee_reward'] = max(0, (int) ($data['invitee_reward'] ?? 0));
                $payload['invitee_enabled'] = !empty($data['invitee_enabled']) ? 1 : 0;
            }
            $id = $db->insert('commission_setting_versions', $payload);
            self::$cached = null;
            return $db->first('SELECT * FROM commission_setting_versions WHERE id = :id', ['id' => $id]);
        }));
    }
}
