<?php

namespace App\Services\Payroll;

use App\Models\BonusTaxRateBracket;

/**
 * 賞与に対する源泉徴収税額の算出率。
 *
 * 率は bonus_tax_rate_brackets（適用期間つきマスタ）から取得する。
 * マスタ未投入の期間は内蔵の既定率へフォールバックする。
 * 「前月の社会保険料等控除後の給与等の金額」と「扶養親族等の数」から率(%)を引き、
 * 賞与(社保控除後) × 率 で税額を求める（甲欄）。
 */
class BonusTaxCalculator
{
    /** 直近の計算で使用したマスタ識別子（明細スナップショット用） */
    public string $lastSource = 'builtin';

    /** 甲欄 内蔵既定率ブラケット（下限: 前月社保控除後給与, rate: %） */
    private const KOU_BRACKETS = [
        ['floor' => 0,        'rate' => 0.0],
        ['floor' => 68_000,   'rate' => 2.042],
        ['floor' => 79_000,   'rate' => 4.084],
        ['floor' => 252_000,  'rate' => 6.126],
        ['floor' => 300_000,  'rate' => 8.168],
        ['floor' => 334_000,  'rate' => 10.210],
        ['floor' => 363_000,  'rate' => 12.252],
        ['floor' => 395_000,  'rate' => 14.294],
        ['floor' => 426_000,  'rate' => 16.336],
        ['floor' => 550_000,  'rate' => 18.378],
        ['floor' => 647_000,  'rate' => 20.420],
        ['floor' => 850_000,  'rate' => 22.462],
        ['floor' => 1_200_000,'rate' => 24.504],
        ['floor' => 1_700_000,'rate' => 26.546],
        ['floor' => 2_170_000,'rate' => 28.588],
        ['floor' => 2_210_000,'rate' => 30.630],
        ['floor' => 2_250_000,'rate' => 32.672],
        ['floor' => 3_550_000,'rate' => 35.735],
        ['floor' => 6_690_000,'rate' => 40.840],
    ];

    /** 扶養1人あたり前月給与の閾値シフト額（内蔵既定） */
    private const DEPENDENT_SHIFT = 45_000;

    /**
     * @param  int          $bonusAfterInsurance  賞与 − 社会保険料等
     * @param  int          $previousMonthTaxable 前月の社保控除後給与
     * @param  int          $dependents           扶養親族等の数
     * @param  string       $taxTable             kou|otsu
     * @param  string|null  $effectiveDate        適用日(Y-m-d)。指定時はマスタを参照
     */
    public function tax(int $bonusAfterInsurance, int $previousMonthTaxable, int $dependents, string $taxTable, ?string $effectiveDate = null): int
    {
        if ($bonusAfterInsurance <= 0) {
            return 0;
        }

        $rate = $this->resolveRate($previousMonthTaxable, $bonusAfterInsurance, $dependents, $taxTable, $effectiveDate);

        return (int) floor($bonusAfterInsurance * $rate / 100);
    }

    private function resolveRate(int $prevTaxable, int $bonusAfterInsurance, int $dependents, string $taxTable, ?string $effectiveDate): float
    {
        // マスタ参照（適用日指定時）
        if ($effectiveDate) {
            $rows = BonusTaxRateBracket::forDate($taxTable, $effectiveDate);
            if ($rows->isNotEmpty()) {
                $this->lastSource = 'table:' . $rows->first()->effective_from->toDateString();
                $shift = (int) ($rows->firstWhere('dependent_shift', '!=', null)?->dependent_shift ?? self::DEPENDENT_SHIFT);
                $key = $taxTable === 'otsu' ? $bonusAfterInsurance : max(0, $prevTaxable - $dependents * $shift);

                $rate = 0.0;
                foreach ($rows as $row) {
                    if ($key >= (int) $row->min_prev_taxable) {
                        $rate = (float) $row->rate;
                    } else {
                        break;
                    }
                }

                return $rate;
            }
        }

        // フォールバック（内蔵既定）
        $this->lastSource = 'builtin';

        return $taxTable === 'otsu'
            ? $this->otsuRate($bonusAfterInsurance)
            : $this->kouRate($prevTaxable, $dependents);
    }

    private function kouRate(int $previousMonthTaxable, int $dependents): float
    {
        $adjusted = max(0, $previousMonthTaxable - $dependents * self::DEPENDENT_SHIFT);

        $rate = 0.0;
        foreach (self::KOU_BRACKETS as $bracket) {
            if ($adjusted >= $bracket['floor']) {
                $rate = $bracket['rate'];
            } else {
                break;
            }
        }

        return $rate;
    }

    private function otsuRate(int $bonusAfterInsurance): float
    {
        return match (true) {
            $bonusAfterInsurance <= 94_000 => 6.126,
            $bonusAfterInsurance <= 243_000 => 18.378,
            default => 30.630,
        };
    }
}
