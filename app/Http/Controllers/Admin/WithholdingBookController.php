<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\User;
use App\Services\Payroll\Reports\WithholdingBookService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * 源泉徴収簿（給与所得・退職所得に対する源泉徴収簿）。
 * 公式仕様に倣い「左側＝月次実績エリア」のみを給与計算確定データから自動反映する。
 * （右側＝申告・年末調整エリアは別途年末調整機能の対象のため本帳票では扱わない）
 *
 * 参照: 資料/設計書 30_源泉徴収簿
 */
class WithholdingBookController extends Controller
{
    public function __construct(private WithholdingBookService $book) {}

    public function show(Request $request)
    {
        $year = (int) ($request->query('year') ?: now()->format('Y'));
        $locationId = $request->query('location');

        $employees = $this->book->employeeList($locationId);
        $userId = $request->query('user') ?: ($employees->first()['id'] ?? null);

        $book = $userId ? $this->book->build((int) $userId, $year, $locationId) : null;

        return Inertia::render('Admin/Payroll/Reports/WithholdingBook', [
            'year' => $year,
            'selectedUserId' => $userId ? (int) $userId : null,
            'employees' => $employees,
            'book' => $book,
            'options' => [
                'years' => range((int) now()->format('Y'), (int) now()->format('Y') - 5),
                'businessLocations' => BusinessLocation::orderBy('sort_order')->get(['id', 'name']),
            ],
        ]);
    }

    public function pdf(Request $request, User $user)
    {
        $year = (int) ($request->query('year') ?: now()->format('Y'));
        $book = $this->book->build($user->id, $year, $request->query('location'));

        $pdf = Pdf::loadView('payslips.withholding_book', [
            'year' => $year,
            'book' => $book,
        ])->setPaper('a4', 'portrait');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="withholding_book_' . $user->id . '_' . $year . '.pdf"',
        ]);
    }
}
