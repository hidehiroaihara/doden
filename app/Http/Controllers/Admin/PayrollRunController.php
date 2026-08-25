<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BonusInput;
use App\Models\BusinessLocation;
use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\BonusCalculator;
use App\Services\Payroll\PayrollCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * 給与計算バッチ（給与計算画面）。
 * 一覧で期間ごとのバッチを管理し、詳細でマスターディテール
 * （左: 従業員一覧 / 右: 支給・控除・勤怠の内訳）を表示して計算・確定する。
 *
 * 参照: 資料/設計書 04_給与計算 / 19_給与明細
 */
class PayrollRunController extends Controller
{
    public function __construct(
        private PayrollCalculator $calculator,
        private BonusCalculator $bonusCalculator,
    ) {}

    public function index()
    {
        $runs = PayrollRun::with('businessLocation:id,name')
            ->withCount('payslips')
            ->orderByDesc('period_key')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PayrollRun $r) => [
                'id' => $r->id,
                'period_key' => $r->period_key,
                'pay_type' => $r->pay_type,
                'business_location' => $r->businessLocation?->name,
                'status' => $r->status,
                'payslips_count' => $r->payslips_count,
                'payment_date' => $r->payment_date?->toDateString(),
                'finalized_at' => $r->finalized_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('Admin/Payroll/Runs/Index', [
            'runs' => $runs,
            'options' => [
                'businessLocations' => BusinessLocation::orderBy('sort_order')->get(['id', 'name']),
                'defaultPeriod' => now()->format('Y-m'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'period_key' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'business_location_id' => ['nullable', 'exists:business_locations,id'],
            'pay_type' => ['required', 'in:salary,bonus'],
            'payment_date' => ['nullable', 'date'],
        ]);

        $exists = PayrollRun::where('period_key', $validated['period_key'])
            ->where('pay_type', $validated['pay_type'])
            ->where('business_location_id', $validated['business_location_id'] ?? null)
            ->first();

        if ($exists) {
            return redirect()->route('admin.payroll.runs.show', $exists->id)
                ->with('info', '同じ期間・事業所のバッチが既に存在します。');
        }

        $run = PayrollRun::create([
            ...$validated,
            'status' => 'draft',
        ]);

        return redirect()->route('admin.payroll.runs.show', $run->id)
            ->with('success', '給与計算バッチを作成しました。');
    }

    public function show(PayrollRun $run)
    {
        $run->load('businessLocation:id,name');

        $payslips = $run->payslips()
            ->with([
                'user:id,name,last_name,first_name,department_id',
                'user.department:id,name',
                'user.employeePayroll:id,user_id,employee_no,position,employment_type',
                'items',
            ])
            ->orderByEmployeeNo()
            ->get()
            ->map(fn (Payslip $p) => $this->presentPayslip($p));

        $isBonus = $run->pay_type === 'bonus';

        return Inertia::render('Admin/Payroll/Runs/Show', [
            'run' => [
                'id' => $run->id,
                'period_key' => $run->period_key,
                'pay_type' => $run->pay_type,
                'business_location' => $run->businessLocation?->name,
                'business_location_id' => $run->business_location_id,
                'status' => $run->status,
                'closing_date' => $run->closing_date?->toDateString(),
                'payment_date' => $run->payment_date?->toDateString(),
                'publish_date' => $run->publish_date?->toDateString(),
                'finalized_at' => $run->finalized_at?->format('Y-m-d H:i'),
                'memo' => $run->memo,
            ],
            'payslips' => $payslips,
            'eligibleCount' => $this->eligibleEmployeesQuery($run)->count(),
            'bonusInputs' => $isBonus ? $this->bonusInputRows($run) : [],
            'periodRuns' => $this->periodRunOptions($run),
            'previousRun' => $this->previousRunComparison($run),
            'attendanceCategories' => $this->attendanceCategoryLabels(),
            'summary' => [
                'total_earnings' => $payslips->sum('total_earnings'),
                'total_deductions' => $payslips->sum('total_deductions'),
                'net_pay' => $payslips->sum('net_pay'),
                'confirmed_count' => $payslips->where('is_confirmed', true)->count(),
            ],
        ]);
    }

    /** 締め日/支給日/公開日の変更（MFメニュー）。 */
    public function updateDates(Request $request, PayrollRun $run)
    {
        $validated = $request->validate([
            'closing_date' => ['nullable', 'date'],
            'payment_date' => ['nullable', 'date'],
            'publish_date' => ['nullable', 'date'],
        ]);

        $run->update($validated);

        return back()->with('success', '締め日・支給日・公開日を更新しました。');
    }

    /** 期間メモの更新（給与明細には表示しない）。 */
    public function updateMemo(Request $request, PayrollRun $run)
    {
        $validated = $request->validate([
            'memo' => ['nullable', 'string', 'max:2000'],
        ]);

        $run->update(['memo' => $validated['memo'] ?? null]);

        return back()->with('success', 'メモを保存しました。');
    }

    /** 手入力の上書きを一括で自動計算に戻す（MFメニュー）。 */
    public function resetOverrides(PayrollRun $run)
    {
        if ($run->isFinalized()) {
            return back()->with('info', '確定済みのバッチは変更できません。');
        }
        if ($run->payslips()->count() === 0) {
            return back()->with('info', '先に給与計算を実行してください。');
        }

        DB::transaction(function () use ($run) {
            $run->payslips()->each(function (Payslip $p) {
                $p->items()->update(['is_manual_override' => false]);
            });
        });

        if ($run->pay_type === 'bonus') {
            $this->bonusCalculator->calculateRun($run);
        } else {
            $this->calculator->calculateRun($run);
        }

        return back()->with('success', '手入力を破棄し、一括で自動計算に戻しました。');
    }

    /** 一括入力モードの保存（複数明細の支給・控除を一括更新）。 */
    public function bulkUpdate(Request $request, PayrollRun $run)
    {
        if ($run->isFinalized()) {
            return back()->with('info', '確定済みのため編集できません。');
        }

        $validated = $request->validate([
            'items' => ['array'],
            'items.*.id' => ['required', 'integer', 'exists:payslip_items,id'],
            'items.*.amount' => ['nullable', 'integer'],
        ]);

        $runPayslipIds = $run->payslips()->pluck('id');

        DB::transaction(function () use ($validated, $runPayslipIds) {
            $affected = [];
            foreach ($validated['items'] ?? [] as $row) {
                $item = \App\Models\PayslipItem::whereKey($row['id'])
                    ->whereIn('payslip_id', $runPayslipIds)
                    ->first();
                if (! $item || $item->item_type === 'attendance') {
                    continue;
                }
                $newAmount = (int) ($row['amount'] ?? 0);
                if ((int) $item->amount === $newAmount) {
                    continue;
                }
                $item->update([
                    'amount' => $newAmount,
                    'is_manual_override' => true,
                ]);
                $affected[$item->payslip_id] = true;
            }

            foreach (array_keys($affected) as $payslipId) {
                $this->recalcPayslipTotals(Payslip::find($payslipId));
            }
        });

        return back()->with('success', '一括入力を保存しました。');
    }

    public function calculate(PayrollRun $run)
    {
        if ($run->isFinalized()) {
            return back()->with('info', '確定済みのバッチは再計算できません。');
        }

        if ($run->pay_type === 'bonus') {
            $this->bonusCalculator->calculateRun($run);
        } else {
            $this->calculator->calculateRun($run);
        }
        $run->update(['status' => 'calculated']);

        return back()->with('success', $run->pay_type === 'bonus' ? '賞与計算を実行しました。' : '給与計算を実行しました。');
    }

    public function finalize(PayrollRun $run)
    {
        if ($run->status === 'draft' || $run->payslips()->count() === 0) {
            return back()->with('info', '先に給与計算を実行してください。');
        }

        $run->update(['status' => 'finalized', 'finalized_at' => now()]);

        return back()->with('success', 'バッチを確定しました。');
    }

    public function reopen(PayrollRun $run)
    {
        if (! $run->isFinalized()) {
            return back();
        }

        $run->update(['status' => 'calculated', 'finalized_at' => null]);

        return back()->with('success', '確定を解除しました。【注意】再計算すると料率・標準報酬・税額表など「現在の」マスタが適用され、過去の内容が上書きされます。当時の内容を保持したい場合は再計算せずに確定し直してください。');
    }

    public function updatePayslip(Request $request, PayrollRun $run, Payslip $payslip)
    {
        abort_unless($payslip->payroll_run_id === $run->id, 404);

        if ($run->isFinalized()) {
            return back()->with('info', '確定済みのため編集できません。');
        }

        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
            'is_confirmed' => ['boolean'],
            'items' => ['array'],
            'items.*.id' => ['required', 'integer', 'exists:payslip_items,id'],
            'items.*.amount' => ['nullable', 'integer'],
            'items.*.minutes' => ['nullable', 'integer'],
            'items.*.quantity' => ['nullable', 'numeric'],
        ]);

        DB::transaction(function () use ($payslip, $validated) {
            foreach ($validated['items'] ?? [] as $row) {
                $item = $payslip->items()->whereKey($row['id'])->first();
                if (! $item) {
                    continue;
                }

                // 勤怠は時間(分)・回数(数量)を手入力として上書き。
                if ($item->item_type === 'attendance') {
                    $changed = false;
                    if (array_key_exists('minutes', $row) && $row['minutes'] !== null) {
                        $newMinutes = (int) $row['minutes'];
                        if ((int) $item->minutes !== $newMinutes) {
                            $item->minutes = $newMinutes;
                            $changed = true;
                        }
                    }
                    if (array_key_exists('quantity', $row) && $row['quantity'] !== null) {
                        $newQuantity = (float) $row['quantity'];
                        if ((float) $item->quantity !== $newQuantity) {
                            $item->quantity = $newQuantity;
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        $item->is_manual_override = true;
                        $item->save();
                    }
                    continue;
                }

                // 金額が変わった項目だけ手入力として上書き（未変更行の自動計算状態を維持）。
                $newAmount = (int) ($row['amount'] ?? 0);
                if ((int) $item->amount === $newAmount) {
                    continue;
                }
                $item->update([
                    'amount' => $newAmount,
                    'is_manual_override' => true,
                ]);
            }

            $this->recalcPayslipTotals($payslip);

            $payslip->update([
                'remarks' => $validated['remarks'] ?? null,
                'is_confirmed' => (bool) ($validated['is_confirmed'] ?? false),
            ]);
        });

        return back()->with('success', '明細を更新しました。');
    }

    /** 明細項目1件を自動計算の金額に戻す（手入力の解除）。 */
    public function revertItem(PayrollRun $run, Payslip $payslip, \App\Models\PayslipItem $item)
    {
        abort_unless($payslip->payroll_run_id === $run->id && $item->payslip_id === $payslip->id, 404);

        if ($run->isFinalized()) {
            return back()->with('info', '確定済みのため変更できません。');
        }

        // 手入力フラグを解除し、当該従業員のみ再計算（自動計算行として再生成される）。
        $item->update(['is_manual_override' => false]);

        $user = $payslip->user;
        if ($user) {
            if ($run->pay_type === 'bonus') {
                $this->bonusCalculator->calculate($run, $user);
            } else {
                $this->calculator->calculate($run, $user);
            }
        }

        return back()->with('success', '自動計算の金額に戻しました。');
    }

    public function destroy(PayrollRun $run)
    {
        if ($run->isFinalized()) {
            return back()->with('info', '確定済みのバッチは削除できません。');
        }

        $run->delete();

        return redirect()->route('admin.payroll.runs.index')->with('success', 'バッチを削除しました。');
    }

    /** 賞与額の一括入力（賞与バッチのみ）。 */
    public function saveBonusInputs(Request $request, PayrollRun $run)
    {
        abort_unless($run->pay_type === 'bonus', 404);

        if ($run->isFinalized()) {
            return back()->with('info', '確定済みのため編集できません。');
        }

        $validated = $request->validate([
            'inputs' => ['array'],
            'inputs.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'inputs.*.gross_amount' => ['required', 'integer', 'min:0'],
            'inputs.*.previous_month_taxable' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($run, $validated) {
            foreach ($validated['inputs'] ?? [] as $row) {
                BonusInput::updateOrCreate(
                    ['payroll_run_id' => $run->id, 'user_id' => $row['user_id']],
                    [
                        'gross_amount' => $row['gross_amount'],
                        'previous_month_taxable' => $row['previous_month_taxable'],
                    ],
                );
            }
        });

        return back()->with('success', '賞与額を保存しました。「賞与計算を実行」で反映します。');
    }

    /** 明細の支給・控除合計と差引支給額を再集計する。 */
    private function recalcPayslipTotals(?Payslip $payslip): void
    {
        if (! $payslip) {
            return;
        }

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

    /** 支給期間セレクタ用: 同一種別のバッチ一覧（新しい順）。 */
    private function periodRunOptions(PayrollRun $run): array
    {
        return PayrollRun::where('pay_type', $run->pay_type)
            ->orderByDesc('period_key')
            ->orderByDesc('id')
            ->get(['id', 'period_key', 'closing_date', 'payment_date', 'status'])
            ->map(fn (PayrollRun $r) => [
                'id' => $r->id,
                'period_key' => $r->period_key,
                'closing_date' => $r->closing_date?->toDateString(),
                'payment_date' => $r->payment_date?->toDateString(),
                'status' => $r->status,
            ])
            ->all();
    }

    /** 前月比較用: 直前期間の run と従業員別 net_pay を返す。 */
    private function previousRunComparison(PayrollRun $run): ?array
    {
        $prev = PayrollRun::where('pay_type', $run->pay_type)
            ->where('business_location_id', $run->business_location_id)
            ->where('period_key', '<', $run->period_key)
            ->orderByDesc('period_key')
            ->first();

        if (! $prev) {
            return null;
        }

        $byUser = $prev->payslips()
            ->get(['user_id', 'total_earnings', 'total_deductions', 'net_pay'])
            ->keyBy('user_id')
            ->map(fn (Payslip $p) => [
                'total_earnings' => (int) $p->total_earnings,
                'total_deductions' => (int) $p->total_deductions,
                'net_pay' => (int) $p->net_pay,
            ]);

        return [
            'period_key' => $prev->period_key,
            'total_earnings' => (int) $prev->payslips()->sum('total_earnings'),
            'total_deductions' => (int) $prev->payslips()->sum('total_deductions'),
            'net_pay' => (int) $prev->payslips()->sum('net_pay'),
            'by_user' => $byUser,
        ];
    }

    /** 勤怠4象限のカテゴリラベル（設計書§3-5）。 */
    private function attendanceCategoryLabels(): array
    {
        return [
            'fixed_work' => '所定労働',
            'attendance' => '出欠勤',
            'actual_work' => '実働時間',
            'leave' => '休暇',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function bonusInputRows(PayrollRun $run): array
    {
        $inputs = BonusInput::where('payroll_run_id', $run->id)->get()->keyBy('user_id');

        return $this->eligibleEmployeesQuery($run)
            ->orderByEmployeeNo()
            ->get(['users.id', 'users.name'])
            ->map(fn (User $u) => [
                'user_id' => $u->id,
                'user_name' => $u->name,
                'gross_amount' => (int) ($inputs->get($u->id)?->gross_amount ?? 0),
                'previous_month_taxable' => (int) ($inputs->get($u->id)?->previous_month_taxable ?? 0),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    /** 雇用形態コードを日本語ラベルへ変換（社員/アルバイト/パート等）。 */
    private function employmentTypeLabel(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        return [
            'executive' => '役員',
            'employee_executive' => '使用人兼務役員',
            'full_time' => '正社員',
            'contract' => '契約社員',
            'entrusted' => '嘱託社員',
            'part_time' => 'パート',
            'arbeit' => 'アルバイト',
            'dispatch' => '派遣社員',
            'other' => 'その他',
        ][$type] ?? $type;
    }

    private function presentPayslip(Payslip $p): array
    {
        return [
            'id' => $p->id,
            'user_id' => $p->user_id,
            'user_name' => $p->user?->name,
            'employee_no' => $p->user?->employeePayroll?->employee_no,
            'employment_type_label' => $this->employmentTypeLabel($p->user?->employeePayroll?->employment_type),
            'department' => $p->user?->department?->name,
            'total_earnings' => $p->total_earnings,
            'total_deductions' => $p->total_deductions,
            'net_pay' => $p->net_pay,
            'is_confirmed' => $p->is_confirmed,
            'remarks' => $p->remarks,
            'calculated_at' => $p->calculated_at?->format('Y-m-d H:i'),
            'earnings' => $this->itemsByType($p, 'earning'),
            'deductions' => $this->itemsByType($p, 'deduction'),
            'attendances' => $this->itemsByType($p, 'attendance'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsByType(Payslip $p, string $type): array
    {
        return $p->items
            ->where('item_type', $type)
            ->map(fn ($i) => [
                'id' => $i->id,
                'code' => $i->code,
                'name' => $i->name,
                'category' => $i->category,
                'amount' => $i->amount,
                'minutes' => $i->minutes,
                'quantity' => $i->quantity !== null ? (float) $i->quantity : null,
                'is_manual_override' => $i->is_manual_override,
            ])
            ->values()
            ->all();
    }

    private function eligibleEmployeesQuery(PayrollRun $run)
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('employeePayroll', function ($q) use ($run) {
                if ($run->business_location_id) {
                    $q->where('business_location_id', $run->business_location_id);
                }
            });
    }
}
