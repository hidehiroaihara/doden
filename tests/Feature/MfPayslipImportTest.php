<?php

namespace Tests\Feature;

use App\Models\DeductionItemMaster;
use App\Models\EmployeePayroll;
use App\Models\PayItemMaster;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\Payroll\Migration\MfPayslipImporter;
use App\Services\Payroll\Migration\MfPayslipPdfParser;
use App\Services\Payroll\Reports\PayrollReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\MfPayslipPdfParserTest;

/**
 * MF給与明細PDFからの過去給与の移行登録と、支給日の年での年次集計。
 */
class MfPayslipImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['base_salary', '基本給', 'basic'], ['position_allowance', '役職手当', 'basic'], ['commute_non_taxable', '通勤手当/非課', 'commute']] as $i => [$code, $name, $category]) {
            PayItemMaster::create([
                'pay_type' => 'monthly',
                'code' => $code,
                'name' => $name,
                'category' => $category,
                'is_active' => true,
                'calc_method' => 'employee',
                'sort_order' => $i,
            ]);
        }
        foreach ([
            ['health_insurance', '健康保険料', 'social_insurance'],
            ['child_contribution', '子ども・子育て支援金', 'social_insurance'],
            ['pension_insurance', '厚生年金保険料', 'pension'],
            ['employment_insurance', '雇用保険料', 'labor_insurance'],
            ['income_tax', '所得税', 'tax'],
        ] as $i => [$code, $name, $category]) {
            DeductionItemMaster::create(['code' => $code, 'name' => $name, 'category' => $category, 'is_active' => true, 'sort_order' => $i]);
        }

        $this->user = User::factory()->create(['name' => '山田 太郎', 'is_active' => true]);
        EmployeePayroll::create(['user_id' => $this->user->id, 'employee_no' => '2', 'pay_type' => 'monthly']);
    }

    /** @return array<int, array<string, mixed>> */
    private function slips(array ...$pages): array
    {
        $parser = new MfPayslipPdfParser();

        return array_map(
            fn ($overrides) => $parser->parsePage(MfPayslipPdfParserTest::pageText($overrides)) + ['file' => 'test.pdf'],
            $pages ?: [[]],
        );
    }

    public function test_registers_finalized_run_with_items(): void
    {
        $importer = new MfPayslipImporter();
        $prepared = $importer->prepare($this->slips(), null);

        $this->assertSame([], $prepared['errors']);
        $counts = $importer->commit($prepared, null);
        $this->assertSame(['runs' => 1, 'payslips' => 1], $counts);

        $run = PayrollRun::firstOrFail();
        $this->assertSame('2025-12', $run->period_key);
        $this->assertSame('salary', $run->pay_type);
        $this->assertSame('finalized', $run->status);
        $this->assertSame('2026-01-30', $run->payment_date->toDateString());
        $this->assertSame('2025-12-31', $run->closing_date->toDateString());
        $this->assertSame(MfPayslipImporter::RUN_MEMO, $run->memo);

        $payslip = Payslip::with('items')->firstOrFail();
        $this->assertSame($this->user->id, $payslip->user_id);
        $this->assertSame(325000, $payslip->total_earnings);
        $this->assertSame(54170, $payslip->total_deductions);
        $this->assertSame(270830, $payslip->net_pay);
        $this->assertCount(8, $payslip->items);
        $this->assertSame(500, (int) $payslip->items->firstWhere('code', 'child_contribution')->amount);
        $this->assertTrue($payslip->items->every(fn ($i) => $i->is_manual_override));
    }

    public function test_reimport_replaces_migrated_run_but_rejects_system_run(): void
    {
        $importer = new MfPayslipImporter();
        $importer->commit($importer->prepare($this->slips(), null), null);

        $prepared = (new MfPayslipImporter())->prepare($this->slips(), null);
        $this->assertSame([], $prepared['errors']);
        $this->assertNotEmpty($prepared['warnings']);
        (new MfPayslipImporter())->commit($prepared, null);
        $this->assertSame(1, PayrollRun::count());
        $this->assertSame(1, Payslip::count());

        PayrollRun::query()->update(['memo' => null]);
        $prepared = (new MfPayslipImporter())->prepare($this->slips(), null);
        $this->assertNotEmpty($prepared['errors']);
    }

    public function test_unknown_item_and_unknown_employee_block_import(): void
    {
        $prepared = (new MfPayslipImporter())->prepare($this->slips([
            'earnings' => "基本給\t300,000\n謎手当\t20,000\n通勤手当/非課\t5,000",
        ]), null);
        $this->assertStringContainsString('謎手当', implode("\n", $prepared['errors']));

        $prepared = (new MfPayslipImporter())->prepare($this->slips(['no' => '99', 'name' => '未登録 花子']), null);
        $this->assertNotEmpty($prepared['errors']);

        $prepared = (new MfPayslipImporter())->prepare($this->slips(['no' => '99', 'name' => '未登録 花子']), null, true);
        $this->assertSame([], $prepared['errors']);
        $this->assertCount(1, $prepared['skipped']);
    }

    public function test_employee_number_reused_falls_back_to_name(): void
    {
        $prepared = (new MfPayslipImporter())->prepare($this->slips(['no' => '5', 'name' => '山田　太郎']), null);

        $this->assertSame([], $prepared['errors']);
        $this->assertNotEmpty($prepared['warnings']);
        $this->assertSame($this->user->id, $prepared['runs']['2025-12']['slips'][0]['user_id']);
    }

    public function test_zero_slip_is_skipped(): void
    {
        $prepared = (new MfPayslipImporter())->prepare($this->slips([
            'net' => '0', 'earnings' => '', 'gross' => '0', 'deductions' => "雇用保険料\t0", 'ded' => '0',
        ]), null);

        $this->assertSame([], $prepared['runs']);
        $this->assertCount(1, $prepared['skipped']);
    }

    public function test_yearly_aggregation_uses_payment_year_and_includes_child_contribution(): void
    {
        $importer = new MfPayslipImporter();
        $importer->commit($importer->prepare($this->slips(
            [],
            [
                'payment' => '2025（令和07）年12月26日',
                'from' => '2025（令和07）年11月01日',
                'to' => '2025（令和07）年11月30日',
            ],
        ), null), null);

        $reports = app(PayrollReportService::class);

        $y2026 = $reports->employeeYearlyPayslips($this->user->id, 2026);
        $this->assertSame(['2026-01'], $y2026['salary']->keys()->all());
        $s = $reports->summarize($y2026['salary']->first());
        $this->assertSame(325000, $s['gross']);
        $this->assertSame(16000 + 500 + 29280 + 1950, $s['social_insurance']);
        $this->assertSame(6440, $s['income_tax']);

        $y2025 = $reports->employeeYearlyPayslips($this->user->id, 2025);
        $this->assertSame(['2025-12'], $y2025['salary']->keys()->all());
        $this->assertSame('2025-11', $y2025['salary']->first()->payrollRun->period_key);

        $this->assertSame(['2025-12'], $reports->runsForYear(2026)->pluck('period_key')->all());
    }
}
