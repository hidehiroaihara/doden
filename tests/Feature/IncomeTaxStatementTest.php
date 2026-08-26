<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BusinessLocation;
use App\Models\IncomeTaxStatementOverride;
use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payroll\Reports\IncomeTaxStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeTaxStatementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => '管理者',
            'email' => 'admin@example.com',
            'password' => 'password',
            'role' => 1,
        ]);
    }

    private function makeFinalizedRun(string $periodKey, string $payType, int $gross, int $tax): PayrollRun
    {
        $run = PayrollRun::create([
            'business_location_id' => null,
            'period_key' => $periodKey,
            'pay_type' => $payType,
            'payment_date' => $periodKey.'-25',
            'status' => 'finalized',
            'finalized_at' => now(),
        ]);

        $user = User::factory()->create();
        $payslip = Payslip::create([
            'payroll_run_id' => $run->id,
            'user_id' => $user->id,
            'total_earnings' => $gross,
            'total_deductions' => $tax,
            'net_pay' => $gross - $tax,
        ]);
        $payslip->items()->create([
            'item_type' => 'earning',
            'code' => 'base_salary',
            'name' => '基本給',
            'amount' => $gross,
        ]);
        $payslip->items()->create([
            'item_type' => 'deduction',
            'code' => 'income_tax',
            'name' => '所得税',
            'category' => 'tax',
            'amount' => $tax,
        ]);

        return $run;
    }

    public function test_aggregate_salary_and_bonus_from_finalized_runs(): void
    {
        $this->makeFinalizedRun('2026-08', 'salary', 300000, 8500);
        $this->makeFinalizedRun('2026-08', 'bonus', 100000, 3000);

        $service = app(IncomeTaxStatementService::class);
        $result = $service->aggregate(['2026-08']);

        $this->assertSame(1, $result['salary']['count']);
        $this->assertSame(300000, $result['salary']['amount']);
        $this->assertSame(8500, $result['salary']['tax']);
        $this->assertSame(1, $result['bonus']['count']);
        $this->assertSame(100000, $result['bonus']['amount']);
        $this->assertSame(3000, $result['bonus']['tax']);
        $this->assertSame(11500, $result['total']['tax']);
    }

    public function test_build_report_includes_company_settings_and_totals(): void
    {
        BusinessLocation::create([
            'name' => '株式会社テスト',
            'is_main' => true,
            'postal_code' => '100-0001',
            'prefecture' => '東京都',
            'address' => '千代田区1-1',
            'note' => 'TEL 03-1234-5678',
        ]);
        Setting::setValue('tax_office_name', '麹町税務署');
        Setting::setValue('corporate_individual_number', '32309');
        Setting::setValue('tax_office_sign_number', '000');
        Setting::setValue('tax_office_number', '110');

        $this->makeFinalizedRun('2026-08', 'salary', 3902700, 76730);

        $service = app(IncomeTaxStatementService::class);
        $aggregate = $service->aggregate(['2026-08']);
        $report = $service->buildReport(
            $aggregate,
            2026,
            8,
            'general',
            '2026年8月分',
            '2026-08-25',
            null,
            IncomeTaxStatementOverride::defaultData(),
        )->toArray();

        $this->assertSame('general', $report['form_type']);
        $this->assertSame('一般分', $report['form_type_label']);
        $this->assertSame('麹町税務署', $report['tax_office_name']);
        $this->assertSame('32309', $report['reference_number']);
        $this->assertSame(1, $report['salary']['employee_count']);
        $this->assertSame(3902700, $report['salary']['payment_amount']);
        $this->assertSame(76730, $report['salary']['tax_amount']);
        $this->assertSame(76730, $report['principal_tax']);
        $this->assertSame(76730, $report['total_tax']);
        $this->assertSame('株式会社テスト', $report['company']['name']);
        $this->assertStringContainsString('東京都', $report['company']['address']);

        $form = $service->buildFormFromReport($report, 'normal');
        $this->assertSame(['0', '8'], $form['payment_date']['era']);
        $this->assertSame(['0', '8'], $form['payment_date']['month']);
        $this->assertSame(['2', '5'], $form['payment_date']['day']);
        $this->assertSame(['0', '8'], $form['due_period']['era']);
        $this->assertSame(['0', '8'], $form['due_period']['month']);
    }

    public function test_overrides_save_and_merge_into_report(): void
    {
        $admin = $this->admin();
        $this->makeFinalizedRun('2026-08', 'salary', 100000, 5000);

        $payload = [
            'year' => 2026,
            'month' => 8,
            'form_type' => 'general',
            'data' => [
                'daily_worker' => [
                    'employee_count' => 2,
                    'payment_amount' => 50000,
                    'tax_amount' => 1000,
                ],
                'late_payment_tax' => 200,
                'remarks' => 'テスト摘要',
            ],
        ];

        $this->actingAs($admin, 'admin')
            ->put(route('admin.payroll.reports.income-tax-statement.overrides'), $payload)
            ->assertRedirect();

        $record = IncomeTaxStatementOverride::findFor(2026, 8, 'general');
        $this->assertNotNull($record);
        $this->assertSame('テスト摘要', $record->data['remarks']);
        $this->assertSame(1000, $record->data['daily_worker']['tax_amount']);

        $service = app(IncomeTaxStatementService::class);
        $aggregate = $service->aggregate(['2026-08']);
        $overrides = IncomeTaxStatementOverride::mergedData($record);
        $report = $service->buildReport(
            $aggregate,
            2026,
            8,
            'general',
            '2026年8月分',
            '2026-08-25',
            null,
            $overrides,
        )->toArray();

        $this->assertSame(6000, $report['principal_tax']);
        $this->assertSame(6200, $report['total_tax']);
        $this->assertSame('テスト摘要', $report['remarks']);
    }

    public function test_preview_and_pdf_return_success(): void
    {
        $admin = $this->admin();
        $this->makeFinalizedRun('2026-08', 'salary', 200000, 3000);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payroll.reports.income-tax-statement.preview', ['year' => 2026, 'month' => 8]))
            ->assertOk()
            ->assertSee('income-tax-statement-form', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payroll.reports.income-tax-statement.pdf', ['year' => 2026, 'month' => 8]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_pdf_with_test_flag_uses_filled_overlay(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payroll.reports.income-tax-statement.pdf', ['year' => 2026, 'month' => 8, 'test' => 1]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $overlay = app(\App\Services\Payroll\Reports\IncomeTaxStatementOverlay::class)->buildTest(2026);
        $this->assertSame('048', $overlay['tel1']);
        $this->assertSame('株式会社テスト', $overlay['payer_name']);
        $this->assertSame('摘要テスト文字', $overlay['remarks']);
        $this->assertContains('¥', $overlay['total_tax']);
    }
}
