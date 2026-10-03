<?php

namespace Tests\Unit;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payroll\PayslipPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayslipPdfServiceViewDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('payslip_show_attendance', '1');
        Setting::setValue('payslip_show_hourly', '1');
    }

    public function test_monthly_employee_payslip_hides_attendance_and_related_info(): void
    {
        $user = User::factory()->create();
        $user->employeePayroll()->create([
            'employee_no' => 'M1',
            'pay_type' => 'monthly',
            'hourly_wage' => 0,
            'employment_type' => 'full_time',
        ]);

        $run = PayrollRun::create([
            'period_key' => '2026-09',
            'pay_type' => 'salary',
            'status' => 'draft',
        ]);

        $payslip = Payslip::create([
            'payroll_run_id' => $run->id,
            'user_id' => $user->id,
            'total_earnings' => 300000,
            'total_deductions' => 50000,
            'net_pay' => 250000,
        ]);

        PayslipItem::create([
            'payslip_id' => $payslip->id,
            'item_type' => 'attendance',
            'code' => 'work_days',
            'name' => '出勤日数',
            'quantity' => 20,
        ]);

        $data = app(PayslipPdfService::class)->viewData($payslip);

        $this->assertFalse($data['showAttendance']);
        $this->assertSame([], $data['relatedInfo']);
    }

    public function test_hourly_employee_payslip_shows_attendance_when_setting_enabled(): void
    {
        $user = User::factory()->create();
        $user->employeePayroll()->create([
            'employee_no' => 'H1',
            'pay_type' => 'hourly',
            'hourly_wage' => 1200,
            'hourly_wage2' => 1330,
            'employment_type' => 'part_time',
        ]);

        $run = PayrollRun::create([
            'period_key' => '2026-09',
            'pay_type' => 'salary',
            'status' => 'draft',
        ]);

        $payslip = Payslip::create([
            'payroll_run_id' => $run->id,
            'user_id' => $user->id,
            'total_earnings' => 100000,
            'total_deductions' => 10000,
            'net_pay' => 90000,
        ]);

        PayslipItem::create([
            'payslip_id' => $payslip->id,
            'item_type' => 'attendance',
            'code' => 'actual_total_weekday',
            'name' => '実労働時間',
            'minutes' => 6000,
        ]);

        $data = app(PayslipPdfService::class)->viewData($payslip);

        $this->assertTrue($data['showAttendance']);
        $this->assertNotEmpty($data['relatedInfo']);
        $this->assertSame('時給1', $data['relatedInfo'][0]['label']);
        $this->assertSame('時給2', $data['relatedInfo'][1]['label']);
        $this->assertSame('1,330', $data['relatedInfo'][1]['value']);
        $this->assertSame(
            $data['columnMinHeight'],
            $data['payPanelHeight'] + $data['relatedBlockHeight'],
        );
    }

    public function test_attendance_label_html_breaks_before_parentheses(): void
    {
        $user = User::factory()->create();
        $user->employeePayroll()->create([
            'employee_no' => 'H2',
            'pay_type' => 'hourly',
            'hourly_wage' => 1000,
            'employment_type' => 'part_time',
        ]);

        $run = PayrollRun::create([
            'period_key' => '2026-09',
            'pay_type' => 'salary',
            'status' => 'draft',
        ]);

        $payslip = Payslip::create([
            'payroll_run_id' => $run->id,
            'user_id' => $user->id,
            'total_earnings' => 0,
            'total_deductions' => 0,
            'net_pay' => 0,
        ]);

        PayslipItem::create([
            'payslip_id' => $payslip->id,
            'item_type' => 'attendance',
            'code' => 'scheduled_time_prescribed_holiday',
            'name' => '所定時間（所定休日）',
            'minutes' => 1920,
        ]);

        $data = app(PayslipPdfService::class)->viewData($payslip);

        $this->assertStringContainsString('所定時間<br>（所定休日）', $data['attendances'][0]['nameHtml']);
    }
}
