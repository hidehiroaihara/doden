<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BusinessLocation;
use App\Models\DeductionItemMaster;
use App\Models\EmployeePayroll;
use App\Models\EmployeeResidentTax;
use App\Models\EmployeeStandardReward;
use App\Models\InsuranceRate;
use App\Models\InsuranceRateSet;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MF準拠の従業員給与情報（住民税 月別 / 社会保険料 手入力 / 標準報酬月額 履歴）に関する計算・保存テスト。
 */
class EmployeeInsurancePayrollTest extends TestCase
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

    private function locationWithHealthRate(float $healthEmployeeRate): BusinessLocation
    {
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

        foreach (['health', 'nursing', 'pension', 'child_contribution'] as $kind) {
            InsuranceRate::create([
                'insurance_rate_set_id' => $set->id,
                'kind' => $kind,
                'employee_rate' => $kind === 'health' ? $healthEmployeeRate : 0,
                'employer_rate' => $kind === 'health' ? $healthEmployeeRate : 0,
            ]);
        }

        return $location->fresh();
    }

    private function runDeductions(EmployeePayroll $employee, User $user, string $periodKey): array
    {
        $run = PayrollRun::create([
            'business_location_id' => $employee->business_location_id,
            'period_key' => $periodKey,
            'pay_type' => 'monthly',
            'payment_date' => $periodKey.'-25',
            'status' => 'draft',
        ]);

        $calculator = app(PayrollCalculator::class);
        $method = new \ReflectionMethod($calculator, 'buildDeductions');
        $method->setAccessible(true);

        [$deductions] = $method->invoke($calculator, $employee->fresh(), [], $run, $user->fresh(), $periodKey.'-25');

        return $deductions;
    }

    public function test_resident_tax_uses_month_specific_amount(): void
    {
        DeductionItemMaster::create(['code' => 'resident_tax', 'name' => '住民税', 'category' => 'tax', 'is_active' => true, 'sort_order' => 1]);

        $user = User::factory()->create();
        $employee = EmployeePayroll::create([
            'user_id' => $user->id,
            'employee_no' => 'E001',
            'resident_tax_monthly' => 9000,
            'resident_tax_june' => 12000,
        ]);

        // 2026年度（2026-06〜2027-05）の7月分を 8,500 円に設定
        EmployeeResidentTax::create(['user_id' => $user->id, 'fiscal_year' => 2026, 'month' => 7, 'amount' => 8500]);

        $deductions = $this->runDeductions($employee, $user, '2026-07');
        $residentTax = collect($deductions)->firstWhere('code', 'resident_tax');
        $this->assertSame(8500, $residentTax['amount']);
    }

    public function test_resident_tax_falls_back_to_legacy_columns_when_no_month_row(): void
    {
        DeductionItemMaster::create(['code' => 'resident_tax', 'name' => '住民税', 'category' => 'tax', 'is_active' => true, 'sort_order' => 1]);

        $user = User::factory()->create();
        $employee = EmployeePayroll::create([
            'user_id' => $user->id,
            'employee_no' => 'E002',
            'resident_tax_monthly' => 9000,
            'resident_tax_june' => 12000,
        ]);

        // 6月は6月分、その他は毎月分へフォールバック
        $june = collect($this->runDeductions($employee, $user, '2026-06'))->firstWhere('code', 'resident_tax');
        $july = collect($this->runDeductions($employee, $user, '2026-07'))->firstWhere('code', 'resident_tax');

        $this->assertSame(12000, $june['amount']);
        $this->assertSame(9000, $july['amount']);
    }

    public function test_manual_premium_override_is_used_instead_of_rate_table(): void
    {
        DeductionItemMaster::create(['code' => 'health_insurance', 'name' => '健康保険', 'category' => 'social_insurance', 'is_active' => true, 'sort_order' => 1]);

        $user = User::factory()->create();
        $location = $this->locationWithHealthRate(50.0); // 額表なら 200,000 × 50/1,000 = 10,000

        $employee = EmployeePayroll::create([
            'user_id' => $user->id,
            'business_location_id' => $location->id,
            'employee_no' => 'E003',
            'is_social_insurance_enrolled' => true,
            'standard_reward_health' => 200000,
            'health_premium_mode' => 'manual',
            'health_premium_employee' => 7777,
        ]);

        $deductions = $this->runDeductions($employee, $user, '2026-05');
        $health = collect($deductions)->firstWhere('code', 'health_insurance');
        $this->assertSame(7777, $health['amount']);
    }

    public function test_standard_reward_history_overrides_flat_value(): void
    {
        DeductionItemMaster::create(['code' => 'health_insurance', 'name' => '健康保険', 'category' => 'social_insurance', 'is_active' => true, 'sort_order' => 1]);

        $user = User::factory()->create();
        $location = $this->locationWithHealthRate(50.0); // 50/1,000

        $employee = EmployeePayroll::create([
            'user_id' => $user->id,
            'business_location_id' => $location->id,
            'employee_no' => 'E004',
            'is_social_insurance_enrolled' => true,
            'standard_reward_health' => 200000, // 額表 flat なら 10,000
        ]);

        // 2026-04 以降は 300,000 に改定（→ 300,000 × 50/1,000 = 15,000）
        EmployeeStandardReward::create([
            'user_id' => $user->id,
            'applied_from' => '2026-04-01',
            'health_amount' => 300000,
        ]);

        $may = collect($this->runDeductions($employee, $user, '2026-05'))->firstWhere('code', 'health_insurance');
        $this->assertSame(15000, $may['amount']);

        // 適用開始前（2026-03）は flat 値へフォールバック
        $march = collect($this->runDeductions($employee, $user, '2026-03'))->firstWhere('code', 'health_insurance');
        $this->assertSame(10000, $march['amount']);
    }

    public function test_insurance_section_persists_qualification_and_premium_fields(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create();
        EmployeePayroll::create(['user_id' => $user->id, 'employee_no' => 'E005']);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.users.section', ['user' => $user->id, 'section' => 'insurance']), [
                'is_short_time_worker' => true,
                'is_miner' => false,
                'health_qualified_at' => '2026-04-01',
                'health_insured_number' => '12345',
                'basic_pension_number' => '9999-000000',
                'accident_employee_type' => 'regular',
                'health_premium_mode' => 'manual',
                'health_premium_employee' => 8000,
                'health_premium_employer' => 8000,
                'nursing_premium_mode' => 'table',
                'child_premium_mode' => 'table',
                'pension_premium_mode' => 'table',
            ])->assertRedirect();

        $employee = $user->employeePayroll->fresh();
        $this->assertTrue((bool) $employee->is_short_time_worker);
        $this->assertSame('12345', $employee->health_insured_number);
        $this->assertSame('9999-000000', $employee->basic_pension_number);
        $this->assertSame('manual', $employee->health_premium_mode);
        $this->assertSame(8000, $employee->health_premium_employee);
    }

    public function test_insurance_section_persists_child_premium_employer_manual(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create();
        EmployeePayroll::create(['user_id' => $user->id, 'employee_no' => 'E009']);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.users.section', ['user' => $user->id, 'section' => 'insurance']), [
                'is_short_time_worker' => false,
                'is_miner' => false,
                'accident_employee_type' => 'regular',
                'health_premium_mode' => 'table',
                'nursing_premium_mode' => 'table',
                'child_premium_mode' => 'manual',
                'child_premium_employee' => 567,
                'child_premium_employer' => 1234,
                'pension_premium_mode' => 'table',
            ])->assertRedirect();

        $employee = $user->employeePayroll->fresh();
        $this->assertSame('manual', $employee->child_premium_mode);
        $this->assertSame(567, $employee->child_premium_employee);
        $this->assertSame(1234, $employee->child_premium_employer);
    }

    public function test_resident_tax_months_section_persists_year_schedule(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create();
        EmployeePayroll::create(['user_id' => $user->id, 'employee_no' => 'E006']);

        $months = [];
        foreach ([6, 7, 8, 9, 10, 11, 12, 1, 2, 3, 4, 5] as $m) {
            $months[] = ['month' => $m, 'amount' => $m === 6 ? 12000 : 9000];
        }

        $this->actingAs($admin, 'admin')
            ->put(route('admin.users.section', ['user' => $user->id, 'section' => 'resident_tax_months']), [
                'fiscal_year' => 2026,
                'months' => $months,
            ])->assertRedirect();

        $this->assertSame(12, EmployeeResidentTax::where('user_id', $user->id)->where('fiscal_year', 2026)->count());
        $this->assertSame(12000, (int) EmployeeResidentTax::where('user_id', $user->id)->where('fiscal_year', 2026)->where('month', 6)->value('amount'));
    }

    public function test_standard_rewards_section_replaces_history(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create();
        EmployeePayroll::create(['user_id' => $user->id, 'employee_no' => 'E007']);

        EmployeeStandardReward::create(['user_id' => $user->id, 'applied_from' => '2025-04-01', 'health_amount' => 200000]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.users.section', ['user' => $user->id, 'section' => 'standard_rewards']), [
                'rewards' => [
                    ['applied_from' => '2026-04-01', 'health_grade' => 22, 'health_amount' => 300000, 'pension_grade' => 19, 'pension_amount' => 300000],
                ],
            ])->assertRedirect();

        $rows = EmployeeStandardReward::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('2026-04-01', $rows->first()->applied_from->toDateString());
        $this->assertSame(300000, $rows->first()->health_amount);
    }

    public function test_resident_tax_section_persists_prefecture_and_municipality(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create();
        EmployeePayroll::create(['user_id' => $user->id, 'employee_no' => 'E008']);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.users.section', ['user' => $user->id, 'section' => 'resident_tax']), [
                'report_prefecture' => '埼玉県',
                'report_municipality' => 'さいたま市',
                'resident_tax_prefecture' => '埼玉県',
                'resident_tax_municipality' => '川口市',
                'resident_tax_reference_number' => 'A-123',
                'resident_tax_recipient_number' => 'R-999',
            ])->assertRedirect();

        $payroll = EmployeePayroll::where('user_id', $user->id)->first();
        $this->assertSame('埼玉県', $payroll->report_prefecture);
        $this->assertSame('さいたま市', $payroll->report_municipality);
        $this->assertSame('埼玉県', $payroll->resident_tax_prefecture);
        $this->assertSame('川口市', $payroll->resident_tax_municipality);
        $this->assertSame('A-123', $payroll->resident_tax_reference_number);
        $this->assertSame('R-999', $payroll->resident_tax_recipient_number);

        // 納付先/提出先の市区町村がマスタへ都道府県付きで同期される。
        $this->assertDatabaseHas('resident_tax_municipalities', ['name' => '川口市', 'prefecture' => '埼玉県']);
        $this->assertDatabaseHas('resident_tax_municipalities', ['name' => 'さいたま市', 'prefecture' => '埼玉県']);
    }
}
