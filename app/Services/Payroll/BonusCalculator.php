<?php

namespace App\Services\Payroll;

use App\Models\BonusInput;
use App\Models\EmployeePayroll;
use App\Models\InsuranceRateSet;
use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 賞与計算エンジン。
 *
 * 賞与は勤怠から自動算出できないため、担当者が入力した総支給額(bonus_inputs)を起点に
 * 標準賞与額 → 社会保険 → 雇用保険 → 賞与源泉徴収 → 差引支給 を算出して payslip へ確定する。
 * 確定済みバッチは再計算しない。
 *
 * 参照: 資料/設計書 04_給与計算（賞与計算）/ 12_社会保険
 */
class BonusCalculator
{
    // 標準賞与額の上限（健保: 年度累計573万 / 厚年: 1回あたり150万）
    private const HEALTH_ANNUAL_CAP = 5_730_000;
    private const PENSION_PER_BONUS_CAP = 1_500_000;

    public function __construct(
        private BonusTaxCalculator $bonusTax,
        private FlatTaxReductionService $flatTax,
    ) {}

    /** バッチ内の対象従業員（賞与額が入力済みの在籍者）を全計算。 */
    public function calculateRun(PayrollRun $run): void
    {
        $inputs = BonusInput::where('payroll_run_id', $run->id)->get()->keyBy('user_id');
        if ($inputs->isEmpty()) {
            return;
        }

        $users = User::whereIn('id', $inputs->keys())->where('is_active', true)->get();
        foreach ($users as $user) {
            $this->calculate($run, $user, $inputs->get($user->id));
        }
    }

    public function calculate(PayrollRun $run, User $user, ?BonusInput $input = null): Payslip
    {
        if ($run->isFinalized()) {
            return Payslip::firstOrCreate(['payroll_run_id' => $run->id, 'user_id' => $user->id]);
        }

        $input ??= BonusInput::firstOrNew(['payroll_run_id' => $run->id, 'user_id' => $user->id]);
        $employee = $user->employeePayroll;
        if (! $employee) {
            throw new \RuntimeException("従業員給与情報(employee_payroll)が未登録です: user_id={$user->id}");
        }

        $gross = (int) $input->gross_amount;
        $standardBonus = intdiv($gross, 1000) * 1000; // 標準賞与額（1000円未満切捨）

        $effectiveDate = ($run->payment_date ?? Carbon::parse($run->period_key . '-01')->endOfMonth())->toDateString();
        $rateSet = $employee->businessLocation?->rateSetForDate($effectiveDate);

        [$deductions, $flatTaxApplied, $snapshot] = $this->buildDeductions($employee, $user, $run, $rateSet, $gross, $standardBonus, (int) $input->previous_month_taxable, $effectiveDate);

        $totalDeductions = array_sum(array_map(fn ($d) => $d['amount'], $deductions));
        $netPay = $gross - $totalDeductions;

        return DB::transaction(function () use ($run, $user, $gross, $deductions, $totalDeductions, $netPay, $flatTaxApplied, $snapshot) {
            $payslip = Payslip::updateOrCreate(
                ['payroll_run_id' => $run->id, 'user_id' => $user->id],
                array_merge([
                    'total_earnings' => $gross,
                    'total_deductions' => $totalDeductions,
                    'net_pay' => $netPay,
                    'calculated_at' => now(),
                ], $snapshot),
            );

            $payslip->items()->where('is_manual_override', false)->delete();

            $sort = 0;
            // 支給（賞与）
            $this->createItem($payslip, 'earning', 'bonus', '賞与', 'basic', $gross, $sort++);
            // 控除
            foreach ($deductions as $d) {
                $this->createItem($payslip, 'deduction', $d['code'], $d['name'], $d['category'], $d['amount'], $sort++);
            }
            // 定額減税の当月控除額を記録（総控除額・差引支給には非影響の情報行）
            if ($flatTaxApplied > 0) {
                $this->createItem(
                    $payslip,
                    FlatTaxReductionService::ITEM_TYPE,
                    FlatTaxReductionService::ITEM_CODE,
                    FlatTaxReductionService::ITEM_NAME,
                    'tax',
                    $flatTaxApplied,
                    $sort++,
                );
            }

            return $payslip->load('items');
        });
    }

    /**
     * @return array{0: array<int, array{code: string, name: string, category: string, amount: int}>, 1: int}  [控除行, 定額減税適用額]
     */
    private function buildDeductions(EmployeePayroll $employee, User $user, PayrollRun $run, ?InsuranceRateSet $rateSet, int $gross, int $standardBonus, int $prevMonthTaxable, string $effectiveDate): array
    {
        $rows = [];
        $socialTotal = 0;

        $careTarget = \App\Support\CareInsurance::isTarget($user, $employee, $run->period_key);

        $healthBase = min($standardBonus, self::HEALTH_ANNUAL_CAP);
        $pensionBase = min($standardBonus, self::PENSION_PER_BONUS_CAP);

        if ($employee->is_social_insurance_enrolled) {
            $health = $this->insurance($rateSet, 'health', $healthBase);
            $rows[] = ['code' => 'health_insurance', 'name' => '健康保険', 'category' => 'insurance', 'amount' => $health];
            $socialTotal += $health;

            if ($careTarget) {
                $nursing = $this->insurance($rateSet, 'nursing', $healthBase);
                $rows[] = ['code' => 'nursing_insurance', 'name' => '介護保険', 'category' => 'insurance', 'amount' => $nursing];
                $socialTotal += $nursing;
            }

            $pension = $this->insurance($rateSet, 'pension', $pensionBase);
            $rows[] = ['code' => 'pension_insurance', 'name' => '厚生年金', 'category' => 'insurance', 'amount' => $pension];
            $socialTotal += $pension;

            // 厚生年金基金掛金（賞与料率・全基金合算）。料率未設定なら行を追加しない。
            $fund = $this->pensionFundEmployee($employee, $effectiveDate, $pensionBase);
            if ($fund > 0) {
                $rows[] = ['code' => 'pension_fund', 'name' => '厚生年金基金掛金', 'category' => 'insurance', 'amount' => $fund];
                $socialTotal += $fund;
            }
        }

        if ($employee->is_employment_insurance_enrolled) {
            $employment = $this->insurance($rateSet, 'employment', $gross);
            $rows[] = ['code' => 'employment_insurance', 'name' => '雇用保険', 'category' => 'insurance', 'amount' => $employment];
            $socialTotal += $employment;
        }

        $grossTax = $this->bonusTax->tax(
            max(0, $gross - $socialTotal),
            $prevMonthTaxable,
            (int) $employee->dependents_count,
            $employee->tax_table,
            $effectiveDate,
        );
        $flatTaxApplied = $this->flatTax->monthlyReduction($employee, $user, $run, $grossTax);
        $rows[] = ['code' => 'income_tax', 'name' => '所得税', 'category' => 'tax', 'amount' => max(0, $grossTax - $flatTaxApplied)];

        $snapshot = [
            'insurance_rate_set_id' => $rateSet?->id,
            'applied_rates' => $rateSet ? [
                'health' => (float) ($rateSet->rate('health')?->employee_rate ?? 0),
                'nursing' => (float) ($rateSet->rate('nursing')?->employee_rate ?? 0),
                'pension' => (float) ($rateSet->rate('pension')?->employee_rate ?? 0),
                'employment' => (float) ($rateSet->rate('employment')?->employee_rate ?? 0),
            ] : null,
            'snapshot_standard_reward_health' => (int) ($employee->standard_reward_health ?? 0) ?: null,
            'snapshot_standard_reward_pension' => (int) ($employee->standard_reward_pension ?? 0) ?: null,
            'snapshot_grade_health' => $employee->standard_reward_grade_health,
            'snapshot_grade_pension' => $employee->standard_reward_grade_pension,
            'snapshot_tax_table' => $employee->tax_table,
            'snapshot_dependents_count' => (int) $employee->dependents_count,
            'income_tax_source' => $this->bonusTax->lastSource,
        ];

        return [$rows, $flatTaxApplied, $snapshot];
    }

    /**
     * 厚生年金基金掛金（賞与・従業員負担）を全基金合算で算出する。
     */
    private function pensionFundEmployee(EmployeePayroll $employee, string $effectiveDate, int $base): int
    {
        if ($base <= 0) {
            return 0;
        }

        $funds = $employee->businessLocation?->pensionFunds()->with('rates')->get() ?? collect();
        $rate = \App\Models\PensionFund::totalRates($funds, $effectiveDate, 'bonus')['employee'];
        if ($rate <= 0) {
            return 0;
        }

        return (int) round($base * $rate / 1000);
    }

    private function insurance(?InsuranceRateSet $rateSet, string $kind, int $base): int
    {
        $rate = $rateSet?->rate($kind);
        if (! $rate || $base <= 0) {
            return 0;
        }

        // 料率は千分率(/1,000)で保持
        return (int) round($base * (float) $rate->employee_rate / 1000);
    }

    private function createItem(Payslip $payslip, string $type, string $code, string $name, string $category, int $amount, int $sort): void
    {
        $exists = $payslip->items()
            ->where('item_type', $type)->where('code', $code)
            ->where('is_manual_override', true)->exists();
        if ($exists) {
            return;
        }

        $payslip->items()->create([
            'item_type' => $type,
            'code' => $code,
            'name' => $name,
            'category' => $category,
            'amount' => $amount,
            'is_manual_override' => false,
            'sort_order' => $sort,
        ]);
    }
}
