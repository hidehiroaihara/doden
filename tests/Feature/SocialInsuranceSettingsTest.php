<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BusinessLocation;
use App\Models\DeductionItemMaster;
use App\Models\EmployeePayroll;
use App\Models\InsuranceRate;
use App\Models\InsuranceRateSet;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialInsuranceSettingsTest extends TestCase
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

    private function locationWithRateSet(string $healthType = 'kyokai'): BusinessLocation
    {
        $location = BusinessLocation::create([
            'name' => '本社',
            'health_insurance_type' => $healthType,
            'is_main' => true,
        ]);

        $set = InsuranceRateSet::create([
            'business_location_id' => $location->id,
            'name' => '2026年度',
            'effective_from' => '2026-04-01',
        ]);

        foreach (['health', 'nursing', 'child_support', 'pension', 'child_contribution', 'pension_fund'] as $kind) {
            InsuranceRate::create([
                'insurance_rate_set_id' => $set->id,
                'kind' => $kind,
                'employee_rate' => 0,
                'employer_rate' => 0,
            ]);
        }

        return $location->fresh();
    }

    public function test_pension_section_updates_meta_and_rates(): void
    {
        $admin = $this->superAdmin();
        $location = $this->locationWithRateSet();

        $response = $this->actingAs($admin, 'admin')
            ->put(route('admin.payroll.settings.locations.social-insurance', $location), [
                'section' => 'pension',
                'pension_jurisdiction' => '東京',
                'pension_office_number' => '12345',
                'pension_office_symbol' => 'ABC',
                'rates' => [
                    'pension' => ['employee_rate' => 91.5, 'employer_rate' => 91.5],
                    'child_contribution' => ['employee_rate' => 0, 'employer_rate' => 3.6],
                ],
            ]);

        $response->assertRedirect();

        $location->refresh();
        $this->assertSame('東京', $location->pension_jurisdiction);
        $this->assertSame('12345', $location->pension_office_number);
        $this->assertSame('ABC', $location->pension_office_symbol);

        $set = $location->insuranceRateSets()->first();
        $this->assertSame('91.50000', (string) $set->rate('pension')->employee_rate);
        $this->assertSame('3.60000', (string) $set->rate('child_contribution')->employer_rate);
    }

    public function test_pension_fund_can_be_created_with_salary_and_bonus_rates(): void
    {
        $admin = $this->superAdmin();
        $location = $this->locationWithRateSet();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.payroll.settings.pension-funds.store'), [
                'business_location_id' => $location->id,
                'name' => 'テスト基金',
                'number' => '999',
                'office_number' => '777',
                'rates' => [
                    ['effective_from' => '2026-04-01', 'salary_employee_rate' => 5, 'salary_employer_rate' => 6, 'bonus_employee_rate' => 3, 'bonus_employer_rate' => 4],
                ],
            ])->assertRedirect();

        $fund = \App\Models\PensionFund::where('business_location_id', $location->id)->first();
        $this->assertNotNull($fund);
        $this->assertSame('テスト基金', $fund->name);
        $this->assertSame('999', $fund->number);

        $rate = $fund->rates()->first();
        $this->assertSame('5.00000', (string) $rate->salary_employee_rate);
        $this->assertSame('6.00000', (string) $rate->salary_employer_rate);
        $this->assertSame('3.00000', (string) $rate->bonus_employee_rate);
        $this->assertSame('4.00000', (string) $rate->bonus_employer_rate);
    }

    public function test_multiple_pension_funds_can_be_registered(): void
    {
        $admin = $this->superAdmin();
        $location = $this->locationWithRateSet();

        foreach (['基金A', '基金B'] as $name) {
            $this->actingAs($admin, 'admin')
                ->post(route('admin.payroll.settings.pension-funds.store'), [
                    'business_location_id' => $location->id,
                    'name' => $name,
                    'rates' => [
                        ['effective_from' => '2026-04-01', 'salary_employee_rate' => 1, 'salary_employer_rate' => 1, 'bonus_employee_rate' => 1, 'bonus_employer_rate' => 1],
                    ],
                ])->assertRedirect();
        }

        $this->assertSame(2, \App\Models\PensionFund::where('business_location_id', $location->id)->count());
    }

    public function test_section_updates_only_its_own_kinds(): void
    {
        $admin = $this->superAdmin();
        $location = $this->locationWithRateSet();

        // 厚生年金セクションから pension_fund の料率を送っても更新されないこと
        $this->actingAs($admin, 'admin')
            ->put(route('admin.payroll.settings.locations.social-insurance', $location), [
                'section' => 'pension',
                'rates' => [
                    'pension' => ['employee_rate' => 91.5, 'employer_rate' => 91.5],
                    'pension_fund' => ['employee_rate' => 5, 'employer_rate' => 5],
                ],
            ])->assertRedirect();

        $set = $location->insuranceRateSets()->first();
        $this->assertSame('91.50000', (string) $set->rate('pension')->employee_rate);
        $this->assertSame('0.00000', (string) $set->rate('pension_fund')->employee_rate);
    }

    public function test_invalid_section_is_rejected(): void
    {
        $admin = $this->superAdmin();
        $location = $this->locationWithRateSet();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.payroll.settings.locations.social-insurance', $location), [
                'section' => 'unknown',
            ])->assertSessionHasErrors('section');
    }

    public function test_pension_fund_is_auto_calculated_from_standard_reward(): void
    {
        $user = User::factory()->create();
        $location = $this->locationWithRateSet();

        // 厚生年金基金 給与掛金料率（従業員負担）を 5.0/1,000 に設定
        $fund = \App\Models\PensionFund::create([
            'business_location_id' => $location->id,
            'name' => 'テスト基金',
        ]);
        $fund->rates()->create([
            'effective_from' => '2026-04-01',
            'salary_employee_rate' => 5.0,
            'salary_employer_rate' => 5.0,
            'bonus_employee_rate' => 0,
            'bonus_employer_rate' => 0,
        ]);

        $employee = EmployeePayroll::create([
            'user_id' => $user->id,
            'business_location_id' => $location->id,
            'employee_no' => 'E001',
            'is_social_insurance_enrolled' => true,
            'standard_reward_pension' => 300000,
            'standard_reward_health' => 300000,
        ]);

        // 厚生年金基金掛金の控除マスタのみ有効化（他の控除計算を避ける）
        DeductionItemMaster::create([
            'code' => 'pension_fund',
            'name' => '厚生年金基金掛金',
            'category' => 'pension',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $run = PayrollRun::create([
            'business_location_id' => $location->id,
            'period_key' => '2026-05',
            'pay_type' => 'monthly',
            'payment_date' => '2026-05-25',
            'status' => 'draft',
        ]);

        $calculator = app(PayrollCalculator::class);
        $method = new \ReflectionMethod($calculator, 'buildDeductions');
        $method->setAccessible(true);
        [$deductions] = $method->invoke($calculator, $employee->fresh(), [], $run, $user, '2026-05-25');

        $fund = collect($deductions)->firstWhere('code', 'pension_fund');
        $this->assertNotNull($fund);
        // 300,000 × 5.0 / 1,000 = 1,500 円
        $this->assertSame(1500, $fund['amount']);
    }
}
