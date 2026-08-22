<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\User;
use App\Services\Payroll\Reports\WageLedgerService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * 賃金台帳（法定帳簿）。
 * 従業員別・月別（1〜12月度）の勤怠・支給・控除・差引をマトリクスで表示・PDF出力する。
 * 給与計算確定データ（payslips）を月次に集計・転記して生成する。
 *
 * 参照: 資料/設計書 26_賃金台帳
 */
class WageLedgerController extends Controller
{
    public function __construct(private WageLedgerService $ledger) {}

    public function show(Request $request)
    {
        $year = (int) ($request->query('year') ?: now()->format('Y'));
        $locationId = $request->query('location');

        $employees = $this->ledger->employeeList($locationId);
        $userId = $request->query('user') ?: ($employees->first()['id'] ?? null);

        $matrix = $userId ? $this->ledger->build((int) $userId, $year, $locationId) : null;

        return Inertia::render('Admin/Payroll/Reports/WageLedger', [
            'year' => $year,
            'selectedUserId' => $userId ? (int) $userId : null,
            'employees' => $employees,
            'matrix' => $matrix,
            'options' => [
                'years' => range((int) now()->format('Y'), (int) now()->format('Y') - 5),
                'businessLocations' => BusinessLocation::orderBy('sort_order')->get(['id', 'name']),
            ],
        ]);
    }

    public function pdf(Request $request, User $user)
    {
        $year = (int) ($request->query('year') ?: now()->format('Y'));
        $matrix = $this->ledger->build($user->id, $year, $request->query('location'));

        $pdf = Pdf::loadView('payslips.wage_ledger', [
            'year' => $year,
            'userName' => $user->name,
            'matrix' => $matrix,
        ])->setPaper('a4', 'landscape');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="wage_ledger_' . $user->id . '_' . $year . '.pdf"',
        ]);
    }
}
