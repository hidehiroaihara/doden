<?php

namespace Tests\Feature;

use App\Models\EmployeePayroll;
use App\Models\User;
use App\Services\Payroll\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 時給2/日給2（第2単価）は未設定なら 0 とし、時給1/日給1 で代替しないことのテスト。
 * カスタム計算式「時給2 × 所定時間（所定休日）」が未設定者にも付く不具合の回帰防止。
 */
class SecondRateBasesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{hourly1: float, hourly2: float, daily1: float, daily2: float} */
    private function rates(EmployeePayroll $employee, float $allowanceBase = 210000, float $monthlyHours = 168, float $monthlyDays = 21): array
    {
        $calculator = app(PayrollCalculator::class);
        $method = new \ReflectionMethod($calculator, 'rateBases');
        $method->setAccessible(true);

        return $method->invoke($calculator, $employee, $allowanceBase, $monthlyHours, $monthlyDays);
    }

    private function employee(array $attributes): EmployeePayroll
    {
        return EmployeePayroll::create([
            'user_id' => User::factory()->create()->id,
            ...$attributes,
        ]);
    }

    public function test_second_hourly_rate_is_used_when_set(): void
    {
        $rates = $this->rates($this->employee([
            'employee_no' => 'E001',
            'hourly_wage' => 1300,
            'hourly_wage2' => 1330,
        ]));

        $this->assertSame(1300.0, $rates['hourly1']);
        $this->assertSame(1330.0, $rates['hourly2']);
    }

    public function test_second_hourly_rate_is_zero_when_unset(): void
    {
        $rates = $this->rates($this->employee([
            'employee_no' => 'E002',
            'hourly_wage' => 1300,
            'hourly_wage2' => null,
        ]));

        $this->assertSame(1300.0, $rates['hourly1']);
        $this->assertSame(0.0, $rates['hourly2']);
    }

    public function test_second_daily_rate_is_zero_when_unset(): void
    {
        $rates = $this->rates($this->employee([
            'employee_no' => 'E003',
            'daily_wage' => 10000,
            'daily_wage2' => null,
        ]));

        $this->assertSame(10000.0, $rates['daily1']);
        $this->assertSame(0.0, $rates['daily2']);
    }

    public function test_first_rates_still_fall_back_to_allowance_base(): void
    {
        $rates = $this->rates($this->employee([
            'employee_no' => 'E004',
            'hourly_wage' => 0,
            'daily_wage' => 0,
        ]));

        $this->assertSame(1250.0, $rates['hourly1']);
        $this->assertSame(10000.0, $rates['daily1']);
        $this->assertSame(0.0, $rates['hourly2']);
        $this->assertSame(0.0, $rates['daily2']);
    }
}
