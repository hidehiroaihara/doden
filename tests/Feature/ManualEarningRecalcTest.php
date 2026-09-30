<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BusinessLocation;
use App\Models\DeductionItemMaster;
use App\Models\EmployeePayroll;
use App\Models\InsuranceRate;
use App\Models\InsuranceRateSet;
use App\Models\PayItemMaster;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\User;
use App\Services\Payroll\IncomeTaxCalculator;
use App\Services\Payroll\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 給与計算画面で手入力した支給を、その従業員だけの再計算で控除へ反映する。
 */
class ManualEarningRecalcTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): Admin
    {
        return Admin::create([
            'name' => '管理者',
            'email' => 'admin@example.com',
            'password' => 'password',
            'role' => 1,
        ]);
    }

    private function seedMasters(): BusinessLocation
    {
        foreach ([['base_salary', '基本給'], ['position_allowance', '役職手当']] as $i => [$code, $name]) {
            PayItemMaster::create([
                'pay_type' => 'monthly',
                'code' => $code,
                'name' => $name,
                'category' => 'basic',
                'is_active' => true,
                'calc_method' => 'employee',
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
                'sort_order' => $i,
            ]);
        }

        DeductionItemMaster::create(['code' => 'employment_insurance', 'name' => '雇用保険', 'category' => 'labor_insurance', 'is_active' => true, 'sort_order' => 1]);
        DeductionItemMaster::create(['code' => 'income_tax', 'name' => '所得税', 'category' => 'tax', 'is_active' => true, 'sort_order' => 2]);

        $location = BusinessLocation::create([
            'name' => '本社',
            'health_insurance_type' => 'kyokai',
            'is_main' => true,
        ]);
        $set = InsuranceRateSet::create([
            'business_location_id' => $location->id,
            'name' => '料率',
            'effective_from' => '2025-04-01',
        ]);
        InsuranceRate::create([
            'insurance_rate_set_id' => $set->id,
            'kind' => 'employment',
            'employee_rate' => 5,
            'employer_rate' => 8.5,
        ]);

        return $location;
    }

    /**
     * @return array{0: User, 1: PayrollRun, 2: Payslip}
     */
    private function calculateEmployee(BusinessLocation $location): array
    {
        $user = User::factory()->create(['is_active' => true]);
        EmployeePayroll::create([
            'user_id' => $user->id,
            'business_location_id' => $location->id,
            'employee_no' => 'E100',
            'pay_type' => 'monthly',
            'base_salary' => 364990,
            'tax_table' => 'kou',
            'dependents_count' => 0,
            'is_social_insurance_enrolled' => false,
            'is_employment_insurance_enrolled' => true,
        ]);

        $run = PayrollRun::create([
            'business_location_id' => $location->id,
            'period_key' => '2026-09',
            'pay_type' => 'monthly',
            'payment_date' => '2026-09-25',
            'status' => 'calculated',
        ]);

        $payslip = app(PayrollCalculator::class)->calculate($run, $user->fresh('employeePayroll'));

        return [$user, $run, $payslip];
    }

    public function test_manual_earning_updates_employment_and_income_tax_on_recalculate(): void
    {
        $location = $this->seedMasters();
        [$user, $run, $payslip] = $this->calculateEmployee($location);

        $this->assertSame(364990, (int) $payslip->total_earnings);
        $this->assertSame(1825, (int) $payslip->items->firstWhere('code', 'employment_insurance')->amount);

        $position = $payslip->items->firstWhere('code', 'position_allowance');
        $this->assertSame(0, (int) $position->amount);

        $this->actingAs($this->superAdmin(), 'admin')
            ->post(route('admin.payroll.runs.payslips.recalculate', [
                'run' => $run->id,
                'payslip' => $payslip->id,
            ]), [
                'items' => [
                    ['id' => $position->id, 'amount' => 160000],
                ],
            ])
            ->assertRedirect();

        $payslip->refresh()->load('items');
        $this->assertTrue((bool) $payslip->items->firstWhere('code', 'position_allowance')->is_manual_override);
        $this->assertSame(160000, (int) $payslip->items->firstWhere('code', 'position_allowance')->amount);
        $this->assertSame(524990, (int) $payslip->total_earnings);

        $employment = (int) $payslip->items->firstWhere('code', 'employment_insurance')->amount;
        $this->assertSame(2625, $employment);

        $taxable = 524990 - $employment;
        $expectedTax = app(IncomeTaxCalculator::class)->monthly($taxable, 0, 'kou', '2026-09-25');
        $this->assertSame($expectedTax, (int) $payslip->items->firstWhere('code', 'income_tax')->amount);
    }

    public function test_recalculate_does_not_touch_other_employees(): void
    {
        $location = $this->seedMasters();
        [, $run, $first] = $this->calculateEmployee($location);

        $other = User::factory()->create(['is_active' => true]);
        EmployeePayroll::create([
            'user_id' => $other->id,
            'business_location_id' => $location->id,
            'employee_no' => 'E200',
            'pay_type' => 'monthly',
            'base_salary' => 200000,
            'tax_table' => 'kou',
            'is_employment_insurance_enrolled' => true,
        ]);
        $otherSlip = app(PayrollCalculator::class)->calculate($run, $other->fresh('employeePayroll'));
        $otherBefore = $otherSlip->fresh();

        $position = $first->items->firstWhere('code', 'position_allowance');
        $this->actingAs($this->superAdmin(), 'admin')
            ->post(route('admin.payroll.runs.payslips.recalculate', [
                'run' => $run->id,
                'payslip' => $first->id,
            ]), [
                'items' => [
                    ['id' => $position->id, 'amount' => 160000],
                ],
            ])
            ->assertRedirect();

        $otherSlip->refresh();
        $this->assertSame((int) $otherBefore->total_earnings, (int) $otherSlip->total_earnings);
        $this->assertSame((int) $otherBefore->total_deductions, (int) $otherSlip->total_deductions);
        $this->assertSame(
            (string) $otherBefore->calculated_at,
            (string) $otherSlip->calculated_at,
        );
    }

    public function test_finalized_run_cannot_recalculate_one_employee(): void
    {
        $location = $this->seedMasters();
        [, $run, $payslip] = $this->calculateEmployee($location);
        $run->update(['status' => 'finalized', 'finalized_at' => now()]);

        $this->actingAs($this->superAdmin(), 'admin')
            ->post(route('admin.payroll.runs.payslips.recalculate', [
                'run' => $run->id,
                'payslip' => $payslip->id,
            ]))
            ->assertRedirect();

        $this->assertSame(0, PayslipItem::where('payslip_id', $payslip->id)->where('is_manual_override', true)->count());
    }
}
