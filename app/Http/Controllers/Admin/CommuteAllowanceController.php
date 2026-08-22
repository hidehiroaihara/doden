<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * 通勤手当一覧（交通用具）。
 * 従業員ごとの通勤手当（課税／非課税・月額／年額）を一覧表示し、CSV出力する。
 * 従業員給与情報の通勤手当設定を集計する。
 *
 * 参照: 資料/設計書 27_通勤手当一覧
 */
class CommuteAllowanceController extends Controller
{
    public function show(Request $request)
    {
        $locationId = $request->query('location');
        $rows = $this->rows($locationId);

        return Inertia::render('Admin/Payroll/Reports/Commute', [
            'year' => (int) now()->format('Y'),
            'rows' => $rows,
            'options' => [
                'businessLocations' => BusinessLocation::orderBy('sort_order')->get(['id', 'name']),
            ],
        ]);
    }

    public function csv(Request $request)
    {
        $rows = $this->rows($request->query('location'));

        $header = ['従業員番号', '従業員', '事業所', '通勤手当(課税・月額)', '通勤手当(非課税・月額)', '月額計', '年額計'];
        $lines = [$this->csvRow($header)];
        foreach ($rows as $r) {
            $lines[] = $this->csvRow([
                $r['employee_no'] ?? '', $r['name'], $r['business_location'] ?? '',
                $r['taxable'], $r['non_taxable'], $r['monthly_total'], $r['annual_total'],
            ]);
        }

        $content = mb_convert_encoding(implode("\r\n", $lines) . "\r\n", 'SJIS-win', 'UTF-8');

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=Shift_JIS',
            'Content-Disposition' => 'attachment; filename="commute_allowance_' . now()->format('Y') . '.csv"',
        ]);
    }

    /** @return array<int, array<string,mixed>> */
    private function rows($locationId): array
    {
        return User::query()
            ->whereHas('employeePayroll', function ($q) use ($locationId) {
                if ($locationId) {
                    $q->where('business_location_id', $locationId);
                }
            })
            ->where('is_active', true)
            ->with(['employeePayroll.businessLocation:id,name'])
            ->orderBy('name')
            ->get()
            ->map(function (User $u) {
                $ep = $u->employeePayroll;
                $taxable = (int) ($ep?->commute_allowance_taxable ?? 0);
                $nonTaxable = (int) ($ep?->commute_allowance_non_taxable ?? 0);
                $monthly = $taxable + $nonTaxable;

                return [
                    'employee_no' => $ep?->employee_no,
                    'name' => $u->name,
                    'business_location' => $ep?->businessLocation?->name,
                    'taxable' => $taxable,
                    'non_taxable' => $nonTaxable,
                    'monthly_total' => $monthly,
                    'annual_total' => $monthly * 12,
                ];
            })
            ->filter(fn ($r) => $r['monthly_total'] > 0)
            ->values()
            ->all();
    }

    private function csvRow(array $cells): string
    {
        return implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $cells));
    }
}
