<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\BusinessLocation;
use App\Models\EmployeePayroll;
use App\Models\PayrollRun;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payroll\PayrollCalculator;
use Carbon\Carbon;
use Database\Seeders\Support\VariedAttendanceSlots;
use Illuminate\Database\Seeder;

/**
 * ローカル検証用: 既存従業員に給与条件・打刻を補完し、2026年1〜5月の給与を計算・確定する。
 *
 * 実行: php artisan db:seed --class=LocalFinalizedPayrollSeeder
 */
class LocalFinalizedPayrollSeeder extends Seeder
{
    private const YEAR = 2026;

    /** @var array<int, int> 確定する月（1〜12） */
    private const MONTHS = [1, 2, 3, 4, 5];

    public function run(): void
    {
        $this->ensureSettings();

        $patched = $this->patchEmployeePayrolls();
        $this->command?->info("給与条件を補完: {$patched} 名");

        $attendanceRows = $this->seedAttendances();
        $this->command?->info("打刻を生成: {$attendanceRows} 件");

        $calc = app(PayrollCalculator::class);
        $runsCreated = 0;
        $payslipsTotal = 0;

        foreach (BusinessLocation::orderBy('id')->get() as $location) {
            $employeeCount = EmployeePayroll::where('business_location_id', $location->id)
                ->whereHas('user', fn ($q) => $q->where('is_active', true))
                ->count();
            if ($employeeCount === 0) {
                continue;
            }

            foreach (self::MONTHS as $month) {
                $period = sprintf('%s-%02d', self::YEAR, $month);
                $run = PayrollRun::updateOrCreate(
                    [
                        'business_location_id' => $location->id,
                        'period_key' => $period,
                        'pay_type' => 'salary',
                    ],
                    [
                        'closing_date' => Carbon::parse("{$period}-01")->endOfMonth()->toDateString(),
                        'payment_date' => Carbon::parse("{$period}-25")->addMonth()->toDateString(),
                        'status' => 'draft',
                        'finalized_at' => null,
                    ],
                );

                $payslips = $calc->calculateRun($run->fresh());
                $run->update(['status' => 'finalized', 'finalized_at' => now()]);
                $runsCreated++;
                $payslipsTotal += count($payslips);
            }
        }

        $this->command?->info("給与バッチ確定: {$runsCreated} 件 / 明細 {$payslipsTotal} 件");
        $this->command?->info('賃金台帳: 2026年・各事業所で 1〜5月度にデータが入ります。');
    }

    private function ensureSettings(): void
    {
        foreach ([
            'monthly_avg_work_days' => '21',
            'default_break_minutes' => '60',
        ] as $key => $value) {
            if (Setting::getValue($key) === null) {
                Setting::setValue($key, $value);
            }
        }
    }

    /** 賃金未設定の従業員に最低限の給与条件・社保を付与する。 */
    private function patchEmployeePayrolls(): int
    {
        $count = 0;
        EmployeePayroll::query()
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->with('user:id,name')
            ->each(function (EmployeePayroll $ep) use (&$count) {
                $patch = [];

                if ($ep->pay_type === 'monthly' && (int) $ep->base_salary <= 0) {
                    $patch['base_salary'] = 280_000;
                }
                if ($ep->pay_type === 'hourly' && (int) $ep->hourly_wage <= 0) {
                    $patch['hourly_wage'] = 1_200;
                }
                if ($ep->pay_type === 'daily' && (int) $ep->daily_wage <= 0) {
                    $patch['daily_wage'] = 10_000;
                }

                if (! $ep->is_social_insurance_enrolled) {
                    $patch['is_social_insurance_enrolled'] = true;
                    $patch['standard_reward_health'] = 260_000;
                    $patch['standard_reward_pension'] = 260_000;
                    $patch['standard_reward_grade_health'] = 22;
                    $patch['standard_reward_grade_pension'] = 22;
                }

                if (! $ep->is_employment_insurance_enrolled) {
                    $patch['is_employment_insurance_enrolled'] = true;
                }

                if ((int) $ep->resident_tax_monthly <= 0) {
                    $patch['resident_tax_monthly'] = 12_000;
                }

                if ($patch !== []) {
                    $ep->update($patch);
                    $count++;
                }
            });

        return $count;
    }

    /** 2026年1〜5月: アルバイト・パートはシフト別、正社員は所定勤務で打刻を生成する。 */
    private function seedAttendances(): int
    {
        $users = User::query()
            ->where('is_active', true)
            ->whereHas('employeePayroll', fn ($q) => $q->whereIn('pay_type', ['hourly', 'daily']))
            ->with('employeePayroll:id,user_id,employment_type')
            ->get(['id', 'department_id', 'customer_no', 'joined_at']);

        if ($users->isEmpty()) {
            return 0;
        }

        $from = sprintf('%d-%02d-01', self::YEAR, min(self::MONTHS));
        $to = Carbon::create(self::YEAR, max(self::MONTHS), 1)->endOfMonth()->toDateString();
        Attendance::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->whereBetween('work_date', [$from, $to])
            ->delete();

        $rows = 0;
        foreach (self::MONTHS as $month) {
            $cursor = Carbon::create(self::YEAR, $month, 1);
            $end = $cursor->copy()->endOfMonth();
            while ($cursor->lte($end)) {
                $date = $cursor->toDateString();

                foreach ($users as $user) {
                    if ($user->joined_at && $date < $user->joined_at->format('Y-m-d')) {
                        continue;
                    }

                    $employmentType = $user->employeePayroll?->employment_type ?? 'arbeit';
                    $slot = VariedAttendanceSlots::resolve($user, $employmentType, $date);
                    if ($slot === null) {
                        continue;
                    }

                    $in = Carbon::parse("{$date} {$slot['in']}");
                    $out = Carbon::parse("{$date} {$slot['out']}");
                    if ($out->lte($in)) {
                        $out->addDay();
                    }

                    Attendance::updateOrCreate(
                        ['user_id' => $user->id, 'work_date' => $date],
                        [
                            'department_id' => $user->department_id,
                            'clock_in_at' => $in,
                            'clock_out_at' => $out,
                            'break_minutes' => $slot['break'],
                        ],
                    );
                    $rows++;
                }
                $cursor->addDay();
            }
        }

        return $rows;
    }
}
