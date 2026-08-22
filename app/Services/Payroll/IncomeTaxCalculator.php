<?php

namespace App\Services\Payroll;

use App\Models\IncomeTaxBracket;

/**
 * 源泉所得税(月額)の計算。
 *
 * 係数は income_tax_brackets（適用期間つきマスタ）から取得する。
 * マスタ未投入の期間は内蔵の既定係数（電算機特例の近似）へフォールバックする。
 * これにより年度改定は「新しい適用開始日の行を追加」するだけで反映でき、
 * 過去分の再計算でも当時の係数が参照される（明細スナップショットと併用）。
 *
 * 計算の入力（設計書10 控除項目「所得税」）:
 *   課税対象 = (所得税の計算対象 − 社会保険料合計)
 * 甲欄は扶養親族等の数で控除段階が変わる。乙欄は扶養を考慮しない。
 */
class IncomeTaxCalculator
{
    /** 直近の計算で使用したマスタ識別子（明細スナップショット用） */
    public string $lastSource = 'builtin';

    /** 扶養親族等1人あたりの月額控除(甲欄・内蔵既定) */
    private const DEPENDENT_DEDUCTION = 31667;

    /** 甲欄 内蔵既定ブラケット: [上限(この額未満), 税率, 速算控除額] */
    private const KOU_BRACKETS = [
        [88000, 0.0, 0],
        [162500, 0.05, 4400],
        [275000, 0.10, 12525],
        [579000, 0.20, 40025],
        [750000, 0.23, 57395],
        [1500000, 0.33, 132395],
        [PHP_INT_MAX, 0.40, 237395],
    ];

    /** 乙欄 内蔵既定 */
    private const OTSU_BRACKETS = [
        [88000, 0.03, 0],
        [740000, 0.30, 20000],
        [PHP_INT_MAX, 0.40, 94000],
    ];

    /**
     * @param  int          $socialInsuranceDeductedAmount  社会保険料等控除後の課税支給額(円)
     * @param  int          $dependents                     扶養親族等の数
     * @param  string       $taxTable                       'kou'(甲) | 'otsu'(乙)
     * @param  string|null  $effectiveDate                  適用日(Y-m-d)。指定時はマスタを参照
     */
    public function monthly(int $socialInsuranceDeductedAmount, int $dependents = 0, string $taxTable = 'kou', ?string $effectiveDate = null): int
    {
        $amount = max(0, $socialInsuranceDeductedAmount);

        // マスタ参照（適用日指定時）
        if ($effectiveDate) {
            $rows = IncomeTaxBracket::forDate($taxTable, $effectiveDate);
            if ($rows->isNotEmpty()) {
                $this->lastSource = 'table:' . $rows->first()->effective_from->toDateString();

                $depDeduction = (int) ($rows->firstWhere('dependent_deduction', '!=', null)?->dependent_deduction ?? self::DEPENDENT_DEDUCTION);
                $taxable = $taxTable === 'otsu' ? $amount : max(0, $amount - $depDeduction * $dependents);

                foreach ($rows as $row) {
                    $upper = $row->max_amount;
                    if ($upper === null || $taxable < $upper) {
                        return max(0, (int) floor($taxable * (float) $row->rate - (int) $row->deduction));
                    }
                }

                return 0;
            }
        }

        // フォールバック（内蔵既定）
        $this->lastSource = 'builtin';
        if ($taxTable === 'otsu') {
            return $this->applyBrackets($amount, self::OTSU_BRACKETS);
        }
        $taxable = max(0, $amount - self::DEPENDENT_DEDUCTION * $dependents);

        return $this->applyBrackets($taxable, self::KOU_BRACKETS);
    }

    /**
     * @param  array<int, array{0:int,1:float,2:int}>  $brackets
     */
    private function applyBrackets(int $amount, array $brackets): int
    {
        foreach ($brackets as [$upper, $rate, $deduction]) {
            if ($amount < $upper) {
                return max(0, (int) floor($amount * $rate - $deduction));
            }
        }

        return 0;
    }
}
