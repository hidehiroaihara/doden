<?php

namespace App\Services\Payroll;

use App\Models\AttendanceItemMaster;
use App\Models\DeductionItemMaster;
use App\Models\EmployeeCommuteRoute;
use App\Models\EmployeePayItemValue;
use App\Models\EmployeePayroll;
use App\Models\InsuranceRateSet;
use App\Models\PayItemMaster;
use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Models\Setting;
use App\Models\User;
use App\Services\AttendanceSummaryService;
use App\Services\MonthPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 給与計算エンジン。
 *
 * 支給(マスタ駆動/割増)→ 社会保険 → 所得税 → その他控除 → 差引支給 の順に算出し、
 * payslips / payslip_items へ確定する。勤怠実績は AttendanceSummaryService を単一ソースとして参照。
 * 確定(finalized)済みバッチは再計算しない(当時の料率・値を保持)。
 *
 * 参照: 資料/設計書 04_給与計算 / 09_支給項目 / 10_控除項目 / 12_社会保険 / 13_労働保険
 */
class PayrollCalculator
{
    public function __construct(
        private AttendanceSummaryService $summaries,
        private IncomeTaxCalculator $incomeTax,
        private FlatTaxReductionService $flatTax,
        private FormulaEvaluator $formula,
    ) {}

    /**
     * バッチ内の対象従業員(employee_payroll を持つ在籍者。事業所指定があれば一致者)を全計算。
     *
     * @return array<int, Payslip>
     */
    public function calculateRun(PayrollRun $run): array
    {
        $query = User::query()
            ->where('is_active', true)
            ->whereHas('employeePayroll', function ($q) use ($run) {
                if ($run->business_location_id) {
                    $q->where('business_location_id', $run->business_location_id);
                }
            });

        $payslips = [];
        foreach ($query->get() as $user) {
            $payslips[] = $this->calculate($run, $user);
        }

        return $payslips;
    }

    /**
     * 1従業員の給与を計算して確定する。
     */
    public function calculate(PayrollRun $run, User $user): Payslip
    {
        if ($run->isFinalized()) {
            return Payslip::firstOrCreate(
                ['payroll_run_id' => $run->id, 'user_id' => $user->id],
            );
        }

        $employee = $user->employeePayroll;
        if (! $employee) {
            throw new \RuntimeException("従業員給与情報(employee_payroll)が未登録です: user_id={$user->id}");
        }

        $settings = $this->loadSettings($employee, $run);
        $settings = array_merge($settings, $this->scheduledForPeriod($run, $settings));
        $settings['periodMonthNumber'] = (int) Carbon::parse($run->period_key . '-01')->month;
        $attendance = $this->attendanceMinutes($run, $user, $settings);
        $effectiveDate = ($run->payment_date ?? Carbon::parse($run->period_key . '-01')->endOfMonth())->toDateString();

        $prevBases = $this->previousBases($run, $user);
        $bases = ['allowance_base' => 0.0, 'deduction_base' => 0.0];
        $earnings = $this->buildEarnings($employee, $attendance, $settings, $prevBases, $bases);
        [$deductions, , $flatTaxApplied, $snapshot] = $this->buildDeductions($employee, $earnings, $run, $user, $effectiveDate);
        $attendanceItems = $this->buildAttendanceItems($attendance, $settings);

        $totalEarnings = array_sum(array_map(fn ($e) => $e['amount'], $earnings));
        $totalDeductions = array_sum(array_map(fn ($d) => $d['amount'], $deductions));
        $netPay = $totalEarnings - $totalDeductions;

        return DB::transaction(function () use ($run, $user, $earnings, $deductions, $attendanceItems, $totalEarnings, $totalDeductions, $netPay, $flatTaxApplied, $bases, $settings, $snapshot) {
            $payslip = Payslip::updateOrCreate(
                ['payroll_run_id' => $run->id, 'user_id' => $user->id],
                array_merge([
                    'total_earnings' => $totalEarnings,
                    'total_deductions' => $totalDeductions,
                    'net_pay' => $netPay,
                    'allowance_base' => (int) round($bases['allowance_base']),
                    'deduction_base' => (int) round($bases['deduction_base']),
                    'scheduled_work_days' => (float) ($settings['scheduledDaysMonthActual'] ?? 0),
                    'scheduled_work_minutes' => (int) round(($settings['scheduledHoursMonthActual'] ?? 0) * 60),
                    'calculated_at' => now(),
                ], $snapshot),
            );

            // 手入力で上書きされた行は保持し、自動計算行のみ再生成する
            $payslip->items()->where('is_manual_override', false)->delete();

            $sort = 0;
            foreach ($earnings as $e) {
                $this->upsertItem($payslip, 'earning', $e, $sort++);
            }
            foreach ($deductions as $d) {
                $this->upsertItem($payslip, 'deduction', $d, $sort++);
            }
            foreach ($attendanceItems as $a) {
                $this->upsertItem($payslip, 'attendance', $a, $sort++);
            }

            // 定額減税の当月控除額を記録（総控除額・差引支給には非影響の情報行）
            if ($flatTaxApplied > 0) {
                $payslip->items()->create([
                    'item_type' => FlatTaxReductionService::ITEM_TYPE,
                    'code' => FlatTaxReductionService::ITEM_CODE,
                    'name' => FlatTaxReductionService::ITEM_NAME,
                    'category' => 'tax',
                    'amount' => $flatTaxApplied,
                    'is_manual_override' => false,
                    'sort_order' => $sort++,
                ]);
            }

            return $payslip->load('items');
        });
    }

    /**
     * 支給項目をマスタ駆動で算出。
     * 1パス目: employee/manual の固定額 → 割増基礎・控除基礎を確定。
     * 2パス目: allowance_base/deduction_base の割増・控除を算出。
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildEarnings(EmployeePayroll $employee, array $attendance, array $settings, array $prevBases = [], array &$outBases = []): array
    {
        $masters = PayItemMaster::active()
            ->forPayType($employee->pay_type)
            ->orderBy('sort_order')
            ->get();

        $results = [];
        $allowanceBase = 0;
        $deductionBase = 0;

        // 従業員別の支給項目値（汎用テーブル）と通勤手当（ルート算出）を先に解決
        $valueMap = $this->employeePayItemValueMap($employee->user_id);
        $commute = $this->commuteAmounts($employee, $attendance, $settings);

        // 1パス目: 固定額(従業員情報参照)
        foreach ($masters as $m) {
            if (! in_array($m->calc_method, ['employee', 'manual'], true)) {
                continue;
            }
            $amount = $m->calc_method === 'employee'
                // 従業員情報の入力値を単価とし、÷単位×倍率×勤怠 の式ビルダーを適用（既定値なら入力値そのまま）
                ? $this->employeeBuilderAmount($m, $this->resolveEmployeeAmount($m, $employee, $valueMap, $commute), $attendance, $settings)
                : 0; // manual は給与計算画面での手入力(自動計算では0)

            $signed = $m->sign === 'minus' ? -$amount : $amount;

            $results[$m->code] = $this->earningRow($m, $signed);

            if ($m->is_allowance_base) {
                $allowanceBase += $amount;
            }
            if ($m->is_deduction_base) {
                $deductionBase += $amount;
            }
        }

        // パス1完了時点で割増基礎・控除基礎を確定（スナップショット保存用に呼び出し側へ返す）
        $outBases['allowance_base'] = (float) $allowanceBase;
        $outBases['deduction_base'] = (float) $deductionBase;

        // 2パス目: 割増・控除・時給/日給ベース（基礎額 ÷ 除算単位 × 倍率 × 勤怠数量）
        $rateMethods = ['allowance_base', 'deduction_base', 'hourly1', 'hourly2', 'daily1', 'daily2'];
        $monthlyHours = (float) ($settings['monthlyScheduledHours'] ?? 0);
        $monthlyDays = (float) ($settings['monthlyAvgDays'] ?? 0);
        // 時給1/2・日給1/2 は従業員情報の設定値を優先し、未設定なら割増基礎からの算出値へフォールバック
        $rates = $this->rateBases($employee, (float) $allowanceBase, $monthlyHours, $monthlyDays);
        foreach ($masters as $m) {
            if (! in_array($m->calc_method, $rateMethods, true)) {
                continue;
            }

            $base = match ($m->calc_method) {
                'allowance_base' => (float) $allowanceBase,
                'deduction_base' => (float) $deductionBase,
                'hourly1' => $rates['hourly1'],
                'hourly2' => $rates['hourly2'],
                'daily1' => $rates['daily1'],
                'daily2' => $rates['daily2'],
                default => 0.0,
            };

            // 時給/日給は既に単価のため、除算単位未指定(=1)を許容する
            $isPreRate = in_array($m->calc_method, ['hourly1', 'hourly2', 'daily1', 'daily2'], true);
            $divisor = $this->divisorValue($m->divisor_unit, $settings, $attendance);
            $unitPrice = $divisor > 0 ? $base / $divisor : ($isPreRate ? $base : 0.0);

            $quantity = $this->quantityValue($m, $attendance, $settings);
            $raw = $unitPrice * (float) ($m->multiplier ?? 1.0) * $quantity;
            $amount = $this->roundYen($raw, $m->rounding);

            $signed = $m->sign === 'minus' ? -$amount : $amount;
            $results[$m->code] = $this->earningRow($m, $signed);
        }

        // 3パス目: カスタム計算式（支給項目/勤怠/基礎を参照して評価）
        $customMasters = $masters->filter(fn ($m) => $m->calc_method === 'custom')->values();
        if ($customMasters->isNotEmpty()) {
            $basis = [
                'allowance_base' => (float) $allowanceBase,
                // 前月の割増基礎/控除基礎（前月給与明細のスナップショットから取得。無ければ0）
                'prev_allowance_base' => (float) ($prevBases['allowance_base'] ?? 0),
                'deduction_base' => (float) $deductionBase,
                'prev_deduction_base' => (float) ($prevBases['deduction_base'] ?? 0),
                'hourly1' => $rates['hourly1'],
                'hourly2' => $rates['hourly2'],
                'daily1' => $rates['daily1'],
                'daily2' => $rates['daily2'],
                'employee' => 0.0,
            ];
            $attendanceCtx = [];
            foreach ($attendance as $code => $v) {
                $attendanceCtx[$code] = isset($v['minutes']) && $v['minutes'] !== null
                    ? ((float) $v['minutes']) / 60.0
                    : (float) ($v['quantity'] ?? 0);
            }

            // custom → custom 参照を解決するため項目数だけ反復（非循環なら収束）
            for ($iteration = 0, $n = $customMasters->count(); $iteration < $n; $iteration++) {
                foreach ($customMasters as $m) {
                    $payCtx = [];
                    foreach ($results as $code => $row) {
                        $payCtx[$code] = (float) $row['amount'];
                    }
                    try {
                        $raw = $this->formula->evaluate($m->custom_formula ?? [], [
                            'basis' => $basis,
                            'pay' => $payCtx,
                            'attendance' => $attendanceCtx,
                        ]);
                    } catch (\Throwable $e) {
                        $raw = 0.0;
                    }
                    $amount = $this->roundYen($raw, $m->rounding);
                    $signed = $m->sign === 'minus' ? -$amount : $amount;
                    $results[$m->code] = $this->earningRow($m, $signed);
                }
            }
        }

        // 給与計算画面では0円の有効項目も行として保持（後から手入力するため）。
        // 給与明細PDFでは PayslipPdfService 側で show_zero に従い非表示にする。
        return array_values($results);
    }

    /**
     * 控除項目をマスタ駆動で算出。社会保険→労働保険→所得税→住民税の順に確定。
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int, 2: int}  [控除行, 社会保険料合計, 定額減税適用額]
     */
    private function buildDeductions(EmployeePayroll $employee, array $earnings, PayrollRun $run, User $user, string $effectiveDate): array
    {
        $rateSet = $employee->businessLocation?->rateSetForDate($effectiveDate);

        $incomeTaxTarget = $this->sumEarningsByFlag($employee, $earnings, 'is_income_tax_target');
        $laborInsuranceTarget = $this->sumEarningsByFlag($employee, $earnings, 'is_labor_insurance_target');

        // 標準報酬月額は「適用開始月つき履歴」を優先し、無ければ従業員情報の単一値を使う。
        [$stdHealth, $stdPension, $gradeHealth, $gradePension] = $this->resolveStandardReward($user, $employee, $run);

        // 介護保険は生年月日から満40〜64歳を自動判定（従業員情報で上書き可）
        $careTarget = \App\Support\CareInsurance::isTarget($user, $employee, $run->period_key);
        // 扶養親族等の数（自動集計値があれば優先。手動上書きされていればそれを尊重）
        $dependentsCount = $this->effectiveDependentsCount($employee, $user);

        $masters = DeductionItemMaster::active()->orderBy('sort_order')->get();

        $results = [];
        $socialTotal = 0;
        $flatTaxApplied = 0;

        foreach ($masters as $m) {
            $amount = 0;

            switch ($m->code) {
                case 'health_insurance':
                    if ($employee->is_social_insurance_enrolled) {
                        $amount = $this->employeePremium($employee, 'health', $rateSet, 'health', $stdHealth);
                    }
                    $socialTotal += $amount;
                    break;

        case 'nursing_insurance':
                    if ($employee->is_social_insurance_enrolled && ($careTarget || ($employee->nursing_premium_mode ?? 'table') === 'manual')) {
                        $amount = $this->employeePremium($employee, 'nursing', $rateSet, 'nursing', $stdHealth);
                    }
                    $socialTotal += $amount;
                    break;

                case 'child_contribution':
                    // 子ども・子育て拠出金は事業主全額負担。従業員控除は0。
                    $amount = 0;
                    break;

                case 'pension_insurance':
                    if ($employee->is_social_insurance_enrolled) {
                        $amount = $this->employeePremium($employee, 'pension', $rateSet, 'pension', $stdPension);
                    }
                    $socialTotal += $amount;
                    break;

                case 'pension_fund':
                    // 厚生年金基金掛金（従業員負担）= 標準報酬月額(厚年) × 給与掛金料率（全基金合算）。
                    // 料率未設定(0)なら0円。MFクラウド準拠で掛金料率のみで自動計算する。
                    if ($employee->is_social_insurance_enrolled) {
                        $amount = $this->pensionFundEmployee($employee, $effectiveDate, $stdPension, 'salary');
                    }
                    $socialTotal += $amount;
                    break;

                case 'employment_insurance':
                    if ($employee->is_employment_insurance_enrolled) {
                        $amount = $this->insuranceEmployeeOnBase($rateSet, 'employment', $laborInsuranceTarget);
                    }
                    $socialTotal += $amount;
                    break;

                case 'income_tax':
                    $grossTax = $this->incomeTax->monthly(
                        max(0, $incomeTaxTarget - $socialTotal),
                        $dependentsCount,
                        $employee->tax_table,
                        $effectiveDate,
                    );
                    $flatTaxApplied = $this->flatTax->monthlyReduction($employee, $user, $run, $grossTax);
                    $amount = max(0, $grossTax - $flatTaxApplied);
                    break;

                case 'resident_tax':
                    $amount = $this->residentTaxForMonth($user, $employee, $run);
                    break;

                default:
                    // pension_fund / social_insurance_adjust / defined_contribution / year_end_adjustment 等は
                    // 手入力・従業員情報参照のため自動計算では0(必要に応じ画面で上書き)
                    $amount = 0;
            }

            // 給与計算画面では0円の有効控除も行として保持（後から手入力するため）。
            // 給与明細PDFでは PayslipPdfService 側で show_zero に従い非表示にする。
            // ただし子ども・子育て拠出金は事業主全額負担のため従業員控除には表示しない。
            // 介護保険は介護保険料の対象者(満40〜64歳)のときのみ表示する。
            if ($m->code === 'child_contribution') {
                continue;
            }
            if ($m->code === 'nursing_insurance' && ! $careTarget) {
                continue;
            }

            $results[] = [
                'source_master_id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'category' => $m->category,
                'amount' => $amount,
            ];
        }

        // 計算時に適用した法定マスタを明細へスナップショット（過去改定からの保護）
        $snapshot = [
            'insurance_rate_set_id' => $rateSet?->id,
            'applied_rates' => $rateSet ? [
                'health' => (float) ($rateSet->rate('health')?->employee_rate ?? 0),
                'nursing' => (float) ($rateSet->rate('nursing')?->employee_rate ?? 0),
                'pension' => (float) ($rateSet->rate('pension')?->employee_rate ?? 0),
                'employment' => (float) ($rateSet->rate('employment')?->employee_rate ?? 0),
            ] : null,
            'snapshot_standard_reward_health' => $stdHealth ?: null,
            'snapshot_standard_reward_pension' => $stdPension ?: null,
            'snapshot_grade_health' => $gradeHealth,
            'snapshot_grade_pension' => $gradePension,
            'snapshot_tax_table' => $employee->tax_table,
            'snapshot_dependents_count' => $dependentsCount,
            'income_tax_source' => $this->incomeTax->lastSource,
        ];

        return [$results, $socialTotal, $flatTaxApplied, $snapshot];
    }

    /**
     * 扶養親族等の数の実効値。
     * 従業員情報の dependents_count を正とする（手動上書き値を尊重）。
     * 0 のときのみ、扶養親族(所得税対象)の登録件数から自動集計する。
     */
    private function effectiveDependentsCount(EmployeePayroll $employee, User $user): int
    {
        $manual = (int) ($employee->dependents_count ?? 0);
        if ($manual > 0) {
            return $manual;
        }

        return (int) $user->dependents()->where('is_income_tax_dependent', true)->count();
    }

    /**
     * 勤怠項目(明細表示用)を有効マスタ分だけ構築。
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildAttendanceItems(array $map, array $settings): array
    {
        $masters = AttendanceItemMaster::active()->orderBy('sort_order')->orderBy('id')->get();

        $items = [];
        foreach ($masters as $m) {
            if (! array_key_exists($m->code, $map)) {
                continue;
            }
            $value = $map[$m->code];
            if (($value['minutes'] ?? 0) === 0 && ($value['quantity'] ?? 0) == 0 && ! $m->show_zero) {
                continue;
            }
            $items[] = [
                'source_master_id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'category' => $m->category,
                'minutes' => $value['minutes'] ?? null,
                'quantity' => $value['quantity'] ?? null,
            ];
        }

        return $items;
    }

    // ---- ヘルパ ------------------------------------------------------------

    /** @return array<string, mixed> */
    private function earningRow(PayItemMaster $m, int $amount): array
    {
        return [
            'source_master_id' => $m->id,
            'code' => $m->code,
            'name' => $m->name,
            'category' => $m->category,
            'amount' => $amount,
            '_master' => $m,
        ];
    }

    private function employeeFixedAmount(string $code, EmployeePayroll $employee): int
    {
        return match ($code) {
            'base_salary' => (int) $employee->base_salary,
            'commute_taxable' => (int) $employee->commute_allowance_taxable,
            'commute_non_taxable' => (int) $employee->commute_allowance_non_taxable,
            // その他手当(役職・家族・住宅・営業等)は従業員別手当テーブル未実装のため0。
            default => 0,
        };
    }

    /**
     * calc_method='employee' の支給項目金額を解決する。
     * 1) 通勤手当はルート算出を優先（ルートがあれば）。
     * 2) 汎用テーブル(employee_pay_item_values)に値があればそれを使用。
     * 3) 無ければ従来列へフォールバック（既存挙動維持）。
     */
    private function resolveEmployeeAmount(PayItemMaster $m, EmployeePayroll $employee, array $valueMap, ?array $commute): int
    {
        if ($commute !== null && array_key_exists($m->code, $commute)) {
            return (int) $commute[$m->code];
        }
        if (array_key_exists($m->id, $valueMap)) {
            return (int) $valueMap[$m->id];
        }

        return $this->employeeFixedAmount($m->code, $employee);
    }

    /**
     * 従業員別の支給項目金額マップ [pay_item_master_id => amount]。
     */
    private function employeePayItemValueMap(?int $userId): array
    {
        if (! $userId) {
            return [];
        }

        return EmployeePayItemValue::where('user_id', $userId)
            ->pluck('amount', 'pay_item_master_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * 通勤手当をルートから算出。全ルート合算後、非課税限度額までを非課税・超過を課税へ振り分ける。
     * ルートが無い場合は null（従来列フォールバック）。
     *
     * @return array{commute_non_taxable:int, commute_taxable:int}|null
     */
    private function commuteAmounts(EmployeePayroll $employee, array $attendance, array $settings): ?array
    {
        if (! $employee->user_id) {
            return null;
        }
        $routes = EmployeeCommuteRoute::where('user_id', $employee->user_id)->get();
        if ($routes->isEmpty()) {
            return null;
        }

        $nonTaxTotal = 0;
        $taxTotal = 0;
        foreach ($routes as $r) {
            $amount = $this->commuteRouteAmount($r, $attendance, $settings);
            $limit = $r->non_taxable_limit;
            if ($limit === null) {
                $nonTaxTotal += $amount;
            } else {
                $nonTax = min($amount, (int) $limit);
                $nonTaxTotal += $nonTax;
                $taxTotal += max(0, $amount - $nonTax);
            }
            // 駐車場代（MF準拠）は課税の通勤手当として合算
            if ($r->uses_parking) {
                $taxTotal += $this->parkingRouteAmount($r, $attendance, $settings);
            }
        }

        return [
            'commute_non_taxable' => $nonTaxTotal,
            'commute_taxable' => $taxTotal,
        ];
    }

    /**
     * 1ルートの当月支給額（円）を算出。
     * - fixed: 支給月なら月額（支給月未指定=毎月）。
     * - by_workdays: 日額 × 対象勤怠項目の数量。
     * いずれも cap_amount で上限を適用。
     */
    private function commuteRouteAmount(EmployeeCommuteRoute $r, array $attendance, array $settings): int
    {
        return $this->amountForCondition(
            $r->condition,
            (float) $r->amount,
            $r->payment_months,
            $r->attendance_item_code,
            $r->cap_amount,
            $attendance,
            $settings,
        );
    }

    /**
     * 駐車場代の当月支給額（円）。駐車場代の支給条件・支給月・上限を用いて算出。
     */
    private function parkingRouteAmount(EmployeeCommuteRoute $r, array $attendance, array $settings): int
    {
        return $this->amountForCondition(
            $r->parking_condition ?? 'fixed',
            (float) $r->parking_amount,
            $r->parking_payment_months,
            $r->parking_attendance_item_code,
            $r->parking_cap_amount,
            $attendance,
            $settings,
        );
    }

    /**
     * 支給条件（定額/出勤日数連動）に応じた当月支給額を算出する共通ロジック。
     *
     * @param  array<int>|null  $months
     * @param  array<string, mixed>  $attendance
     * @param  array<string, mixed>  $settings
     */
    private function amountForCondition(string $condition, float $amount, ?array $months, ?string $attendanceCode, ?int $capAmount, array $attendance, array $settings): int
    {
        if ($condition === 'by_workdays') {
            $code = $attendanceCode ?: 'work_days_weekday';
            $entry = $attendance[$code] ?? [];
            $qty = isset($entry['minutes']) && $entry['minutes'] !== null
                ? ((float) $entry['minutes']) / 60.0
                : (float) ($entry['quantity'] ?? 0);
            $raw = $amount * $qty;
        } else {
            $curMonth = $settings['periodMonthNumber'] ?? null;
            if (is_array($months) && count($months) > 0 && $curMonth !== null && ! in_array((int) $curMonth, array_map('intval', $months), true)) {
                $raw = 0;
            } else {
                $raw = $amount;
            }
        }

        if ($capAmount !== null) {
            $raw = min($raw, (float) $capAmount);
        }

        return (int) round($raw);
    }

    /**
     * 支給行のうち、支給項目マスタの指定フラグが立っている項目の合計額。
     */
    private function sumEarningsByFlag(EmployeePayroll $employee, array $earnings, string $flag): int
    {
        $total = 0;
        foreach ($earnings as $e) {
            $master = $e['_master'] ?? null;
            if ($master instanceof PayItemMaster && $master->{$flag}) {
                $total += $e['amount'];
            }
        }

        return max(0, $total);
    }

    /**
     * 厚生年金基金掛金（従業員負担）を全基金合算で算出する。
     * $payKind: 'salary'(給与) / 'bonus'(賞与)。
     */
    private function pensionFundEmployee(EmployeePayroll $employee, string $effectiveDate, int $standardReward, string $payKind): int
    {
        if ($standardReward <= 0) {
            return 0;
        }

        $funds = $employee->businessLocation?->pensionFunds()->with('rates')->get() ?? collect();
        $rate = \App\Models\PensionFund::totalRates($funds, $effectiveDate, $payKind)['employee'];
        if ($rate <= 0) {
            return 0;
        }

        return $this->roundYen($standardReward * $rate / 1000, 'round');
    }

    private function insuranceEmployee(?InsuranceRateSet $rateSet, string $kind, int $standardReward): int
    {
        $rate = $rateSet?->rate($kind);
        if (! $rate || $standardReward <= 0) {
            return 0;
        }

        // 料率は千分率(/1,000)で保持
        return $this->roundYen($standardReward * (float) $rate->employee_rate / 1000, 'round');
    }

    /**
     * 社会保険料（本人負担）を返す。従業員情報で「手入力(manual)」が指定されていれば手入力額を、
     * そうでなければ料率表(額表)から自動計算する。$key は health/nursing/pension。
     */
    private function employeePremium(EmployeePayroll $employee, string $key, ?InsuranceRateSet $rateSet, string $kind, int $standardReward): int
    {
        if (($employee->{"{$key}_premium_mode"} ?? 'table') === 'manual') {
            return (int) ($employee->{"{$key}_premium_employee"} ?? 0);
        }

        return $this->insuranceEmployee($rateSet, $kind, $standardReward);
    }

    /**
     * 標準報酬月額を解決する。適用開始月つき履歴があれば支給月に有効な最新行を優先し、
     * 無ければ従業員情報の単一値（standard_reward_health / _pension）へフォールバックする。
     *
     * @return array{0:int,1:int,2:?int,3:?int} [健保標準報酬, 厚年標準報酬, 健保等級, 厚年等級]
     */
    private function resolveStandardReward(User $user, EmployeePayroll $employee, PayrollRun $run): array
    {
        $stdHealth = (int) ($employee->standard_reward_health ?? 0);
        $stdPension = (int) ($employee->standard_reward_pension ?? 0);
        $gradeHealth = $employee->standard_reward_grade_health;
        $gradePension = $employee->standard_reward_grade_pension;

        $periodStart = \Illuminate\Support\Carbon::parse($run->period_key.'-01')->startOfMonth()->toDateString();

        $row = $user->standardRewards()
            ->whereDate('applied_from', '<=', $periodStart)
            ->orderByDesc('applied_from')
            ->first();

        if ($row) {
            if ($row->health_amount !== null) {
                $stdHealth = (int) $row->health_amount;
                $gradeHealth = $row->health_grade ?? $gradeHealth;
            }
            if ($row->pension_amount !== null) {
                $stdPension = (int) $row->pension_amount;
                $gradePension = $row->pension_grade ?? $gradePension;
            }
        }

        return [$stdHealth, $stdPension, $gradeHealth, $gradePension];
    }

    /**
     * 住民税の当月控除額を返す。年度・月別の登録があればそれを優先し、
     * 無ければ従業員情報の resident_tax_june / resident_tax_monthly へフォールバックする。
     */
    private function residentTaxForMonth(User $user, EmployeePayroll $employee, PayrollRun $run): int
    {
        $year = (int) substr($run->period_key, 0, 4);
        $month = (int) substr($run->period_key, 5, 2);
        $fiscalYear = \App\Models\EmployeeResidentTax::fiscalYearForMonth($year, $month);

        $row = $user->residentTaxes()
            ->where('fiscal_year', $fiscalYear)
            ->where('month', $month)
            ->first();

        if ($row) {
            return (int) $row->amount;
        }

        return $month === 6 && $employee->resident_tax_june > 0
            ? (int) $employee->resident_tax_june
            : (int) $employee->resident_tax_monthly;
    }

    private function insuranceEmployeeOnBase(?InsuranceRateSet $rateSet, string $kind, int $base): int
    {
        $rate = $rateSet?->rate($kind);
        if (! $rate || $base <= 0) {
            return 0;
        }

        // 料率は千分率(/1,000)で保持
        return $this->roundYen($base * (float) $rate->employee_rate / 1000, 'round');
    }

    /**
     * 「計算の基礎を何で割るか（÷単位）」の値を解決する。
     * MFクラウド準拠で単位・勤怠項目のいずれも指定可能。
     * - 'one' → 1
     * - 勤怠項目コード → 勤怠集計値（時間項目は時間換算 / それ以外は数量）
     * - 「当月」の所定労働時間/日数は月次実績未保持のため月平均で近似
     */
    private function divisorValue(?string $unit, array $settings, array $attendance = []): float
    {
        if ($unit === null || $unit === '' || $unit === 'one') {
            return $unit === 'one' ? 1.0 : 0.0;
        }

        // 勤怠集計に存在する項目はその値を使用（scheduled_hours_month_avg 等もここで解決）
        if (isset($attendance[$unit])) {
            $v = $attendance[$unit];

            return isset($v['minutes']) && $v['minutes'] !== null
                ? ((float) $v['minutes']) / 60.0
                : (float) ($v['quantity'] ?? 0);
        }

        // 勤怠集計に無い単位のフォールバック
        return match ($unit) {
            'work_hours_per_day', 'fixed_work_hours_per_day' => ($settings['workHoursPerDayMin'] ?? 0) / 60.0,
            'scheduled_hours_month', 'scheduled_hours_month_avg' => (float) ($settings['monthlyScheduledHours'] ?? 0),
            'scheduled_days_month', 'scheduled_days_month_avg' => (float) ($settings['monthlyAvgDays'] ?? 0),
            default => 0.0,
        };
    }

    /**
     * 「従業員情報で設定」項目の支給額を算出する。
     * 従業員入力値を単価とし、MFと同じ「÷単位 × 倍率 × 勤怠項目」の式ビルダーを適用する。
     * 既定（÷単位なし・倍率なし・勤怠項目なし）の場合は入力値そのまま（従来挙動と一致）。
     */
    private function employeeBuilderAmount(PayItemMaster $m, int $unit, array $attendance, array $settings): int
    {
        $divisor = $this->divisorValue($m->divisor_unit, $settings, $attendance);
        $base = $divisor > 0 ? (float) $unit / $divisor : (float) $unit;
        $multiplier = $m->multiplier !== null ? (float) $m->multiplier : 1.0;
        // 勤怠項目未指定なら数量は1（＝勤怠を掛けない）
        $quantity = $m->quantity_unit ? $this->quantityValue($m, $attendance, $settings) : 1.0;

        return (int) $this->roundYen($base * $multiplier * $quantity, $m->rounding);
    }

    /** 割増・控除の数量(時間項目は時間、日数項目は日数) */
    private function quantityValue(PayItemMaster $m, array $attendance, array $settings): float
    {
        // 勤怠項目なし/「1」（MF準拠の既定）は数量1（＝単価×倍率のみ）
        if (! $m->quantity_unit || $m->quantity_unit === 'one') {
            return 1.0;
        }
        $minutes = $attendance[$m->quantity_unit]['minutes'] ?? null;
        if ($minutes !== null) {
            return $minutes / 60.0;
        }
        $quantity = $attendance[$m->quantity_unit]['quantity'] ?? 0;

        return (float) $quantity;
    }

    /**
     * AttendanceSummaryService の集計値を勤怠項目 code の分数/数量へ写像。
     *
     * @return array<string, array{minutes?: int, quantity?: float}>
     */
    private function attendanceValueMap(array $s, array $settings): array
    {
        return [
            // 所定労働（従業員情報/会社設定由来）
            'fixed_work_hours_per_day' => ['minutes' => (int) $settings['workHoursPerDayMin']],
            'scheduled_hours_month_avg' => ['minutes' => (int) round($settings['monthlyScheduledHours'] * 60)],
            'scheduled_days_month_avg' => ['quantity' => $settings['monthlyAvgDays']],
            // 当月の所定（期間の平日数から実値化。未算出時は月平均へフォールバック）
            'scheduled_days_month' => ['quantity' => (float) ($settings['scheduledDaysMonthActual'] ?? $settings['monthlyAvgDays'])],
            'scheduled_hours_month' => ['minutes' => (int) round(($settings['scheduledHoursMonthActual'] ?? $settings['monthlyScheduledHours']) * 60)],
            // 出勤日数
            'work_days_weekday' => ['quantity' => (float) ($s['weekday_work_days'] ?? $s['work_days'] ?? 0)],
            'work_days_total' => ['quantity' => (float) ($s['work_days'] ?? 0)],
            'work_days_prescribed_holiday' => ['quantity' => (float) ($s['prescribed_holiday_days'] ?? 0)],
            'work_days_legal_holiday' => ['quantity' => (float) ($s['legal_holiday_days'] ?? 0)],
            'late_count' => ['quantity' => (float) ($s['late_count'] ?? 0)],
            'early_leave_count' => ['quantity' => (float) ($s['early_leave_count'] ?? 0)],
            // 実働時間（平日）
            'actual_total_weekday' => ['minutes' => (int) ($s['weekday_work_minutes'] ?? $s['total_work_minutes'] ?? 0)],
            'scheduled_time_weekday' => ['minutes' => (int) ($s['weekday_within_statutory_minutes'] ?? $s['within_statutory_minutes'] ?? 0)],
            'overtime_weekday' => ['minutes' => (int) ($s['weekday_overtime_minutes'] ?? $s['overtime_minutes'] ?? 0)],
            'statutory_overtime_weekday' => ['minutes' => (int) ($s['weekday_statutory_overtime_minutes'] ?? $s['statutory_overtime_minutes'] ?? 0)],
            'night_weekday' => ['minutes' => (int) ($s['weekday_night_minutes'] ?? $s['night_minutes'] ?? 0)],
            'break_weekday' => ['minutes' => (int) ($s['weekday_break_minutes'] ?? $s['total_break_minutes'] ?? 0)],
            // 休日労働（所定休日=土曜 / 法定休日=日曜）
            'work_prescribed_holiday' => ['minutes' => (int) ($s['prescribed_holiday_minutes'] ?? 0)],
            'work_statutory_holiday' => ['minutes' => (int) ($s['legal_holiday_minutes'] ?? 0)],
            'night_prescribed_holiday' => ['minutes' => (int) ($s['prescribed_holiday_night_minutes'] ?? 0)],
            'night_statutory_holiday' => ['minutes' => (int) ($s['legal_holiday_night_minutes'] ?? 0)],
            // 休日の所定/所定外内訳（MF名目: 所定時間（所定休日/法定休日）・所定外時間（所定休日））
            'scheduled_time_prescribed_holiday' => ['minutes' => (int) ($s['prescribed_holiday_within_minutes'] ?? 0)],
            'overtime_prescribed_holiday' => ['minutes' => (int) ($s['prescribed_holiday_overtime_minutes'] ?? 0)],
            'scheduled_time_legal_holiday' => ['minutes' => (int) ($s['legal_holiday_within_minutes'] ?? 0)],
            // === MF準拠の拡充項目 ===
            // 遅刻・早退時間（分）
            'late_minutes_weekday' => ['minutes' => (int) ($s['late_minutes_weekday'] ?? 0)],
            'late_minutes_prescribed_holiday' => ['minutes' => (int) ($s['late_minutes_prescribed_holiday'] ?? 0)],
            'late_minutes_legal_holiday' => ['minutes' => (int) ($s['late_minutes_legal_holiday'] ?? 0)],
            'early_leave_minutes_weekday' => ['minutes' => (int) ($s['early_leave_minutes_weekday'] ?? 0)],
            'early_leave_minutes_prescribed_holiday' => ['minutes' => (int) ($s['early_leave_minutes_prescribed_holiday'] ?? 0)],
            'early_leave_minutes_legal_holiday' => ['minutes' => (int) ($s['early_leave_minutes_legal_holiday'] ?? 0)],
            // 遅刻・早退回数（休日）
            'late_count_prescribed_holiday' => ['quantity' => (float) ($s['late_count_prescribed_holiday'] ?? 0)],
            'late_count_legal_holiday' => ['quantity' => (float) ($s['late_count_legal_holiday'] ?? 0)],
            'early_leave_count_prescribed_holiday' => ['quantity' => (float) ($s['early_leave_count_prescribed_holiday'] ?? 0)],
            'early_leave_count_legal_holiday' => ['quantity' => (float) ($s['early_leave_count_legal_holiday'] ?? 0)],
            // 所定外時間（法定休日）／法定外時間（所定・法定休日）
            'overtime_legal_holiday' => ['minutes' => (int) ($s['legal_holiday_overtime_minutes'] ?? 0)],
            'statutory_overtime_prescribed_holiday' => ['minutes' => (int) ($s['prescribed_holiday_statutory_over_minutes'] ?? 0)],
            'statutory_overtime_legal_holiday' => ['minutes' => (int) ($s['legal_holiday_statutory_over_minutes'] ?? 0)],
            // 深夜所定外時間
            'night_overtime_weekday' => ['minutes' => (int) ($s['night_overtime_weekday'] ?? 0)],
            'night_overtime_prescribed_holiday' => ['minutes' => (int) ($s['night_overtime_prescribed_holiday'] ?? 0)],
            'night_overtime_legal_holiday' => ['minutes' => (int) ($s['night_overtime_legal_holiday'] ?? 0)],
            // 深夜法定外時間
            'night_statutory_overtime_weekday' => ['minutes' => (int) ($s['night_statutory_weekday'] ?? 0)],
            'night_statutory_overtime_prescribed_holiday' => ['minutes' => (int) ($s['night_statutory_prescribed_holiday'] ?? 0)],
            'night_statutory_overtime_legal_holiday' => ['minutes' => (int) ($s['night_statutory_legal_holiday'] ?? 0)],
            // 休憩時間（休日）
            'break_prescribed_holiday' => ['minutes' => (int) ($s['prescribed_holiday_break_minutes'] ?? 0)],
            'break_legal_holiday' => ['minutes' => (int) ($s['legal_holiday_break_minutes'] ?? 0)],
            // 所定外休憩時間
            'break_overtime_weekday' => ['minutes' => (int) ($s['break_overtime_weekday'] ?? 0)],
            'break_overtime_prescribed_holiday' => ['minutes' => (int) ($s['break_overtime_prescribed_holiday'] ?? 0)],
            'break_overtime_legal_holiday' => ['minutes' => (int) ($s['break_overtime_legal_holiday'] ?? 0)],
            // 法定外休憩時間
            'break_statutory_weekday' => ['minutes' => (int) ($s['break_statutory_weekday'] ?? 0)],
            'break_statutory_prescribed_holiday' => ['minutes' => (int) ($s['break_statutory_prescribed_holiday'] ?? 0)],
            'break_statutory_legal_holiday' => ['minutes' => (int) ($s['break_statutory_legal_holiday'] ?? 0)],
            // 深夜休憩時間
            'break_night_weekday' => ['minutes' => (int) ($s['break_night_weekday'] ?? 0)],
            'break_night_prescribed_holiday' => ['minutes' => (int) ($s['break_night_prescribed_holiday'] ?? 0)],
            'break_night_legal_holiday' => ['minutes' => (int) ($s['break_night_legal_holiday'] ?? 0)],
            // 深夜所定外休憩時間
            'break_night_overtime_weekday' => ['minutes' => (int) ($s['break_night_overtime_weekday'] ?? 0)],
            'break_night_overtime_prescribed_holiday' => ['minutes' => (int) ($s['break_night_overtime_prescribed_holiday'] ?? 0)],
            'break_night_overtime_legal_holiday' => ['minutes' => (int) ($s['break_night_overtime_legal_holiday'] ?? 0)],
            // 深夜法定外休憩時間
            'break_night_statutory_weekday' => ['minutes' => (int) ($s['break_night_statutory_weekday'] ?? 0)],
            'break_night_statutory_prescribed_holiday' => ['minutes' => (int) ($s['break_night_statutory_prescribed_holiday'] ?? 0)],
            'break_night_statutory_legal_holiday' => ['minutes' => (int) ($s['break_night_statutory_legal_holiday'] ?? 0)],
        ];
    }

    /**
     * 当該従業員の期間勤怠を集計し、勤怠項目 code → 分数/数量 の写像を返す。
     *
     * @param array<string, mixed> $settings loadSettings() の結果（従業員個別値を反映済み）
     */
    private function attendanceMinutes(PayrollRun $run, User $user, array $settings): array
    {
        $result = $this->summaries->forMonth(
            $run->period_key,
            User::whereKey($user->id)->get(['id', 'name', 'break_minutes']),
        );
        $userSummary = $result['users'][0] ?? [];

        return $this->attendanceValueMap($userSummary, $settings);
    }

    /**
     * 所定労働の基礎値を解決する。
     * 優先順位: 従業員個別値（給与情報タブ） > 年度設定(FiscalYear) > 会社共通設定（基本設定＞勤怠）。
     *
     * @return array<string, mixed>
     */
    private function loadSettings(?EmployeePayroll $employee = null, ?PayrollRun $run = null): array
    {
        $workHoursPerDayMin = (int) Setting::getValue('work_hours_per_day', '480');
        $monthlyAvgDays = (float) Setting::getValue('monthly_avg_work_days', '21');

        // 年度設定(FiscalYear)があれば会社共通設定より優先
        $effectiveDate = $run
            ? (($run->payment_date ?? Carbon::parse($run->period_key . '-01')->endOfMonth())->toDateString())
            : null;
        if ($fy = \App\Models\FiscalYear::forDate($effectiveDate)) {
            $workHoursPerDayMin = $fy->workHoursPerDayMinutes();
            $monthlyAvgDays = $fy->effectiveMonthlyAvgDays();
        }

        // 従業員個別の所定労働時間（時間）があれば優先（分換算）
        if ($employee && (float) $employee->work_hours_per_day > 0) {
            $workHoursPerDayMin = (int) round((float) $employee->work_hours_per_day * 60);
        }
        // 従業員個別の月平均勤務日数があれば優先
        if ($employee && (float) $employee->work_days_monthly_avg > 0) {
            $monthlyAvgDays = (float) $employee->work_days_monthly_avg;
        }

        $monthlyScheduledHours = ($workHoursPerDayMin / 60.0) * $monthlyAvgDays;

        return [
            'workHoursPerDayMin' => $workHoursPerDayMin,
            'monthlyAvgDays' => $monthlyAvgDays,
            'monthlyScheduledHours' => $monthlyScheduledHours,
        ];
    }

    /**
     * 時給1/時給2/日給1/日給2 の単価を解決する。
     * MFに倣い、時給1/日給1・時給2/日給2 は従業員情報の設定値を優先し、
     * 未設定の場合は割増基礎からの算出値（時給1=基礎÷月平均所定時間 / 日給1=基礎÷月平均所定日数）へフォールバックする。
     *
     * @return array{hourly1: float, hourly2: float, daily1: float, daily2: float}
     */
    private function rateBases(EmployeePayroll $employee, float $allowanceBase, float $monthlyHours, float $monthlyDays): array
    {
        $computedHourly = $monthlyHours > 0 ? $allowanceBase / $monthlyHours : 0.0;
        $computedDaily = $monthlyDays > 0 ? $allowanceBase / $monthlyDays : 0.0;

        $hourly1 = (int) $employee->hourly_wage > 0 ? (float) $employee->hourly_wage : $computedHourly;
        $daily1 = (int) $employee->daily_wage > 0 ? (float) $employee->daily_wage : $computedDaily;

        return [
            'hourly1' => $hourly1,
            'hourly2' => (int) $employee->hourly_wage2 > 0 ? (float) $employee->hourly_wage2 : $hourly1,
            'daily1' => $daily1,
            'daily2' => (int) $employee->daily_wage2 > 0 ? (float) $employee->daily_wage2 : $daily1,
        ];
    }

    /**
     * 前月（同一 pay_type・同一事業所スコープ）の給与明細から割増基礎/控除基礎のスナップショットを取得する。
     *
     * @return array{allowance_base: float, deduction_base: float}
     */
    private function previousBases(PayrollRun $run, User $user): array
    {
        $prev = Payslip::query()
            ->select('payslips.allowance_base', 'payslips.deduction_base')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->where('payslips.user_id', $user->id)
            ->where('payroll_runs.pay_type', $run->pay_type)
            ->where('payroll_runs.period_key', '<', $run->period_key)
            ->when($run->business_location_id, fn ($q) => $q->where('payroll_runs.business_location_id', $run->business_location_id))
            ->orderByDesc('payroll_runs.period_key')
            ->first();

        return [
            'allowance_base' => (float) ($prev->allowance_base ?? 0),
            'deduction_base' => (float) ($prev->deduction_base ?? 0),
        ];
    }

    /**
     * 対象期間の「当月の所定労働日数/時間」を暦（休日曜日を除く）から算出する。
     * 既定は 法定休日=日曜 / 所定休日=土曜。1日の所定労働時間は settings から取得。
     *
     * @param  array<string, mixed>  $settings
     * @return array{scheduledDaysMonthActual: float, scheduledHoursMonthActual: float}
     */
    private function scheduledForPeriod(PayrollRun $run, array $settings): array
    {
        $period = MonthPeriod::resolve($run->period_key);
        $legal = $this->splitDows(Setting::getValue('legal_holiday_dows', 'sunday'));
        $prescribed = $this->splitDows(Setting::getValue('prescribed_holiday_dows', 'saturday'));
        $holidays = array_merge($legal, $prescribed);

        $days = 0;
        $cursor = Carbon::parse($period['from']);
        $end = Carbon::parse($period['to']);
        while ($cursor->lte($end)) {
            if (! in_array(strtolower($cursor->englishDayOfWeek), $holidays, true)) {
                $days++;
            }
            $cursor->addDay();
        }

        $hoursPerDay = ((float) ($settings['workHoursPerDayMin'] ?? 0)) / 60.0;

        return [
            'scheduledDaysMonthActual' => (float) $days,
            'scheduledHoursMonthActual' => $hoursPerDay * $days,
        ];
    }

    /**
     * "sunday,saturday" 形式の設定値を小文字曜日名の配列へ。
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

    private function roundYen(float $value, string $rule): int
    {
        return match ($rule) {
            'ceil' => (int) ceil($value),
            'floor' => (int) floor($value),
            default => (int) round($value),
        };
    }

    private function upsertItem(Payslip $payslip, string $type, array $row, int $sort): void
    {
        // 手入力上書き行が存在する場合は自動値で潰さない
        $exists = $payslip->items()
            ->where('item_type', $type)
            ->where('code', $row['code'])
            ->where('is_manual_override', true)
            ->exists();
        if ($exists) {
            return;
        }

        $payslip->items()->create([
            'item_type' => $type,
            'source_master_id' => $row['source_master_id'] ?? null,
            'code' => $row['code'],
            'name' => $row['name'],
            'category' => $row['category'] ?? null,
            'amount' => $row['amount'] ?? null,
            'minutes' => $row['minutes'] ?? null,
            'quantity' => $row['quantity'] ?? null,
            'is_manual_override' => false,
            'sort_order' => $sort,
        ]);
    }
}
