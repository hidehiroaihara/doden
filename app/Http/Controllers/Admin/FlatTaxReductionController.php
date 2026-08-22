<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use App\Models\User;
use App\Services\Payroll\FlatTaxReductionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * 定額減税（所得税）各人別控除事績簿。
 * 従業員ごとの定額減税額（本人＋同一生計配偶者・扶養親族：1人3万円）と、
 * 各月の給与・賞与で控除済みの累計を管理する。
 *
 * 月次の控除実績は payslip_items のうち定額減税に該当する項目（code=flat_tax_reduction 等、
 * または名称に「定額減税」を含む項目）から集計する。未計上の場合は0で表示する。
 *
 * 参照: 資料/設計書 28_定額減税（各人別控除事績簿）
 */
class FlatTaxReductionController extends Controller
{
    public function __construct(private FlatTaxReductionService $flatTax) {}

    public function show(Request $request)
    {
        $year = (int) ($request->query('year') ?: 2024);
        $measure = $this->flatTax->measureForYear($year);
        $rows = $this->rows($year);

        return Inertia::render('Admin/Payroll/Reports/FlatTax', [
            'year' => $year,
            'perPerson' => (int) ($measure?->per_person_amount ?? 0),
            'measureConfigured' => (bool) $measure,
            'rows' => $rows,
            'options' => ['years' => [2024, 2025]],
        ]);
    }

    public function csv(Request $request)
    {
        $year = (int) ($request->query('year') ?: 2024);
        $rows = $this->rows($year);

        $header = ['従業員番号', '従業員', '扶養人数', '減税対象人数', '減税総額', '控除済累計', '控除残額'];
        $lines = [$this->csvRow($header)];
        foreach ($rows as $r) {
            $lines[] = $this->csvRow([
                $r['employee_no'] ?? '', $r['name'], $r['dependents'], $r['target_count'],
                $r['total_reduction'], $r['applied'], $r['remaining'],
            ]);
        }

        $content = mb_convert_encoding(implode("\r\n", $lines) . "\r\n", 'SJIS-win', 'UTF-8');

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=Shift_JIS',
            'Content-Disposition' => 'attachment; filename="flat_tax_reduction_' . $year . '.csv"',
        ]);
    }

    /** @return array<int, array<string,mixed>> */
    private function rows(int $year): array
    {
        $measure = $this->flatTax->measureForYear($year);

        return User::query()
            ->whereHas('employeePayroll')
            ->where('is_active', true)
            ->with('employeePayroll:id,user_id,employee_no,dependents_count,tax_table')
            ->orderBy('name')
            ->get()
            ->map(function (User $u) use ($year, $measure) {
                $ep = $u->employeePayroll;
                // 定額減税は甲欄（居住者）が対象。扶養親族等の数＋本人。給与計算エンジンと同一ロジック。
                $targetCount = $ep ? $this->flatTax->targetCount($ep) : 0;
                $totalReduction = $ep ? $this->flatTax->totalReduction($ep, $measure) : 0;

                $applied = $this->appliedAmount($u->id, $year);

                return [
                    'employee_no' => $ep?->employee_no,
                    'name' => $u->name,
                    'dependents' => (int) ($ep?->dependents_count ?? 0),
                    'target_count' => $targetCount,
                    'total_reduction' => $totalReduction,
                    'applied' => $applied,
                    'remaining' => max(0, $totalReduction - $applied),
                ];
            })
            ->values()
            ->all();
    }

    /** 給与計算結果に定額減税の控除実績が計上されていれば集計する。 */
    private function appliedAmount(int $userId, int $year): int
    {
        return (int) Payslip::query()
            ->where('user_id', $userId)
            ->whereHas('payrollRun', fn ($q) => $q->whereBetween('period_key', ["{$year}-01", "{$year}-12"]))
            ->with('items')
            ->get()
            ->sum(function (Payslip $p) {
                return $p->items
                    ->filter(fn ($i) => $i->code === 'flat_tax_reduction' || str_contains((string) $i->name, '定額減税'))
                    ->sum(fn ($i) => abs((int) $i->amount));
            });
    }

    private function csvRow(array $cells): string
    {
        return implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $cells));
    }
}
