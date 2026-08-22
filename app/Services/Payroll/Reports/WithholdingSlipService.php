<?php

namespace App\Services\Payroll\Reports;

use App\Models\User;
use App\Models\YearEndAdjustment;

/**
 * 給与所得の源泉徴収票データの構築。
 *
 * 年末調整（year_end_adjustments）が確定/反映済みの場合は、その結果
 * （支払金額・給与所得控除後の金額・所得控除の額の合計額・年調年税額＝源泉徴収税額・各種控除）を反映する。
 * 未実施の場合は給与計算確定データの年次集計（速報値）で出力する。
 *
 * 参照: 資料/設計書 25_退職者の源泉徴収票 / 30_源泉徴収簿
 */
class WithholdingSlipService
{
    public function __construct(private PayrollReportService $reports) {}

    /** @return array<string, mixed> */
    public function build(User $user, int $year): array
    {
        $user->loadMissing('employeePayroll.businessLocation');
        $ep = $user->employeePayroll;

        $data = $this->reports->employeeYearlyPayslips($user->id, $year);
        $gross = 0;
        $social = 0;
        $withheld = 0;
        foreach ($data['salary']->concat($data['bonus']->values()) as $p) {
            $s = $this->reports->summarize($p);
            $gross += $s['gross'];
            $social += $s['social_insurance'];
            $withheld += $s['income_tax'];
        }

        $yea = YearEndAdjustment::where('user_id', $user->id)->where('year', $year)->first();
        $adjusted = $yea && in_array($yea->status, ['confirmed', 'reflected'], true);

        return [
            'year' => $year,
            'reiwa' => $year - 2018,
            'name' => $user->name,
            'postal_code' => $user->postal_code,
            'address' => $user->address,
            'birth_date' => $user->birth_date?->format('Y年n月j日'),
            'business_location' => $ep?->businessLocation?->name,
            'employee_no' => $ep?->employee_no,

            'adjusted' => $adjusted,
            'status_label' => $yea?->statusLabel(),

            // 金額欄
            'payment' => $adjusted ? (int) $yea->gross_total : $gross,
            'social' => $adjusted ? ((int) $yea->social_insurance_withheld + (int) $yea->social_insurance_declared) : $social,
            'withheld' => $withheld,
            'income_tax' => $adjusted ? (int) $yea->yearly_tax : $withheld,
            'salary_income' => $adjusted ? (int) $yea->salary_income : null,
            'income_deductions_total' => $adjusted ? max(0, (int) $yea->salary_income - (int) $yea->taxable_income) : null,

            // 控除内訳（年調確定時のみ）
            'life_insurance' => $adjusted ? (int) $yea->life_insurance_deduction : 0,
            'earthquake_insurance' => $adjusted ? (int) $yea->earthquake_insurance_deduction : 0,
            'housing_loan_credit' => $adjusted ? (int) $yea->housing_loan_credit : 0,
            'spouse_deduction' => $adjusted ? (int) $yea->spouse_deduction : 0,
            'dependent_count' => $adjusted ? (int) $yea->dependent_count : (int) ($ep?->dependents_count ?? 0),
        ];
    }
}
