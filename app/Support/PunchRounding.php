<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * 給与計算用の打刻丸め。
 *
 * - time（既定）: 出勤は丸め単位のグリッド(0:00起点)へ切り上げ、退勤は切り捨て。
 *   ちょうどグリッド上の打刻はそのまま（8:00→8:00, 8:09→8:30 / 17:15→17:00）。
 *   休憩・区分・深夜・遅刻早退はすべて丸め後の出退勤で判定する。
 * - minutes（従来）: 打刻はそのままで、シフトごとの実労働分を丸め単位・ルールで丸める。
 */
final class PunchRounding
{
    public const MODE_TIME = 'time';

    public const MODE_MINUTES = 'minutes';

    /**
     * @return array{mode: string, unit: int, rule: string}
     */
    public static function settings(): array
    {
        $mode = Setting::getValue('salary_round_mode', self::MODE_TIME);

        return [
            'mode' => $mode === self::MODE_MINUTES ? self::MODE_MINUTES : self::MODE_TIME,
            'unit' => (int) Setting::getValue('salary_round_minutes', 30),
            'rule' => (string) Setting::getValue('salary_round_rule', 'floor'),
        ];
    }

    public static function isTimeMode(array $settings): bool
    {
        return $settings['mode'] === self::MODE_TIME;
    }

    /**
     * 給与計算に使う出退勤。time モード以外は打刻そのまま。
     * 丸めで退勤が出勤以前になる短時間勤務は、労働0分（退勤=出勤）とする。
     *
     * @param  array{mode: string, unit: int, rule: string}  $settings
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function interval(Carbon $in, Carbon $out, array $settings): array
    {
        if (! self::isTimeMode($settings) || $settings['unit'] <= 0) {
            return [$in->copy(), $out->copy()];
        }

        $roundedIn = self::ceilToGrid($in, $settings['unit']);
        $roundedOut = self::floorToGrid($out, $settings['unit']);
        if ($roundedOut->lt($roundedIn)) {
            $roundedOut = $roundedIn->copy();
        }

        return [$roundedIn, $roundedOut];
    }

    public static function ceilToGrid(Carbon $t, int $unit): Carbon
    {
        $base = $t->copy()->startOfMinute();
        $remainder = ($base->hour * 60 + $base->minute) % $unit;

        return $remainder === 0 ? $base : $base->addMinutes($unit - $remainder);
    }

    public static function floorToGrid(Carbon $t, int $unit): Carbon
    {
        $base = $t->copy()->startOfMinute();
        $remainder = ($base->hour * 60 + $base->minute) % $unit;

        return $base->subMinutes($remainder);
    }

    /**
     * 実労働分の丸め（minutes モード用）。time モードでは丸め済み時刻から算出するためそのまま返す。
     *
     * @param  array{mode: string, unit: int, rule: string}  $settings
     */
    public static function roundNetMinutes(int $minutes, array $settings): int
    {
        if (self::isTimeMode($settings)) {
            return $minutes;
        }

        return self::roundMinutes($minutes, $settings['unit'], $settings['rule']);
    }

    public static function roundMinutes(int $minutes, int $unit, string $rule): int
    {
        if ($unit <= 0) {
            return $minutes;
        }
        $quotient = $minutes / $unit;

        return match ($rule) {
            'ceil' => (int) ceil($quotient) * $unit,
            'round' => (int) round($quotient) * $unit,
            default => (int) floor($quotient) * $unit,
        };
    }
}
