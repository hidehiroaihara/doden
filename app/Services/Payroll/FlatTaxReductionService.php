<?php

namespace App\Services\Payroll;

use App\Models\EmployeePayroll;
use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Models\TaxMeasure;
use App\Models\User;

/**
 * 定額減税（所得税）の月次減税額を算出するサービス。
 *
 * 適用可否・金額・適用期間は税制措置マスタ（tax_measures / type=flat_tax_reduction）から取得する。
 * 対象バッチ（支給月が適用期間内）に対して、対象者ごとの減税総額
 * （本人＋同一生計配偶者・扶養親族：1人あたりマスタ設定額）を各支給時の所得税額を上限に消し込む。
 * 乙欄・非居住者（税額表区分が甲以外）は対象外。
 *
 * 実際の控除は所得税額を直接減額する形で反映し（各帳票が「控除後の税額」を参照）、
 * 別途 item_type=info の code=flat_tax_reduction 行に当月控除額を記録する
 * （各人別控除事績簿・源泉徴収簿の月次控除額参照用。総控除額・差引支給には影響しない）。
 *
 * 参照: 資料/設計書 28_定額減税
 */
class FlatTaxReductionService
{
    /** 減税実績を記録する明細行のコード／名称／種別 */
    public const ITEM_CODE = 'flat_tax_reduction';
    public const ITEM_NAME = '定額減税（所得税）';
    public const ITEM_TYPE = 'info';

    /** 当該バッチに適用される税制措置（定額減税）を取得。なければ null。 */
    public function measureForRun(PayrollRun $run): ?TaxMeasure
    {
        return TaxMeasure::query()
            ->where('type', TaxMeasure::TYPE_FLAT_TAX)
            ->where('is_active', true)
            ->where('start_period', '<=', $run->period_key)
            ->where(function ($q) use ($run) {
                $q->whereNull('end_period')->orWhere('end_period', '>=', $run->period_key);
            })
            ->orderByDesc('start_period')
            ->first();
    }

    /** 指定年に対象となる税制措置（定額減税）を取得。帳票（各人別控除事績簿）用。 */
    public function measureForYear(int $year): ?TaxMeasure
    {
        return TaxMeasure::query()
            ->where('type', TaxMeasure::TYPE_FLAT_TAX)
            ->where('is_active', true)
            ->where('target_year', $year)
            ->orderByDesc('start_period')
            ->first();
    }

    /**
     * 減税対象人数（本人＋同一生計配偶者・扶養親族）。
     * 税額表区分が甲欄以外（乙・丙）は非居住者等とみなし対象外（0人）。
     */
    public function targetCount(EmployeePayroll $employee): int
    {
        if (($employee->tax_table ?? 'kou') !== 'kou') {
            return 0;
        }

        return 1 + (int) ($employee->dependents_count ?? 0);
    }

    /** 減税総額（＝対象人数 × マスタの1人あたり控除額）。 */
    public function totalReduction(EmployeePayroll $employee, ?TaxMeasure $measure): int
    {
        return $this->targetCount($employee) * (int) ($measure?->per_person_amount ?? 0);
    }

    /**
     * 当月に適用する定額減税額（残額と当月所得税の小さい方）を算出する。
     *
     * @param  int  $grossIncomeTax  定額減税を適用する前の当月所得税額
     */
    public function monthlyReduction(EmployeePayroll $employee, User $user, PayrollRun $run, int $grossIncomeTax): int
    {
        if ($grossIncomeTax <= 0) {
            return 0;
        }

        $measure = $this->measureForRun($run);
        if (! $measure) {
            return 0;
        }

        $total = $this->totalReduction($employee, $measure);
        if ($total <= 0) {
            return 0;
        }

        $remaining = max(0, $total - $this->appliedBefore($user->id, $run, $measure));

        return min($remaining, $grossIncomeTax);
    }

    /**
     * 当該バッチより前に控除済みの定額減税額の累計。
     * period_key が前月以前、または同月でも id が小さいバッチ（＝先に処理された給与・賞与）を対象とする。
     */
    public function appliedBefore(int $userId, PayrollRun $run, TaxMeasure $measure): int
    {
        $year = $measure->target_year;

        return (int) Payslip::query()
            ->where('user_id', $userId)
            ->whereHas('payrollRun', function ($r) use ($run, $year) {
                $r->whereBetween('period_key', ["{$year}-01", "{$year}-12"])
                    ->where(function ($w) use ($run) {
                        $w->where('period_key', '<', $run->period_key)
                            ->orWhere(function ($x) use ($run) {
                                $x->where('period_key', $run->period_key)->where('id', '<', $run->id);
                            });
                    });
            })
            ->with('items')
            ->get()
            ->sum(fn (Payslip $p) => $p->items
                ->where('code', self::ITEM_CODE)
                ->sum(fn ($i) => abs((int) $i->amount)));
    }
}
