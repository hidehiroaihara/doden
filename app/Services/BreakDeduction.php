<?php

namespace App\Services;

use App\Models\Attendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 休憩控除の単一ソース。
 *
 * 優先順位:
 *  1. attendance_breaks に完了済みレコードがあればその合計分（最優先）
 *  2. attendance.break_minutes が NULL でなければその値（管理者手入力）
 *  3. NULL の場合、勤務区間と規定休憩時間帯の「重なり分」を算出し、
 *     ユーザー個別 or 企業デフォルト休憩分を上限として返す。
 *     → 退勤が規定休憩開始以前、または出勤が規定休憩終了以降は 0。
 */
class BreakDeduction
{
    /**
     * Attendance モデルから控除すべき休憩分数を返す。
     * attendance_breaks が eager-load 済みであること。
     */
    public static function resolve(
        Attendance $attendance,
        string $breakStartTime,
        string $breakEndTime,
        int $defaultBreakMinutes,
    ): int {
        // 1. 休憩ボタン（完了済みレコード）が最優先
        $fromButtons = self::sumCompletedBreaks($attendance->attendanceBreaks ?? collect());
        if ($fromButtons !== null) {
            return $fromButtons;
        }

        // 2. 手入力値
        if ($attendance->break_minutes !== null) {
            return (int) $attendance->break_minutes;
        }

        // 3. 打刻が揃っていなければ控除なし
        if (! $attendance->clock_in_at || ! $attendance->clock_out_at) {
            return 0;
        }

        return self::calcFromTimes(
            $attendance->clock_in_at,
            $attendance->clock_out_at,
            $attendance->work_date->format('Y-m-d'),
            $breakStartTime,
            $breakEndTime,
            $attendance->user->break_minutes ?? $defaultBreakMinutes,
        );
    }

    /**
     * 完了済み AttendanceBreak コレクションから合計分を返す。
     * 1件以上あれば整数、なければ null。
     *
     * @param  Collection  $breaks  AttendanceBreak のコレクション
     */
    public static function sumCompletedBreaks(Collection $breaks): ?int
    {
        $completed = $breaks->filter(fn ($b) => $b->ended_at !== null);
        if ($completed->isEmpty()) {
            return null;
        }

        return $completed->sum(function ($b) {
            return (int) $b->started_at->diffInMinutes($b->ended_at);
        });
    }

    /**
     * 手動上書き値と Carbon を直接渡して控除分を返す。
     * DashboardController など eager-load なしのループ内で使用。
     *
     * @param  int|null    $manualBreakMinutes  attendance.break_minutes（null なら時間帯計算）
     * @param  Collection  $breaks              AttendanceBreak コレクション（空でも可）
     */
    public static function resolveWithLimit(
        ?int $manualBreakMinutes,
        Carbon $clockIn,
        Carbon $clockOut,
        string $workDate,
        string $breakStartTime,
        string $breakEndTime,
        int $userBreakLimit,
        ?Collection $breaks = null,
    ): int {
        // 1. 休憩ボタン（完了済みレコード）が最優先
        if ($breaks !== null) {
            $fromButtons = self::sumCompletedBreaks($breaks);
            if ($fromButtons !== null) {
                return $fromButtons;
            }
        }

        // 2. 手入力値
        if ($manualBreakMinutes !== null) {
            return $manualBreakMinutes;
        }

        return self::calcFromTimes($clockIn, $clockOut, $workDate, $breakStartTime, $breakEndTime, $userBreakLimit);
    }

    /**
     * Carbon インスタンスを直接渡して規定時間帯との重なりを計算するバリアント。
     */
    public static function calcFromTimes(
        Carbon $clockIn,
        Carbon $clockOut,
        string $workDate,
        string $breakStartTime,
        string $breakEndTime,
        int $userBreakLimit,
    ): int {
        $breakStart = Carbon::parse("{$workDate} {$breakStartTime}");
        $breakEnd   = Carbon::parse("{$workDate} {$breakEndTime}");

        // 退勤が規定休憩開始以前 → 重なりなし
        if ($clockOut->lte($breakStart)) {
            return 0;
        }

        // 出勤が規定休憩終了以降 → 重なりなし
        if ($clockIn->gte($breakEnd)) {
            return 0;
        }

        // 重なり区間
        $overlapStart   = $clockIn->gt($breakStart) ? $clockIn : $breakStart;
        $overlapEnd     = $clockOut->lt($breakEnd) ? $clockOut : $breakEnd;
        $overlapMinutes = (int) $overlapStart->diffInMinutes($overlapEnd);

        return min($overlapMinutes, $userBreakLimit);
    }
}
