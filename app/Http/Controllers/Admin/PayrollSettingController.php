<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceItemMaster;
use App\Models\BusinessLocation;
use App\Models\ClosingDateGroup;
use App\Models\DeductionItemMaster;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\FiscalYearCustomHoliday;
use App\Models\FiscalYearHoliday;
use App\Models\InsuranceRate;
use App\Models\InsuranceRateSet;
use App\Models\JobTitle;
use App\Models\PayrollRun;
use App\Models\LeaveType;
use App\Models\PayItemMaster;
use App\Models\PensionFund;
use App\Models\ResidentTaxMunicipality;
use App\Models\Setting;
use App\Services\JapaneseHolidayImporter;
use App\Support\LaborInsuranceRates;
use App\Support\PayrollMasterSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * 給与の基本設定（マスタ編集）ハブ。
 * 設計書06/09/10/11/12 準拠。タブ切替で各マスタをまとめて編集し「保存する」で一括更新。
 */
class PayrollSettingController extends Controller
{
    public function index(Request $request)
    {
        $payItems = PayItemMaster::orderBy('sort_order')->orderBy('id')->get();
        $deductionItems = DeductionItemMaster::orderBy('sort_order')->orderBy('id')->get();
        $attendanceItems = AttendanceItemMaster::orderBy('sort_order')->orderBy('id')->get();

        $locations = BusinessLocation::orderBy('sort_order')->orderBy('id')
            ->with([
                'insuranceRateSets' => fn ($q) => $q->orderByDesc('effective_from')->with('rates'),
                'pensionFunds' => fn ($q) => $q->with(['rates' => fn ($r) => $r->orderByDesc('effective_from')]),
            ])
            ->get();

        return Inertia::render('Admin/Payroll/Settings/Index', [
            'payItems' => $payItems,
            'deductionItems' => $deductionItems,
            'attendanceItems' => $attendanceItems,
            'locations' => $locations,
            'municipalities' => ResidentTaxMunicipality::orderBy('name')->get(['id', 'name', 'designation_number']),
            // 全般タブ
            'general' => $this->generalSettings(),
            'closingDateGroups' => ClosingDateGroup::orderBy('sort_order')->orderBy('id')->get(),
            'jobTitles' => JobTitle::orderBy('sort_order')->orderBy('id')->get(),
            'leaveTypes' => LeaveType::orderBy('sort_order')->orderBy('id')->get(),
            'departments' => Department::orderBy('sort_order')->get(['id', 'name']),
            // 勤怠タブ（旧「設定」ページを統合）
            'attendanceSettings' => $this->attendanceSettings(),
            // 年度設定タブ(se15)
            'fiscalYear' => $this->fiscalYearData($request),
            // 明細設定タブ(se17)
            'payslipSettings' => $this->payslipSettings(),
            'options' => [
                'payCategories' => $this->payCategoryLabels(),
                'calcMethods' => $this->calcMethodLabels(),
                'attendanceCategories' => $this->attendanceCategoryLabels(),
                'unitFormats' => $this->unitFormatLabels(),
                'insuranceKinds' => $this->insuranceKindLabels(),
                'roundings' => ['floor' => '切り捨て', 'round' => '四捨五入', 'ceil' => '切り上げ'],
                'healthInsuranceTypes' => [
                    'kyokai' => '協会けんぽ',
                    'kumiai' => '組合管掌',
                    'kokuho' => '国保組合',
                ],
                'prefectures' => $this->prefectures(),
                'accidentIndustries' => \App\Support\LaborInsuranceRates::accidentIndustryLabels(),
                'employmentIndustries' => \App\Support\LaborInsuranceRates::employmentIndustryLabels(),
                'holidayTypes' => [
                    'weekday' => '平日',
                    'prescribed' => '所定休日',
                    'legal' => '法定休日',
                ],
                'payslipDisplayMonths' => [
                    'payment' => '支給日が属する月',
                    'closing' => '締め日が属する月',
                ],
                'payslipNotifyOptions' => [
                    'none' => '通知なし',
                    'payment' => '支給日',
                    'publish' => '公開日',
                ],
                // 全般タブ用
                'leaveKinds' => [
                    'childcare' => '育児休業',
                    'maternity' => '産前産後休業',
                    'nursing' => '介護休業',
                    'work_injury' => '業務上の傷病による休業',
                    'other' => 'その他',
                ],
                'leavePayCalcMethods' => [
                    'all_zero' => '全て0円',
                    'same_as_normal' => '休職期間外と同じ',
                    'leave_target_only' => '休職・休業の計算対象のみ',
                ],
                'incomeTaxMethods' => [
                    'monthly_table' => '税額表（月額表）を使用する',
                    'computer_special' => '電算機計算の特例を使用する',
                ],
                'docSubmitters' => [
                    'employer' => '事業主',
                    'sharoushi' => '社会保険労務士',
                ],
                'sortKeys' => [
                    'join_date' => '入社年月日',
                    'employee_no_text' => '従業員番号（文字列順）',
                    'employee_no_number' => '従業員番号（数値順）',
                ],
                'sortDirections' => ['asc' => '昇順', 'desc' => '降順'],
                'accountTypes' => ['ordinary' => '普通', 'checking' => '当座'],
                'newPayCalcMethods' => [
                    'manual' => '毎月手入力',
                    'allowance_base' => '割増基礎',
                    'deduction_base' => '控除基礎',
                ],
                'newDeductionCalcMethods' => [
                    'manual' => '毎月手入力',
                    'employee' => '従業員情報で設定',
                ],
                // 支給項目「計算の基礎」プルダウン（MFクラウド準拠: 給与区分別）
                // 月給/時給 → 時給1/時給2、日給 → 日給1/日給2
                'payBasisMethodsByType' => [
                    'monthly' => [
                        'manual' => '毎月手入力',
                        'employee' => '従業員情報で設定',
                        'hourly1' => '時給1',
                        'hourly2' => '時給2',
                        'custom' => 'カスタム計算式',
                    ],
                    'hourly' => [
                        'manual' => '毎月手入力',
                        'employee' => '従業員情報で設定',
                        'hourly1' => '時給1',
                        'hourly2' => '時給2',
                        'custom' => 'カスタム計算式',
                    ],
                    'daily' => [
                        'manual' => '毎月手入力',
                        'employee' => '従業員情報で設定',
                        'daily1' => '日給1',
                        'daily2' => '日給2',
                        'custom' => 'カスタム計算式',
                    ],
                    // MF準拠: 賞与は「手入力」のみ（他の計算方法は選択不可）
                    'bonus' => [
                        'manual' => '手入力',
                    ],
                ],
                // 計算の基礎の全ラベル（既存項目の値表示用: 割増基礎などのレガシー値を含む）
                'basisLabels' => [
                    'manual' => '毎月手入力',
                    'employee' => '従業員情報で設定',
                    'hourly1' => '時給1',
                    'hourly2' => '時給2',
                    'daily1' => '日給1',
                    'daily2' => '日給2',
                    'allowance_base' => '割増基礎',
                    'prev_allowance_base' => '前月の割増基礎',
                    'deduction_base' => '控除基礎',
                    'prev_deduction_base' => '前月の控除基礎',
                    'custom' => 'カスタム計算式',
                ],
            ],
        ]);
    }

    /** 全般タブで扱う会社設定のキー一覧 */
    private const GENERAL_KEYS = [
        'income_tax_calc_method', 'corporate_individual_number', 'social_insurance_doc_submitter',
        'tax_office_name', 'tax_office_sign_number', 'tax_office_number',
        'employee_sort_key', 'employee_sort_direction',
        'payment_account_bank_name', 'payment_account_branch_name', 'payment_account_type',
        'payment_account_number', 'payment_account_holder', 'payment_account_transfer_code',
    ];

    /** @return array<string, mixed> */
    private function generalSettings(): array
    {
        $out = [];
        foreach (self::GENERAL_KEYS as $key) {
            $out[$key] = Setting::getValue($key);
        }
        return $out;
    }

    /**
     * 勤怠タブで扱う会社共通の勤怠・締め設定（旧「設定」ページ由来）。
     *
     * @return array<string, mixed>
     */
    private function attendanceSettings(): array
    {
        return [
            'default_break_minutes' => Setting::getValue('default_break_minutes', '60'),
            'break_start_time' => Setting::getValue('break_start_time', '12:00'),
            'break_end_time' => Setting::getValue('break_end_time', '13:00'),
            'salary_round_minutes' => Setting::getValue('salary_round_minutes', '15'),
            'salary_round_rule' => Setting::getValue('salary_round_rule', 'floor'),
            'punch_use_photo' => Setting::getValue('punch_use_photo', '0') === '1',
            'work_start_time' => Setting::getValue('work_start_time'),
            'work_end_time' => Setting::getValue('work_end_time'),
            'work_hours_per_day' => Setting::getValue('work_hours_per_day'),
            'month_closing_day' => Setting::getValue('month_closing_day'),
            'legal_holiday_dows' => $this->splitDows(Setting::getValue('legal_holiday_dows', 'sunday')),
            'prescribed_holiday_dows' => $this->splitDows(Setting::getValue('prescribed_holiday_dows', 'saturday')),
        ];
    }

    /**
     * "sunday,saturday" 形式の設定値を曜日名の配列へ。
     *
     * @return array<int, string>
     */
    private function splitDows(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($d) => strtolower(trim($d)),
            explode(',', $value),
        )));
    }

    // ---- 年度設定(se15) ---------------------------------------------------

    /** 年度設定タブの表示データ（選択年度・休日・独自休日・月別日数表・給与月度）。 */
    private function fiscalYearData(Request $request): array
    {
        $years = FiscalYear::orderBy('year')->pluck('year')->all();
        if (empty($years)) {
            return ['years' => [], 'selected' => null, 'fiscalYear' => null, 'monthlyDayTable' => null, 'payMonths' => []];
        }

        $selected = (int) $request->query('fy', (string) (max($years)));
        if (! in_array($selected, $years, true)) {
            $selected = max($years);
        }

        $fy = FiscalYear::with(['holidays', 'customHolidays'])->where('year', $selected)->first();

        return [
            'years' => $years,
            'selected' => $selected,
            'fiscalYear' => $fy ? [
                'id' => $fy->id,
                'year' => $fy->year,
                'name' => $fy->name,
                'work_hours_per_day_minutes' => $fy->work_hours_per_day_minutes,
                'monthly_avg_work_days' => $fy->monthly_avg_work_days,
                'monthly_avg_work_hours' => $fy->monthly_avg_work_hours,
                'holidays' => $fy->holidays->map(fn ($h) => ['dow' => $h->dow, 'type' => $h->type])->values(),
                // 独自休日設定エディタ用: 手入力分のみ（内閣府取込分は読み取り専用のため除外）
                'custom_holidays' => $fy->customHolidays
                    ->where('source', FiscalYearCustomHoliday::SOURCE_MANUAL)
                    ->map(fn ($c) => [
                        'id' => $c->id,
                        'date' => $c->date instanceof \DateTimeInterface ? $c->date->format('Y-m-d') : (string) $c->date,
                        'label' => $c->label,
                    ])->values(),
                // 祝日一覧モーダル用: 手入力+内閣府取込を日付順で全件
                'holiday_list' => $fy->customHolidays
                    ->sortBy(fn ($c) => $c->date instanceof \DateTimeInterface ? $c->date->format('Y-m-d') : (string) $c->date)
                    ->map(fn ($c) => [
                        'date' => $c->date instanceof \DateTimeInterface ? $c->date->format('Y-m-d') : (string) $c->date,
                        'label' => $c->label,
                        'source' => $c->source,
                    ])->values(),
                'holidays_imported_at' => $fy->holidays_imported_at?->toIso8601String(),
            ] : null,
            'monthlyDayTable' => $fy ? $fy->monthlyDayTable() : null,
            'payMonths' => $fy ? $this->payMonths($fy) : [],
        ];
    }

    /**
     * 給与月度: 締め日グループごとに、対象年度の各月の 締め日/支給日/公開日/所定労働日数/ステータス を算出。
     *
     * @return array<int, array{group:string, months:array<int, array<string,mixed>>}>
     */
    private function payMonths(FiscalYear $fy): array
    {
        $groups = ClosingDateGroup::orderBy('sort_order')->orderBy('id')->get();
        $table = collect($fy->monthlyDayTable()['months'])->keyBy('month');

        // 対象年度に確定済みの run がある period_key を収集
        $finalized = PayrollRun::whereYear('closing_date', $fy->year)
            ->where('status', 'finalized')
            ->pluck('period_key')
            ->flip();

        $out = [];
        foreach ($groups as $g) {
            $months = [];
            for ($m = 1; $m <= 12; $m++) {
                $base = \Carbon\CarbonImmutable::create($fy->year, $m, 1);
                $closing = $this->dayOfMonth($base, (int) $g->closing_day);
                $payMonthBase = $base->addMonths((int) $g->payment_month_offset);
                $payment = $this->dayOfMonth($payMonthBase, (int) $g->payment_day);
                $publish = $payment->subDay();
                $periodKey = sprintf('%04d-%02d', $fy->year, $m);
                $months[] = [
                    'month' => $m,
                    'closing_date' => $closing->format('Y-m-d'),
                    'payment_date' => $payment->format('Y-m-d'),
                    'publish_date' => $publish->format('Y-m-d'),
                    'work_days' => $table[$m]['work_days'] ?? null,
                    'status' => $finalized->has($periodKey) ? 'finalized' : 'draft',
                ];
            }
            $out[] = ['group' => $g->name, 'months' => $months];
        }

        return $out;
    }

    /** 月内の指定日を返す（月末超過は月末に丸める）。 */
    private function dayOfMonth(\Carbon\CarbonImmutable $base, int $day): \Carbon\CarbonImmutable
    {
        $last = (int) $base->daysInMonth;

        return $base->day(min(max($day, 1), $last));
    }

    /** 直近年度を複製して新しい年度を作成（休日設定・所定労働をコピー）。 */
    public function storeFiscalYear(Request $request, JapaneseHolidayImporter $importer)
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100', 'unique:fiscal_years,year'],
            'import_holidays' => ['nullable', 'boolean'],
        ]);

        $source = FiscalYear::with('holidays')->orderByDesc('year')->first();

        DB::transaction(function () use ($data, $source) {
            $fy = FiscalYear::create([
                'year' => $data['year'],
                'work_hours_per_day_minutes' => $source?->work_hours_per_day_minutes ?? 480,
                'monthly_avg_work_days' => $source?->monthly_avg_work_days,
                'monthly_avg_work_hours' => $source?->monthly_avg_work_hours,
            ]);

            if ($source) {
                foreach ($source->holidays as $h) {
                    FiscalYearHoliday::create(['fiscal_year_id' => $fy->id, 'dow' => $h->dow, 'type' => $h->type]);
                }
            } else {
                for ($dow = 0; $dow <= 7; $dow++) {
                    $type = $dow === 0 ? 'legal' : ($dow === 6 ? 'prescribed' : 'weekday');
                    FiscalYearHoliday::create(['fiscal_year_id' => $fy->id, 'dow' => $dow, 'type' => $type]);
                }
            }
        });

        // 任意: 作成年度の祝日を内閣府CSVから自動取込（失敗しても年度作成は成立させる）
        if ($request->boolean('import_holidays')) {
            try {
                $count = $importer->importYear((int) $data['year']);
                $suffix = $count > 0
                    ? "（祝日 {$count} 件を取り込みました）"
                    : '（祝日データは未掲載のため取り込めませんでした。例年2月頃に再取込してください）';

                return back()->with('success', "{$data['year']}年度を作成しました{$suffix}");
            } catch (\Throwable $e) {
                return back()->with('success', "{$data['year']}年度を作成しました（祝日の自動取込に失敗: {$e->getMessage()}）");
            }
        }

        return back()->with('success', "{$data['year']}年度を作成しました");
    }

    /** 年度の休日設定・独自休日・所定労働を更新（全置換）。 */
    public function updateFiscalYear(Request $request, FiscalYear $fiscalYear)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'work_hours_per_day_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'monthly_avg_work_days' => ['nullable', 'numeric', 'min:0', 'max:31'],
            'monthly_avg_work_hours' => ['nullable', 'numeric', 'min:0', 'max:744'],
            'holidays' => ['array'],
            'holidays.*.dow' => ['required', 'integer', 'min:0', 'max:7'],
            'holidays.*.type' => ['required', 'in:weekday,prescribed,legal'],
            'custom_holidays' => ['array'],
            'custom_holidays.*.date' => ['required', 'date'],
            'custom_holidays.*.label' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $fiscalYear) {
            $fiscalYear->update([
                'name' => $data['name'] ?? null,
                'work_hours_per_day_minutes' => $data['work_hours_per_day_minutes'] ?? null,
                'monthly_avg_work_days' => $data['monthly_avg_work_days'] ?? null,
                'monthly_avg_work_hours' => $data['monthly_avg_work_hours'] ?? null,
            ]);

            $fiscalYear->holidays()->delete();
            foreach ($data['holidays'] ?? [] as $h) {
                FiscalYearHoliday::create(['fiscal_year_id' => $fiscalYear->id, 'dow' => $h['dow'], 'type' => $h['type']]);
            }

            // 手入力分のみ全置換（内閣府取込分 source='cabinet_office' は保持）
            $fiscalYear->customHolidays()
                ->where('source', FiscalYearCustomHoliday::SOURCE_MANUAL)
                ->delete();
            foreach ($data['custom_holidays'] ?? [] as $c) {
                FiscalYearCustomHoliday::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'date' => $c['date'],
                    'label' => $c['label'] ?? null,
                    'source' => FiscalYearCustomHoliday::SOURCE_MANUAL,
                ]);
            }
        });

        return back()->with('success', '年度設定を保存しました');
    }

    /** 内閣府CSVから対象年度の祝日を取り込む（内閣府由来のみ入替、手入力は保持）。 */
    public function importFiscalYearHolidays(FiscalYear $fiscalYear, JapaneseHolidayImporter $importer)
    {
        try {
            $count = $importer->importYear($fiscalYear->year);
        } catch (\Throwable $e) {
            return back()->with('error', '祝日の取り込みに失敗しました: '.$e->getMessage());
        }

        if ($count === 0) {
            return back()->with('error', "{$fiscalYear->year}年の祝日データが内閣府CSVに見つかりませんでした。翌年分は例年2月頃に掲載されます。");
        }

        return back()->with('success', "{$fiscalYear->year}年の祝日を {$count} 件取り込みました");
    }

    // ---- 明細設定(se17) ---------------------------------------------------

    /** 明細設定で扱う設定キー（真偽値は '1'/'0' 文字列で保持）。 */
    private const PAYSLIP_KEYS = [
        'payslip_display_month', 'payslip_show_target_period', 'payslip_show_affiliation',
        'payslip_show_department', 'payslip_show_attendance', 'payslip_show_ytd',
        'payslip_show_hourly', 'payslip_show_standard_monthly', 'payslip_show_dependents',
        'payslip_show_tax_category', 'payslip_notify', 'bonus_notify',
    ];

    /** @return array<string, mixed> */
    private function payslipSettings(): array
    {
        $defaults = [
            'payslip_display_month' => 'payment',
            'payslip_show_target_period' => '1',
            'payslip_show_affiliation' => '1',
            'payslip_show_department' => '1',
            'payslip_show_attendance' => '1',
            'payslip_show_ytd' => '0',
            'payslip_show_hourly' => '1',
            'payslip_show_standard_monthly' => '0',
            'payslip_show_dependents' => '0',
            'payslip_show_tax_category' => '0',
            'payslip_notify' => 'none',
            'bonus_notify' => 'none',
        ];

        $out = [];
        foreach (self::PAYSLIP_KEYS as $key) {
            $out[$key] = Setting::getValue($key, $defaults[$key] ?? null);
        }

        return $out;
    }

    public function updatePayslipSettings(Request $request)
    {
        $data = $request->validate([
            'payslip_display_month' => ['required', 'in:payment,closing'],
            'payslip_show_target_period' => ['boolean'],
            'payslip_show_affiliation' => ['boolean'],
            'payslip_show_department' => ['boolean'],
            'payslip_show_attendance' => ['boolean'],
            'payslip_show_ytd' => ['boolean'],
            'payslip_show_hourly' => ['boolean'],
            'payslip_show_standard_monthly' => ['boolean'],
            'payslip_show_dependents' => ['boolean'],
            'payslip_show_tax_category' => ['boolean'],
        ]);

        Setting::setValue('payslip_display_month', $data['payslip_display_month']);
        foreach ([
            'payslip_show_target_period', 'payslip_show_affiliation', 'payslip_show_department',
            'payslip_show_attendance', 'payslip_show_ytd', 'payslip_show_hourly',
            'payslip_show_standard_monthly', 'payslip_show_dependents', 'payslip_show_tax_category',
        ] as $key) {
            Setting::setValue($key, ! empty($data[$key]) ? '1' : '0');
        }

        return back()->with('success', '明細設定を保存しました');
    }

    public function updatePayslipNotify(Request $request)
    {
        $data = $request->validate([
            'payslip_notify' => ['required', 'in:none,payment,publish'],
            'bonus_notify' => ['required', 'in:none,payment,publish'],
        ]);

        Setting::setValue('payslip_notify', $data['payslip_notify']);
        Setting::setValue('bonus_notify', $data['bonus_notify']);

        return back()->with('success', '通知設定を保存しました');
    }

    public function updateGeneral(Request $request)
    {
        $data = $request->validate([
            'income_tax_calc_method' => ['nullable', 'in:monthly_table,computer_special'],
            'corporate_individual_number' => ['nullable', 'string', 'max:20'],
            'social_insurance_doc_submitter' => ['nullable', 'in:employer,sharoushi'],
            'tax_office_name' => ['nullable', 'string', 'max:255'],
            'tax_office_sign_number' => ['nullable', 'string', 'max:50'],
            'tax_office_number' => ['nullable', 'string', 'max:50'],
            'employee_sort_key' => ['nullable', 'in:join_date,employee_no_text,employee_no_number'],
            'employee_sort_direction' => ['nullable', 'in:asc,desc'],
            'payment_account_bank_name' => ['nullable', 'string', 'max:255'],
            'payment_account_branch_name' => ['nullable', 'string', 'max:255'],
            'payment_account_type' => ['nullable', 'in:ordinary,checking'],
            'payment_account_number' => ['nullable', 'string', 'max:20'],
            'payment_account_holder' => ['nullable', 'string', 'max:255'],
            'payment_account_transfer_code' => ['nullable', 'string', 'max:20'],
        ]);

        foreach ($data as $key => $value) {
            Setting::setValue($key, $value);
        }

        return back()->with('success', '全般設定を保存しました');
    }

    // ---- 締め日グループ ----------------------------------------------------
    public function storeClosingGroup(Request $request)
    {
        $data = $this->validateClosingGroup($request);
        ClosingDateGroup::create($data);
        return back()->with('success', '締め日グループを追加しました');
    }

    public function updateClosingGroup(Request $request, ClosingDateGroup $group)
    {
        $group->update($this->validateClosingGroup($request));
        return back()->with('success', '締め日グループを更新しました');
    }

    public function destroyClosingGroup(ClosingDateGroup $group)
    {
        if ($group->employeePayrolls()->exists()) {
            return back()->with('error', 'この締め日グループは従業員に割り当てられているため削除できません');
        }
        $group->delete();
        return back()->with('success', '締め日グループを削除しました');
    }

    /** @return array<string, mixed> */
    private function validateClosingGroup(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'closing_day' => ['required', 'integer', 'min:1', 'max:31'],
            'payment_day' => ['required', 'integer', 'min:1', 'max:31'],
            'payment_month_offset' => ['required', 'integer', 'min:0', 'max:2'],
            'sort_order' => ['nullable', 'integer'],
        ]);
    }

    // ---- 職種 --------------------------------------------------------------
    public function storeJobTitle(Request $request)
    {
        JobTitle::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
        ]));
        return back()->with('success', '職種を追加しました');
    }

    public function updateJobTitle(Request $request, JobTitle $jobTitle)
    {
        $jobTitle->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
        ]));
        return back()->with('success', '職種を更新しました');
    }

    public function destroyJobTitle(JobTitle $jobTitle)
    {
        if ($jobTitle->employeePayrolls()->exists()) {
            return back()->with('error', 'この職種は従業員に割り当てられているため削除できません');
        }
        $jobTitle->delete();
        return back()->with('success', '職種を削除しました');
    }

    // ---- 休職・休業種別 ----------------------------------------------------
    public function storeLeaveType(Request $request)
    {
        $data = $this->validateLeaveType($request);
        $data['code'] = $data['code'] ?? 'leave_'.Str::lower(Str::random(6));
        LeaveType::create($data);
        return back()->with('success', '休職・休業種別を追加しました');
    }

    public function updateLeaveType(Request $request, LeaveType $leaveType)
    {
        $leaveType->update($this->validateLeaveType($request, $leaveType->id));
        return back()->with('success', '休職・休業種別を更新しました');
    }

    public function destroyLeaveType(LeaveType $leaveType)
    {
        if ($leaveType->employeeLeaves()->exists()) {
            return back()->with('error', 'この種別は従業員に使用されているため削除できません');
        }
        $leaveType->delete();
        return back()->with('success', '休職・休業種別を削除しました');
    }

    /** @return array<string, mixed> */
    private function validateLeaveType(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code' => ['nullable', 'string', 'max:50', 'unique:leave_types,code'.($id ? ",{$id}" : '')],
            'name' => ['required', 'string', 'max:255'],
            'leave_kind' => ['required', 'in:childcare,maternity,nursing,work_injury,other'],
            'pay_calc_method' => ['required', 'in:all_zero,same_as_normal,leave_target_only'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);
    }

    // ---- 支給項目の行追加・削除 --------------------------------------------
    public function storePayItem(Request $request)
    {
        $data = $request->validate([
            'pay_type' => ['required', 'in:monthly,hourly,daily,bonus'],
            'name' => ['required', 'string', 'max:255'],
            'calc_method' => ['required', 'in:manual,employee,hourly1,hourly2,daily1,daily2,allowance_base,prev_allowance_base,deduction_base,prev_deduction_base,custom'],
            'is_active' => ['boolean'],
            'divisor_unit' => ['nullable', 'string', 'max:64'],
            'multiplier' => ['nullable', 'numeric', 'min:0', 'max:9.999'],
            'quantity_unit' => ['nullable', 'string', 'max:255'],
            'is_income_tax_target' => ['boolean'],
            'is_labor_insurance_target' => ['boolean'],
            'is_social_insurance_target' => ['boolean'],
        ]);

        // MF準拠: 賞与は手入力のみ
        if ($data['pay_type'] === 'bonus') {
            $data['calc_method'] = 'manual';
        }

        // 時給/日給・割増/控除ベース、および「従業員情報で設定」も「÷単位×倍率×勤怠」で編集できる（MF準拠）
        $builderMethods = ['employee', 'hourly1', 'hourly2', 'daily1', 'daily2', 'allowance_base', 'prev_allowance_base', 'deduction_base', 'prev_deduction_base'];
        $isRate = in_array($data['calc_method'], $builderMethods, true);

        $code = 'custom_'.Str::lower(Str::random(8));
        PayItemMaster::create([
            'pay_type' => $data['pay_type'],
            'code' => $code,
            'name' => $data['name'],
            'category' => 'custom',
            'is_active' => $data['is_active'] ?? true,
            'calc_method' => $data['calc_method'],
            'divisor_unit' => $isRate ? ($data['divisor_unit'] ?? 'one') : null,
            'multiplier' => $isRate ? ($data['multiplier'] ?? 1.0) : null,
            'quantity_unit' => $isRate ? ($data['quantity_unit'] ?: null) : null,
            'is_income_tax_target' => $data['is_income_tax_target'] ?? true,
            'is_labor_insurance_target' => $data['is_labor_insurance_target'] ?? true,
            'is_social_insurance_target' => $data['is_social_insurance_target'] ?? true,
            'sign' => 'plus',
            'rounding' => 'round',
            // 手入力連動のため 0円でも計算画面に表示する
            'show_zero' => true,
            'is_system' => false,
            'sort_order' => (int) PayItemMaster::where('pay_type', $data['pay_type'])->max('sort_order') + 1,
        ]);

        return back()->with('success', '支給項目を追加しました。給与計算画面で金額を入力できます。');
    }

    public function destroyPayItem(PayItemMaster $payItem)
    {
        if ($payItem->is_system) {
            return back()->with('error', 'システム標準の支給項目は削除できません');
        }
        $payItem->delete();
        return back()->with('success', '支給項目を削除しました');
    }

    // ---- 控除項目の行追加・削除 --------------------------------------------
    public function storeDeductionItem(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'calc_method' => ['required', 'in:manual,employee'],
        ]);

        DeductionItemMaster::create([
            'code' => 'custom_'.Str::lower(Str::random(8)),
            'name' => $data['name'],
            'category' => 'custom',
            'is_active' => true,
            'calc_method' => $data['calc_method'],
            'show_zero' => true,
            'is_system' => false,
            'sort_order' => (int) DeductionItemMaster::max('sort_order') + 1,
        ]);

        return back()->with('success', '控除項目を追加しました。給与計算画面で金額を入力できます。');
    }

    public function destroyDeductionItem(DeductionItemMaster $deductionItem)
    {
        if ($deductionItem->is_system) {
            return back()->with('error', 'システム標準の控除項目は削除できません');
        }
        $deductionItem->delete();
        return back()->with('success', '控除項目を削除しました');
    }

    // ---- 勤怠項目の行追加・削除 --------------------------------------------
    public function storeAttendanceItem(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:fixed_work,attendance,actual_work,leave'],
            'unit_format' => ['required', 'in:hour,hour_1,hour_decimal,hour_min60,day,day_decimal,count'],
        ]);

        AttendanceItemMaster::create([
            'code' => 'custom_'.Str::lower(Str::random(8)),
            'name' => $data['name'],
            'category' => $data['category'],
            'is_active' => true,
            'unit_format' => $data['unit_format'],
            'show_zero' => true,
            'is_system' => false,
            'sort_order' => (int) AttendanceItemMaster::where('category', $data['category'])->max('sort_order') + 1,
        ]);

        return back()->with('success', '勤怠項目を追加しました');
    }

    public function destroyAttendanceItem(AttendanceItemMaster $attendanceItem)
    {
        if ($attendanceItem->is_system) {
            return back()->with('error', 'システム標準の勤怠項目は削除できません');
        }
        $attendanceItem->delete();
        return back()->with('success', '勤怠項目を削除しました');
    }

    // ---- 社会保険料率セットの追加 ------------------------------------------
    public function storeInsuranceSet(Request $request)
    {
        $data = $request->validate([
            'business_location_id' => ['required', 'exists:business_locations,id'],
            'name' => ['required', 'string', 'max:255'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        DB::transaction(function () use ($data) {
            $set = InsuranceRateSet::create($data);
            $location = BusinessLocation::find($data['business_location_id']);
            $date = $set->effective_from?->toDateString() ?? now()->toDateString();

            // 労災・雇用は事業所の業種（＋メリット制）から適用開始日時点の料率を自動セット。
            // 他の保険種別は0で用意し、画面で改定入力（協会けんぽは「都道府県料率を反映」）。
            $laborDefaults = $this->laborRateDefaults($location, $date);

            foreach (['health', 'nursing', 'child_support', 'pension', 'child_contribution', 'pension_fund', 'employment', 'accident'] as $kind) {
                InsuranceRate::create([
                    'insurance_rate_set_id' => $set->id,
                    'kind' => $kind,
                    'employee_rate' => $laborDefaults[$kind]['employee'] ?? 0,
                    'employer_rate' => $laborDefaults[$kind]['employer'] ?? 0,
                ]);
            }
        });

        return back()->with('success', '保険料率セットを追加しました。労災・雇用は業種から自動反映済みです。他の料率を入力して保存してください。');
    }

    /**
     * 事業所の業種（＋労災メリット制）から、労災・雇用の料率初期値(/1,000)を返す。
     *
     * @return array<string, array{employee: float, employer: float}>
     */
    private function laborRateDefaults(?BusinessLocation $location, string $date): array
    {
        $defaults = [];

        if ($location && ($location->accident_industry_code || $location->accident_merit_enabled)) {
            $defaults['accident'] = ['employee' => 0.0, 'employer' => $location->accidentEmployerRate($date)];
        }

        if ($location && $location->employment_industry_type) {
            $defaults['employment'] = LaborInsuranceRates::employmentRates($location->employment_industry_type, $date);
        }

        return $defaults;
    }

    public function destroyInsuranceSet(InsuranceRateSet $insuranceSet)
    {
        $insuranceSet->delete();
        return back()->with('success', '保険料率セットを削除しました');
    }

    /**
     * 協会けんぽ都道府県別料率を、この料率セットの健康保険・介護保険へ自動反映する。
     * 併せて全国一律の厚生年金・子ども子育て拠出金の標準料率もセットする。
     * いずれも料使折半（従業員=事業主=総額/2）で千分率のまま保存する。
     */
    public function applyKyokaiRates(InsuranceRateSet $insuranceSet)
    {
        $location = $insuranceSet->businessLocation;
        if (! $location || $location->health_insurance_type !== 'kyokai') {
            return back()->with('error', '協会けんぽの事業所のみ自動反映できます（健康保険の種類が「協会けんぽ」の事業所）。');
        }
        if (! $location->prefecture) {
            return back()->with('error', '事業所に都道府県が未設定です。先に事業所の都道府県を設定してください。');
        }

        $date = $insuranceSet->effective_from?->toDateString() ?? now()->toDateString();
        $rate = \App\Models\KyokaiKenpoRate::resolve($location->prefecture, $date);
        if (! $rate) {
            return back()->with('error', "「{$location->prefecture}」の{$date}時点の協会けんぽ料率が未登録です。年度マスタを確認してください。");
        }

        // 総額の折半（千分率のまま）
        $half = fn (float $permille) => round($permille / 2, 3);

        DB::transaction(function () use ($insuranceSet, $rate, $half) {
            $apply = function (string $kind, float $emp, float $empr) use ($insuranceSet) {
                InsuranceRate::updateOrCreate(
                    ['insurance_rate_set_id' => $insuranceSet->id, 'kind' => $kind],
                    ['employee_rate' => $emp, 'employer_rate' => $empr],
                );
            };

            // 健康保険・介護保険（都道府県別／介護は全国一律）
            $apply('health', $half((float) $rate->health_permille), $half((float) $rate->health_permille));
            $apply('nursing', $half((float) $rate->nursing_permille), $half((float) $rate->nursing_permille));
            // 厚生年金（全国一律 総額183.00‰）・子ども子育て拠出金（事業主のみ 3.60‰）
            $apply('pension', 91.500, 91.500);
            $apply('child_contribution', 0.000, 3.600);
            // 子ども・子育て支援金（2026年4月〜。従業員・会社ともに 1.15‰）
            $apply('child_support', 1.150, 1.150);
        });

        return back()->with('success', "協会けんぽ（{$location->prefecture}・{$date}）の料率を反映しました。厚生年金・拠出金・子ども子育て支援金の標準値もセットしました。内容をご確認ください。");
    }

    /**
     * MFクラウド準拠の社会保険セクション（健康保険 / 厚生年金保険 / 厚生年金基金）を一括保存する。
     * セクション単位で送信されるが、事業所メタ項目と最新料率セットの料率をまとめて更新する。
     */
    public function updateSocialInsuranceConfig(Request $request, BusinessLocation $location)
    {
        $data = $request->validate([
            'section' => ['required', 'in:health,pension'],

            // 健康保険
            'health_insurance_type' => ['nullable', 'string', 'in:kyokai,kumiai,kokuho'],
            'prefecture' => ['nullable', 'string', 'max:20'],
            'health_union_name' => ['nullable', 'string', 'max:255'],
            'health_office_symbol' => ['nullable', 'string', 'max:100'],

            // 厚生年金保険
            'pension_jurisdiction' => ['nullable', 'string', 'max:100'],
            'pension_office_number' => ['nullable', 'string', 'max:100'],
            'pension_office_symbol' => ['nullable', 'string', 'max:100'],

            // 料率（kind => {employee_rate, employer_rate}）。省略された kind は更新しない
            'rates' => ['array'],
            'rates.*.employee_rate' => ['required', 'numeric', 'min:0', 'max:1000'],
            'rates.*.employer_rate' => ['required', 'numeric', 'min:0', 'max:1000'],
        ]);

        $section = $data['section'];

        // セクションごとに更新対象の事業所メタ項目・料率 kind を限定する
        $metaByCategory = [
            'health' => ['health_insurance_type', 'prefecture', 'health_union_name', 'health_office_symbol'],
            'pension' => ['pension_jurisdiction', 'pension_office_number', 'pension_office_symbol'],
        ];
        $allowedKindsByCategory = [
            'health' => ['health', 'nursing', 'child_support'],
            'pension' => ['pension', 'child_contribution'],
        ];

        DB::transaction(function () use ($location, $data, $section, $metaByCategory, $allowedKindsByCategory) {
            $meta = [];
            foreach ($metaByCategory[$section] as $field) {
                if (array_key_exists($field, $data)) {
                    $meta[$field] = $data[$field];
                }
            }
            if ($meta !== []) {
                $location->update($meta);
            }

            $set = $location->insuranceRateSets()->orderByDesc('effective_from')->first();
            if ($set) {
                $allowed = $allowedKindsByCategory[$section];
                foreach ($data['rates'] ?? [] as $kind => $row) {
                    if (! in_array($kind, $allowed, true)) {
                        continue;
                    }
                    InsuranceRate::updateOrCreate(
                        ['insurance_rate_set_id' => $set->id, 'kind' => $kind],
                        [
                            'employee_rate' => $row['employee_rate'],
                            'employer_rate' => $row['employer_rate'],
                        ],
                    );
                }
            }
        });

        $labels = ['health' => '健康保険', 'pension' => '厚生年金保険'];

        return back()->with('success', "{$labels[$section]}を更新しました。");
    }

    /**
     * 労働保険（労災・雇用）の事業所情報をセクション別に更新する（MFクラウド準拠モーダル保存）。
     * 業種変更時は最新料率セットへ自動反映する。
     */
    public function updateLaborInsuranceConfig(Request $request, BusinessLocation $location)
    {
        $section = $request->validate([
            'section' => ['required', 'in:accident,employment'],
        ])['section'];

        if ($section === 'accident') {
            $data = $request->validate([
                'section' => ['required', 'in:accident,employment'],
                'labor_bureau' => ['nullable', 'string', 'max:255'],
                'labor_insurance_pref_code' => ['nullable', 'string', 'max:2'],
                'labor_insurance_jurisdiction_code' => ['nullable', 'string', 'max:1'],
                'labor_insurance_office_code' => ['nullable', 'string', 'max:2'],
                'labor_insurance_serial_number' => ['nullable', 'string', 'max:6'],
                'labor_insurance_branch_code' => ['nullable', 'string', 'max:3'],
                'accident_business_desc' => ['nullable', 'string', 'max:255'],
                'accident_industry_code' => ['nullable', 'string', 'max:64'],
                'accident_merit_enabled' => ['boolean'],
                'accident_merit_rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            ]);
            $data['accident_merit_enabled'] = $data['accident_merit_enabled'] ?? false;
            if (! $data['accident_merit_enabled']) {
                $data['accident_merit_rate'] = null;
            }
        } else {
            $data = $request->validate([
                'section' => ['required', 'in:accident,employment'],
                'employment_bureau' => ['nullable', 'string', 'max:255'],
                'employment_office_number' => ['nullable', 'string', 'max:50'],
                'employment_industry_type' => ['nullable', 'string', 'in:general,agri_sake_forestry,construction'],
            ]);
        }

        DB::transaction(function () use ($location, $data, $section) {
            $fields = $section === 'accident'
                ? [
                    'labor_bureau', 'labor_insurance_pref_code', 'labor_insurance_jurisdiction_code',
                    'labor_insurance_office_code', 'labor_insurance_serial_number', 'labor_insurance_branch_code',
                    'accident_business_desc', 'accident_industry_code', 'accident_merit_enabled', 'accident_merit_rate',
                ]
                : ['employment_bureau', 'employment_office_number', 'employment_industry_type'];

            $meta = [];
            foreach ($fields as $field) {
                if (array_key_exists($field, $data)) {
                    $meta[$field] = $data[$field];
                }
            }

            $location->fill($meta);
            if ($section === 'accident') {
                $composed = $location->composeLaborInsuranceNumber();
                if ($composed !== null) {
                    $location->labor_insurance_number = $composed;
                }
            }
            $location->save();
            $location->syncLaborInsuranceRates();
        });

        $labels = ['accident' => '労災保険', 'employment' => '雇用保険'];

        return back()->with('success', "{$labels[$section]}を更新しました。");
    }

    /**
     * 厚生年金基金を新規登録する（MFクラウド準拠）。基金は1事業所に複数登録できる。
     * 掛金料率は適用開始月単位で、給与・賞与別に被保険者負担／事業主負担（/1,000）を保持する。
     */
    public function storePensionFund(Request $request)
    {
        $data = $this->validatePensionFund($request);

        DB::transaction(function () use ($data) {
            $fund = PensionFund::create([
                'business_location_id' => $data['business_location_id'],
                'name' => $data['name'],
                'number' => $data['number'] ?? null,
                'office_number' => $data['office_number'] ?? null,
                'sort_order' => PensionFund::where('business_location_id', $data['business_location_id'])->count(),
            ]);
            $this->syncPensionFundRates($fund, $data['rates'] ?? []);
        });

        return back()->with('success', '厚生年金基金を登録しました。');
    }

    /**
     * 厚生年金基金を更新する（基金情報＋掛金料率をまとめて置き換える）。
     */
    public function updatePensionFund(Request $request, PensionFund $pensionFund)
    {
        $data = $this->validatePensionFund($request, requireLocation: false);

        DB::transaction(function () use ($pensionFund, $data) {
            $pensionFund->update([
                'name' => $data['name'],
                'number' => $data['number'] ?? null,
                'office_number' => $data['office_number'] ?? null,
            ]);
            $pensionFund->rates()->delete();
            $this->syncPensionFundRates($pensionFund, $data['rates'] ?? []);
        });

        return back()->with('success', '厚生年金基金を更新しました。');
    }

    public function destroyPensionFund(PensionFund $pensionFund)
    {
        $pensionFund->delete();

        return back()->with('success', '厚生年金基金を削除しました。');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePensionFund(Request $request, bool $requireLocation = true): array
    {
        return $request->validate([
            'business_location_id' => [$requireLocation ? 'required' : 'nullable', 'exists:business_locations,id'],
            'name' => ['required', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:100'],
            'office_number' => ['nullable', 'string', 'max:100'],
            'rates' => ['array'],
            'rates.*.effective_from' => ['required', 'date'],
            'rates.*.salary_employee_rate' => ['required', 'numeric', 'min:0', 'max:1000'],
            'rates.*.salary_employer_rate' => ['required', 'numeric', 'min:0', 'max:1000'],
            'rates.*.bonus_employee_rate' => ['required', 'numeric', 'min:0', 'max:1000'],
            'rates.*.bonus_employer_rate' => ['required', 'numeric', 'min:0', 'max:1000'],
        ]);
    }

    /**
     * 掛金料率行を（適用開始月をユニークキーとして）登録する。
     *
     * @param  array<int, array<string, mixed>>  $rates
     */
    private function syncPensionFundRates(PensionFund $fund, array $rates): void
    {
        $seen = [];
        foreach ($rates as $row) {
            $from = \Illuminate\Support\Carbon::parse($row['effective_from'])->startOfMonth()->toDateString();
            if (in_array($from, $seen, true)) {
                continue;
            }
            $seen[] = $from;

            $fund->rates()->create([
                'effective_from' => $from,
                'salary_employee_rate' => $row['salary_employee_rate'],
                'salary_employer_rate' => $row['salary_employer_rate'],
                'bonus_employee_rate' => $row['bonus_employee_rate'],
                'bonus_employer_rate' => $row['bonus_employer_rate'],
            ]);
        }
    }

    /** @return array<int, string> */
    private function prefectures(): array
    {
        return [
            '北海道', '青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県',
            '茨城県', '栃木県', '群馬県', '埼玉県', '千葉県', '東京都', '神奈川県',
            '新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県', '岐阜県',
            '静岡県', '愛知県', '三重県', '滋賀県', '京都府', '大阪府', '兵庫県',
            '奈良県', '和歌山県', '鳥取県', '島根県', '岡山県', '広島県', '山口県',
            '徳島県', '香川県', '愛媛県', '高知県', '福岡県', '佐賀県', '長崎県',
            '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県',
        ];
    }

    public function updatePayItems(Request $request)
    {
        $data = $request->validate([
            'items' => ['array'],
            'items.*.id' => ['required', 'integer', 'exists:pay_item_masters,id'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.is_active' => ['boolean'],
            'items.*.calc_method' => ['required', 'in:manual,employee,hourly1,hourly2,daily1,daily2,allowance_base,prev_allowance_base,deduction_base,prev_deduction_base,custom'],
            'items.*.divisor_unit' => ['nullable', 'string', 'max:64'],
            'items.*.multiplier' => ['nullable', 'numeric', 'min:0', 'max:9.999'],
            'items.*.quantity_unit' => ['nullable', 'string', 'max:255'],
            'items.*.sign' => ['in:plus,minus'],
            'items.*.rounding' => ['in:floor,round,ceil'],
            'items.*.is_income_tax_target' => ['boolean'],
            'items.*.is_labor_insurance_target' => ['boolean'],
            'items.*.is_social_insurance_target' => ['boolean'],
            'items.*.is_fixed_wage' => ['boolean'],
            'items.*.is_in_kind' => ['boolean'],
            'items.*.is_allowance_base' => ['boolean'],
            'items.*.is_deduction_base' => ['boolean'],
            'items.*.show_zero' => ['boolean'],
            'items.*.is_daily_proration_base' => ['boolean'],
            'items.*.sort_order' => ['nullable', 'integer'],
            'items.*.custom_formula' => ['nullable', 'array'],
            'items.*.custom_formula.*.t' => ['required_with:items.*.custom_formula', 'string', 'in:ref,num,op,cmp,fn,paren,comma'],
            'items.*.custom_formula.*.kind' => ['nullable', 'string', 'in:basis,pay,attendance'],
            'items.*.custom_formula.*.code' => ['nullable', 'string', 'max:255'],
            'items.*.custom_formula.*.label' => ['nullable', 'string', 'max:255'],
            'items.*.custom_formula.*.value' => ['nullable'],
        ]);

        DB::transaction(function () use ($data, $request) {
            // custom_formula は validated() だとネストの一部キーが落ちるため、生の input から id で引く
            $rawFormulas = collect($request->input('items', []))->keyBy('id');

            foreach ($data['items'] ?? [] as $row) {
                $item = PayItemMaster::find($row['id']);
                if (! $item) {
                    continue;
                }
                // モデル経由で保存し custom_formula(array) を JSON へ正しくキャスト
                $item->fill([
                    'name' => $row['name'],
                    'is_active' => $row['is_active'] ?? false,
                    'calc_method' => $row['calc_method'],
                    'divisor_unit' => $row['divisor_unit'] ?? null,
                    'multiplier' => $row['multiplier'] ?? null,
                    'quantity_unit' => $row['quantity_unit'] ?? null,
                    'sign' => $row['sign'] ?? 'plus',
                    'rounding' => $row['rounding'] ?? 'round',
                    'is_income_tax_target' => $row['is_income_tax_target'] ?? false,
                    'is_labor_insurance_target' => $row['is_labor_insurance_target'] ?? false,
                    'is_social_insurance_target' => $row['is_social_insurance_target'] ?? false,
                    'is_fixed_wage' => $row['is_fixed_wage'] ?? false,
                    'is_in_kind' => $row['is_in_kind'] ?? false,
                    'is_allowance_base' => $row['is_allowance_base'] ?? false,
                    'is_deduction_base' => $row['is_deduction_base'] ?? false,
                    'show_zero' => $row['show_zero'] ?? false,
                    'is_daily_proration_base' => $row['is_daily_proration_base'] ?? false,
                ]);
                // MF準拠: 時給・日給の支給項目では割増基礎・控除基礎は使用不可
                if (in_array($item->pay_type, ['hourly', 'daily'], true)) {
                    $item->is_allowance_base = false;
                    $item->is_deduction_base = false;
                }
                // MF準拠: 通勤手当は端数処理「切り上げ」固定（UIロックのサーバ側担保）
                if ($item->category === 'commute') {
                    $item->rounding = 'ceil';
                }
                // MF準拠: 賞与は手入力のみ（他の計算方法は選択不可）
                if ($item->pay_type === 'bonus') {
                    $item->calc_method = 'manual';
                }
                if (array_key_exists('sort_order', $row) && $row['sort_order'] !== null) {
                    $item->sort_order = $row['sort_order'];
                }
                $rawFormula = $rawFormulas->get($row['id'])['custom_formula'] ?? null;
                $item->custom_formula = ($row['calc_method'] === 'custom' && is_array($rawFormula))
                    ? $rawFormula
                    : null;
                $item->save();
            }

            PayrollMasterSync::syncLateEarlyAttendanceItems();
        });

        return back()->with('success', '支給項目を保存しました');
    }

    public function updateDeductionItems(Request $request)
    {
        $data = $request->validate([
            'items' => ['array'],
            'items.*.id' => ['required', 'integer', 'exists:deduction_item_masters,id'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.calc_method' => ['nullable', 'in:manual,employee'],
            'items.*.is_active' => ['boolean'],
            'items.*.show_zero' => ['boolean'],
            'items.*.sort_order' => ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['items'] ?? [] as $row) {
                $item = DeductionItemMaster::find($row['id']);
                if (! $item) {
                    continue;
                }
                $item->is_active = $row['is_active'] ?? false;
                $item->show_zero = $row['show_zero'] ?? false;
                if (array_key_exists('sort_order', $row) && $row['sort_order'] !== null) {
                    $item->sort_order = $row['sort_order'];
                }
                // MF準拠: 初期項目は名称・計算方法を変更不可。ユーザー追加項目のみ変更可能
                if (! $item->is_system) {
                    if (isset($row['name']) && $row['name'] !== '') {
                        $item->name = $row['name'];
                    }
                    if (isset($row['calc_method'])) {
                        $item->calc_method = $row['calc_method'];
                    }
                }
                $item->save();
            }
        });

        return back()->with('success', '控除項目を保存しました');
    }

    public function updateAttendanceItems(Request $request)
    {
        $data = $request->validate([
            'items' => ['array'],
            'items.*.id' => ['required', 'integer', 'exists:attendance_item_masters,id'],
            'items.*.is_active' => ['boolean'],
            'items.*.unit_format' => ['in:hour,hour_1,hour_decimal,hour_min60,day,day_decimal,count'],
            'items.*.show_zero' => ['boolean'],
        ]);

        $lateEarlyBlocked = ! PayrollMasterSync::isLateEarlyDeductionActive();

        DB::transaction(function () use ($data, $lateEarlyBlocked) {
            $lateEarlyIds = $lateEarlyBlocked
                ? AttendanceItemMaster::query()
                    ->whereIn('code', PayrollMasterSync::LATE_EARLY_ATTENDANCE_CODES)
                    ->pluck('id')
                    ->all()
                : [];

            foreach ($data['items'] ?? [] as $row) {
                $isActive = $row['is_active'] ?? false;
                if ($lateEarlyBlocked && in_array($row['id'], $lateEarlyIds, true)) {
                    $isActive = false;
                }

                AttendanceItemMaster::whereKey($row['id'])->update([
                    'is_active' => $isActive,
                    'unit_format' => $row['unit_format'] ?? 'hour_decimal',
                    'show_zero' => $row['show_zero'] ?? false,
                ]);
            }
        });

        return back()->with('success', '勤怠項目を保存しました');
    }

    public function updateInsuranceRates(Request $request)
    {
        $data = $request->validate([
            'rates' => ['array'],
            'rates.*.id' => ['required', 'integer', 'exists:insurance_rates,id'],
            'rates.*.employee_rate' => ['required', 'numeric', 'min:0', 'max:1000'],
            'rates.*.employer_rate' => ['required', 'numeric', 'min:0', 'max:1000'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['rates'] ?? [] as $row) {
                InsuranceRate::whereKey($row['id'])->update([
                    'employee_rate' => $row['employee_rate'],
                    'employer_rate' => $row['employer_rate'],
                ]);
            }
        });

        return back()->with('success', '保険料率を保存しました');
    }

    public function updateMunicipalities(Request $request)
    {
        $data = $request->validate([
            'items' => ['array'],
            'items.*.id' => ['required', 'integer', 'exists:resident_tax_municipalities,id'],
            'items.*.designation_number' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['items'] ?? [] as $row) {
                ResidentTaxMunicipality::whereKey($row['id'])->update([
                    'designation_number' => $row['designation_number'] ?? null,
                ]);
            }
        });

        return back()->with('success', '住民税の指定番号を保存しました');
    }

    /** @return array<string, string> */
    private function payCategoryLabels(): array
    {
        return [
            'basic' => '基本給系',
            'overtime' => '割増賃金系',
            'deduction' => '控除系',
            'commute' => '通勤手当系',
            'manual' => '手入力系',
            'fixed_overtime' => '固定残業系',
            'other' => 'その他',
            'custom' => 'カスタム',
        ];
    }

    /** @return array<string, string> */
    private function calcMethodLabels(): array
    {
        return [
            'manual' => '毎月手入力',
            'employee' => '従業員情報で設定',
            'allowance_base' => '割増基礎',
            'prev_allowance_base' => '前月の割増基礎',
            'deduction_base' => '控除基礎',
            'prev_deduction_base' => '前月の控除基礎',
            'custom' => 'カスタム計算式',
            'statutory' => '法定計算',
        ];
    }

    /** @return array<string, string> */
    private function attendanceCategoryLabels(): array
    {
        return [
            'fixed_work' => '所定労働',
            'attendance' => '出欠勤',
            'actual_work' => '実働時間',
            'leave' => '休暇',
        ];
    }

    /** @return array<string, string> */
    private function unitFormatLabels(): array
    {
        return [
            'hour' => '0時間',
            'hour_1' => '0.0時間',
            'hour_decimal' => '0.00時間(10進)',
            'hour_min60' => '000時間00分(60進)',
            'day' => '0.0日',
            'day_decimal' => '0.00日',
            'count' => '0回',
        ];
    }

    /** @return array<string, string> */
    private function insuranceKindLabels(): array
    {
        return [
            'health' => '健康保険',
            'nursing' => '介護保険',
            'child_support' => '子ども・子育て支援金',
            'pension' => '厚生年金',
            'child_contribution' => '子ども・子育て拠出金',
            'employment' => '雇用保険',
            'accident' => '労災保険',
            'pension_fund' => '厚生年金基金',
        ];
    }
}
