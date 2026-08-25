<?php

namespace App\Services\Payroll\Reports;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 源泉徴収簿（左側＝月次実績）の集計ロジック。
 * 画面表示（WithholdingBookController）と一括出力ジョブ（GenerateReportArchive）の双方から利用する。
 *
 * 参照: 資料/設計書 30_源泉徴収簿
 */
class WithholdingBookService
{
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
     * 源泉徴収簿の左側（月次実績）データを構築。
     */
    public function build(int $userId, int $year, $locationId = null): array
    {
        $user = User::with(['employeePayroll.businessLocation:id,name', 'department:id,name'])->find($userId);
        $employee = $user?->employeePayroll;

        $data = $this->reports->employeeYearlyPayslips($userId, $year, $locationId);

        $salaryRows = [];
        $salaryTotals = ['gross' => 0, 'social' => 0, 'after' => 0, 'tax' => 0];
        for ($m = 1; $m <= 12; $m++) {
            $key = sprintf('%d-%02d', $year, $m);
            $p = $data['salary']->get($key);
            $s = $p ? $this->reports->summarize($p) : null;
            $salaryRows[] = [
                'month' => $m,
                'payment_date' => $p?->payrollRun?->payment_date?->format('n/j'),
                'gross' => $s['gross'] ?? 0,
                'social' => $s['social_insurance'] ?? 0,
                'after_social' => $s['after_social'] ?? 0,
                'dependents' => (int) ($employee->dependents_count ?? 0),
                'tax' => $s['income_tax'] ?? 0,
            ];
            if ($s) {
                $salaryTotals['gross'] += $s['gross'];
                $salaryTotals['social'] += $s['social_insurance'];
                $salaryTotals['after'] += $s['after_social'];
                $salaryTotals['tax'] += $s['income_tax'];
            }
        }

        $bonusRows = [];
        $bonusTotals = ['gross' => 0, 'social' => 0, 'after' => 0, 'tax' => 0];
        foreach ($data['bonus'] as $key => $p) {
            $s = $this->reports->summarize($p);
            $bonusRows[] = [
                'period' => $key,
                'payment_date' => $p->payrollRun?->payment_date?->format('n/j'),
                'gross' => $s['gross'],
                'social' => $s['social_insurance'],
                'after_social' => $s['after_social'],
                'tax' => $s['income_tax'],
                'rate' => $s['after_social'] > 0 ? round($s['income_tax'] / $s['after_social'] * 100, 2) : 0,
            ];
            $bonusTotals['gross'] += $s['gross'];
            $bonusTotals['social'] += $s['social_insurance'];
            $bonusTotals['after'] += $s['after_social'];
            $bonusTotals['tax'] += $s['income_tax'];
        }

        return [
            'employee' => [
                'name' => $user?->name,
                'employee_no' => $employee?->employee_no,
                'tax_table' => $employee?->tax_table ?? 'kou',
                'tax_table_label' => ($employee?->tax_table ?? 'kou') === 'otsu' ? '乙欄' : '甲欄',
                'dependents' => (int) ($employee->dependents_count ?? 0),
                'business_location' => $employee?->businessLocation?->name,
                'department' => $user?->department?->name,
                'address' => $user?->address,
            ],
            'salary' => ['rows' => $salaryRows, 'totals' => $salaryTotals],
            'bonus' => ['rows' => $bonusRows, 'totals' => $bonusTotals],
        ];
    }
}
