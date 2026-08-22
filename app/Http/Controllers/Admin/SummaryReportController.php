<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\DeductionItemMaster;
use App\Models\PayItemMaster;
use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Models\ReportViewPattern;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * 支給控除一覧表（通常／部門別）。
 * 指定した支給期間の全従業員の支給・控除内訳を横持ちのピボット表で表示し、CSV出力する。
 * 部門別は従業員の所属部門でグルーピングし小計行を挿入する。
 *
 * 本帳票は専用テーブルを持たず、payslips / payslip_items から動的にピボット生成する。
 *
 * 参照: 資料/設計書 21_支給控除一覧表
 */
class SummaryReportController extends Controller
{
    private const REPORT_KEY = 'summary';

    public function show(Request $request)
    {
        $group = $request->query('group') === 'department' ? 'department' : 'none';
        $run = $this->resolveRun($request);

        $data = $run ? $this->buildTable($run, $group) : ['columns' => [], 'rows' => [], 'totals' => []];

        return Inertia::render('Admin/Payroll/Reports/Summary', [
            'group' => $group,
            'run' => $run ? [
                'id' => $run->id,
                'period_key' => $run->period_key,
                'business_location' => $run->businessLocation?->name,
                'payment_date' => $run->payment_date?->toDateString(),
            ] : null,
            'options' => [
                'runs' => $this->runOptions(),
                'businessLocations' => BusinessLocation::orderBy('sort_order')->get(['id', 'name']),
            ],
            'table' => $data,
            'patterns' => $this->patternList(),
            'columnGroups' => [
                'earning' => '支給項目',
                'deduction' => '控除項目',
                'total' => '差引合計項目',
            ],
        ]);
    }

    /** 表示パターン一覧（列カスタマイズ）。 */
    private function patternList(): array
    {
        return ReportViewPattern::where('report_key', self::REPORT_KEY)
            ->orderBy('name')
            ->get(['id', 'name', 'hidden_columns'])
            ->map(fn (ReportViewPattern $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'hidden_columns' => $p->hidden_columns ?? [],
            ])
            ->all();
    }

    public function storePattern(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'hidden_columns' => ['array'],
            'hidden_columns.*' => ['string', 'max:80'],
        ]);

        ReportViewPattern::create([
            'report_key' => self::REPORT_KEY,
            'name' => $validated['name'],
            'hidden_columns' => array_values($validated['hidden_columns'] ?? []),
            'created_by' => Auth::guard('admin')->id(),
        ]);

        return back()->with('success', '表示パターンを保存しました。');
    }

    public function destroyPattern(ReportViewPattern $pattern)
    {
        abort_unless($pattern->report_key === self::REPORT_KEY, 404);
        $pattern->delete();

        return back()->with('success', '表示パターンを削除しました。');
    }

    public function csv(Request $request)
    {
        $group = $request->query('group') === 'department' ? 'department' : 'none';
        $run = $this->resolveRun($request);
        abort_unless($run, 404);

        $data = $this->buildTable($run, $group);

        // 表示パターンで非表示指定された列を除外
        $hidden = array_flip((array) $request->query('hidden', []));
        $columns = array_values(array_filter($data['columns'], fn ($c) => ! isset($hidden[$c['key']])));

        $header = array_merge(['従業員番号', '従業員'], array_map(fn ($c) => $c['label'], $columns));
        $lines = [$this->csvRow($header)];

        foreach ($data['rows'] as $row) {
            if (($row['is_subtotal'] ?? false)) {
                $cells = array_merge(['', '【' . $row['name'] . '】'], array_map(fn ($c) => $row['values'][$c['key']] ?? 0, $columns));
            } else {
                $cells = array_merge([$row['employee_no'] ?? '', $row['name']], array_map(fn ($c) => $row['values'][$c['key']] ?? 0, $columns));
            }
            $lines[] = $this->csvRow($cells);
        }
        $totalCells = array_merge(['', '合計'], array_map(fn ($c) => $data['totals'][$c['key']] ?? 0, $columns));
        $lines[] = $this->csvRow($totalCells);

        $content = mb_convert_encoding(implode("\r\n", $lines) . "\r\n", 'SJIS-win', 'UTF-8');
        $fileName = 'sikyu_kojo_' . str_replace('-', '_', $run->period_key) . ($group === 'department' ? '_bumon' : '') . '.csv';

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=Shift_JIS',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    private function resolveRun(Request $request): ?PayrollRun
    {
        $query = PayrollRun::where('pay_type', 'salary')->with('businessLocation:id,name');

        if ($request->filled('run')) {
            return (clone $query)->find($request->query('run'));
        }
        if ($request->filled('location')) {
            $query->where('business_location_id', $request->query('location'));
        }

        return $query->orderByDesc('period_key')->first();
    }

    /**
     * @return array{columns: array<int, array{key:string,label:string,group:string,is_active:bool}>, rows: array<int, array<string,mixed>>, totals: array<string,int>}
     */
    private function buildTable(PayrollRun $run, string $group): array
    {
        $payslips = $run->payslips()
            ->with(['user:id,name,department_id', 'user.department:id,name', 'user.employeePayroll:id,user_id,employee_no', 'items'])
            ->get();

        $payActive = PayItemMaster::pluck('is_active', 'code');
        $deductionActive = DeductionItemMaster::pluck('is_active', 'code');

        // 列（支給→控除）の順序をコード出現順で構築
        $earningCols = [];
        $deductionCols = [];
        foreach ($payslips as $p) {
            foreach ($p->items as $item) {
                if ($item->item_type === 'earning' && ! isset($earningCols[$item->code])) {
                    $earningCols[$item->code] = $item->name;
                } elseif ($item->item_type === 'deduction' && ! isset($deductionCols[$item->code])) {
                    $deductionCols[$item->code] = $item->name;
                }
            }
        }

        $columns = [];
        foreach ($earningCols as $code => $name) {
            $columns[] = [
                'key' => 'e_' . $code,
                'label' => $name,
                'group' => 'earning',
                'is_active' => (bool) ($payActive[$code] ?? true),
            ];
        }
        $columns[] = ['key' => 'total_earnings', 'label' => '支給合計', 'group' => 'total', 'is_active' => true];
        foreach ($deductionCols as $code => $name) {
            $columns[] = [
                'key' => 'd_' . $code,
                'label' => $name,
                'group' => 'deduction',
                'is_active' => (bool) ($deductionActive[$code] ?? true),
            ];
        }
        $columns[] = ['key' => 'total_deductions', 'label' => '控除合計', 'group' => 'total', 'is_active' => true];
        $columns[] = ['key' => 'net_pay', 'label' => '差引支給', 'group' => 'total', 'is_active' => true];

        // 行データ
        $entries = $payslips->map(function (Payslip $p) {
            $values = [];
            foreach ($p->items as $item) {
                if ($item->item_type === 'earning') {
                    $values['e_' . $item->code] = (int) $item->amount;
                } elseif ($item->item_type === 'deduction') {
                    $values['d_' . $item->code] = (int) $item->amount;
                }
            }
            $values['total_earnings'] = (int) $p->total_earnings;
            $values['total_deductions'] = (int) $p->total_deductions;
            $values['net_pay'] = (int) $p->net_pay;

            return [
                'employee_no' => $p->user?->employeePayroll?->employee_no,
                'name' => $p->user?->name ?? '—',
                'department' => $p->user?->department?->name,
                'department_sort' => $p->user?->department?->id ?? PHP_INT_MAX,
                'values' => $values,
            ];
        });

        $totals = [];
        foreach ($columns as $c) {
            $totals[$c['key']] = (int) $entries->sum(fn ($e) => $e['values'][$c['key']] ?? 0);
        }

        if ($group === 'department') {
            $rows = $this->withDepartmentSubtotals($entries, $columns);
        } else {
            $rows = $entries->values()->all();
        }

        return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * 部門でグルーピングし、各部門の直後に小計行を挿入する。
     */
    private function withDepartmentSubtotals($entries, array $columns): array
    {
        $grouped = $entries->sortBy('department_sort')->groupBy(fn ($e) => $e['name'] === null ? '' : ($e['department'] ?? '（部門未設定）'));

        $rows = [];
        foreach ($grouped as $deptName => $members) {
            foreach ($members as $m) {
                $rows[] = $m;
            }
            $subtotal = [];
            foreach ($columns as $c) {
                $subtotal[$c['key']] = (int) $members->sum(fn ($e) => $e['values'][$c['key']] ?? 0);
            }
            $rows[] = ['is_subtotal' => true, 'name' => $deptName ?: '（部門未設定）', 'values' => $subtotal];
        }

        return $rows;
    }

    private function runOptions(): array
    {
        return PayrollRun::where('pay_type', 'salary')
            ->with('businessLocation:id,name')
            ->orderByDesc('period_key')
            ->limit(24)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'label' => $r->period_key . ($r->businessLocation ? ' / ' . $r->businessLocation->name : ''),
            ])
            ->all();
    }

    private function csvRow(array $cells): string
    {
        return implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $cells));
    }
}
