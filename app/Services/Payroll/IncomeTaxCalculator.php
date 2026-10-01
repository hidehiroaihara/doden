<?php

namespace App\Services\Payroll;

use App\Models\IncomeTaxBracket;
use App\Models\IncomeTaxMonthlyTable;
use App\Models\Setting;

/**
 * 源泉所得税(月額)の計算。
 *
 * 方式は基本設定＞全般の「源泉徴収税額の計算方法」で切り替える。
 *  - monthly_table（既定・MFクラウド準拠）: 国税庁「給与所得の源泉徴収税額表（月額表）」から
 *    社会保険料等控除後の給与等の階級 × 扶養親族等の数で税額を引く（復興特別所得税込み）。
 *    表は income_tax_monthly_tables / income_tax_monthly_rows に年分ごとに登録する。
 *  - computer_special: 電子計算機による計算の特例の係数（income_tax_brackets）で式計算する。
 *
 * どちらも適用日で年分を解決するため、過去分の再計算でも当時の表・係数が参照される
 * （明細スナップショット income_tax_source と併用）。
 *
 * 計算の入力（設計書10 控除項目「所得税」）:
 *   課税対象 = (所得税の計算対象 − 社会保険料合計)
 * 甲欄は扶養親族等の数で税額が変わる。乙欄は扶養を考慮しない。
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
     * @param  int  $socialInsuranceDeductedAmount  社会保険料等控除後の課税支給額(円)
     * @param  int  $dependents  扶養親族等の数
     * @param  string  $taxTable  'kou'(甲) | 'otsu'(乙)
     * @param  string|null  $effectiveDate  適用日(Y-m-d)。指定時はマスタを参照
     * @param  string|null  $calcMethod  'monthly_table' | 'computer_special'。null なら基本設定に従う
     */
    public function monthly(
        int $socialInsuranceDeductedAmount,
        int $dependents = 0,
        string $taxTable = 'kou',
        ?string $effectiveDate = null,
        ?string $calcMethod = null,
    ): int {
        $amount = max(0, $socialInsuranceDeductedAmount);
        $method = $calcMethod ?? Setting::getValue('income_tax_calc_method', 'monthly_table');

        // 月額表（既定）。該当年分が未登録なら電算機特例へフォールバックする。
        if ($method !== 'computer_special' && $effectiveDate) {
            $tax = $this->fromMonthlyTable($amount, $dependents, $taxTable, $effectiveDate);
            if ($tax !== null) {
                return $tax;
            }
        }

        return $this->fromBrackets($amount, $dependents, $taxTable, $effectiveDate);
    }

    /** 指定日の月額表で税額を引く。表・該当階級が無ければ null。 */
    private function fromMonthlyTable(int $amount, int $dependents, string $taxTable, string $effectiveDate): ?int
    {
        $table = IncomeTaxMonthlyTable::forDate($effectiveDate);
        if (! $table) {
            return null;
        }

        $table->loadMissing('rows');
        $tax = $table->taxFor($amount, $dependents, $taxTable);
        if ($tax === null) {
            return null;
        }

        $this->lastSource = 'monthly_table:'.$table->effective_from->toDateString();

        return $tax;
    }

    /** 電子計算機による計算の特例（係数マスタ→内蔵既定）。 */
    private function fromBrackets(int $amount, int $dependents, string $taxTable, ?string $effectiveDate): int
    {
        if ($effectiveDate) {
            $rows = IncomeTaxBracket::forDate($taxTable, $effectiveDate);
            if ($rows->isNotEmpty()) {
                $this->lastSource = 'table:'.$rows->first()->effective_from->toDateString();

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
