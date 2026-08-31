<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Attendance;
use App\Models\BonusInput;
use App\Models\BusinessLocation;
use App\Models\ClosingDateGroup;
use App\Models\Department;
use App\Models\EmployeePayItemValue;
use App\Models\EmployeePayroll;
use App\Models\PayItemMaster;
use App\Models\InsuranceRate;
use App\Models\InsuranceRateSet;
use App\Models\JobTitle;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\ReportViewPattern;
use App\Models\ResidentTaxMunicipality;
use App\Models\Setting;
use App\Models\Terminal;
use App\Models\User;
use App\Models\YearEndAdjustment;
use App\Support\AdminPermission;
use App\Services\Payroll\BonusCalculator;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\YearEndAdjustmentCalculator;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 全機能確認用のデモデータを投入する。
 *
 * 前提: migrate 済み、PayrollMasterSeeder 済み（支給/控除/勤怠マスタ・本社・2026年度料率・定額減税制度）。
 *
 * 実行例:
 *   php artisan db:seed --class=DemoDataSeeder
 *   php artisan migrate:fresh --seed   （DatabaseSeeder 経由でも可）
 *
 * ログイン:
 *   管理画面（デモ） … demo.admin@example.com / password（全権限）
 *   管理画面（本番用） … AdminSeeder 参照（system@frontier-dakoku.com）
 *   打刻ユーザー … demo.*@example.com / password
 */
class DemoDataSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /** @var array<string, User> */
    private array $users = [];

    /** デモの「今日」基準日（2026年8月打刻上限） */
    private const TODAY_2026 = '2026-08-20';

    private ?BusinessLocation $mainLocation = null;

    private ?BusinessLocation $branchLocation = null;

    private ?ClosingDateGroup $closingGroup = null;

    public function run(): void
    {
        $this->command?->info('DemoDataSeeder: デモデータ投入を開始…');

        DB::transaction(function () {
            $this->seedDemoAdmins();
            $this->seedSettings();
            $this->seedOrganization();
            $this->seedInsuranceRates2024();
            $this->seedMunicipalities();
            $this->seedEmployees();
            $this->seedAttendances();
            $this->seedPayroll2024();
            $this->markRetiredEmployee();
            $this->seedPayroll2026();
            $this->seedYearEndAdjustments();
            $this->seedReportPatterns();
            $this->seedTerminal();
        });

        $this->printSummary();
    }

    private function seedDemoAdmins(): void
    {
        Admin::updateOrCreate(
            ['email' => 'demo.admin@example.com'],
            [
                'name'        => 'デモ管理者',
                'password'    => Hash::make(self::PASSWORD),
                'role'        => 1,
                'permissions' => AdminPermission::SUPER,
            ],
        );

        Admin::updateOrCreate(
            ['email' => 'demo.payroll@example.com'],
            [
                'name'        => 'デモ給与担当',
                'password'    => Hash::make(self::PASSWORD),
                'role'        => 2,
                'permissions' => [
                    'dashboard'   => 'read',
                    'users'       => 'read',
                    'attendances' => 'none',
                    'terminals'   => 'none',
                    'settings'    => 'none',
                    'payroll'     => 'write',
                ],
            ],
        );

        Admin::updateOrCreate(
            ['email' => 'demo.attendance@example.com'],
            [
                'name'        => 'デモ勤怠担当',
                'password'    => Hash::make(self::PASSWORD),
                'role'        => 2,
                'permissions' => [
                    'dashboard'   => 'read',
                    'users'       => 'read',
                    'attendances' => 'write',
                    'terminals'   => 'write',
                    'settings'    => 'none',
                    'payroll'     => 'none',
                ],
            ],
        );

        Admin::updateOrCreate(
            ['email' => 'demo.viewer@example.com'],
            [
                'name'        => 'デモ閲覧者',
                'password'    => Hash::make(self::PASSWORD),
                'role'        => 2,
                'permissions' => [
                    'dashboard'   => 'read',
                    'users'       => 'read',
                    'attendances' => 'read',
                    'terminals'   => 'read',
                    'settings'    => 'read',
                    'payroll'     => 'read',
                ],
            ],
        );
    }

    private function seedSettings(): void
    {
        Setting::setValue('monthly_avg_work_days', '21');
        // 所定時間（出勤/退勤/1日所定）は初期未設定（遅刻・早退・残業の自動算出なし）
        Setting::setValue('work_hours_per_day', null);
        Setting::setValue('work_start_time', null);
        Setting::setValue('work_end_time', null);
        Setting::setValue('default_break_minutes', '60');
        Setting::setValue('break_start_time', '12:00');
        Setting::setValue('break_end_time', '13:00');
    }

    private function seedOrganization(): void
    {
        $this->mainLocation = BusinessLocation::firstOrCreate(
            ['name' => '本社'],
            ['is_main' => true, 'health_insurance_type' => 'kyokai', 'prefecture' => '東京都', 'sort_order' => 0],
        );

        $this->branchLocation = BusinessLocation::updateOrCreate(
            ['name' => '渋谷支店'],
            [
                'is_main' => false,
                'health_insurance_type' => 'kyokai',
                'prefecture' => '東京都',
                'postal_code' => '150-0001',
                'address' => '東京都渋谷区神宮前1-1-1',
                'sort_order' => 1,
            ],
        );

        $depts = [
            ['name' => '営業部', 'sort_order' => 0],
            ['name' => '開発部', 'sort_order' => 1],
            ['name' => '店舗運営', 'sort_order' => 2],
        ];
        foreach ($depts as $d) {
            Department::updateOrCreate(['name' => $d['name']], ['sort_order' => $d['sort_order']]);
        }

        foreach (['一般', '主任', '店長'] as $i => $title) {
            JobTitle::updateOrCreate(['name' => $title], ['sort_order' => $i]);
        }

        $this->closingGroup = ClosingDateGroup::updateOrCreate(
            ['name' => '月末締め・翌月25日払い'],
            ['closing_day' => 31, 'payment_day' => 25, 'payment_month_offset' => 1, 'sort_order' => 0],
        );

        // 支店用 2026年度料率（本社と同率）
        $branchSet = InsuranceRateSet::firstOrCreate(
            ['business_location_id' => $this->branchLocation->id, 'effective_from' => '2026-04-01'],
            ['name' => '2026年度 渋谷支店', 'effective_to' => null],
        );
        $mainSet = $this->mainLocation->rateSetForDate('2026-06-01');
        if ($mainSet) {
            foreach ($mainSet->rates as $rate) {
                InsuranceRate::updateOrCreate(
                    ['insurance_rate_set_id' => $branchSet->id, 'kind' => $rate->kind],
                    ['employee_rate' => $rate->employee_rate, 'employer_rate' => $rate->employer_rate],
                );
            }
        }
    }

    /** 2024年分給与・定額減税・年末調整用の料率セット。 */
    private function seedInsuranceRates2024(): void
    {
        $set = InsuranceRateSet::firstOrCreate(
            ['business_location_id' => $this->mainLocation->id, 'effective_from' => '2024-04-01'],
            ['name' => '2024年度 本社（デモ）', 'effective_to' => '2025-03-31'],
        );

        // 料率は千分率(/1,000)で保持（health 4.955% → 49.55）
        $rates = [
            ['health', 49.55, 49.55],
            ['nursing', 7.95, 7.95],
            ['pension', 91.50, 91.50],
            ['child_contribution', 0.00, 3.60],
            ['child_support', 1.15, 1.15],
            ['employment', 6.00, 9.50],
            ['accident', 0.00, 3.00],
        ];
        foreach ($rates as [$kind, $emp, $empr]) {
            InsuranceRate::updateOrCreate(
                ['insurance_rate_set_id' => $set->id, 'kind' => $kind],
                ['employee_rate' => $emp, 'employer_rate' => $empr],
            );
        }
    }

    private function seedMunicipalities(): void
    {
        foreach ([
            ['name' => '千代田区', 'designation_number' => '131016'],
            ['name' => '渋谷区', 'designation_number' => '131041'],
        ] as $row) {
            ResidentTaxMunicipality::updateOrCreate(['name' => $row['name']], ['designation_number' => $row['designation_number']]);
        }
    }

    private function seedEmployees(): void
    {
        $sales = Department::where('name', '営業部')->first();
        $dev = Department::where('name', '開発部')->first();
        $store = Department::where('name', '店舗運営')->first();
        $jobGeneral = JobTitle::where('name', '一般')->first();
        $jobLead = JobTitle::where('name', '主任')->first();

        $defs = [
            'yamada' => [
                'user' => [
                    'name' => '山田 太郎',
                    'email' => 'demo.yamada@example.com',
                    'department_id' => $sales?->id,
                    'birth_date' => '1985-03-15',
                    'postal_code' => '100-0001',
                    'address' => '東京都千代田区丸の内1-1-1',
                    'joined_at' => '2018-04-01',
                    'phone' => '090-1111-0001',
                ],
                'payroll' => [
                    'employee_no' => 'E001',
                    'business_location_id' => $this->mainLocation->id,
                    'job_title_id' => $jobLead?->id,
                    'employment_type' => 'full_time',
                    'pay_type' => 'monthly',
                    'base_salary' => 350000,
                    'tax_table' => 'kou',
                    'dependents_count' => 2,
                    'standard_reward_grade_health' => 20,
                    'standard_reward_health' => 300000,
                    'standard_reward_grade_pension' => 18,
                    'standard_reward_pension' => 280000,
                    'commute_allowance_non_taxable' => 15000,
                    'resident_tax_monthly' => 12500,
                    'resident_tax_june' => 25000,
                    'resident_tax_municipality' => '千代田区',
                    'bank_name' => 'みずほ銀行',
                    'bank_code' => '0001',
                    'branch_name' => '丸の内支店',
                    'branch_code' => '001',
                    'account_type' => 'ordinary',
                    'account_number' => '1234567',
                    'account_holder_kana' => 'ヤマダタロウ',
                ],
            ],
            'sato' => [
                'user' => [
                    'name' => '佐藤 花子',
                    'email' => 'demo.sato@example.com',
                    'department_id' => $dev?->id,
                    'birth_date' => '1990-07-22',
                    'postal_code' => '150-0001',
                    'address' => '東京都渋谷区神南1-2-3',
                    'joined_at' => '2020-04-01',
                    'phone' => '090-1111-0002',
                ],
                'payroll' => [
                    'employee_no' => 'E002',
                    'business_location_id' => $this->mainLocation->id,
                    'job_title_id' => $jobGeneral?->id,
                    'employment_type' => 'full_time',
                    'pay_type' => 'monthly',
                    'base_salary' => 280000,
                    'tax_table' => 'kou',
                    'dependents_count' => 1,
                    'standard_reward_grade_health' => 18,
                    'standard_reward_health' => 260000,
                    'standard_reward_grade_pension' => 16,
                    'standard_reward_pension' => 240000,
                    'commute_allowance_non_taxable' => 10000,
                    'resident_tax_monthly' => 8500,
                    'resident_tax_municipality' => '渋谷区',
                    'bank_name' => '三菱UFJ銀行',
                    'bank_code' => '0005',
                    'branch_name' => '渋谷支店',
                    'branch_code' => '045',
                    'account_type' => 'ordinary',
                    'account_number' => '7654321',
                    'account_holder_kana' => 'サトウハナコ',
                ],
            ],
            'suzuki' => [
                'user' => [
                    'name' => '鈴木 一郎',
                    'email' => 'demo.suzuki@example.com',
                    'department_id' => $store?->id,
                    'birth_date' => '1995-11-08',
                    'postal_code' => '150-0043',
                    'address' => '東京都渋谷区道玄坂2-1-1',
                    'joined_at' => '2022-10-01',
                ],
                'payroll' => [
                    'employee_no' => 'E003',
                    'business_location_id' => $this->mainLocation->id,
                    'employment_type' => 'part_time',
                    'pay_type' => 'monthly',
                    'base_salary' => 220000,
                    'tax_table' => 'otsu',
                    'dependents_count' => 0,
                    'is_social_insurance_enrolled' => false,
                    'is_employment_insurance_enrolled' => true,
                    'resident_tax_monthly' => 5000,
                    'resident_tax_municipality' => '渋谷区',
                ],
            ],
            'tanaka' => [
                'user' => [
                    'name' => '田中 美咲',
                    'email' => 'demo.tanaka@example.com',
                    'department_id' => $sales?->id,
                    'birth_date' => '1998-01-30',
                    'joined_at' => '2023-04-01',
                ],
                'payroll' => [
                    'employee_no' => 'E004',
                    'business_location_id' => $this->branchLocation->id,
                    'employment_type' => 'contract',
                    'pay_type' => 'monthly',
                    'base_salary' => 250000,
                    'tax_table' => 'kou',
                    'dependents_count' => 0,
                    'standard_reward_grade_health' => 16,
                    'standard_reward_health' => 220000,
                    'standard_reward_grade_pension' => 14,
                    'standard_reward_pension' => 200000,
                    'bank_name' => 'ゆうちょ銀行',
                    'bank_code' => '9900',
                    'branch_name' => '〇一八店',
                    'branch_code' => '018',
                    'account_type' => 'ordinary',
                    'account_number' => '1234567',
                    'account_holder_kana' => 'タナカミサキ',
                ],
            ],
            'takahashi' => [
                'user' => [
                    'name' => '高橋 健太',
                    'email' => 'demo.takahashi@example.com',
                    'department_id' => $dev?->id,
                    'birth_date' => '1982-05-10',
                    'postal_code' => '160-0022',
                    'address' => '東京都新宿区新宿3-1-1',
                    'joined_at' => '2015-04-01',
                    'is_active' => true,
                ],
                'payroll' => [
                    'employee_no' => 'E005',
                    'business_location_id' => $this->mainLocation->id,
                    'employment_type' => 'full_time',
                    'pay_type' => 'monthly',
                    'base_salary' => 320000,
                    'tax_table' => 'kou',
                    'dependents_count' => 1,
                    'standard_reward_grade_health' => 19,
                    'standard_reward_health' => 280000,
                    'standard_reward_grade_pension' => 17,
                    'standard_reward_pension' => 260000,
                ],
            ],
        ];

        foreach ($defs as $key => $def) {
            // 氏名を姓/名へ分割（最初の空白で区切る）
            $nameParts = preg_split('/[\s\x{3000}]+/u', trim($def['user']['name']), 2);
            $user = User::updateOrCreate(
                ['email' => $def['user']['email']],
                array_merge($def['user'], [
                    'last_name' => $nameParts[0] ?? $def['user']['name'],
                    'first_name' => $nameParts[1] ?? '',
                    'password' => Hash::make(self::PASSWORD),
                    'is_active' => $def['user']['is_active'] ?? true,
                    'role' => 1,
                ]),
            );

            EmployeePayroll::updateOrCreate(
                ['user_id' => $user->id],
                array_merge([
                    'closing_date_group_id' => $this->closingGroup?->id,
                    'is_social_insurance_enrolled' => true,
                    'is_employment_insurance_enrolled' => true,
                    'is_care_insurance_target' => false,
                ], $def['payroll']),
            );

            $user->refresh();
            $this->seedEmployeePayItemValues($user, $def['payroll']['pay_type'] ?? 'monthly', $def['pay_item_values'] ?? []);

            $this->users[$key] = $user;
        }
    }

    /**
     * 給与情報タブ「支給項目」の従業員別金額を投入（月給の基本給等）。
     * 時給1/2・日給1/2 は employee_payroll 列が UI の単価欄。
     */
    private function seedEmployeePayItemValues(User $user, string $payType, array $overrides = []): void
    {
        $ep = $user->employeePayroll;
        if (! $ep) {
            return;
        }

        $masters = PayItemMaster::query()
            ->where('pay_type', $payType)
            ->where('calc_method', 'employee')
            ->where('category', '!=', 'commute')
            ->get();

        foreach ($masters as $m) {
            $amount = $overrides[$m->code] ?? match ($m->code) {
                'base_salary' => (int) $ep->base_salary,
                default => 0,
            };

            EmployeePayItemValue::updateOrCreate(
                ['user_id' => $user->id, 'pay_item_master_id' => $m->id],
                ['amount' => $amount],
            );
        }
    }

    /** 2024年（年末調整・定額減税）と 2026年（現行給与）の勤怠。 */
    private function seedAttendances(): void
    {
        foreach ($this->users as $user) {
            // PayrollVerificationSeeder の検証用従業員は上書きしない
            if (str_starts_with($user->email, 'verify.')) {
                continue;
            }
            $deptId = $user->department_id;
            foreach ([2024, 2026] as $year) {
                for ($month = 1; $month <= 12; $month++) {
                    if ($year === 2026 && $month > 8) {
                        continue;
                    }
                    $this->seedMonthAttendance($user, $year, $month, $deptId);
                }
            }
        }

        // 8/20 以降の打刻を削除（月途中の今日基準）
        Attendance::whereIn('user_id', collect($this->users)->pluck('id'))
            ->where('work_date', '>', self::TODAY_2026)
            ->delete();
    }

    private function seedMonthAttendance(User $user, int $year, int $month, ?int $departmentId): void
    {
        $cursor = Carbon::create($year, $month, 1);
        $end = $cursor->copy()->endOfMonth();
        if ($year === 2026 && $month === 8) {
            $cap = Carbon::parse(self::TODAY_2026);
            if ($end->gt($cap)) {
                $end = $cap;
            }
        }

        while ($cursor->lte($end)) {
            if ($cursor->isWeekend()) {
                $cursor->addDay();
                continue;
            }

            $date = $cursor->format('Y-m-d');
            $in = $cursor->copy()->setTime(9, 0);
            $out = $cursor->copy()->setTime(18, 0);

            // 一部残業・遅刻パターン（山田・2026年6月）
            if ($user->email === 'demo.yamada@example.com' && $year === 2026 && $month === 6 && $cursor->day === 10) {
                $out = $cursor->copy()->setTime(20, 30);
            }
            if ($user->email === 'demo.sato@example.com' && $year === 2026 && $month === 6 && $cursor->day === 5) {
                $in = $cursor->copy()->setTime(9, 25);
            }

            Attendance::updateOrCreate(
                ['user_id' => $user->id, 'work_date' => $date],
                [
                    'department_id' => $departmentId,
                    'clock_in_at' => $in,
                    'clock_out_at' => $out,
                    'break_minutes' => 60,
                ],
            );

            $cursor->addDay();
        }
    }

    private function seedPayroll2024(): void
    {
        $calc = app(PayrollCalculator::class);
        $bonusCalc = app(BonusCalculator::class);

        for ($month = 1; $month <= 12; $month++) {
            $period = sprintf('2024-%02d', $month);
            $run = PayrollRun::updateOrCreate(
                [
                    'business_location_id' => $this->mainLocation->id,
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

            $calc->calculateRun($run);

            if ($month !== 12) {
                $run->update(['status' => 'finalized', 'finalized_at' => now()]);
            } else {
                $run->update(['status' => 'calculated']);
            }
        }

        // 2024年 冬賞与（定額減税対象期間内）
        $bonusRun = PayrollRun::updateOrCreate(
            [
                'business_location_id' => $this->mainLocation->id,
                'period_key' => '2024-12',
                'pay_type' => 'bonus',
            ],
            [
                'payment_date' => '2024-12-10',
                'status' => 'draft',
                'finalized_at' => null,
            ],
        );

        foreach (['yamada', 'sato', 'takahashi'] as $key) {
            $user = $this->users[$key];
            BonusInput::updateOrCreate(
                ['payroll_run_id' => $bonusRun->id, 'user_id' => $user->id],
                ['gross_amount' => $key === 'yamada' ? 600000 : 400000, 'previous_month_taxable' => 300000],
            );
        }
        $bonusCalc->calculateRun($bonusRun);
        $bonusRun->update(['status' => 'finalized', 'finalized_at' => now()]);
    }

    private function markRetiredEmployee(): void
    {
        $user = $this->users['takahashi'];
        $user->update(['is_active' => false]);
    }

    private function seedPayroll2026(): void
    {
        $calc = app(PayrollCalculator::class);
        $bonusCalc = app(BonusCalculator::class);

        for ($month = 1; $month <= 8; $month++) {
            $period = sprintf('2026-%02d', $month);
            $run = PayrollRun::updateOrCreate(
                [
                    'business_location_id' => $this->mainLocation->id,
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

            $calc->calculateRun($run);

            if ($month <= 5) {
                $run->update(['status' => 'finalized', 'finalized_at' => now()]);
            } else {
                // 6・7・8月は確定しない
                $run->update(['status' => 'calculated', 'finalized_at' => null]);
            }
        }

        // 2026年 夏賞与
        $bonusRun = PayrollRun::updateOrCreate(
            [
                'business_location_id' => $this->mainLocation->id,
                'period_key' => '2026-07',
                'pay_type' => 'bonus',
            ],
            [
                'payment_date' => '2026-07-10',
                'status' => 'draft',
                'finalized_at' => null,
            ],
        );
        foreach (['yamada', 'sato', 'tanaka'] as $key) {
            BonusInput::updateOrCreate(
                ['payroll_run_id' => $bonusRun->id, 'user_id' => $this->users[$key]->id],
                ['gross_amount' => 500000, 'previous_month_taxable' => 280000],
            );
        }
        $bonusCalc->calculateRun($bonusRun);
        $bonusRun->update(['status' => 'calculated']);

        // 渋谷支店（田中のみ）
        $branchRun = PayrollRun::updateOrCreate(
            [
                'business_location_id' => $this->branchLocation->id,
                'period_key' => '2026-06',
                'pay_type' => 'salary',
            ],
            [
                'closing_date' => '2026-06-30',
                'payment_date' => '2026-07-25',
                'status' => 'draft',
                'finalized_at' => null,
            ],
        );
        $calc->calculateRun($branchRun);
        $branchRun->update(['status' => 'calculated']);
    }

    private function seedYearEndAdjustments(): void
    {
        $calc = app(YearEndAdjustmentCalculator::class);
        $year = 2024;

        // 山田: 確定済み（源泉徴収票PDFで年調反映を確認）
        $this->upsertYearEnd($this->users['yamada'], $year, [
            'life_insurance_deduction' => 50000,
            'spouse_deduction' => 0,
            'dependent_count' => 2,
            'housing_loan_credit' => 0,
        ], 'confirmed', $calc);

        // 佐藤: 給与反映済み（2024-12給与に年調過不足を反映）
        $adjustment = $this->upsertYearEnd($this->users['sato'], $year, [
            'life_insurance_deduction' => 30000,
            'earthquake_insurance_deduction' => 10000,
            'dependent_count' => 1,
        ], 'confirmed', $calc);

        $decRun = PayrollRun::where('business_location_id', $this->mainLocation->id)
            ->where('period_key', '2024-12')->where('pay_type', 'salary')->first();

        if ($decRun && $adjustment) {
            $payslip = Payslip::where('payroll_run_id', $decRun->id)->where('user_id', $this->users['sato']->id)->first();
            if ($payslip) {
                $sort = (int) $payslip->items()->reorder()->where('item_type', 'deduction')->max('sort_order') + 1;
                $payslip->items()->updateOrCreate(
                    ['item_type' => 'deduction', 'code' => 'year_end_adjustment'],
                    [
                        'name' => '年調過不足税額',
                        'category' => 'tax',
                        'amount' => $adjustment->adjustment_amount,
                        'is_manual_override' => true,
                        'sort_order' => $sort,
                    ],
                );
                $totals = $payslip->items()->reorder()
                    ->selectRaw('item_type, COALESCE(SUM(amount),0) as total')
                    ->groupBy('item_type')->pluck('total', 'item_type');
                $earnings = (int) ($totals['earning'] ?? 0);
                $deductions = (int) ($totals['deduction'] ?? 0);
                $payslip->update([
                    'total_earnings' => $earnings,
                    'total_deductions' => $deductions,
                    'net_pay' => $earnings - $deductions,
                ]);
                $adjustment->update([
                    'status' => 'reflected',
                    'reflected_run_id' => $decRun->id,
                    'reflected_at' => now(),
                ]);
            }
        }

        // 鈴木: 下書き
        $this->upsertYearEnd($this->users['suzuki'], $year, ['dependent_count' => 0], 'draft', $calc);
    }

    private function upsertYearEnd(User $user, int $year, array $inputs, string $status, YearEndAdjustmentCalculator $calc): ?YearEndAdjustment
    {
        $gross = 0;
        $social = 0;
        $withheld = 0;
        $payslips = $user->payslips()->whereHas('payrollRun', fn ($q) => $q->whereBetween('period_key', ["{$year}-01", "{$year}-12"]))->with('items')->get();
        foreach ($payslips as $p) {
            $gross += (int) $p->total_earnings;
            foreach ($p->items->where('item_type', 'deduction') as $item) {
                if (in_array($item->code, ['health_insurance', 'nursing_insurance', 'pension_insurance', 'employment_insurance'], true)) {
                    $social += (int) $item->amount;
                }
                if ($item->code === 'income_tax') {
                    $withheld += (int) $item->amount;
                }
            }
        }

        $result = $calc->compute(array_merge([
            'gross' => $gross,
            'withheld_tax' => $withheld,
            'social_insurance_withheld' => $social,
            'social_insurance_declared' => 0,
            'life_insurance_deduction' => 0,
            'earthquake_insurance_deduction' => 0,
            'spouse_deduction' => 0,
            'dependent_count' => (int) ($user->employeePayroll?->dependents_count ?? 0),
            'housing_loan_credit' => 0,
            'other_deduction' => 0,
        ], $inputs));

        return YearEndAdjustment::updateOrCreate(
            ['user_id' => $user->id, 'year' => $year],
            array_merge([
                'gross_total' => $gross,
                'social_insurance_withheld' => $social,
                'withheld_tax' => $withheld,
                'status' => $status,
            ], $inputs, [
                'salary_income' => $result['salary_income'],
                'taxable_income' => $result['taxable_income'],
                'calculated_tax' => $result['calculated_tax'],
                'yearly_tax' => $result['yearly_tax'],
                'adjustment_amount' => $result['adjustment_amount'],
            ]),
        );
    }

    private function seedReportPatterns(): void
    {
        ReportViewPattern::updateOrCreate(
            ['report_key' => 'summary', 'name' => '基本項目のみ'],
            ['hidden_columns' => ['e_commute_taxable', 'e_commute_non_taxable', 'd_nursing_insurance']],
        );
    }

    private function seedTerminal(): void
    {
        Terminal::updateOrCreate(
            ['terminal_id' => 'demo-tablet01'],
            [
                'name' => '本社打刻端末',
                'terminal_key' => 'demo-terminal-key-' . substr(md5('demo-doden'), 0, 24),
                'description' => 'デモ用（本社受付）',
                'is_active' => true,
            ],
        );
    }

    private function printSummary(): void
    {
        $lines = [
            '',
            '✅ DemoDataSeeder 完了',
            '────────────────────────────────────────',
            '【管理画面】パスワード: ' . self::PASSWORD,
            '  demo.admin@example.com … 全権限（スーパー管理者）',
            '  demo.payroll@example.com … 給与のみ編集可',
            '  demo.attendance@example.com … 勤怠・端末のみ編集可',
            '  demo.viewer@example.com … 全セクション閲覧のみ',
            '',
            '【従業員】パスワード: ' . self::PASSWORD,
            '  E001 山田太郎 … 甲欄・扶養2・定額減税対象・年末調整確定',
            '  E002 佐藤花子 … 甲欄・扶養1・年末調整反映済（2024-12給与）',
            '  E003 鈴木一郎 … 乙欄・定額減税対象外',
            '  E004 田中美咲 … 渋谷支店・2026年給与',
            '  E005 高橋健太 … 退職者（2024年源泉徴収票）',
            '',
            '【給与バッチ】',
            '  2024-01〜12 給与（確定）+ 2024-12 賞与 … 定額減税・年末調整用',
            '  2026-01〜08 給与 + 2026-07 賞与 … 現行操作確認用（6〜8月は計算済・未確定）',
            '',
            '【確認ポイント】',
            '  定額減税 … 2024年6月以降の給与明細（info行）・各人別控除事績簿',
            '  年末調整 … /admin/payroll/year-end → 源泉徴収票PDF',
            '  振込一覧 … 2026-06給与バッチ → 給与振込一覧表',
            '  住民税 … 2026-06給与バッチ → 住民税一覧表',
            '  帳票 … 賃金台帳/源泉徴収簿/支給控除一覧表 等',
            '────────────────────────────────────────',
        ];

        foreach ($lines as $line) {
            $this->command?->info($line);
        }
    }
}
