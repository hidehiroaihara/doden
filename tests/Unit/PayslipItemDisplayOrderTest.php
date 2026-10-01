<?php

namespace Tests\Unit;

use App\Models\PayItemMaster;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\User;
use App\Support\PayslipItemDisplayOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayslipItemDisplayOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_hourly_earnings_follow_pay_item_master_sort_order(): void
    {
        PayItemMaster::create([
            'pay_type' => 'hourly',
            'code' => 'base_salary',
            'name' => '基本給',
            'category' => 'basic',
            'is_active' => true,
            'calc_method' => 'custom',
            'sort_order' => 0,
        ]);
        PayItemMaster::create([
            'pay_type' => 'hourly',
            'code' => 'commute_non_taxable',
            'name' => '通勤手当/非課',
            'category' => 'commute',
            'is_active' => true,
            'calc_method' => 'employee',
            'sort_order' => 2,
        ]);
        PayItemMaster::create([
            'pay_type' => 'hourly',
            'code' => 'custom_wcnewgqg',
            'name' => '仕込み手当',
            'category' => 'custom',
            'is_active' => true,
            'calc_method' => 'custom',
            'sort_order' => 3,
        ]);

        $user = User::factory()->create();
        $user->employeePayroll()->create([
            'employee_no' => 'T1',
            'pay_type' => 'hourly',
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
        $payslip->setRelation('user', $user->load('employeePayroll'));

        // 保存順は算出パス順（通勤→基本→仕込み）になっている想定
        foreach ([
            ['commute_non_taxable', 0],
            ['base_salary', 1],
            ['custom_wcnewgqg', 2],
        ] as [$code, $sort]) {
            PayslipItem::create([
                'payslip_id' => $payslip->id,
                'item_type' => 'earning',
                'code' => $code,
                'name' => $code,
                'amount' => 100,
                'sort_order' => $sort,
            ]);
        }

        $codes = PayslipItemDisplayOrder::sort($payslip, $payslip->items, 'earning')
            ->pluck('code')
            ->all();

        $this->assertSame(['base_salary', 'commute_non_taxable', 'custom_wcnewgqg'], $codes);
    }
}
