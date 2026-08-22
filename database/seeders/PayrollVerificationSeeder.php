<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\BusinessLocation;
use App\Models\ClosingDateGroup;
use App\Models\Department;
use App\Models\EmployeePayItemValue;
use App\Models\EmployeePayroll;
use App\Models\FiscalYear;
use App\Models\FiscalYearCustomHoliday;
use App\Models\FiscalYearHoliday;
use App\Models\JobTitle;
use App\Models\Payslip;
use App\Models\PayItemMaster;
use App\Models\PayrollRun;
use App\Models\Setting;
use App\Models\User;
use App\Services\AttendanceSummaryService;
use App\Services\Payroll\PayrollCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * 給与連動検証用テストデータ（2026年9月）。
 *
 * 従業員構成（計12名・打刻画面に全員表示）:
 *   - 正社員 ×4 … 月給
 *   - アルバイト ×6 … 時給（深夜帯・仕込み手当の検証を含む）
 *   - 日給 ×2 … 日給
 *
 * 支給項目マスタ（pay_item_masters）は変更しない。
 *
 * 実行:  php artisan db:seed --class=PayrollVerificationSeeder
 */
class PayrollVerificationSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /** デモの「今日」基準日（打刻はこの日まで） */
    private const TODAY = '2026-08-20';

    /** @var array<string, User> */
    private array $users = [];

    public function run(): void
    {
        $this->ensureBaseSettings();
        $this->ensureFiscalYear2026();
        $this->deactivateLegacyVerifyUsers();

        $location = BusinessLocation::firstOrCreate(
            ['name' => '本社'],
            ['is_main' => true, 'health_insurance_type' => 'kyokai', 'prefecture' => '東京都', 'sort_order' => 0],
        );
        $closingGroup = ClosingDateGroup::where('name', '月末締め・翌月25日払い')->first();
        $org = $this->ensureOrganization();

        $this->seedEmployees($location, $closingGroup, $org);
        $this->seedAttendances();

        $allUsers = array_values($this->users);
        // 6〜9月はすべて「計算済」（確定しない）
        foreach (['2026-06', '2026-07', '2026-08', '2026-09'] as $period) {
            $this->seedPayrollRun($location, $period, 'calculated', $allUsers);
        }

        $this->printVerification();
    }

    /** 旧「検証 日給/時給」ユーザーを非表示にする。 */
    private function deactivateLegacyVerifyUsers(): void
    {
        User::whereIn('email', [
            'demo.daily.verify@example.com',
            'demo.hourly.verify@example.com',
        ])->update(['is_active' => false]);
    }

    /** @return array{sales: Department, dev: Department, store: Department, jobGeneral: ?JobTitle} */
    private function ensureOrganization(): array
    {
        $sales = Department::firstOrCreate(['name' => '営業部'], ['sort_order' => 0]);
        $dev = Department::firstOrCreate(['name' => '開発部'], ['sort_order' => 1]);
        $store = Department::firstOrCreate(['name' => '店舗運営'], ['sort_order' => 2]);
        $jobGeneral = JobTitle::firstOrCreate(['name' => '一般'], ['sort_order' => 0]);

        return compact('sales', 'dev', 'store', 'jobGeneral');
    }

    private function ensureBaseSettings(): void
    {
        $defaults = [
            'monthly_avg_work_days' => '21',
            'default_break_minutes' => '60',
            'break_start_time' => '12:00',
            'break_end_time' => '13:00',
        ];
        foreach ($defaults as $key => $value) {
            if (Setting::getValue($key) === null) {
                Setting::setValue($key, $value);
            }
        }
    }

    private function ensureFiscalYear2026(): void
    {
        $fy = FiscalYear::updateOrCreate(
            ['year' => 2026],
            ['name' => '2026年度', 'work_hours_per_day_minutes' => 480, 'monthly_avg_work_days' => 21],
        );

        foreach ([0 => 'legal', 6 => 'prescribed', 3 => 'prescribed'] as $dow => $type) {
            FiscalYearHoliday::updateOrCreate(
                ['fiscal_year_id' => $fy->id, 'dow' => $dow],
                ['type' => $type],
            );
        }

        foreach ([
            '2026-09-21' => '敬老の日',
            '2026-09-22' => '国民の休日',
            '2026-09-23' => '秋分の日',
        ] as $date => $label) {
            FiscalYearCustomHoliday::updateOrCreate(
                ['fiscal_year_id' => $fy->id, 'date' => $date],
                ['label' => $label],
            );
        }
    }

    /** @param array{sales: Department, dev: Department, store: Department, jobGeneral: ?JobTitle} $org */
    private function seedEmployees(BusinessLocation $location, ?ClosingDateGroup $closingGroup, array $org): void
    {
        $defs = [
            // ── 正社員（月給）×4 ──
            [
                'key' => 'ito',
                'user' => ['name' => '伊藤 誠', 'email' => 'verify.e101@example.com', 'department_id' => $org['sales']->id, 'joined_at' => '2020-04-01'],
                'payroll' => ['employee_no' => 'E101', 'employment_type' => 'full_time', 'pay_type' => 'monthly', 'base_salary' => 300000, 'job_title_id' => $org['jobGeneral']?->id],
            ],
            [
                'key' => 'watanabe',
                'user' => ['name' => '渡辺 由美', 'email' => 'verify.e102@example.com', 'department_id' => $org['dev']->id, 'joined_at' => '2021-04-01'],
                'payroll' => ['employee_no' => 'E102', 'employment_type' => 'full_time', 'pay_type' => 'monthly', 'base_salary' => 280000, 'job_title_id' => $org['jobGeneral']?->id],
            ],
            [
                'key' => 'nakamura',
                'user' => ['name' => '中村 大輔', 'email' => 'verify.e103@example.com', 'department_id' => $org['sales']->id, 'joined_at' => '2019-04-01'],
                'payroll' => ['employee_no' => 'E103', 'employment_type' => 'full_time', 'pay_type' => 'monthly', 'base_salary' => 320000, 'job_title_id' => $org['jobGeneral']?->id],
            ],
            [
                'key' => 'kobayashi',
                'user' => ['name' => '小林 恵', 'email' => 'verify.e104@example.com', 'department_id' => $org['store']->id, 'joined_at' => '2022-04-01'],
                'payroll' => ['employee_no' => 'E104', 'employment_type' => 'full_time', 'pay_type' => 'monthly', 'base_salary' => 290000, 'job_title_id' => $org['jobGeneral']?->id],
            ],

            // ── アルバイト（時給）×5 … 深夜帯打刻検証用 ──
            [
                'key' => 'matsumoto',
                'user' => ['name' => '松本 翔太', 'email' => 'verify.e201@example.com', 'department_id' => $org['store']->id, 'joined_at' => '2024-04-01'],
                'payroll' => ['employee_no' => 'E201', 'employment_type' => 'arbeit', 'pay_type' => 'hourly', 'hourly_wage' => 1500, 'hourly_wage2' => 1800],
            ],
            [
                'key' => 'inoue',
                'user' => ['name' => '井上 美月', 'email' => 'verify.e202@example.com', 'department_id' => $org['store']->id, 'joined_at' => '2024-10-01'],
                'payroll' => ['employee_no' => 'E202', 'employment_type' => 'arbeit', 'pay_type' => 'hourly', 'hourly_wage' => 1400, 'hourly_wage2' => 1700],
            ],
            [
                'key' => 'kimura',
                'user' => ['name' => '木村 蓮', 'email' => 'verify.e203@example.com', 'department_id' => $org['store']->id, 'joined_at' => '2025-04-01'],
                'payroll' => ['employee_no' => 'E203', 'employment_type' => 'arbeit', 'pay_type' => 'hourly', 'hourly_wage' => 1300, 'hourly_wage2' => 1600],
            ],
            [
                'key' => 'hayashi',
                'user' => ['name' => '林 さくら', 'email' => 'verify.e204@example.com', 'department_id' => $org['store']->id, 'joined_at' => '2025-06-01'],
                'payroll' => ['employee_no' => 'E204', 'employment_type' => 'arbeit', 'pay_type' => 'hourly', 'hourly_wage' => 1450, 'hourly_wage2' => 1750],
            ],
            [
                'key' => 'shimizu',
                'user' => ['name' => '清水 悠斗', 'email' => 'verify.e205@example.com', 'department_id' => $org['store']->id, 'joined_at' => '2025-09-01'],
                'payroll' => ['employee_no' => 'E205', 'employment_type' => 'arbeit', 'pay_type' => 'hourly', 'hourly_wage' => 1200, 'hourly_wage2' => 1500],
            ],

            // ── 日給 ×3 ──
            [
                'key' => 'kato',
                'user' => ['name' => '加藤 健', 'email' => 'verify.e301@example.com', 'department_id' => $org['store']->id, 'joined_at' => '2024-04-01'],
                'payroll' => ['employee_no' => 'E301', 'employment_type' => 'arbeit', 'pay_type' => 'daily', 'daily_wage' => 12000],
            ],
            [
                'key' => 'yoshida',
                'user' => ['name' => '吉田 真由', 'email' => 'verify.e302@example.com', 'department_id' => $org['store']->id, 'joined_at' => '2024-07-01'],
                'payroll' => ['employee_no' => 'E302', 'employment_type' => 'arbeit', 'pay_type' => 'hourly', 'hourly_wage' => 1350, 'hourly_wage2' => 1650],
            ],
            [
                'key' => 'yamaguchi',
                'user' => ['name' => '山口 拓也', 'email' => 'verify.e303@example.com', 'department_id' => $org['store']->id, 'joined_at' => '2025-01-01'],
                'payroll' => ['employee_no' => 'E303', 'employment_type' => 'arbeit', 'pay_type' => 'daily', 'daily_wage' => 11000],
            ],
        ];

        foreach ($defs as $def) {
            $nameParts = preg_split('/[\s\x{3000}]+/u', trim($def['user']['name']), 2);
            $user = User::updateOrCreate(
                ['email' => $def['user']['email']],
                array_merge($def['user'], [
                    'last_name' => $nameParts[0] ?? $def['user']['name'],
                    'first_name' => $nameParts[1] ?? '',
                    'password' => Hash::make(self::PASSWORD),
                    'is_active' => true,
                    'role' => 1,
                    'break_minutes' => 60,
                ]),
            );

            EmployeePayroll::updateOrCreate(
                ['user_id' => $user->id],
                array_merge([
                    'business_location_id' => $location->id,
                    'closing_date_group_id' => $closingGroup?->id,
                    'tax_table' => 'kou',
                    'dependents_count' => 0,
                    'is_social_insurance_enrolled' => false,
                    'is_employment_insurance_enrolled' => false,
                    'is_care_insurance_target' => false,
                ], $def['payroll']),
            );

            $user->refresh();
            $this->seedEmployeePayItemValues($user, $def['payroll']['pay_type']);
            $this->users[$def['key']] = $user;
        }
    }

    private function seedEmployeePayItemValues(User $user, string $payType): void
    {
        $ep = $user->employeePayroll;
        if (! $ep) {
            return;
        }

        foreach (PayItemMaster::query()
            ->where('pay_type', $payType)
            ->where('calc_method', 'employee')
            ->where('category', '!=', 'commute')
            ->get() as $m) {
            $amount = match ($m->code) {
                'base_salary' => (int) $ep->base_salary,
                default => 0,
            };
            EmployeePayItemValue::updateOrCreate(
                ['user_id' => $user->id, 'pay_item_master_id' => $m->id],
                ['amount' => $amount],
            );
        }
    }

    private function seedAttendances(): void
    {
        $verifyIds = collect($this->users)->pluck('id');
        Attendance::whereIn('user_id', $verifyIds)->delete();

        $jun = $this->monthWeekdays(2026, 6);
        $jul = $this->monthWeekdays(2026, 7);
        $aug = $this->monthWeekdays(2026, 8, self::TODAY);
        $sep = $this->sepWeekdays();

        $schedules = [
            // ── 正社員: 6〜8月(20日まで)・9月は平日日中 ──
            'ito' => $this->daySlots(array_merge($jun, $jul, $aug, $sep), '09:00', '18:00'),
            'watanabe' => $this->daySlots(array_merge($jun, $jul, $aug, $sep), '09:00', '18:00'),
            'nakamura' => $this->daySlots(array_merge($jun, $jul, $aug, $sep), '09:00', '18:00'),
            'kobayashi' => $this->daySlots(array_merge($jun, $jul, $aug, $sep), '09:00', '18:00'),

            // ── アルバイト: 深夜帯パターン混在 ──
            'matsumoto' => array_merge(
                $this->daySlots(['2026-06-02', '2026-06-09', '2026-07-07', '2026-07-14', '2026-08-06', '2026-08-18', '2026-09-04', '2026-09-07'], '09:00', '18:00'),
                [
                    ['2026-06-03', '18:00', '02:00'],
                    ['2026-06-17', '18:00', '02:00'],
                    ['2026-07-08', '18:00', '02:00'],
                    ['2026-08-04', '18:00', '02:00'],
                    ['2026-08-11', '18:00', '02:00'],
                    ['2026-09-01', '18:00', '02:00'],
                    ['2026-09-08', '18:00', '02:00'],
                    ['2026-09-15', '18:00', '02:00'],
                ],
            ),
            'inoue' => array_merge(
                $this->daySlots(['2026-06-01', '2026-06-08', '2026-07-01', '2026-07-15', '2026-08-10', '2026-08-19', '2026-09-04', '2026-09-07', '2026-09-08', '2026-09-11'], '09:00', '18:00'),
                [
                    ['2026-06-11', '22:00', '06:00'],
                    ['2026-07-08', '22:00', '06:00'],
                    ['2026-08-06', '22:00', '06:00'],
                    ['2026-09-10', '22:00', '06:00'],
                ],
            ),
            'kimura' => array_merge(
                $this->daySlots(['2026-06-05', '2026-07-02', '2026-08-12', '2026-09-04', '2026-09-16'], '09:00', '18:00'),
                [
                    ['2026-06-03', '17:00', '01:00'], // 水曜（所定休日）
                    ['2026-07-01', '17:00', '01:00'],
                    ['2026-08-05', '17:00', '01:00'],
                    ['2026-09-09', '17:00', '01:00'],
                ],
            ),
            'hayashi' => array_merge(
                $this->daySlots(['2026-06-10', '2026-07-09', '2026-09-01', '2026-09-16'], '09:00', '18:00'),
                [
                    ['2026-08-20', '20:00', '04:00'], // 今日の夜勤
                    ['2026-09-21', '20:00', '04:00'],
                ],
            ),
            'shimizu' => $this->daySlots(array_merge($jun, $jul, $aug, $sep), '09:00', '18:00'),

            // ── 日給 ──
            'kato' => $this->daySlots(
                array_merge($aug, ['2026-09-01', '2026-09-02', '2026-09-04', '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-21', '2026-09-28']),
                '09:00', '18:00',
            ),
            'yoshida' => array_merge(
                // 平日（基本給用）
                $this->daySlots(
                    array_merge(
                        $this->through(self::TODAY, ['2026-06-02', '2026-06-09', '2026-07-06', '2026-07-13', '2026-08-04', '2026-08-11']),
                        ['2026-09-01', '2026-09-04', '2026-09-07', '2026-09-08', '2026-09-28'],
                    ),
                    '09:00', '18:00',
                ),
                // 水曜（所定休日）… 仕込み手当 = 時給2 × 所定時間（所定休日）
                $this->daySlots(
                    array_merge(
                        $this->through(self::TODAY, $this->monthWednesdays(2026, 6, self::TODAY)),
                        $this->through(self::TODAY, $this->monthWednesdays(2026, 7, self::TODAY)),
                        $this->through(self::TODAY, $this->monthWednesdays(2026, 8, self::TODAY)),
                        $this->monthWednesdays(2026, 9),
                    ),
                    '09:00', '18:00',
                ),
            ),
            'yamaguchi' => $this->daySlots(
                array_merge(
                    $this->through(self::TODAY, ['2026-06-01', '2026-06-08', '2026-07-07', '2026-08-03', '2026-08-10', '2026-08-18']),
                    ['2026-09-01', '2026-09-04', '2026-09-07', '2026-09-08', '2026-09-28'],
                ),
                '09:00', '18:00',
            ),
        ];

        foreach ($schedules as $key => $entries) {
            foreach ($entries as [$date, $in, $out]) {
                if ($date > self::TODAY && str_starts_with($date, '2026-08')) {
                    continue;
                }
                $this->punch($this->users[$key], $date, $in, $out);
            }
        }
    }

    /**
     * 指定月の平日一覧。$until 指定時はその日まで（8月打刻上限など）。
     *
     * @return list<string>
     */
    private function monthWeekdays(int $year, int $month, ?string $until = null): array
    {
        $cursor = Carbon::create($year, $month, 1);
        $monthEnd = $cursor->copy()->endOfMonth();
        $end = $until ? Carbon::parse(min($until, $monthEnd->format('Y-m-d'))) : $monthEnd;
        $dates = [];
        while ($cursor->lte($end)) {
            if (! $cursor->isWeekend()) {
                $dates[] = $cursor->format('Y-m-d');
            }
            $cursor->addDay();
        }

        return $dates;
    }

    /** @param  list<string>  $dates @return list<string> */
    private function through(string $cutoff, array $dates): array
    {
        return array_values(array_filter($dates, fn (string $d) => $d <= $cutoff));
    }

    /**
     * @param  list<string>  $dates
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function daySlots(array $dates, string $in, string $out): array
    {
        return array_map(fn (string $d) => [$d, $in, $out], $dates);
    }

    /** @return list<string> */
    private function monthWednesdays(int $year, int $month, ?string $until = null): array
    {
        $cursor = Carbon::create($year, $month, 1);
        $monthEnd = $cursor->copy()->endOfMonth();
        $end = $until ? Carbon::parse(min($until, $monthEnd->format('Y-m-d'))) : $monthEnd;
        $dates = [];
        while ($cursor->lte($end)) {
            if ($cursor->dayOfWeek === Carbon::WEDNESDAY) {
                $dates[] = $cursor->format('Y-m-d');
            }
            $cursor->addDay();
        }

        return $dates;
    }

    /** @return list<string> */
    private function sepWeekdays(): array
    {
        return ['2026-09-01', '2026-09-04', '2026-09-07', '2026-09-08', '2026-09-11', '2026-09-14', '2026-09-18', '2026-09-25', '2026-09-28'];
    }

    private function punch(User $user, string $date, string $inTime, string $outTime, int $breakMin = 60): void
    {
        $in = Carbon::parse("{$date} {$inTime}");
        $out = Carbon::parse("{$date} {$outTime}");
        if ($out->lte($in)) {
            $out->addDay();
        }

        Attendance::updateOrCreate(
            ['user_id' => $user->id, 'work_date' => $date],
            [
                'department_id' => $user->department_id,
                'clock_in_at' => $in,
                'clock_out_at' => $out,
                'break_minutes' => $breakMin,
            ],
        );
    }

    /** @param array<int, User> $users */
    private function seedPayrollRun(BusinessLocation $location, string $period, string $status, array $users): PayrollRun
    {
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

        $calc = app(PayrollCalculator::class);
        foreach ($users as $user) {
            $calc->calculate($run->fresh(), $user);
        }

        $run->update($status === 'finalized'
            ? ['status' => 'finalized', 'finalized_at' => now()]
            : ['status' => $status]);

        return $run->fresh();
    }

    private function printVerification(): void
    {
        $summaryService = app(AttendanceSummaryService::class);
        $augResult = $summaryService->forMonth('2026-08');
        $sepResult = $summaryService->forMonth('2026-09');
        $augByUser = collect($augResult['users'])->keyBy('user_id');
        $sepByUser = collect($sepResult['users'])->keyBy('user_id');

        $out = $this->command?->getOutput();
        $line = fn (string $s) => $out ? $out->writeln($s) : print($s . "\n");

        $line('');
        $line('==================================================================');
        $line(' 検証データ（正社員4 / アルバイト6 / 日給2 = 計12名）');
        $line(' 打刻: 8月は '.self::TODAY.' まで / 給与: 6〜9月=計算済（確定なし）');
        $line('==================================================================');

        $line('');
        $line('[8月 深夜帯集計（〜'.self::TODAY.'）]');
        foreach (['matsumoto', 'inoue', 'kimura', 'hayashi', 'shimizu'] as $key) {
            $u = $this->users[$key];
            $s = $augByUser[$u->id] ?? [];
            $line(sprintf('  %s %s: 平日深夜=%.2fh / 所定休日深夜=%.2fh',
                $u->employeePayroll->employee_no, $u->name,
                ($s['weekday_night_minutes'] ?? 0) / 60,
                ($s['prescribed_holiday_night_minutes'] ?? 0) / 60,
            ));
        }

        $line('');
        $line('[9月 給与連動サンプル]');
        foreach (['matsumoto', 'yoshida', 'kato'] as $key) {
            $u = $this->users[$key];
            $s = $sepByUser[$u->id] ?? [];
            $line(sprintf('  %s %s', $u->employeePayroll->employee_no, $u->name));
            if ($u->employeePayroll->pay_type === 'hourly') {
                $line(sprintf('    平日深夜=%.2fh / 所定休日深夜=%.2fh / 所定休日所定=%.2fh',
                    ($s['weekday_night_minutes'] ?? 0) / 60,
                    ($s['prescribed_holiday_night_minutes'] ?? 0) / 60,
                    ($s['prescribed_holiday_within_minutes'] ?? 0) / 60,
                ));
            } else {
                $line(sprintf('    出勤=%d日', (int) ($s['work_days'] ?? 0)));
            }
        }

        $sepRun = PayrollRun::where('period_key', '2026-09')->where('pay_type', 'salary')->latest('id')->first();
        if ($sepRun) {
            $this->printActualItems($line, $sepRun, $this->users['matsumoto']);
            $this->printActualItems($line, $sepRun, $this->users['yoshida']);
            $this->printActualItems($line, $sepRun, $this->users['kato']);
        }

        $line('');
        $line('[給与バッチ状態]');
        foreach (PayrollRun::whereIn('period_key', ['2026-06', '2026-07', '2026-08', '2026-09'])
            ->where('pay_type', 'salary')->orderBy('period_key')->get() as $run) {
            $line(sprintf('  %s … %s', $run->period_key, $run->status));
        }
        $line('==================================================================');
        $line('');
    }

    private function printActualItems(callable $line, PayrollRun $run, User $user): void
    {
        $payslip = Payslip::where('payroll_run_id', $run->id)
            ->where('user_id', $user->id)
            ->with('items')
            ->first();

        if (! $payslip) {
            $line("  {$user->name}: (payslip なし)");

            return;
        }

        $line("  {$user->name}:");
        foreach ($payslip->items->where('item_type', 'earning')->where('amount', '>', 0) as $item) {
            $line(sprintf('    %-12s = %s', $item->name, number_format((int) $item->amount)));
        }
        $line(sprintf('    → 総支給 = %s', number_format((int) $payslip->total_earnings)));
    }
}
