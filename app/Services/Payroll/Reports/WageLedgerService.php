<?php

namespace App\Services\Payroll\Reports;

use App\Models\Payslip;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 賃金台帳の集計ロジック。
 * 画面表示（WageLedgerController）と一括出力ジョブ（GenerateReportArchive）の双方から利用する。
 *
 * 参照: 資料/設計書 26_賃金台帳
 */
class WageLedgerService
{
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
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'employee_no' => $u->employeePayroll?->employee_no,
                'is_active' => (bool) $u->is_active,
            ]);
    }

    /**
     * 従業員1人の年次マトリクス（行=項目、列=1〜12月度＋合計）。
     *
     * @return array{months: array<int, string>, sections: array<int, array<string,mixed>>}
     */
    public function build(int $userId, int $year, $locationId = null): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = sprintf('%d-%02d', $year, $m);
        }

        $payslips = Payslip::query()
            ->where('user_id', $userId)
            ->whereHas('payrollRun', function ($q) use ($year, $locationId) {
                $q->where('pay_type', 'salary')->whereBetween('period_key', ["{$year}-01", "{$year}-12"]);
                if ($locationId) {
                    $q->where('business_location_id', $locationId);
                }
            })
            ->with(['items', 'payrollRun:id,period_key'])
            ->get()
            ->keyBy(fn ($p) => $p->payrollRun->period_key);

        $rowDefs = ['attendance' => [], 'earning' => [], 'deduction' => []];
        foreach ($payslips as $p) {
            foreach ($p->items as $item) {
                $type = $item->item_type;
                if (isset($rowDefs[$type]) && ! isset($rowDefs[$type][$item->code])) {
                    $rowDefs[$type][$item->code] = $item->name;
                }
            }
        }

        $sectionTitles = ['attendance' => '勤怠', 'earning' => '支給', 'deduction' => '控除'];
        $sections = [];

        foreach (['attendance', 'earning', 'deduction'] as $type) {
            $rows = [];
            foreach ($rowDefs[$type] as $code => $name) {
                $values = [];
                $total = 0.0;
                foreach ($months as $m => $key) {
                    $item = $payslips->get($key)?->items->firstWhere(fn ($i) => $i->item_type === $type && $i->code === $code);
                    if ($type === 'attendance') {
                        $v = $item ? ($item->minutes !== null ? round($item->minutes / 60, 1) : (float) ($item->quantity ?? 0)) : 0;
                    } else {
                        $v = $item ? (int) $item->amount : 0;
                    }
                    $values[$m] = $v;
                    $total += $v;
                }
                $rows[] = ['name' => $name, 'is_time' => $type === 'attendance', 'values' => $values, 'total' => $type === 'attendance' ? round($total, 1) : (int) $total];
            }
            $sections[] = ['type' => $type, 'title' => $sectionTitles[$type], 'rows' => $rows];
        }

        $summaryRows = [];
        foreach (['total_earnings' => '支給合計', 'total_deductions' => '控除合計', 'net_pay' => '差引支給額'] as $field => $label) {
            $values = [];
            $total = 0;
            foreach ($months as $m => $key) {
                $v = (int) ($payslips->get($key)?->{$field} ?? 0);
                $values[$m] = $v;
                $total += $v;
            }
            $summaryRows[] = ['name' => $label, 'is_time' => false, 'values' => $values, 'total' => $total];
        }
        $sections[] = ['type' => 'summary', 'title' => '差引合計', 'rows' => $summaryRows];

        return ['months' => array_values($months), 'sections' => $sections];
    }
}
