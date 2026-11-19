<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;
use RB\Support\Money;

/**
 * تنظیمات نسخه‌بندی‌شده‌ی پورسانت و درآمد لایه‌ای
 *
 * ساختار لایه‌ها:
 *   - لایه ۰ (دعوت مستقیم): همیشه مبلغ ثابت به تومان (directReward / directEnabled)
 *   - پاداش دعوت‌شده (خوش‌آمدگویی): مبلغ ثابت به تومان (inviteeReward / inviteeEnabled)
 *   - لایه ۱ تا maxLevels (دعوت‌های غیرمستقیم در عمق ۲ تا maxLevels+1):
 *     هر لایه به‌صورت مستقل دارای enabled، نوع محاسبه calc_type (fixed | percent)،
 *     مقدار ثابت amount (تومان)، مقدار درصدی percent و سقف پرداخت لایه max_amount (تومان) است.
 *
 * هر تغییر یک نسخه جدید می‌سازد؛ تراکنش‌ها و محاسبات گذشته هرگز تغییر نمی‌کنند.
 */
final class CommissionSettings
{
    public const MAX_SUPPORTED_LEVELS = 8;

    public const PERCENT_BASES = [
        'direct_reward' => 'پاداش پایه (مبلغ لایه صفر / دعوت مستقیم)',
        'fixed_base'    => 'مبلغ پایه ثابت تعیین‌شده در تنظیمات',
        'event_amount'  => 'مبلغ واجد شرایط رویداد',
    ];

    public int $versionId = 0;
    public int $version = 1;
    public int $directReward = 0;
    public bool $directEnabled = true;
    public int $inviteeReward = 0;
    public bool $inviteeEnabled = false;
    public bool $multiLevelEnabled = false;
    public int $maxLevels = 3;
    /**
     * @var array<int,array{level:int,enabled:bool,calc_type:string,amount:int,percent:string,max_amount:int}>
     */
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

    public static function forgetCache(): void
    {
        self::$cached = null;
    }

    /**
     * نرمال‌سازی یک ردیف تنظیم لایه با سازگاری کامل عقب‌رو با نسخه‌های قبلی (که فقط percent داشتند)
     *
     * @return array{level:int,enabled:bool,calc_type:string,amount:int,percent:string,max_amount:int}
     */
    public static function normalizeLevel(array $row, int $fallbackLevel = 1): array
    {
        $level = max(1, min(self::MAX_SUPPORTED_LEVELS, (int) ($row['level'] ?? $fallbackLevel)));
        $rawType = strtolower(trim((string) ($row['calc_type'] ?? '')));
        $calcType = in_array($rawType, ['fixed', 'percent'], true) ? $rawType : 'percent';
        $amount = max(0, (int) ($row['amount'] ?? 0));
        $rawPercent = trim((string) ($row['percent'] ?? '0'));
        $percent = is_numeric($rawPercent) && (float) $rawPercent >= 0
            ? Money::fromMicro(Money::toMicro($rawPercent))
            : '0';
        $maxAmount = max(0, (int) ($row['max_amount'] ?? 0));

        return [
            'level' => $level,
            'enabled' => !empty($row['enabled']),
            'calc_type' => $calcType,
            'amount' => $amount,
            'percent' => $percent,
            'max_amount' => $maxAmount,
        ];
    }

    public static function fromRow(array $row): self
    {
        $s = new self();
        $s->versionId = (int) $row['id'];
        $s->version = (int) $row['version'];
        $s->directReward = max(0, (int) $row['direct_reward']);
        $s->directEnabled = (bool) $row['direct_enabled'];
        $s->inviteeReward = max(0, (int) ($row['invitee_reward'] ?? 0));
        $s->inviteeEnabled = (bool) ($row['invitee_enabled'] ?? false);
        $s->multiLevelEnabled = (bool) $row['multi_level_enabled'];
        $s->maxLevels = max(1, min(self::MAX_SUPPORTED_LEVELS, (int) $row['max_levels']));
        $decoded = json_decode((string) ($row['levels'] ?? '[]'), true);
        $normalized = [];
        if (is_array($decoded)) {
            foreach ($decoded as $idx => $item) {
                if (is_array($item)) {
                    $normalized[] = self::normalizeLevel($item, (int) $idx + 1);
                }
            }
        }
        $s->levels = $normalized;
        $s->percentBase = (string) ($row['percent_base'] ?? 'direct_reward');
        $s->fixedBaseAmount = max(0, (int) ($row['fixed_base_amount'] ?? 0));
        $s->maxPayoutPerEvent = max(0, (int) ($row['max_payout_per_event'] ?? 0));
        $s->dailyPayoutCap = max(0, (int) ($row['daily_payout_cap'] ?? 0));
        $conditions = json_decode((string) ($row['qualify_conditions'] ?? '[]'), true);
        $s->qualifyConditions = is_array($conditions) ? $conditions : [];
        $s->blockedUsersNoCommission = (bool) ($row['blocked_users_no_commission'] ?? true);
        $s->effectiveFrom = (string) ($row['effective_from'] ?? '');
        return $s;
    }

    public static function defaults(): self
    {
        $s = new self();
        $s->directReward = 0;
        $s->directEnabled = true;
        $s->levels = [
            self::normalizeLevel(['level' => 1, 'calc_type' => 'fixed',   'amount' => 1000, 'percent' => '20', 'enabled' => true]),
            self::normalizeLevel(['level' => 2, 'calc_type' => 'percent', 'amount' => 0,    'percent' => '10', 'enabled' => true]),
            self::normalizeLevel(['level' => 3, 'calc_type' => 'percent', 'amount' => 0,    'percent' => '5',  'enabled' => true]),
        ];
        return $s;
    }

    /**
     * تنظیمات لایه غیرمستقیم شماره $level (۱ تا ۸)
     *
     * @return array{level:int,enabled:bool,calc_type:string,amount:int,percent:string,max_amount:int}|null
     */
    public function level(int $level): ?array
    {
        if ($level < 1 || $level > self::MAX_SUPPORTED_LEVELS) {
            return null;
        }
        foreach ($this->levels as $row) {
            if ((int) ($row['level'] ?? 0) === $level) {
                return self::normalizeLevel($row, $level);
            }
        }
        return null;
    }

    /**
     * محاسبه دقیق مبلغ پاداش یک لایه غیرمستقیم (۱ به بعد) با رعایت نوع محاسبه و سقف لایه.
     *
     * @return array{level:int,calc_type:string,amount:int,raw_amount:int,percent:string,fixed_amount:int,base_amount:int,max_amount:int,cap_applied:bool}|null
     */
    public function calculateLevelPayout(int $level, int $eventBaseAmount): ?array
    {
        if ($level < 1 || $level > $this->maxLevels) {
            return null;
        }
        $cfg = $this->level($level);
        if ($cfg === null || !$cfg['enabled']) {
            return null;
        }

        $calcType = $cfg['calc_type'];
        $base = $this->percentBaseAmount($eventBaseAmount);
        $percent = '0';
        $fixedAmount = 0;

        if ($calcType === 'fixed') {
            $fixedAmount = max(0, (int) $cfg['amount']);
            $rawAmount = $fixedAmount;
            $effectiveBase = $fixedAmount;
        } else {
            $percent = (string) $cfg['percent'];
            if ((float) $percent <= 0 || $base <= 0) {
                return null;
            }
            $rawAmount = Money::percentOf($base, $percent, $this->rounding);
            $effectiveBase = $base;
        }

        if ($rawAmount <= 0) {
            return null;
        }

        $maxAmount = max(0, (int) $cfg['max_amount']);
        $capApplied = false;
        $finalAmount = $rawAmount;
        if ($maxAmount > 0 && $finalAmount > $maxAmount) {
            $finalAmount = $maxAmount;
            $capApplied = true;
        }

        if ($finalAmount <= 0) {
            return null;
        }

        return [
            'level' => $level,
            'calc_type' => $calcType,
            'amount' => $finalAmount,
            'raw_amount' => $rawAmount,
            'percent' => $percent,
            'fixed_amount' => $fixedAmount,
            'base_amount' => $effectiveBase,
            'max_amount' => $maxAmount,
            'cap_applied' => $capApplied,
        ];
    }

    /** توصیف فارسی کوتاه فرمول یک لایه برای نمایش شفاف در پنل و گزارش‌ها */
    public function describeLevel(int $level): string
    {
        if ($level === 0) {
            return $this->directEnabled
                ? 'مبلغ ثابت: ' . wtoman($this->directReward)
                : 'غیرفعال';
        }
        $cfg = $this->level($level);
        if ($cfg === null || !$cfg['enabled']) {
            return 'غیرفعال';
        }
        if ($cfg['calc_type'] === 'fixed') {
            $txt = 'مبلغ ثابت: ' . wtoman((int) $cfg['amount']);
        } else {
            $calc = $this->calculateLevelPayout($level, $this->directReward);
            $sample = $calc !== null ? ' (معادل ' . wtoman($calc['amount']) . ')' : '';
            $txt = 'درصدی: ' . fa_digits($cfg['percent']) . '٪' . $sample;
        }
        if ((int) $cfg['max_amount'] > 0) {
            $txt .= ' · سقف ' . wtoman((int) $cfg['max_amount']);
        }
        return $txt;
    }

    /** مبنای محاسبه درصدها به تومان */
    public function percentBaseAmount(int $eventBaseAmount): int
    {
        return match ($this->percentBase) {
            'fixed_base'   => max(0, $this->fixedBaseAmount),
            'event_amount' => max(0, $eventBaseAmount),
            default        => max(0, $this->directReward), // direct_reward (پاداش پایه لایه ۰)
        };
    }

    /** عنوان فارسی شفاف مبنای محاسبه درصد */
    public function percentBaseLabel(): string
    {
        return self::PERCENT_BASES[$this->percentBase] ?? self::PERCENT_BASES['direct_reward'];
    }

    /** مجموع درصد لایه‌های فعال درصدی — برای نمایش و اعتبارسنجی */
    public function totalPercent(): string
    {
        $total = 0;
        foreach ($this->levels as $row) {
            $norm = self::normalizeLevel($row);
            if ($norm['enabled'] && $norm['calc_type'] === 'percent' && $norm['level'] <= $this->maxLevels) {
                $total += (int) Money::toMicro($norm['percent']);
            }
        }
        return Money::fromMicro($total);
    }

    /** مجموع مبلغ ثابت لایه‌های فعال ثابت (لایه ۱ به بعد) */
    public function totalFixedLayers(): int
    {
        $sum = 0;
        foreach ($this->levels as $row) {
            $norm = self::normalizeLevel($row);
            if ($norm['enabled'] && $norm['calc_type'] === 'fixed' && $norm['level'] <= $this->maxLevels) {
                $amt = $norm['amount'];
                if ($norm['max_amount'] > 0 && $amt > $norm['max_amount']) {
                    $amt = $norm['max_amount'];
                }
                $sum += $amt;
            }
        }
        return $sum;
    }

    /** مجموع تخمینی کل پرداخت لایه‌های غیرمستقیم (۱ تا maxLevels) در یک رویداد نمونه */
    public function estimatedIndirectTotal(?int $eventBaseAmount = null): int
    {
        if (!$this->multiLevelEnabled) {
            return 0;
        }
        $base = $eventBaseAmount ?? $this->directReward;
        $sum = 0;
        for ($l = 1; $l <= $this->maxLevels; $l++) {
            $calc = $this->calculateLevelPayout($l, $base);
            if ($calc !== null) {
                $sum += $calc['amount'];
            }
        }
        return $sum;
    }

    /** مبلغ مؤثر پاداش لایه ۰ / دعوت‌کننده (در صورت غیرفعال بودن صفر است) */
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
     * ذخیره نسخه جدید تنظیمات (بدون دست‌زدن به نسخه‌ها و تراکنش‌های پیشین)
     */
    public static function saveVersion(array $data, ?int $adminId = null, ?Database $db = null): self
    {
        $db ??= Database::instance();
        $hasInviteeCols = $db->columnExists('commission_setting_versions', 'invitee_reward')
            && $db->columnExists('commission_setting_versions', 'invitee_enabled');

        $rawLevels = is_array($data['levels'] ?? null) ? $data['levels'] : [];
        $cleanLevels = [];
        foreach ($rawLevels as $idx => $item) {
            if (is_array($item)) {
                $cleanLevels[] = self::normalizeLevel($item, (int) $idx + 1);
            }
        }

        return self::fromRow($db->transaction(function () use ($db, $data, $cleanLevels, $adminId, $hasInviteeCols) {
            $maxVersion = (int) $db->value('SELECT COALESCE(MAX(version), 0) FROM commission_setting_versions');
            $payload = [
                'version' => $maxVersion + 1,
                'direct_reward' => max(0, (int) ($data['direct_reward'] ?? 0)),
                'direct_enabled' => !empty($data['direct_enabled']) ? 1 : 0,
                'multi_level_enabled' => !empty($data['multi_level_enabled']) ? 1 : 0,
                'max_levels' => max(1, min(self::MAX_SUPPORTED_LEVELS, (int) ($data['max_levels'] ?? 1))),
                'levels' => json_encode($cleanLevels, JSON_UNESCAPED_UNICODE),
                'percent_base' => in_array((string) ($data['percent_base'] ?? ''), array_keys(self::PERCENT_BASES), true)
                    ? (string) $data['percent_base']
                    : 'direct_reward',
                'fixed_base_amount' => max(0, (int) ($data['fixed_base_amount'] ?? 0)),
                'max_payout_per_event' => max(0, (int) ($data['max_payout_per_event'] ?? 0)),
                'daily_payout_cap' => max(0, (int) ($data['daily_payout_cap'] ?? 0)),
                'qualify_conditions' => json_encode($data['qualify_conditions'] ?? ['joined'], JSON_UNESCAPED_UNICODE),
                'blocked_users_no_commission' => array_key_exists('blocked_users_no_commission', $data)
                    ? (!empty($data['blocked_users_no_commission']) ? 1 : 0)
                    : 1,
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
