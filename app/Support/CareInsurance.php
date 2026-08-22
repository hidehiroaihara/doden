<?php

namespace App\Support;

use App\Models\EmployeePayroll;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * 介護保険（第2号被保険者）該当判定。
 *
 * 原則: 満40歳に達した日（＝40歳の誕生日の前日）の属する月から、
 *       満65歳に達した日（＝65歳の誕生日の前日）の属する月の前月まで徴収対象。
 * 生年月日から自動判定し、従業員情報の care_insurance_override(null=自動/1=対象/0=対象外)で上書きできる。
 */
class CareInsurance
{
    /**
     * 対象期間(period_key 'Y-m')において介護保険料の徴収対象か。
     */
    public static function isTarget(?User $user, EmployeePayroll $employee, string $periodKey): bool
    {
        // 手動上書き優先
        $override = $employee->care_insurance_override;
        if ($override !== null) {
            return (bool) $override;
        }

        $birth = $user?->birth_date;
        if (! $birth) {
            // 生年月日未登録時は従来フラグにフォールバック
            return (bool) $employee->is_care_insurance_target;
        }

        $birth = Carbon::parse($birth);
        // 「到達日＝誕生日の前日」の属する月
        $start = $birth->copy()->addYears(40)->subDay()->startOfMonth();   // 徴収開始月
        $end = $birth->copy()->addYears(65)->subDay()->startOfMonth();     // 65歳到達月（この月から対象外）

        $period = Carbon::parse($periodKey . '-01')->startOfMonth();

        return $period->greaterThanOrEqualTo($start) && $period->lessThan($end);
    }
}
