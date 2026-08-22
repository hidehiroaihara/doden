<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Payroll\Reports\WithholdingSlipService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * 退職者の源泉徴収票。
 * 退職した従業員を一覧表示し、対象年の給与所得の源泉徴収票を従業員単位でPDF出力する。
 * 支払金額・源泉徴収税額・社会保険料等の金額を年次集計する。
 *
 * 参照: 資料/設計書 25_退職者の源泉徴収票
 */
class TaxSlipController extends Controller
{
    public function __construct(private WithholdingSlipService $slip) {}

    public function index(Request $request)
    {
        $year = (int) ($request->query('year') ?: now()->format('Y'));

        $rows = User::query()
            ->where('is_active', false)
            ->whereHas('employeePayroll')
            ->with(['employeePayroll.businessLocation:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'employee_no' => $u->employeePayroll?->employee_no,
                'business_location' => $u->employeePayroll?->businessLocation?->name,
            ]);

        return Inertia::render('Admin/Payroll/Reports/TaxSlip', [
            'year' => $year,
            'rows' => $rows,
            'options' => ['years' => range((int) now()->format('Y'), (int) now()->format('Y') - 5)],
        ]);
    }

    public function pdf(Request $request, User $user)
    {
        $year = (int) ($request->query('year') ?: now()->format('Y'));

        $pdf = Pdf::loadView('payslips.withholding_slip', array_merge($this->slip->build($user, $year), ['retiree' => true]))
            ->setPaper('a4', 'portrait');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="tax_slip_' . $user->id . '_' . $year . '.pdf"',
        ]);
    }
}
