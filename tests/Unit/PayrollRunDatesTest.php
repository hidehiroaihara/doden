<?php

namespace Tests\Unit;

use App\Models\ClosingDateGroup;
use App\Models\PayrollRun;
use App\Models\Setting;
use App\Support\PayrollRunDates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollRunDatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_september_period_with_october_payment_displays_as_october(): void
    {
        Setting::setValue('payslip_display_month', 'payment');

        ClosingDateGroup::create([
            'name' => '月末締翌月25',
            'closing_day' => 30,
            'payment_day' => 25,
            'payment_month_offset' => 1,
            'sort_order' => 0,
        ]);

        $dates = PayrollRunDates::datesForPeriodKey('2026-09');
        $this->assertSame('2026-09-30', $dates['closing_date']);
        $this->assertSame('2026-10-25', $dates['payment_date']);

        $run = PayrollRun::create([
            'period_key' => '2026-09',
            'pay_type' => 'salary',
            'status' => 'draft',
            'closing_date' => $dates['closing_date'],
            'payment_date' => $dates['payment_date'],
        ]);

        $this->assertSame('2026年10月分', PayrollRunDates::displayMonthLabel($run));

        $this->assertSame('2026年9月締め分', PayrollRunDates::closingPeriodLabel($run));
        $this->assertStringStartsWith('2026年9月締め分', PayrollRunDates::selectorLabel($run));
        $this->assertStringContainsString('2026年10月25日支給', PayrollRunDates::selectorLabel($run));
    }

    public function test_selector_label_uses_payment_month_when_payment_date_missing(): void
    {
        Setting::setValue('payslip_display_month', 'payment');

        $run = PayrollRun::create([
            'period_key' => '2026-09',
            'pay_type' => 'salary',
            'status' => 'finalized',
        ]);

        $this->assertSame('2026年9月締め分', PayrollRunDates::closingPeriodLabel($run));
        $this->assertStringContainsString('2026年10月25日支給', PayrollRunDates::selectorLabel($run));
        $this->assertStringNotContainsString('2026-09 支給', PayrollRunDates::selectorLabel($run));
    }
}
