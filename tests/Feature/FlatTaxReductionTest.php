<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\EmployeePayroll;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\TaxMeasure;
use App\Models\User;
use App\Services\Payroll\FlatTaxReductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 定額減税（所得税）の総額算定と従業員別の手動上書きに関するテスト。
 */
class FlatTaxReductionTest extends TestCase
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

    private function measure(): TaxMeasure
    {
        return TaxMeasure::create([
            'type' => TaxMeasure::TYPE_FLAT_TAX,
            'name' => '令和6年 定額減税',
            'target_year' => 2024,
            'start_period' => '2024-06',
            'end_period' => '2024-12',
            'per_person_amount' => 30000,
            'is_active' => true,
        ]);
    }

    public function test_total_reduction_uses_dependents_when_not_overridden(): void
    {
        $measure = $this->measure();
        $user = User::factory()->create();
        $employee = EmployeePayroll::create([
            'user_id' => $user->id,
            'employee_no' => 'E001',
            'tax_table' => 'kou',
            'dependents_count' => 2,
            'flat_tax_reduction_total' => null,
        ]);

        // 本人 + 扶養2 = 3人 × 30,000 = 90,000
        $service = new FlatTaxReductionService();
        $this->assertSame(90000, $service->totalReduction($employee, $measure));
    }

    public function test_manual_total_overrides_automatic_calculation(): void
    {
        $measure = $this->measure();
        $user = User::factory()->create();
        $employee = EmployeePayroll::create([
            'user_id' => $user->id,
            'employee_no' => 'E002',
            'tax_table' => 'kou',
            'dependents_count' => 2,
            'flat_tax_reduction_total' => 50000,
        ]);

        $service = new FlatTaxReductionService();
        // 手動総額が優先される（自動なら 90,000 だが 50,000 を返す）
        $this->assertSame(50000, $service->totalReduction($employee, $measure));
        // 自動算出額は従来どおり扶養数ベース
        $this->assertSame(90000, $service->autoTotalReduction($employee, $measure));
    }

    public function test_monthly_reduction_uses_manual_total_as_remaining_cap(): void
    {
        $measure = $this->measure();
        $user = User::factory()->create();
        $employee = EmployeePayroll::create([
            'user_id' => $user->id,
            'employee_no' => 'E004',
            'tax_table' => 'kou',
            'dependents_count' => 2,
            'flat_tax_reduction_total' => 50000,
        ]);

        $priorRun = PayrollRun::create([
            'period_key' => '2024-06',
            'pay_type' => 'monthly',
            'payment_date' => '2024-06-25',
            'status' => 'draft',
        ]);
        $priorPayslip = Payslip::create([
            'payroll_run_id' => $priorRun->id,
            'user_id' => $user->id,
        ]);
        PayslipItem::create([
            'payslip_id' => $priorPayslip->id,
            'item_type' => FlatTaxReductionService::ITEM_TYPE,
            'code' => FlatTaxReductionService::ITEM_CODE,
            'name' => FlatTaxReductionService::ITEM_NAME,
            'amount' => -40000,
            'sort_order' => 1,
        ]);

        $run = PayrollRun::create([
            'period_key' => '2024-07',
            'pay_type' => 'monthly',
            'payment_date' => '2024-07-25',
            'status' => 'draft',
        ]);

        $service = new FlatTaxReductionService();
        // 手動総額 50,000 − 控除済 40,000 = 残 10,000。所得税 15,000 なら 10,000 が上限。
        $this->assertSame(10000, $service->monthlyReduction($employee, $user, $run, 15000));

        // 自動（90,000）なら残 50,000 のため所得税 15,000 全額が控除対象。
        $employee->update(['flat_tax_reduction_total' => null]);
        $this->assertSame(15000, $service->monthlyReduction($employee->fresh(), $user, $run, 15000));
    }

    public function test_deduction_items_section_persists_manual_and_auto(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create();
        EmployeePayroll::create(['user_id' => $user->id, 'employee_no' => 'E003']);

        // 手動総額を保存
        $this->actingAs($admin, 'admin')
            ->put(route('admin.users.section', ['user' => $user->id, 'section' => 'deduction_items']), [
                'flat_tax_reduction_total' => 60000,
            ])->assertRedirect();
        $this->assertSame(60000, (int) $user->employeePayroll->fresh()->flat_tax_reduction_total);

        // null（自動計算）へ戻す
        $this->actingAs($admin, 'admin')
            ->put(route('admin.users.section', ['user' => $user->id, 'section' => 'deduction_items']), [
                'flat_tax_reduction_total' => null,
            ])->assertRedirect();
        $this->assertNull($user->employeePayroll->fresh()->flat_tax_reduction_total);
    }
}
