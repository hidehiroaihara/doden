<?php

namespace App\Support;

use App\Models\AttendanceItemMaster;
use App\Models\PayItemMaster;

/**
 * 支給項目マスタと勤怠項目マスタの連動（MFクラウド準拠）。
 */
class PayrollMasterSync
{
    /** 遅刻早退控除（late_early_deduction）に紐づく勤怠項目コード */
    public const LATE_EARLY_ATTENDANCE_CODES = [
        'late_minutes_weekday',
        'late_minutes_prescribed_holiday',
        'late_minutes_legal_holiday',
        'early_leave_minutes_weekday',
        'early_leave_minutes_prescribed_holiday',
        'early_leave_minutes_legal_holiday',
        'late_count',
        'late_count_prescribed_holiday',
        'late_count_legal_holiday',
        'early_leave_count',
        'early_leave_count_prescribed_holiday',
        'early_leave_count_legal_holiday',
    ];

    public static function isLateEarlyDeductionActive(): bool
    {
        return PayItemMaster::query()
            ->where('code', 'late_early_deduction')
            ->where('is_active', true)
            ->exists();
    }

    /**
     * 遅刻早退控除が無効のとき、関連勤怠項目も無効に揃える。
     *
     * @return int 更新件数
     */
    public static function syncLateEarlyAttendanceItems(): int
    {
        if (self::isLateEarlyDeductionActive()) {
            return 0;
        }

        return AttendanceItemMaster::query()
            ->whereIn('code', self::LATE_EARLY_ATTENDANCE_CODES)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }
}
