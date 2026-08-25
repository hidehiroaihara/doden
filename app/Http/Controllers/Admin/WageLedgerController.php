<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\User;
use App\Services\Payroll\Reports\WageLedgerBulkExportService;
use App\Services\Payroll\Reports\WageLedgerCsvExporter;
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
    public function __construct(
        private WageLedgerService $ledger,
        private WageLedgerCsvExporter $csvExporter,
    ) {}

    public function show(Request $request)
    {
        $locationId = $request->query('location') ?: null;
        $period = $this->ledger->resolvePeriod($this->periodInputFromRequest($request));

        $employees = $this->ledger->employeeList($locationId);
        $userId = $request->query('user') ?: ($employees->first()['id'] ?? null);

        $matrix = $userId ? $this->ledger->build((int) $userId, $period, $locationId) : null;

        return Inertia::render('Admin/Payroll/Reports/WageLedger', [
            'period' => [
                'mode' => $period['mode'],
                'label' => $period['label'],
                'year' => $period['year'],
                'fiscal_year' => $period['fiscal_year'],
                'from' => $period['from'],
                'to' => $period['to'],
            ],
            'year' => $period['year'],
            'selectedUserId' => $userId ? (int) $userId : null,
            'selectedLocationId' => $locationId ? (int) $locationId : null,
            'employees' => $employees,
            'matrix' => $matrix,
            'displayItemCatalog' => $this->ledger->displayItemCatalog(),
            'options' => [
                'years' => range((int) now()->format('Y'), (int) now()->format('Y') - 5),
                'businessLocations' => BusinessLocation::orderBy('sort_order')->get(['id', 'name']),
            ],
        ]);
    }

    public function csv(Request $request, User $user)
    {
        $locationId = $request->query('location') ?: null;
        $period = $this->ledger->resolvePeriod($this->periodInputFromRequest($request));
        $matrix = $this->ledger->build($user->id, $period, $locationId ? (int) $locationId : null);

        $content = $this->csvExporter->encode($this->csvExporter->employeeBlockLines($matrix));
        $fileName = sprintf('賃金台帳_%s_%d.csv', $user->name, $matrix['year']);

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=Shift_JIS',
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName),
        ]);
    }

    public function bulkCsv(Request $request, WageLedgerBulkExportService $bulk)
    {
        $locationId = $request->query('location') ? (int) $request->query('location') : null;
        $periodInput = $this->periodInputFromRequest($request);
        $userIds = $this->selectedUserIds($request);
        $content = $bulk->buildCsv($locationId, $periodInput, $userIds);
        $fileName = $bulk->csvFileName($locationId, $periodInput);

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=Shift_JIS',
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName),
        ]);
    }

    public function bulkPdf(Request $request, WageLedgerBulkExportService $bulk)
    {
        $locationId = $request->query('location') ? (int) $request->query('location') : null;
        $periodInput = $this->periodInputFromRequest($request);
        $userIds = $this->selectedUserIds($request);
        [$binary, $fileName] = $bulk->buildPdfZip($locationId, $periodInput, $userIds);

        return response($binary, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName),
        ]);
    }

    /**
     * 一括出力の対象従業員ID（users[] または users=1,2,3）。未指定なら空配列＝全員。
     *
     * @return array<int, int>
     */
    private function selectedUserIds(Request $request): array
    {
        $users = $request->query('users');
        if ($users === null || $users === '') {
            return [];
        }
        if (is_string($users)) {
            $users = explode(',', $users);
        }

        return collect((array) $users)
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function periodInputFromRequest(Request $request): array
    {
        return [
            'period_mode' => $request->query('period_mode', 'calendar'),
            'year' => $request->query('year'),
            'fiscal_year' => $request->query('fiscal_year'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ];
    }

    public function pdf(Request $request, User $user)
    {
        $period = $this->ledger->resolvePeriod($this->periodInputFromRequest($request));
        $matrix = $this->ledger->build($user->id, $period, $request->query('location') ?: null);

        $pdf = Pdf::loadView('payslips.wage_ledger', [
            'periodLabel' => $period['label'],
            'userName' => $user->name,
            'matrix' => $matrix,
        ])->setPaper('a4', 'landscape');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="wage_ledger_' . $user->id . '_' . $matrix['year'] . '.pdf"',
        ]);
    }
}
