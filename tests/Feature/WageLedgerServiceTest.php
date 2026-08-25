<?php

namespace Tests\Feature;

use App\Models\AttendanceItemMaster;
use App\Models\DeductionItemMaster;
use App\Models\EmployeePayroll;
use App\Models\PayItemMaster;
use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\Reports\WageLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WageLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedMasters(): void
    {
        // 勤怠（固定順）
        AttendanceItemMaster::create(['code' => 'work_days_weekday', 'name' => '出勤日数（平日）', 'category' => 'attendance', 'is_active' => true, 'unit_format' => 'day', 'sort_order' => 1]);
        AttendanceItemMaster::create(['code' => 'scheduled_time_weekday', 'name' => '所定時間（平日）', 'category' => 'actual_work', 'is_active' => true, 'unit_format' => 'hour_decimal', 'sort_order' => 2]);
        // 無効項目は行に出さない
        AttendanceItemMaster::create(['code' => 'night_weekday', 'name' => '深夜所定時間（平日）', 'category' => 'actual_work', 'is_active' => false, 'unit_format' => 'hour_decimal', 'sort_order' => 3]);

        // 支給（固定順）
        PayItemMaster::create(['pay_type' => 'monthly', 'code' => 'base_salary', 'name' => '基本給', 'category' => 'basic', 'is_active' => true, 'is_income_tax_target' => true, 'sort_order' => 1]);
        PayItemMaster::create(['pay_type' => 'monthly', 'code' => 'commute_non_taxable', 'name' => '通勤手当/非課', 'category' => 'commute', 'is_active' => true, 'is_income_tax_target' => false, 'sort_order' => 2]);

        // 控除（固定順）
        DeductionItemMaster::create(['code' => 'health_insurance', 'name' => '健康保険', 'category' => 'social_insurance', 'is_active' => true, 'sort_order' => 1]);
        DeductionItemMaster::create(['code' => 'income_tax', 'name' => '所得税', 'category' => 'tax', 'is_active' => true, 'sort_order' => 2]);
    }

    private function makePayslip(int $userId, string $periodKey, string $payType, array $totals, array $items): Payslip
    {
        $run = PayrollRun::create([
            'business_location_id' => null,
            'period_key' => $periodKey,
            'pay_type' => $payType,
            'payment_date' => $periodKey.'-25',
            'status' => 'finalized',
            'finalized_at' => now(),
        ]);

        $payslip = Payslip::create([
            'payroll_run_id' => $run->id,
            'user_id' => $userId,
            'total_earnings' => $totals['earnings'],
            'total_deductions' => $totals['deductions'],
            'net_pay' => $totals['net'],
        ]);

        foreach ($items as $item) {
            $payslip->items()->create($item);
        }

        return $payslip;
    }

    public function test_ledger_uses_fixed_master_row_order_and_merges_bonus(): void
    {
        $this->seedMasters();

        $user = User::factory()->create(['name' => '賃金 太郎']);
        EmployeePayroll::create([
            'user_id' => $user->id,
            'employee_no' => 'E001',
            'pay_type' => 'monthly',
            'tax_table' => 'kou',
            'dependents_count' => 2,
        ]);

        // 3月度の給与
        $this->makePayslip($user->id, '2026-03', 'salary', ['earnings' => 300000, 'deductions' => 50000, 'net' => 250000], [
            ['item_type' => 'attendance', 'code' => 'work_days_weekday', 'name' => '出勤日数（平日）', 'quantity' => 20],
            ['item_type' => 'attendance', 'code' => 'scheduled_time_weekday', 'name' => '所定時間（平日）', 'minutes' => 9600],
            ['item_type' => 'earning', 'code' => 'base_salary', 'name' => '基本給', 'amount' => 280000],
            ['item_type' => 'earning', 'code' => 'commute_non_taxable', 'name' => '通勤手当/非課', 'amount' => 20000],
            ['item_type' => 'deduction', 'code' => 'health_insurance', 'name' => '健康保険', 'amount' => 30000],
            ['item_type' => 'deduction', 'code' => 'income_tax', 'name' => '所得税', 'amount' => 20000],
        ]);

        // 3月度の賞与（同じ支払月へ合算されること）
        $this->makePayslip($user->id, '2026-03', 'bonus', ['earnings' => 100000, 'deductions' => 10000, 'net' => 90000], [
            ['item_type' => 'earning', 'code' => 'base_salary', 'name' => '賞与', 'amount' => 100000],
            ['item_type' => 'deduction', 'code' => 'income_tax', 'name' => '所得税', 'amount' => 10000],
        ]);

        $matrix = app(WageLedgerService::class)->build($user->id, 2026);

        // セクション構成（勤怠・支給・控除・集計系・その他・会社負担）
        $types = array_column($matrix['sections'], 'type');
        $this->assertSame(
            ['attendance', 'earning', 'deduction', 'balance_payment', 'balance_deduction', 'balances', 'other_information', 'group_absorptions'],
            $types,
        );

        // 勤怠は無効項目を除き、マスタの sort_order 固定順
        $attendance = $matrix['sections'][0]['rows'];
        $this->assertSame(['work_days_weekday', 'scheduled_time_weekday'], array_column($attendance, 'code'));
        $this->assertSame(20.0, $attendance[0]['values'][3]);          // 出勤日数
        $this->assertSame(160.0, $attendance[1]['values'][3]);         // 9600分 = 160時間

        // 支給: マスタ固定順、賞与が同月へ合算（基本給 280000 + 賞与 100000）
        $earning = $matrix['sections'][1]['rows'];
        $this->assertSame(['base_salary', 'commute_non_taxable'], array_column($earning, 'code'));
        $this->assertSame(380000, $earning[0]['values'][3]);
        // データの無い月は 0（表示側で空欄）
        $this->assertSame(0, $earning[0]['values'][1]);

        // 集計: 支給合計=400000 / 非課税=20000 / 課税=380000
        $balancePayment = collect($matrix['sections'])->firstWhere('type', 'balance_payment');
        $paymentRows = collect($balancePayment['rows'])->keyBy('name');
        $this->assertSame(400000, $paymentRows['支給合計']['values'][3]);
        $this->assertSame(20000, $paymentRows['非課税支給合計']['values'][3]);
        $this->assertSame(380000, $paymentRows['課税支給合計']['values'][3]);

        $balanceDeduction = collect($matrix['sections'])->firstWhere('type', 'balance_deduction');
        $deductionRows = collect($balanceDeduction['rows'])->keyBy('name');
        $this->assertSame(60000, $deductionRows['控除合計']['values'][3]);

        $other = collect($matrix['sections'])->firstWhere('type', 'other_information');
        $otherRows = collect($other['rows'])->keyBy('name');
        $this->assertSame(2, $otherRows['扶養人数']['values'][3]);
        $this->assertSame('甲', $otherRows['税額表']['values'][3]);

        // 従業員メタ
        $this->assertSame('E001', $matrix['employee']['employee_no']);
        $this->assertSame('甲欄', $matrix['employee']['tax_table_label']);
        $this->assertSame('月給', $matrix['employee']['pay_type_label']);

        // 月列: 3月は has_data=true、1月は false
        $this->assertTrue($matrix['months'][2]['has_data']);
        $this->assertFalse($matrix['months'][0]['has_data']);
    }

    public function test_fiscal_year_period_starts_in_april(): void
    {
        $this->seedMasters();

        $user = User::factory()->create(['name' => '年度 太郎']);
        EmployeePayroll::create([
            'user_id' => $user->id,
            'employee_no' => 'E002',
            'pay_type' => 'monthly',
            'tax_table' => 'kou',
            'dependents_count' => 0,
        ]);

        $this->makePayslip($user->id, '2026-04', 'salary', ['earnings' => 300000, 'deductions' => 30000, 'net' => 270000], [
            ['item_type' => 'earning', 'code' => 'base_salary', 'name' => '基本給', 'amount' => 300000],
            ['item_type' => 'deduction', 'code' => 'health_insurance', 'name' => '健康保険', 'amount' => 30000],
        ]);

        $service = app(WageLedgerService::class);
        $period = $service->resolvePeriod(['period_mode' => 'fiscal', 'fiscal_year' => 2026]);
        $matrix = $service->build($user->id, $period);

        $this->assertSame('fiscal', $matrix['period']['mode']);
        $this->assertSame('1月度', $matrix['months'][0]['label']);
        $this->assertTrue($matrix['months'][0]['has_data']);

        $earning = $matrix['sections'][1]['rows'];
        $baseSalary = collect($earning)->firstWhere('code', 'base_salary');
        $this->assertSame(300000, $baseSalary['values'][1]);
    }

    public function test_manual_period_limits_to_twelve_months(): void
    {
        $service = app(WageLedgerService::class);
        $period = $service->resolvePeriod([
            'period_mode' => 'manual',
            'from' => '2026-01',
            'to' => '2027-06',
        ]);

        $this->assertCount(12, $period['columns']);
        $this->assertSame('2026-01', $period['from']);
        $this->assertSame('2026-12', $period['to']);
    }
}
