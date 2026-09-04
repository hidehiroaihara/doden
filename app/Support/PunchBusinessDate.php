<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * 打刻画面の「営業日」判定。
 * 境界時刻未満は暦日の前日を営業日とする（日跨ぎ退勤と翌朝出勤の両立）。
 */
class PunchBusinessDate
{
    public const SETTING_KEY = 'punch_day_boundary_hour';

    public const DEFAULT_BOUNDARY_HOUR = 5;

    /** 境界時刻（0〜6時）。この時刻未満は前日扱い。 */
    public static function boundaryHour(): int
    {
        $hour = (int) Setting::getValue(self::SETTING_KEY, (string) self::DEFAULT_BOUNDARY_HOUR);

        return max(0, min(6, $hour));
    }

    /** 指定時刻の打刻営業日（Y-m-d）。 */
    public static function date(?Carbon $at = null): string
    {
        $at = ($at ?? now())->copy();
        if ($at->hour < self::boundaryHour()) {
            return $at->copy()->subDay()->toDateString();
        }

        return $at->toDateString();
    }
}
