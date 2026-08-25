<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AttendanceItemMaster;
use App\Models\DeductionItemMaster;
use App\Models\EmployeePayroll;
use App\Models\PayItemMaster;
use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\Reports\WageLedgerCsvExporter;
use App\Services\Payroll\Reports\WageLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WageLedgerCsvExporterTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, string> $line */
    private function parseCsvLine(string $line): array
    {
        return str_getcsv($line);
    }

    public function test_exporter_matches_mf_csv_rules(): void
    {
        $matrix = [
            'year' => 2026,
            'period' => ['label' => '2026年01月01日 〜 2026年12月31日'],
            'months' => [
                ['month' => 1, 'label' => '1月度', 'period' => '1/1 - 1/31', 'has_data' => false],
                ['month' => 2, 'label' => '2月度', 'period' => '2/1 - 2/28', 'has_data' => true],
            ],
            'employee' => [
                'name' => 'テスト太郎',
                'business_location' => '本社',
                'department' => '営業部',
                'gender_label' => '男性',
            ],
            'sections' => [
                [
                    'type' => 'earning',
                    'title' => '支給',
                    'rows' => [
                        ['name' => '基本給', 'format' => 'yen', 'values' => [1 => 0, 2 => 300000], 'total' => 300000],
                    ],
                ],
                [
                    'type' => 'deduction',
                    'title' => '控除',
                    'rows' => [
                        ['name' => '健康保険', 'format' => 'yen', 'values' => [1 => 0, 2 => 15000], 'total' => 15000],
                    ],
                ],
                [
                    'type' => 'balances',
                    'title' => '差引合計',
                    'rows' => [
                        ['name' => '差引支給合計', 'format' => 'yen', 'values' => [1 => 0, 2 => 285000], 'total' => 285000],
                    ],
                ],
                [
                    'type' => 'other_information',
                    'title' => 'その他',
                    'rows' => [
                        ['name' => '税額表', 'format' => 'text', 'values' => [1 => '', 2 => '甲'], 'total' => ''],
                    ],
                ],
            ],
        ];

        $exporter = new WageLedgerCsvExporter;
        $lines = $exporter->employeeBlockLines($matrix);

        $meta = $this->parseCsvLine($lines[0]);
        $this->assertSame('賃金台帳', $meta[0]);
        $this->assertSame('集計期間', $meta[2]);
        $this->assertSame('2026年01月01日 〜 2026年12月31日', $meta[3]);
        $this->assertSame('事業所', $meta[4]);
        $this->assertSame('本社', $meta[5]);
        $this->assertSame('部門', $meta[6]);
        $this->assertSame('営業部', $meta[7]);
        $this->assertSame('氏名', $meta[8]);
        $this->assertSame('テスト太郎', $meta[9]);
        $this->assertSame('性別', $meta[10]);
        $this->assertSame('男性', $meta[11]);

        $header = $this->parseCsvLine($lines[1]);
        $this->assertSame('項目', $header[0]);
        $this->assertStringNotContainsString('区分', $lines[1]);
        $this->assertSame("1月度\n1/1 - 1/31", $header[1]);
        $this->assertSame("2月度\n2/1 - 2/28", $header[2]);
        $this->assertSame('合計', $header[3]);

        $earning = $this->parseCsvLine($lines[2]);
        $this->assertSame('基本給(支給)', $earning[0]);
        $this->assertSame('', $earning[1]);
        $this->assertSame('300,000', $earning[2]);
        $this->assertSame('300,000', $earning[3]);

        $deduction = $this->parseCsvLine($lines[3]);
        $this->assertSame('健康保険(控除)', $deduction[0]);

        $summary = $this->parseCsvLine($lines[4]);
        $this->assertSame('差引支給合計', $summary[0]);
        $this->assertSame('0', $summary[1]);

        $textRow = $this->parseCsvLine($lines[5]);
        $this->assertSame('税額表', $textRow[0]);
        $this->assertSame('', $textRow[1]);
        $this->assertSame('甲', $textRow[2]);
        $this->assertSame('-', $textRow[3]);

        $encoded = $exporter->encode($lines);
        $this->assertNotFalse(mb_detect_encoding($encoded, 'SJIS-win', true));
    }

    public function test_csv_endpoint_uses_mf_format(): void
    {
        AttendanceItemMaster::create(['code' => 'work_days_weekday', 'name' => '出勤日数（平日）', 'category' => 'attendance', 'is_active' => true, 'unit_format' => 'day', 'sort_order' => 1]);
        PayItemMaster::create(['pay_type' => 'monthly', 'code' => 'base_salary', 'name' => '基本給', 'category' => 'basic', 'is_active' => true, 'is_income_tax_target' => true, 'sort_order' => 1]);
        DeductionItemMaster::create(['code' => 'health_insurance', 'name' => '健康保険', 'category' => 'social_insurance', 'is_active' => true, 'sort_order' => 1]);

        $user = User::factory()->create(['name' => 'CSV 太郎', 'gender' => 'male']);
        EmployeePayroll::create([
            'user_id' => $user->id,
            'employee_no' => 'E100',
            'pay_type' => 'monthly',
            'tax_table' => 'kou',
            'dependents_count' => 0,
        ]);

        $run = PayrollRun::create([
            'business_location_id' => null,
            'period_key' => '2026-03',
            'pay_type' => 'salary',
            'payment_date' => '2026-03-25',
            'status' => 'finalized',
            'finalized_at' => now(),
        ]);
        $payslip = Payslip::create([
            'payroll_run_id' => $run->id,
            'user_id' => $user->id,
            'total_earnings' => 300000,
            'total_deductions' => 30000,
            'net_pay' => 270000,
        ]);
        $payslip->items()->create(['item_type' => 'earning', 'code' => 'base_salary', 'name' => '基本給', 'amount' => 300000]);
        $payslip->items()->create(['item_type' => 'deduction', 'code' => 'health_insurance', 'name' => '健康保険', 'amount' => 30000]);

        $admin = Admin::create([
            'name' => '管理者',
            'email' => 'admin@example.com',
            'password' => 'password',
            'role' => 1,
        ]);
        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.payroll.reports.wage-ledger.csv', ['user' => $user->id, 'year' => 2026]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=Shift_JIS');

        $utf8 = mb_convert_encoding($response->getContent(), 'UTF-8', 'SJIS-win');
        $this->assertStringContainsString('賃金台帳', $utf8);
        $this->assertStringContainsString('CSV 太郎', $utf8);
        $this->assertStringContainsString('基本給(支給)', $utf8);
        $this->assertStringContainsString('健康保険(控除)', $utf8);
        $this->assertStringNotContainsString('"区分"', $utf8);
    }

    public function test_bulk_csv_downloads_on_same_request(): void
    {
        AttendanceItemMaster::create(['code' => 'work_days_weekday', 'name' => '出勤日数（平日）', 'category' => 'attendance', 'is_active' => true, 'unit_format' => 'day', 'sort_order' => 1]);
        PayItemMaster::create(['pay_type' => 'monthly', 'code' => 'base_salary', 'name' => '基本給', 'category' => 'basic', 'is_active' => true, 'is_income_tax_target' => true, 'sort_order' => 1]);

        $user = User::factory()->create(['name' => '一括 太郎']);
        EmployeePayroll::create([
            'user_id' => $user->id,
            'employee_no' => 'E900',
            'pay_type' => 'monthly',
            'tax_table' => 'kou',
            'base_salary' => 280000,
            'dependents_count' => 0,
        ]);

        $run = PayrollRun::create([
            'business_location_id' => null,
            'period_key' => '2026-03',
            'pay_type' => 'salary',
            'payment_date' => '2026-03-25',
            'status' => 'finalized',
            'finalized_at' => now(),
        ]);
        $payslip = Payslip::create([
            'payroll_run_id' => $run->id,
            'user_id' => $user->id,
            'total_earnings' => 280000,
            'total_deductions' => 30000,
            'net_pay' => 250000,
        ]);
        $payslip->items()->create(['item_type' => 'earning', 'code' => 'base_salary', 'name' => '基本給', 'amount' => 280000]);

        $admin = Admin::create([
            'name' => '管理者',
            'email' => 'bulk-admin@example.com',
            'password' => 'password',
            'role' => 1,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.payroll.reports.wage-ledger.bulk-csv', ['year' => 2026]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=Shift_JIS');
        $utf8 = mb_convert_encoding($response->getContent(), 'UTF-8', 'SJIS-win');
        $this->assertStringContainsString('一括 太郎', $utf8);
    }

    public function test_build_includes_gender_label(): void
    {
        AttendanceItemMaster::create(['code' => 'work_days_weekday', 'name' => '出勤日数（平日）', 'category' => 'attendance', 'is_active' => true, 'unit_format' => 'day', 'sort_order' => 1]);

        $user = User::factory()->create(['gender' => 'female']);
        EmployeePayroll::create([
            'user_id' => $user->id,
            'employee_no' => 'E200',
            'pay_type' => 'monthly',
            'tax_table' => 'kou',
            'dependents_count' => 0,
        ]);

        $matrix = app(WageLedgerService::class)->build($user->id, 2026);
        $this->assertSame('女性', $matrix['employee']['gender_label']);
    }
}
