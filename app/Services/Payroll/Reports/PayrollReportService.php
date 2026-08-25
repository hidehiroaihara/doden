<?php

namespace App\Services\Payroll\Reports;

use App\Models\Payslip;
use App\Models\PayrollRun;
use Illuminate\Support\Collection;

/**
 * 帳票（賃金台帳／源泉徴収簿／所得税徴収高計算書／源泉徴収票 等）の共通集計。
 *
 * 帳票はいずれも給与計算確定データ（payslips / payslip_items）からの派生ビューであり、
 * 専用テーブルは持たず本サービスでピボット・年次集計を行う。
 *
 * 参照: 資料/設計書 18/21/26/30/23/25
 */
class PayrollReportService
{
    /** 社会保険料等（源泉徴収簿「社会保険料等の控除額」に含む控除コード） */
    public const SOCIAL_INSURANCE_CODES = [
        'health_insurance',
        'nursing_insurance',
        'pension_insurance',
        'employment_insurance',
        'children_contribution',
    ];

    public const INCOME_TAX_CODE = 'income_tax';

    public const RESIDENT_TAX_CODE = 'resident_tax';

    /**
     * 指定年・区分の給与/賞与バッチ（period_key の年で判定）。
     *
     * @return Collection<int, PayrollRun>
     */
    public function runsForYear(int $year, ?int $locationId = null, ?string $payType = null): Collection
    {
        return PayrollRun::query()
            ->whereBetween('period_key', ["{$year}-01", "{$year}-12"])
            ->when($locationId, fn ($q) => $q->where('business_location_id', $locationId))
            ->when($payType, fn ($q) => $q->where('pay_type', $payType))
            ->orderBy('period_key')
            ->get();
    }

    /**
     * 1明細を帳票用の集計行へ整形する。
     *
     * @return array{
     *   gross:int, social_insurance:int, income_tax:int, resident_tax:int,
     *   after_social:int, other_deductions:int, total_deductions:int, net:int
     * }
     */
    public function summarize(Payslip $payslip): array
    {
        $payslip->loadMissing('items');

        $gross = (int) $payslip->total_earnings;
        $social = 0;
        $incomeTax = 0;
        $residentTax = 0;

        foreach ($payslip->items->where('item_type', 'deduction') as $item) {
            $amount = (int) $item->amount;
            if (in_array($item->code, self::SOCIAL_INSURANCE_CODES, true)) {
                $social += $amount;
            } elseif ($item->code === self::INCOME_TAX_CODE) {
                $incomeTax += $amount;
            } elseif ($item->code === self::RESIDENT_TAX_CODE) {
                $residentTax += $amount;
            }
        }

        $totalDeductions = (int) $payslip->total_deductions;

        return [
            'gross' => $gross,
            'social_insurance' => $social,
            'income_tax' => $incomeTax,
            'resident_tax' => $residentTax,
            'after_social' => $gross - $social,
            'other_deductions' => max(0, $totalDeductions - $social - $incomeTax - $residentTax),
            'total_deductions' => $totalDeductions,
            'net' => (int) $payslip->net_pay,
        ];
    }

    /**
     * ある年の従業員1人分の給与/賞与明細を period_key キーで取得。
     *
     * @return array{salary: Collection<string, Payslip>, bonus: Collection<string, Payslip>}
     */
    public function employeeYearlyPayslips(int $userId, int $year, ?int $locationId = null): array
    {
        $payslips = Payslip::query()
            ->where('user_id', $userId)
            ->whereHas('payrollRun', function ($q) use ($year, $locationId) {
                $q->whereBetween('period_key', ["{$year}-01", "{$year}-12"]);
                if ($locationId) {
                    $q->where('business_location_id', $locationId);
                }
            })
            ->with(['items', 'payrollRun:id,period_key,pay_type,payment_date'])
            ->get();

        return [
            'salary' => $payslips->filter(fn ($p) => $p->payrollRun?->pay_type !== 'bonus')
                ->keyBy(fn ($p) => $p->payrollRun->period_key),
            'bonus' => $payslips->filter(fn ($p) => $p->payrollRun?->pay_type === 'bonus')
                ->keyBy(fn ($p) => $p->payrollRun->period_key),
        ];
    }

    /**
     * 年内の給与・賞与明細を「支払月（1〜12）」ごとにまとめる。
     * 賞与はその支払月の列へ給与と合算する（賃金台帳の列は支払月単位）。
     *
     * @return array<int, Collection<int, Payslip>> 月(1..12) => その月に属する明細の集合
     */
    public function employeePayslipsByMonth(int $userId, int $year, ?int $locationId = null): array
    {
        $data = $this->employeeYearlyPayslips($userId, $year, $locationId);

        $byMonth = [];
        for ($m = 1; $m <= 12; $m++) {
            $byMonth[$m] = collect();
        }

        foreach (['salary', 'bonus'] as $kind) {
            foreach ($data[$kind] as $periodKey => $payslip) {
                $month = (int) substr((string) $periodKey, 5, 2);
                if ($month >= 1 && $month <= 12) {
                    $byMonth[$month]->push($payslip);
                }
            }
        }

        return $byMonth;
    }

    /**
     * 指定 period_key ごとに給与・賞与明細をまとめる。
     *
     * @param  array<int, string>  $periodKeys  'Y-m' の配列
     * @return array<string, Collection<int, Payslip>>
     */
    public function employeePayslipsByPeriodKeys(int $userId, array $periodKeys, ?int $locationId = null): array
    {
        $periodKeys = array_values(array_unique($periodKeys));
        $byKey = [];
        foreach ($periodKeys as $key) {
            $byKey[$key] = collect();
        }

        if ($periodKeys === []) {
            return $byKey;
        }

        $payslips = Payslip::query()
            ->where('user_id', $userId)
            ->whereHas('payrollRun', function ($q) use ($periodKeys, $locationId) {
                $q->whereIn('period_key', $periodKeys);
                if ($locationId) {
                    $q->where('business_location_id', $locationId);
                }
            })
            ->with(['items', 'payrollRun:id,period_key,pay_type,payment_date'])
            ->get();

        foreach ($payslips as $payslip) {
            $key = $payslip->payrollRun?->period_key;
            if ($key && isset($byKey[$key])) {
                $byKey[$key]->push($payslip);
            }
        }

        return $byKey;
    }
}
