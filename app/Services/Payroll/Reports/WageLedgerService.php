<?php

namespace App\Services\Payroll\Reports;

use App\Models\AttendanceItemMaster;
use App\Models\DeductionItemMaster;
use App\Models\EmployeePayroll;
use App\Models\InsuranceRateSet;
use App\Models\PayItemMaster;
use App\Models\Payslip;
use App\Models\User;
use App\Services\MonthPeriod;
use App\Support\CareInsurance;
use App\Support\LaborInsuranceRates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 賃金台帳の集計ロジック（マネーフォワード クラウド給与 準拠）。
 *
 * 行はマスタ（勤怠／支給／控除）で有効な項目を sort_order の固定順で並べ、
 * 値は給与計算・賞与計算の確定明細（payslips / payslip_items）から支払月ごとに転記する。
 * 賞与は支払月の列へ給与と合算する。値が 0 の月は空欄扱い（表示側で空にする）。
 *
 * 画面表示（WageLedgerController）と一括出力ジョブ（GenerateReportArchive）の双方から利用する。
 *
 * 参照: 資料/設計書 26_賃金台帳
 */
class WageLedgerService
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $payItemFlagsCache = null;

    /** 従業員負担の社会保険料控除コード */
    private const SOCIAL_DEDUCTION_CODES = [
        'health_insurance',
        'nursing_insurance',
        'pension_insurance',
        'pension_fund',
        'employment_insurance',
    ];

    public function __construct(private PayrollReportService $reports) {}

    /** @return Collection<int, array<string,mixed>> */
    public function employeeList($locationId): Collection
    {
        return User::query()
            ->whereHas('employeePayroll', function ($q) use ($locationId) {
                if ($locationId) {
                    $q->where('business_location_id', $locationId);
                }
            })
            ->with('employeePayroll:id,user_id,employee_no')
            ->orderByDesc('users.is_active')
            ->orderByEmployeeNo()
            ->get(['users.id', 'users.name', 'users.is_active'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'employee_no' => $u->employeePayroll?->employee_no,
                'is_active' => (bool) $u->is_active,
            ]);
    }

    /**
     * 表示項目設定モーダル用カタログ（MF 賃金台帳準拠）。
     *
     * @return array{groups: array<int, array{key: string, title: string, items: array<int, array{key: string, code: string, name: string, is_active: bool}>}>}
     */
    public function displayItemCatalog(): array
    {
        return [
            'groups' => [
                $this->masterCatalogGroup('attendance', '勤怠項目', AttendanceItemMaster::query()->orderBy('sort_order')->get(['code', 'name', 'is_active'])),
                $this->payItemCatalogGroup(),
                $this->masterCatalogGroup('deduction', '控除項目', DeductionItemMaster::query()->orderBy('sort_order')->get(['code', 'name', 'is_active'])),
                $this->fixedCatalogGroup('balance_payment', '支給関連の差引合計項目', [
                    ['tax_amount', '課税支給合計'],
                    ['no_tax_amount', '非課税支給合計'],
                    ['allowance_in_kind_amount', '課税現物支給合計'],
                    ['no_tax_allowance_in_kind_amount', '非課税現物支給合計'],
                    ['total_payment_amount', '支給合計'],
                    ['labor_insurance_amount', '労保対象合計'],
                    ['social_insurance_amount', '社保対象合計(金銭)'],
                    ['social_insurance_in_kind_amount', '社保対象合計(現物)'],
                    ['social_insurance_commuting_allowance_amount', '社保対象通勤手当(金銭)'],
                    ['social_insurance_commuting_allowance_in_kind_amount', '社保対象通勤手当(現物)'],
                    ['fixed_wage_amount', '固定賃金合計'],
                    ['directors_remuneration_amount', '役員報酬合計'],
                    ['base_of_premium', '割増基礎合計'],
                    ['base_of_deduction', '控除基礎合計'],
                ]),
                $this->fixedCatalogGroup('balance_deduction', '控除関連の差引合計項目', [
                    ['social_insurance_premium_amount', '社会保険料合計'],
                    ['total_deduction_amount', '控除合計'],
                ]),
                $this->fixedCatalogGroup('balances', '差引合計項目', [
                    ['tax_amount_after_deducted_social_insurance', '社保控除後合計'],
                    ['net_income_amount', '差引支給合計'],
                    ['in_kind_payment_amount', '現物支給額'],
                    ['transfer_payroll_fixed_amount1', '振込支給１'],
                    ['transfer_payroll_fixed_amount2', '振込支給２'],
                    ['transfer_payroll_remained_amount', '振込支給残額'],
                    ['transfer_payment_amount', '振込支給額合計'],
                    ['cash_payment_amount', '現金支給額'],
                ]),
                $this->fixedCatalogGroup('other_information', 'その他', [
                    ['dependent_number', '扶養人数'],
                    ['tax_list_type_text', '税額表'],
                    ['health_insurance_monthly_remuneration', '健保標準報酬'],
                    ['welfare_annuity_insurance_monthly_remuneration', '厚年標準報酬'],
                    ['payment_date', '支払日'],
                ]),
                $this->fixedCatalogGroup('group_absorptions', '会社負担分', [
                    ['health_insurance_premium', '健康保険料(会社)'],
                    ['nursing_insurance_premium', '介護保険料(会社)'],
                    ['welfare_annuity_insurance_premium', '厚生年金保険料(会社)'],
                    ['child_allowance_contribution', '子ども・子育て拠出金(会社)'],
                    ['employees_pension_fund_premium', '厚生年金基金掛金(会社)'],
                    ['unemployment_insurance_premium', '雇用保険料(会社)'],
                    ['compensation_insurance_premium', '労災保険料(会社)'],
                    ['general_contribution', '一般拠出金(会社)'],
                ]),
            ],
        ];
    }

    /**
     * 賃金台帳の対象期間を解決する（MF: 暦年 / 年度 / 手動）。
     *
     * @param  array<string, mixed>  $input
     * @return array{
     *   mode: string,
     *   label: string,
     *   year: int,
     *   fiscal_year: int|null,
     *   from: string|null,
     *   to: string|null,
     *   columns: array<int, array{index:int, period_key:string, label:string, period:string}>
     * }
     */
    public function resolvePeriod(array $input): array
    {
        $mode = $input['period_mode'] ?? 'calendar';

        if ($mode === 'fiscal') {
            $fiscalYear = (int) ($input['fiscal_year'] ?? now()->format('Y'));
            $periodKeys = [];
            for ($i = 0; $i < 12; $i++) {
                $periodKeys[] = Carbon::create($fiscalYear, 4, 1)->addMonths($i)->format('Y-m');
            }
            $label = sprintf('%d年04月01日 〜 %d年03月31日', $fiscalYear, $fiscalYear + 1);

            return $this->makePeriodConfig('fiscal', $label, $periodKeys, $fiscalYear, $fiscalYear, null, null);
        }

        if ($mode === 'manual') {
            $from = $this->normalizeMonthKey($input['from'] ?? null) ?? now()->format('Y-m');
            $to = $this->normalizeMonthKey($input['to'] ?? null) ?? $from;
            $periodKeys = $this->monthKeysBetween($from, $to);
            $start = Carbon::parse($periodKeys[0].'-01');
            $end = Carbon::parse($periodKeys[array_key_last($periodKeys)].'-01')->endOfMonth();
            $label = sprintf(
                '%d年%02d月01日 〜 %d年%02d月%02d日',
                $start->year,
                $start->month,
                $end->year,
                $end->month,
                $end->day,
            );

            return $this->makePeriodConfig('manual', $label, $periodKeys, $start->year, null, $periodKeys[0], $periodKeys[array_key_last($periodKeys)]);
        }

        $year = (int) ($input['year'] ?? now()->format('Y'));
        $periodKeys = array_map(fn ($m) => sprintf('%d-%02d', $year, $m), range(1, 12));
        $label = sprintf('%d年01月01日 〜 %d年12月31日', $year, $year);

        return $this->makePeriodConfig('calendar', $label, $periodKeys, $year, null, null, null);
    }

    /**
     * @param  array<int, string>  $periodKeys
     * @return array{
     *   mode: string,
     *   label: string,
     *   year: int,
     *   fiscal_year: int|null,
     *   from: string|null,
     *   to: string|null,
     *   columns: array<int, array{index:int, period_key:string, label:string, period:string}>
     * }
     */
    private function makePeriodConfig(
        string $mode,
        string $label,
        array $periodKeys,
        int $year,
        ?int $fiscalYear,
        ?string $from,
        ?string $to,
    ): array {
        $columns = [];
        foreach ($periodKeys as $i => $periodKey) {
            $resolved = MonthPeriod::resolve($periodKey);
            $fromDate = Carbon::parse($resolved['from']);
            $toDate = Carbon::parse($resolved['to']);
            $columns[] = [
                'index' => $i + 1,
                'period_key' => $periodKey,
                'label' => ($i + 1).'月度',
                'period' => sprintf('%d/%d - %d/%d', $fromDate->month, $fromDate->day, $toDate->month, $toDate->day),
            ];
        }

        return [
            'mode' => $mode,
            'label' => $label,
            'year' => $year,
            'fiscal_year' => $fiscalYear,
            'from' => $from,
            'to' => $to,
            'columns' => $columns,
        ];
    }

    /** @return array<int, string> */
    private function monthKeysBetween(string $from, string $to): array
    {
        $start = Carbon::parse($from.'-01')->startOfMonth();
        $end = Carbon::parse($to.'-01')->startOfMonth();
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        $keys = [];
        $cursor = $start->copy();
        while ($cursor->lte($end) && count($keys) < 12) {
            $keys[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        return $keys;
    }

    private function normalizeMonthKey(?string $value): ?string
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * @param  int|array<string, mixed>  $yearOrPeriod
     * @return array{
     *   year: int,
     *   period: array<string, mixed>,
     *   months: array<int, array{month:int, label:string, period:string, has_data:bool}>,
     *   sections: array<int, array{type:string, title:string, rows:array<int, array<string,mixed>>}>,
     *   employee: array<string, mixed>
     * }
     */
    public function build(int $userId, int|array $yearOrPeriod, $locationId = null): array
    {
        $period = is_array($yearOrPeriod)
            ? $yearOrPeriod
            : $this->resolvePeriod(['period_mode' => 'calendar', 'year' => $yearOrPeriod]);

        $user = User::with(['employeePayroll.businessLocation', 'department:id,name'])->find($userId);
        $employee = $user?->employeePayroll;
        $payType = $employee?->pay_type ?: 'monthly';

        $periodKeys = array_column($period['columns'], 'period_key');
        $byPeriodKey = $this->reports->employeePayslipsByPeriodKeys($userId, $periodKeys, $locationId);
        $pivot = $this->pivotByColumns($period['columns'], $byPeriodKey, $employee, $user);

        $months = [];
        foreach ($period['columns'] as $column) {
            $idx = $column['index'];
            $months[] = [
                'month' => $idx,
                'label' => $column['label'],
                'period' => $column['period'],
                'has_data' => $pivot[$idx]['has_data'],
            ];
        }

        $sections = [
            $this->attendanceSection($pivot),
            $this->itemSection('earning', '支給', $this->earningRowDefs($payType), $pivot),
            $this->itemSection('deduction', '控除', $this->deductionRowDefs(), $pivot),
            $this->computedSection('balance_payment', '支給関連', $this->balancePaymentFields(), $pivot),
            $this->computedSection('balance_deduction', '控除関連', $this->balanceDeductionFields(), $pivot),
            $this->computedSection('balances', '差引合計', $this->balancesFields(), $pivot),
            $this->computedSection('other_information', 'その他', $this->otherInformationFields(), $pivot),
            $this->computedSection('group_absorptions', '会社負担', $this->groupAbsorptionFields(), $pivot),
        ];

        return [
            'year' => $period['year'],
            'period' => [
                'mode' => $period['mode'],
                'label' => $period['label'],
                'year' => $period['year'],
                'fiscal_year' => $period['fiscal_year'],
                'from' => $period['from'],
                'to' => $period['to'],
            ],
            'months' => $months,
            'sections' => $sections,
            'employee' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'employee_no' => $employee?->employee_no,
                'business_location' => $employee?->businessLocation?->name,
                'department' => $user?->department?->name,
                'pay_type_label' => $this->payTypeLabel($payType),
                'tax_table_label' => ($employee?->tax_table ?? 'kou') === 'otsu' ? '乙欄' : '甲欄',
                'dependents_count' => (int) ($employee?->dependents_count ?? 0),
                'gender_label' => match ($user?->gender) {
                    'male' => '男性',
                    'female' => '女性',
                    'other' => 'その他',
                    default => '',
                },
            ],
        ];
    }

    /**
     * @param  array<int, array{index:int, period_key:string}>  $columns
     * @param  array<string, Collection<int, Payslip>>  $byPeriodKey
     * @return array<int, array<string, mixed>>
     */
    private function pivotByColumns(array $columns, array $byPeriodKey, ?EmployeePayroll $employee, ?User $user): array
    {
        $payFlags = $this->payItemFlags();
        $allPayslips = collect($byPeriodKey)->flatten(1);
        $rateSetIds = $allPayslips->pluck('insurance_rate_set_id')->filter()->unique()->values();
        $rateSets = InsuranceRateSet::query()
            ->with('rates')
            ->whereIn('id', $rateSetIds)
            ->get()
            ->keyBy('id');

        $location = $employee?->businessLocation;
        $pivot = [];

        foreach ($columns as $column) {
            $idx = $column['index'];
            $items = ['attendance' => [], 'earning' => [], 'deduction' => []];
            $totals = ['total_earnings' => 0, 'total_deductions' => 0, 'net_pay' => 0];
            $computed = $this->emptyComputed();
            $paymentDates = [];
            $hasData = false;
            $latestPayslip = null;

            foreach ($byPeriodKey[$column['period_key']] ?? collect() as $payslip) {
                $hasData = true;
                $totals['total_earnings'] += (int) $payslip->total_earnings;
                $totals['total_deductions'] += (int) $payslip->total_deductions;
                $totals['net_pay'] += (int) $payslip->net_pay;

                $paymentDate = $payslip->payrollRun?->payment_date;
                if ($paymentDate) {
                    $paymentDates[] = $paymentDate;
                    if ($latestPayslip === null || $paymentDate->timestamp > ($latestPayslip->payrollRun?->payment_date?->timestamp ?? 0)) {
                        $latestPayslip = $payslip;
                    }
                } elseif ($latestPayslip === null) {
                    $latestPayslip = $payslip;
                }

                foreach ($payslip->items as $item) {
                    $type = $item->item_type;
                    if (! isset($items[$type])) {
                        continue;
                    }
                    if (! isset($items[$type][$item->code])) {
                        $items[$type][$item->code] = ['name' => $item->name, 'amount' => 0, 'minutes' => 0, 'quantity' => 0.0];
                    }
                    $items[$type][$item->code]['amount'] += (int) ($item->amount ?? 0);
                    $items[$type][$item->code]['minutes'] += (int) ($item->minutes ?? 0);
                    $items[$type][$item->code]['quantity'] += (float) ($item->quantity ?? 0);
                }

                if ($employee) {
                    $rateSet = $rateSets[$payslip->insurance_rate_set_id] ?? null;
                    $this->accumulatePayslipComputed($computed, $payslip, $employee, $user, $payFlags, $rateSet, $location);
                }
            }

            if ($hasData && $employee) {
                $this->applyTransferAmounts($computed, (int) $totals['net_pay'], $employee);
                $this->applySnapshotFields($computed, $latestPayslip, $employee);
                $computed['tax_amount_after_deducted_social_insurance'] = max(
                    0,
                    (int) $computed['tax_amount'] - (int) $computed['social_insurance_premium_amount'],
                );
            }

            $paymentDateLabel = null;
            if ($paymentDates !== []) {
                $latest = collect($paymentDates)->sortByDesc(fn ($d) => $d->timestamp)->first();
                $paymentDateLabel = sprintf('%d/%d', $latest->month, $latest->day);
            }
            $computed['payment_date'] = $paymentDateLabel ?? '';

            $pivot[$idx] = [
                'items' => $items,
                'totals' => $totals,
                'computed' => $computed,
                'payment_date' => $paymentDateLabel,
                'has_data' => $hasData,
            ];
        }

        return $pivot;
    }

    /** @return array<string, int|string> */
    private function emptyComputed(): array
    {
        $fields = array_merge(
            array_keys($this->balancePaymentFields()),
            array_keys($this->balanceDeductionFields()),
            array_keys($this->balancesFields()),
            array_keys($this->otherInformationFields()),
            array_keys($this->groupAbsorptionFields()),
        );

        return array_fill_keys($fields, 0) + ['tax_list_type_text' => '', 'payment_date' => ''];
    }

    /**
     * @param array<string, int|string> $computed
     */
    private function accumulatePayslipComputed(
        array &$computed,
        Payslip $payslip,
        EmployeePayroll $employee,
        ?User $user,
        array $payFlags,
        ?InsuranceRateSet $rateSet,
        $location,
    ): void {
        $periodKey = (string) ($payslip->payrollRun?->period_key ?? '');
        $careTarget = CareInsurance::isTarget($user, $employee, $periodKey);
        $laborBaseThisSlip = 0;

        foreach ($payslip->items->where('item_type', 'earning') as $item) {
            $flags = $payFlags[$item->code] ?? [];
            $amount = (int) $item->amount;
            $isInKind = (bool) ($flags['is_in_kind'] ?? false);
            $isTaxable = (bool) ($flags['is_income_tax_target'] ?? true);
            $isCommute = (bool) ($flags['is_commute'] ?? false);

            if ($isInKind) {
                $computed['in_kind_payment_amount'] += $amount;
                if ($isTaxable) {
                    $computed['allowance_in_kind_amount'] += $amount;
                } else {
                    $computed['no_tax_allowance_in_kind_amount'] += $amount;
                }
            } elseif ($isTaxable) {
                $computed['tax_amount'] += $amount;
            } else {
                $computed['no_tax_amount'] += $amount;
            }

            if ($flags['is_social_insurance_target'] ?? false) {
                if ($isCommute) {
                    if ($isInKind) {
                        $computed['social_insurance_commuting_allowance_in_kind_amount'] += $amount;
                    } else {
                        $computed['social_insurance_commuting_allowance_amount'] += $amount;
                    }
                } elseif ($isInKind) {
                    $computed['social_insurance_in_kind_amount'] += $amount;
                } else {
                    $computed['social_insurance_amount'] += $amount;
                }
            }

            if ($flags['is_labor_insurance_target'] ?? false) {
                $laborBaseThisSlip += $amount;
                $computed['labor_insurance_amount'] += $amount;
            }
            if ($flags['is_fixed_wage'] ?? false) {
                $computed['fixed_wage_amount'] += $amount;
            }
            if ($item->code === 'executive_salary') {
                $computed['directors_remuneration_amount'] += $amount;
            }
        }

        $computed['total_payment_amount'] += (int) $payslip->total_earnings;
        $computed['base_of_premium'] += (int) ($payslip->allowance_base ?? 0);
        $computed['base_of_deduction'] += (int) ($payslip->deduction_base ?? 0);

        foreach ($payslip->items->where('item_type', 'deduction') as $item) {
            if (in_array($item->code, self::SOCIAL_DEDUCTION_CODES, true)) {
                $computed['social_insurance_premium_amount'] += (int) $item->amount;
            }
        }
        $computed['total_deduction_amount'] += (int) $payslip->total_deductions;
        $computed['net_income_amount'] += (int) $payslip->net_pay;

        $stdHealth = (int) ($payslip->snapshot_standard_reward_health ?? $employee->standard_reward_health ?? 0);
        $stdPension = (int) ($payslip->snapshot_standard_reward_pension ?? $employee->standard_reward_pension ?? 0);

        if ($employee->is_social_insurance_enrolled && $rateSet) {
            $computed['health_insurance_premium'] += $this->insuranceEmployer($rateSet, 'health', $stdHealth);
            if ($careTarget) {
                $computed['nursing_insurance_premium'] += $this->insuranceEmployer($rateSet, 'nursing', $stdHealth);
            }
            $computed['welfare_annuity_insurance_premium'] += $this->insuranceEmployer($rateSet, 'pension', $stdPension);
            $computed['child_allowance_contribution'] += $this->insuranceEmployer($rateSet, 'child_contribution', $stdHealth);
            $fund = $this->pensionFundEmployer($location, $payslip, $stdPension);
            $computed['employees_pension_fund_premium'] += $fund;
            $computed['general_contribution'] += $fund;
        }

        if ($employee->is_employment_insurance_enrolled && $rateSet && $laborBaseThisSlip > 0) {
            $computed['unemployment_insurance_premium'] += $this->insuranceEmployerOnBase($rateSet, 'employment', $laborBaseThisSlip);
        }

        if ($laborBaseThisSlip > 0) {
            $accidentRate = (float) ($rateSet?->rate('accident')?->employer_rate
                ?? $location?->accidentEmployerRate()
                ?? LaborInsuranceRates::accidentEmployerRate('other'));
            $computed['compensation_insurance_premium'] += $this->roundYen($laborBaseThisSlip * $accidentRate / 1000);
        }
    }

    /**
     * @param array<string, int|string> $computed
     */
    private function applyTransferAmounts(array &$computed, int $netPay, EmployeePayroll $employee): void
    {
        $fixed1 = (int) ($employee->transfer_fixed_amount1 ?? 0);
        $fixed2 = (int) ($employee->transfer_fixed_amount2 ?? 0);

        $transfer1 = min($fixed1, $netPay);
        $remaining = $netPay - $transfer1;
        $transfer2 = min($fixed2, $remaining);
        $remained = max(0, $netPay - $transfer1 - $transfer2);

        $computed['transfer_payroll_fixed_amount1'] = $transfer1;
        $computed['transfer_payroll_fixed_amount2'] = $transfer2;
        $computed['transfer_payroll_remained_amount'] = $remained;
        $computed['transfer_payment_amount'] = $transfer1 + $transfer2 + $remained;
        $computed['cash_payment_amount'] = max(0, $netPay - (int) $computed['transfer_payment_amount']);
    }

    /**
     * @param array<string, int|string> $computed
     */
    private function applySnapshotFields(array &$computed, ?Payslip $latest, EmployeePayroll $employee): void
    {
        $taxTable = $latest?->snapshot_tax_table ?? $employee->tax_table ?? 'kou';
        $computed['tax_list_type_text'] = $taxTable === 'otsu' ? '乙' : '甲';
        $computed['dependent_number'] = (int) ($latest?->snapshot_dependents_count ?? $employee->dependents_count ?? 0);
        $computed['health_insurance_monthly_remuneration'] = (int) (
            $latest?->snapshot_standard_reward_health ?? $employee->standard_reward_health ?? 0
        );
        $computed['welfare_annuity_insurance_monthly_remuneration'] = (int) (
            $latest?->snapshot_standard_reward_pension ?? $employee->standard_reward_pension ?? 0
        );
    }

    /**
     * 厚生年金基金掛金（事業主負担）を全基金合算で算出する。給与/賞与は明細の pay_type で判定。
     */
    private function pensionFundEmployer($location, Payslip $payslip, int $stdPension): int
    {
        if (! $location || $stdPension <= 0) {
            return 0;
        }

        $payKind = ($payslip->payrollRun?->pay_type === 'bonus') ? 'bonus' : 'salary';
        $date = $payslip->payrollRun?->payment_date?->toDateString()
            ?? \Illuminate\Support\Carbon::parse(($payslip->payrollRun?->period_key ?? '2000-01').'-01')->endOfMonth()->toDateString();

        $funds = $location->pensionFunds()->with('rates')->get();
        $rate = \App\Models\PensionFund::totalRates($funds, $date, $payKind)['employer'];

        return $this->roundYen($stdPension * $rate / 1000);
    }

    private function insuranceEmployer(?InsuranceRateSet $rateSet, string $kind, int $base): int
    {
        $rate = $rateSet?->rate($kind);
        if (! $rate || $base <= 0) {
            return 0;
        }

        return $this->roundYen($base * (float) $rate->employer_rate / 1000);
    }

    private function insuranceEmployerOnBase(?InsuranceRateSet $rateSet, string $kind, int $base): int
    {
        return $this->insuranceEmployer($rateSet, $kind, $base);
    }

    private function roundYen(float $value): int
    {
        return (int) round($value);
    }

    /** @return array<string, string> */
    private function balancePaymentFields(): array
    {
        return [
            'tax_amount' => '課税支給合計',
            'no_tax_amount' => '非課税支給合計',
            'allowance_in_kind_amount' => '課税現物支給合計',
            'no_tax_allowance_in_kind_amount' => '非課税現物支給合計',
            'total_payment_amount' => '支給合計',
            'labor_insurance_amount' => '労保対象合計',
            'social_insurance_amount' => '社保対象合計(金銭)',
            'social_insurance_in_kind_amount' => '社保対象合計(現物)',
            'social_insurance_commuting_allowance_amount' => '社保対象通勤手当(金銭)',
            'social_insurance_commuting_allowance_in_kind_amount' => '社保対象通勤手当(現物)',
            'fixed_wage_amount' => '固定賃金合計',
            'directors_remuneration_amount' => '役員報酬合計',
            'base_of_premium' => '割増基礎合計',
            'base_of_deduction' => '控除基礎合計',
        ];
    }

    /** @return array<string, string> */
    private function balanceDeductionFields(): array
    {
        return [
            'social_insurance_premium_amount' => '社会保険料合計',
            'total_deduction_amount' => '控除合計',
        ];
    }

    /** @return array<string, string> */
    private function balancesFields(): array
    {
        return [
            'tax_amount_after_deducted_social_insurance' => '社保控除後合計',
            'net_income_amount' => '差引支給合計',
            'in_kind_payment_amount' => '現物支給額',
            'transfer_payroll_fixed_amount1' => '振込支給１',
            'transfer_payroll_fixed_amount2' => '振込支給２',
            'transfer_payroll_remained_amount' => '振込支給残額',
            'transfer_payment_amount' => '振込支給額合計',
            'cash_payment_amount' => '現金支給額',
        ];
    }

    /** @return array<string, string> */
    private function otherInformationFields(): array
    {
        return [
            'dependent_number' => '扶養人数',
            'tax_list_type_text' => '税額表',
            'health_insurance_monthly_remuneration' => '健保標準報酬',
            'welfare_annuity_insurance_monthly_remuneration' => '厚年標準報酬',
            'payment_date' => '支払日',
        ];
    }

    /** @return array<string, string> */
    private function groupAbsorptionFields(): array
    {
        return [
            'health_insurance_premium' => '健康保険料(会社)',
            'nursing_insurance_premium' => '介護保険料(会社)',
            'welfare_annuity_insurance_premium' => '厚生年金保険料(会社)',
            'child_allowance_contribution' => '子ども・子育て拠出金(会社)',
            'employees_pension_fund_premium' => '厚生年金基金掛金(会社)',
            'unemployment_insurance_premium' => '雇用保険料(会社)',
            'compensation_insurance_premium' => '労災保険料(会社)',
            'general_contribution' => '一般拠出金(会社)',
        ];
    }

    /**
     * @param array<string, string> $fields
     * @param array<int, array<string,mixed>> $pivot
     */
    private function computedSection(string $type, string $title, array $fields, array $pivot): array
    {
        $textFields = ['tax_list_type_text', 'payment_date'];
        $countFields = ['dependent_number'];

        $rows = [];
        foreach ($fields as $code => $name) {
            $format = in_array($code, $textFields, true) ? 'text'
                : (in_array($code, $countFields, true) ? 'count' : 'yen');
            $values = [];
            $total = $format === 'text' ? '' : 0;

            foreach ($pivot as $m => $data) {
                $v = $data['computed'][$code] ?? ($format === 'text' ? '' : 0);
                $values[$m] = $v;
                if ($format !== 'text') {
                    $total += is_numeric($v) ? (float) $v : 0;
                }
            }

            $rows[] = [
                'key' => "{$type}.{$code}",
                'code' => $code,
                'name' => $name,
                'format' => $format,
                'values' => $values,
                'total' => $total,
            ];
        }

        return ['type' => $type, 'title' => $title, 'rows' => $rows];
    }

    private function attendanceSection(array $pivot): array
    {
        $masters = AttendanceItemMaster::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['code', 'name', 'unit_format']);

        $rows = [];
        foreach ($masters as $master) {
            $format = $this->attendanceFormat($master->unit_format);
            $values = [];
            $total = 0.0;
            foreach ($pivot as $m => $data) {
                $cell = $data['items']['attendance'][$master->code] ?? null;
                $v = $cell ? ($format === 'hours' ? (float) $cell['minutes'] / 60 : (float) $cell['quantity']) : 0.0;
                $values[$m] = $v;
                $total += $v;
            }
            $rows[] = [
                'key' => "attendance.{$master->code}",
                'code' => $master->code,
                'name' => $master->name,
                'format' => $format,
                'values' => $values,
                'total' => $total,
            ];
        }

        return ['type' => 'attendance', 'title' => '勤怠', 'rows' => $rows];
    }

    /**
     * @param array<string, string> $rowDefs
     * @param array<int, array<string,mixed>> $pivot
     */
    private function itemSection(string $type, string $title, array $rowDefs, array $pivot): array
    {
        $rows = [];
        foreach ($rowDefs as $code => $name) {
            $values = [];
            $total = 0;
            foreach ($pivot as $m => $data) {
                $v = (int) ($data['items'][$type][$code]['amount'] ?? 0);
                $values[$m] = $v;
                $total += $v;
            }
            $rows[] = [
                'key' => "{$type}.{$code}",
                'code' => $code,
                'name' => $name,
                'format' => 'yen',
                'values' => $values,
                'total' => $total,
            ];
        }

        return ['type' => $type, 'title' => $title, 'rows' => $rows];
    }

    /** @return array<string, string> */
    private function earningRowDefs(string $payType): array
    {
        $defs = [];
        $masters = PayItemMaster::query()
            ->where('pay_type', $payType)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['code', 'name']);
        foreach ($masters as $master) {
            $defs[$master->code] = $master->name;
        }

        return $defs;
    }

    /** @return array<string, string> */
    private function deductionRowDefs(): array
    {
        $defs = [];
        $masters = DeductionItemMaster::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['code', 'name']);
        foreach ($masters as $master) {
            $defs[$master->code] = $master->name;
        }

        return $defs;
    }

    /** @return array<string, array<string, mixed>> */
    private function payItemFlags(): array
    {
        if ($this->payItemFlagsCache !== null) {
            return $this->payItemFlagsCache;
        }

        $this->payItemFlagsCache = [];
        foreach (PayItemMaster::all() as $master) {
            $this->payItemFlagsCache[$master->code] = [
                'is_income_tax_target' => (bool) $master->is_income_tax_target,
                'is_in_kind' => (bool) $master->is_in_kind,
                'is_social_insurance_target' => (bool) $master->is_social_insurance_target,
                'is_labor_insurance_target' => (bool) $master->is_labor_insurance_target,
                'is_fixed_wage' => (bool) $master->is_fixed_wage,
                'is_commute' => $master->category === 'commute' || str_starts_with($master->code, 'commute_'),
            ];
        }

        return $this->payItemFlagsCache;
    }

    /** @param Collection<int, AttendanceItemMaster|DeductionItemMaster> $masters */
    private function masterCatalogGroup(string $prefix, string $title, Collection $masters): array
    {
        return [
            'key' => $prefix,
            'title' => $title,
            'items' => $masters->map(fn ($master) => [
                'key' => "{$prefix}.{$master->code}",
                'code' => $master->code,
                'name' => $master->name,
                'is_active' => (bool) $master->is_active,
            ])->values()->all(),
        ];
    }

    private function payItemCatalogGroup(): array
    {
        $seen = [];
        $items = [];
        $masters = PayItemMaster::query()
            ->whereIn('pay_type', ['monthly', 'hourly', 'daily', 'bonus'])
            ->orderBy('sort_order')
            ->get(['code', 'name', 'is_active']);

        foreach ($masters as $master) {
            if (isset($seen[$master->code])) {
                continue;
            }
            $seen[$master->code] = true;
            $items[] = [
                'key' => "earning.{$master->code}",
                'code' => $master->code,
                'name' => $master->name,
                'is_active' => (bool) $master->is_active,
            ];
        }

        return ['key' => 'earning', 'title' => '支給項目', 'items' => $items];
    }

    /** @param array<int, array{0: string, 1: string}> $items */
    private function fixedCatalogGroup(string $groupKey, string $title, array $items): array
    {
        return [
            'key' => $groupKey,
            'title' => $title,
            'items' => array_map(fn (array $item) => [
                'key' => "{$groupKey}.{$item[0]}",
                'code' => $item[0],
                'name' => $item[1],
                'is_active' => true,
            ], $items),
        ];
    }

    private function attendanceFormat(?string $unitFormat): string
    {
        return match ($unitFormat) {
            'hour_decimal' => 'hours',
            'count' => 'count',
            default => 'days',
        };
    }

    private function payTypeLabel(string $payType): string
    {
        return match ($payType) {
            'hourly' => '時給',
            'daily' => '日給',
            default => '月給',
        };
    }
}
