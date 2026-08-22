<?php

namespace App\Services\Payroll;

/**
 * 年末調整の税額計算（純粋計算ロジック）。
 *
 * 年間の給与総額・源泉徴収税額・社会保険料等を入力に、給与所得控除・各種所得控除を差し引いて
 * 課税給与所得金額 → 算出所得税額 → 住宅借入金等特別控除 → 年調年税額（復興特別所得税込・100円未満切捨）
 * を求め、徴収済み所得税との差額（過不足税額）を算出する。
 *
 * ※各控除額・税率は令和6年分以降の一般的な速算表に基づく簡易実装。特殊な控除区分（同居老親・
 *   特定扶養・障害者控除等）は対象外で、必要に応じて申告控除額（other_deduction 等）で補正する運用とする。
 *
 * 参照: 資料/設計書 30_源泉徴収簿（年末調整の反映先）
 */
class YearEndAdjustmentCalculator
{
    /** 基礎控除（合計所得2,400万円以下・簡易） */
    public const BASIC_DEDUCTION = 480000;

    /** 扶養控除（一般・1人あたり） */
    public const DEPENDENT_DEDUCTION = 380000;

    /** 配偶者控除（一般・簡易） */
    public const SPOUSE_DEDUCTION = 380000;

    /**
     * 給与所得控除後の給与等の金額（令和2年分以降）。
     */
    public function salaryIncome(int $gross): int
    {
        if ($gross <= 0) {
            return 0;
        }
        if ($gross < 1_625_001) {
            $deduction = 550_000;
        } elseif ($gross < 1_800_001) {
            $deduction = (int) floor($gross * 0.40) - 100_000;
        } elseif ($gross < 3_600_001) {
            $deduction = (int) floor($gross * 0.30) + 80_000;
        } elseif ($gross < 6_600_001) {
            $deduction = (int) floor($gross * 0.20) + 440_000;
        } elseif ($gross < 8_500_001) {
            $deduction = (int) floor($gross * 0.10) + 1_100_000;
        } else {
            $deduction = 1_950_000;
        }

        return max(0, $gross - $deduction);
    }

    /**
     * 算出所得税額（課税給与所得金額に対する速算表）。
     */
    public function calculatedTax(int $taxableIncome): int
    {
        $t = max(0, $taxableIncome);
        $brackets = [
            [1_950_000, 0.05, 0],
            [3_300_000, 0.10, 97_500],
            [6_950_000, 0.20, 427_500],
            [9_000_000, 0.23, 636_000],
            [18_000_000, 0.33, 1_536_000],
            [40_000_000, 0.40, 2_796_000],
            [PHP_INT_MAX, 0.45, 4_796_000],
        ];
        foreach ($brackets as [$upper, $rate, $deduction]) {
            if ($t <= $upper) {
                return max(0, (int) floor($t * $rate - $deduction));
            }
        }

        return 0;
    }

    /**
     * 年調結果を算出する。
     *
     * @param  array<string,int>  $in  gross, withheld_tax, social_insurance_withheld,
     *   social_insurance_declared, life_insurance_deduction, earthquake_insurance_deduction,
     *   spouse_deduction, dependent_count, housing_loan_credit, other_deduction
     * @return array<string,int>
     */
    public function compute(array $in): array
    {
        $gross = max(0, (int) ($in['gross'] ?? 0));
        $withheld = max(0, (int) ($in['withheld_tax'] ?? 0));

        $salaryIncome = $this->salaryIncome($gross);

        $dependentDeduction = max(0, (int) ($in['dependent_count'] ?? 0)) * self::DEPENDENT_DEDUCTION;

        $deductions =
            max(0, (int) ($in['social_insurance_withheld'] ?? 0))
            + max(0, (int) ($in['social_insurance_declared'] ?? 0))
            + max(0, (int) ($in['life_insurance_deduction'] ?? 0))
            + max(0, (int) ($in['earthquake_insurance_deduction'] ?? 0))
            + max(0, (int) ($in['spouse_deduction'] ?? 0))
            + $dependentDeduction
            + self::BASIC_DEDUCTION
            + max(0, (int) ($in['other_deduction'] ?? 0));

        // 課税給与所得金額（1,000円未満切捨）
        $taxableIncome = (int) (floor(max(0, $salaryIncome - $deductions) / 1000) * 1000);

        $calculatedTax = $this->calculatedTax($taxableIncome);

        // 住宅借入金等特別控除（税額控除）
        $housingCredit = min($calculatedTax, max(0, (int) ($in['housing_loan_credit'] ?? 0)));
        $taxAfterCredit = max(0, $calculatedTax - $housingCredit);

        // 年調年税額（復興特別所得税2.1%込・100円未満切捨）
        $yearlyTax = (int) (floor($taxAfterCredit * 1.021 / 100) * 100);

        // 過不足税額（＋：不足＝追徴 / −：超過＝還付）
        $adjustment = $yearlyTax - $withheld;

        return [
            'gross' => $gross,
            'salary_income' => $salaryIncome,
            'dependent_deduction' => $dependentDeduction,
            'income_deductions_total' => $deductions,
            'taxable_income' => $taxableIncome,
            'calculated_tax' => $calculatedTax,
            'housing_loan_credit_applied' => $housingCredit,
            'yearly_tax' => $yearlyTax,
            'withheld_tax' => $withheld,
            'adjustment_amount' => $adjustment,
        ];
    }
}
