<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Models\User;
use App\Models\YearEndAdjustment;
use App\Services\Payroll\Reports\PayrollReportService;
use App\Services\Payroll\Reports\WithholdingSlipService;
use App\Services\Payroll\YearEndAdjustmentCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * 年末調整。
 * 年間の給与確定データ（源泉徴収税額・社会保険料・給与総額）を取り込み、各種申告控除を加味して
 * 年調年税額・過不足税額を算出し、控除項目「年調過不足税額」として給与バッチへ反映する。
 *
 * 参照: 資料/設計書 30_源泉徴収簿 / 04_給与計算
 */
class YearEndAdjustmentController extends Controller
{
    public function __construct(
        private PayrollReportService $reports,
        private YearEndAdjustmentCalculator $calc,
        private WithholdingSlipService $slip,
    ) {}

    public function index(Request $request)
    {
        $year = (int) ($request->query('year') ?: now()->format('Y'));

        $records = YearEndAdjustment::where('year', $year)->get()->keyBy('user_id');

        $rows = User::query()
            ->whereHas('employeePayroll')
            ->with('employeePayroll:id,user_id,employee_no,tax_table')
            ->orderByDesc('users.is_active')
            ->orderByEmployeeNo()
            ->get()
            ->map(function (User $u) use ($year, $records) {
                $agg = $this->aggregate($u->id, $year);
                $rec = $records->get($u->id);

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'employee_no' => $u->employeePayroll?->employee_no,
                    'tax_table' => $u->employeePayroll?->tax_table ?? 'kou',
                    'gross_total' => $agg['gross'],
                    'withheld_tax' => $agg['withheld'],
                    'has_record' => (bool) $rec,
                    'status' => $rec?->status,
                    'status_label' => $rec?->statusLabel(),
                    'adjustment_amount' => $rec?->adjustment_amount,
                ];
            })
            ->values();

        return Inertia::render('Admin/Payroll/YearEnd/Index', [
            'year' => $year,
            'rows' => $rows,
            'options' => ['years' => range((int) now()->format('Y'), (int) now()->format('Y') - 5)],
        ]);
    }

    public function edit(Request $request, User $user)
    {
        $year = (int) ($request->query('year') ?: now()->format('Y'));
        $user->load('employeePayroll');

        $agg = $this->aggregate($user->id, $year);
        $record = YearEndAdjustment::firstOrNew(['user_id' => $user->id, 'year' => $year]);

        // 既定の扶養人数は従業員情報から補完
        $dependentCount = $record->exists ? $record->dependent_count : (int) ($user->employeePayroll?->dependents_count ?? 0);

        $inputs = [
            'social_insurance_declared' => (int) $record->social_insurance_declared,
            'life_insurance_deduction' => (int) $record->life_insurance_deduction,
            'earthquake_insurance_deduction' => (int) $record->earthquake_insurance_deduction,
            'spouse_deduction' => (int) $record->spouse_deduction,
            'dependent_count' => $dependentCount,
            'housing_loan_credit' => (int) $record->housing_loan_credit,
            'other_deduction' => (int) $record->other_deduction,
        ];

        $preview = $this->calc->compute(array_merge($inputs, [
            'gross' => $agg['gross'],
            'withheld_tax' => $agg['withheld'],
            'social_insurance_withheld' => $agg['social'],
        ]));

        return Inertia::render('Admin/Payroll/YearEnd/Edit', [
            'year' => $year,
            'employee' => [
                'id' => $user->id,
                'name' => $user->name,
                'employee_no' => $user->employeePayroll?->employee_no,
                'tax_table' => $user->employeePayroll?->tax_table ?? 'kou',
            ],
            'aggregate' => $agg,
            'inputs' => $inputs,
            'preview' => $preview,
            'record' => $record->exists ? [
                'id' => $record->id,
                'status' => $record->status,
                'status_label' => $record->statusLabel(),
                'reflected_run_id' => $record->reflected_run_id,
            ] : null,
            'runs' => $this->reflectableRuns($year),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $year = (int) ($request->input('year') ?: now()->format('Y'));

        $validated = $request->validate([
            'social_insurance_declared' => ['nullable', 'integer', 'min:0'],
            'life_insurance_deduction' => ['nullable', 'integer', 'min:0'],
            'earthquake_insurance_deduction' => ['nullable', 'integer', 'min:0'],
            'spouse_deduction' => ['nullable', 'integer', 'min:0'],
            'dependent_count' => ['nullable', 'integer', 'min:0', 'max:30'],
            'housing_loan_credit' => ['nullable', 'integer', 'min:0'],
            'other_deduction' => ['nullable', 'integer', 'min:0'],
            'confirm' => ['boolean'],
        ]);

        $agg = $this->aggregate($user->id, $year);

        $result = $this->calc->compute([
            'gross' => $agg['gross'],
            'withheld_tax' => $agg['withheld'],
            'social_insurance_withheld' => $agg['social'],
            'social_insurance_declared' => (int) ($validated['social_insurance_declared'] ?? 0),
            'life_insurance_deduction' => (int) ($validated['life_insurance_deduction'] ?? 0),
            'earthquake_insurance_deduction' => (int) ($validated['earthquake_insurance_deduction'] ?? 0),
            'spouse_deduction' => (int) ($validated['spouse_deduction'] ?? 0),
            'dependent_count' => (int) ($validated['dependent_count'] ?? 0),
            'housing_loan_credit' => (int) ($validated['housing_loan_credit'] ?? 0),
            'other_deduction' => (int) ($validated['other_deduction'] ?? 0),
        ]);

        $record = YearEndAdjustment::firstOrNew(['user_id' => $user->id, 'year' => $year]);
        // 反映済みは再編集で status を戻さない
        $status = $record->status === 'reflected' ? 'reflected' : (($validated['confirm'] ?? false) ? 'confirmed' : 'draft');

        $record->fill([
            'gross_total' => $agg['gross'],
            'social_insurance_withheld' => $agg['social'],
            'withheld_tax' => $agg['withheld'],
            'social_insurance_declared' => (int) ($validated['social_insurance_declared'] ?? 0),
            'life_insurance_deduction' => (int) ($validated['life_insurance_deduction'] ?? 0),
            'earthquake_insurance_deduction' => (int) ($validated['earthquake_insurance_deduction'] ?? 0),
            'spouse_deduction' => (int) ($validated['spouse_deduction'] ?? 0),
            'dependent_count' => (int) ($validated['dependent_count'] ?? 0),
            'housing_loan_credit' => (int) ($validated['housing_loan_credit'] ?? 0),
            'other_deduction' => (int) ($validated['other_deduction'] ?? 0),
            'salary_income' => $result['salary_income'],
            'taxable_income' => $result['taxable_income'],
            'calculated_tax' => $result['calculated_tax'],
            'yearly_tax' => $result['yearly_tax'],
            'adjustment_amount' => $result['adjustment_amount'],
            'status' => $status,
        ])->save();

        return back()->with('success', '年末調整を計算しました。');
    }

    /** 給与所得の源泉徴収票PDF（年末調整の結果を反映）。 */
    public function slip(Request $request, User $user)
    {
        $year = (int) ($request->query('year') ?: now()->format('Y'));

        $pdf = Pdf::loadView('payslips.withholding_slip', $this->slip->build($user, $year))
            ->setPaper('a4', 'portrait');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="withholding_slip_' . $user->id . '_' . $year . '.pdf"',
        ]);
    }

    /** 過不足税額を給与バッチへ反映（控除項目 year_end_adjustment）。 */
    public function reflect(Request $request, YearEndAdjustment $adjustment)
    {
        $validated = $request->validate([
            'run_id' => ['required', 'integer', 'exists:payroll_runs,id'],
        ]);

        $run = PayrollRun::findOrFail($validated['run_id']);
        if ($run->isFinalized()) {
            return back()->with('info', '確定済みのバッチには反映できません。確定を解除してください。');
        }

        $payslip = Payslip::where('payroll_run_id', $run->id)->where('user_id', $adjustment->user_id)->first();
        if (! $payslip) {
            return back()->with('info', '対象バッチに当該従業員の給与明細がありません。先に給与計算を実行してください。');
        }

        DB::transaction(function () use ($payslip, $adjustment, $run) {
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

            $this->recalcPayslipTotals($payslip);

            $adjustment->update([
                'status' => 'reflected',
                'reflected_run_id' => $run->id,
                'reflected_at' => now(),
            ]);
        });

        return back()->with('success', '過不足税額を給与バッチへ反映しました。');
    }

    /** 明細合計を item から再集計する。 */
    private function recalcPayslipTotals(Payslip $payslip): void
    {
        $totals = $payslip->items()
            ->reorder()
            ->selectRaw('item_type, COALESCE(SUM(amount),0) as total')
            ->groupBy('item_type')
            ->pluck('total', 'item_type');

        $earnings = (int) ($totals['earning'] ?? 0);
        $deductions = (int) ($totals['deduction'] ?? 0);

        $payslip->update([
            'total_earnings' => $earnings,
            'total_deductions' => $deductions,
            'net_pay' => $earnings - $deductions,
        ]);
    }

    /**
     * 当該年の給与・賞与から給与総額・社会保険料・所得税を集計。
     *
     * @return array{gross:int, social:int, withheld:int}
     */
    private function aggregate(int $userId, int $year): array
    {
        $data = $this->reports->employeeYearlyPayslips($userId, $year);

        $gross = 0;
        $social = 0;
        $withheld = 0;
        foreach ($data['salary']->concat($data['bonus']->values()) as $p) {
            $s = $this->reports->summarize($p);
            $gross += $s['gross'];
            $social += $s['social_insurance'];
            $withheld += $s['income_tax'];
        }

        return ['gross' => $gross, 'social' => $social, 'withheld' => $withheld];
    }

    /** @return array<int, array{id:int, label:string}> */
    private function reflectableRuns(int $year): array
    {
        return PayrollRun::query()
            ->whereBetween('period_key', ["{$year}-01", "{$year}-12"])
            ->with('businessLocation:id,name')
            ->orderByDesc('period_key')
            ->get()
            ->map(fn (PayrollRun $r) => [
                'id' => $r->id,
                'label' => $r->period_key . ($r->pay_type === 'bonus' ? '（賞与）' : '') . ($r->businessLocation ? ' / ' . $r->businessLocation->name : '') . ($r->isFinalized() ? '【確定済】' : ''),
            ])
            ->all();
    }
}
