<?php

namespace App\Services\Payroll\Reports;

use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Support\PayrollRunDates;
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
        'child_contribution',
    ];

    public const INCOME_TAX_CODE = 'income_tax';

    public const RESIDENT_TAX_CODE = 'resident_tax';

    /**
     * 支給日の年で年次集計する際の period_key の検索範囲（前年1月〜当年12月）。
     * 前年12月締め・当年1月支給のように、締め月と支給年がずれるバッチを含めるため広めに取る。
     *
     * @return array{0: string, 1: string}
     */
    public static function periodKeyWindow(int $year): array
    {
        return [($year - 1) . '-01', "{$year}-12"];
    }

    /** 支給日（未設定なら締め日から推定）の年が指定年か。所得税の年分は支給日で決まる。 */
    public static function isPaidInYear(?PayrollRun $run, int $year): bool
    {
        if (! $run) {
            return false;
        }
        [, $payment] = PayrollRunDates::resolve($run);

        return $payment !== null && $payment->year === $year;
    }

    /**
     * 指定年に支給した給与/賞与バッチ。
     *
     * @return Collection<int, PayrollRun>
     */
    public function runsForYear(int $year, ?int $locationId = null, ?string $payType = null): Collection
    {
        return PayrollRun::query()
            ->whereBetween('period_key', self::periodKeyWindow($year))
            ->when($locationId, fn ($q) => $q->where('business_location_id', $locationId))
            ->when($payType, fn ($q) => $q->where('pay_type', $payType))
            ->orderBy('period_key')
            ->get()
            ->filter(fn (PayrollRun $r) => self::isPaidInYear($r, $year))
            ->values();
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
     * ある年（支給日の年）の従業員1人分の給与/賞与明細を取得。
     * 給与は支給年月（Y-m）キー、賞与は period_key キー。
     *
     * @return array{salary: Collection<string, Payslip>, bonus: Collection<string, Payslip>}
     */
    public function employeeYearlyPayslips(int $userId, int $year, ?int $locationId = null): array
    {
        $payslips = Payslip::query()
            ->where('user_id', $userId)
            ->whereHas('payrollRun', function ($q) use ($year, $locationId) {
                $q->whereBetween('period_key', self::periodKeyWindow($year));
                if ($locationId) {
                    $q->where('business_location_id', $locationId);
                }
            })
            ->with(['items', 'payrollRun:id,period_key,pay_type,closing_date,payment_date'])
            ->get()
            ->filter(fn (Payslip $p) => self::isPaidInYear($p->payrollRun, $year))
            ->sortBy(fn (Payslip $p) => $p->payrollRun->period_key);

        return [
            'salary' => $payslips->filter(fn ($p) => $p->payrollRun?->pay_type !== 'bonus')
                ->keyBy(fn ($p) => PayrollRunDates::resolve($p->payrollRun)[1]->format('Y-m')),
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
            foreach ($data[$kind] as $payslip) {
                $byMonth[(int) PayrollRunDates::resolve($payslip->payrollRun)[1]->month]->push($payslip);
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
