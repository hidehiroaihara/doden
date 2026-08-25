<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Services\Payroll\PayslipPdfService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * 帳票一覧＞給与明細。
 * 支給期間ごとに全従業員の給与明細を一覧し、氏名クリックでプレビュー、
 * 個別PDF・選択分の一括PDF（1ファイル複数ページ）を出力する。
 *
 * ZIP階層一括出力は別画面（admin.payroll.exports）に残す。
 *
 * 参照: 資料/設計書 19_帳票一覧_給与明細
 */
class PayslipReportController extends Controller
{
    public function index(Request $request)
    {
        $runs = PayrollRun::where('pay_type', 'salary')
            ->with('businessLocation:id,name')
            ->orderByDesc('payment_date')
            ->orderByDesc('period_key')
            ->limit(60)
            ->get()
            ->map(fn (PayrollRun $r) => [
                'id' => $r->id,
                'period_key' => $r->period_key,
                'label' => $this->runLabel($r),
                'business_location' => $r->businessLocation?->name,
            ]);

        $selectedRunId = (int) $request->query('run', $runs->first()['id'] ?? 0);
        $run = $selectedRunId ? PayrollRun::with('businessLocation:id,name')->find($selectedRunId) : null;

        $filters = [
            'emp_no' => trim((string) $request->query('emp_no', '')),
            'last_name' => trim((string) $request->query('last_name', '')),
            'first_name' => trim((string) $request->query('first_name', '')),
            'location' => $request->query('location', ''),
            'corrected' => $request->boolean('corrected'),
            'exclude_zero' => $request->boolean('exclude_zero'),
        ];
        $perPage = (int) $request->query('per_page', 25);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;

        $rows = ['data' => [], 'links' => [], 'meta' => null];

        if ($run) {
            $query = Payslip::query()
                ->where('payslips.payroll_run_id', $run->id)
                ->with(['user:id,name', 'user.employeePayroll:id,user_id,employee_no,business_location_id'])
                ->leftJoin('employee_payrolls', 'employee_payrolls.user_id', '=', 'payslips.user_id')
                ->when($filters['emp_no'] !== '', fn ($q) => $q->where('employee_payrolls.employee_no', 'like', "%{$filters['emp_no']}%"))
                ->when($filters['last_name'] !== '', fn ($q) => $q->whereHas('user', fn ($qq) => $qq->where('name', 'like', "%{$filters['last_name']}%")))
                ->when($filters['first_name'] !== '', fn ($q) => $q->whereHas('user', fn ($qq) => $qq->where('name', 'like', "%{$filters['first_name']}%")))
                ->when($filters['location'] !== '' && $filters['location'] !== null, fn ($q) => $q->where('employee_payrolls.business_location_id', $filters['location']))
                ->when($filters['exclude_zero'], fn ($q) => $q->where('payslips.net_pay', '!=', 0))
                ->when($filters['corrected'], fn ($q) => $q->whereHas('items', fn ($qq) => $qq->where('is_manual_override', true)))
                ->orderByRaw('employee_payrolls.employee_no IS NULL, LENGTH(employee_payrolls.employee_no), employee_payrolls.employee_no ASC')
                ->orderBy('payslips.id')
                ->select('payslips.*');

            $paginator = $query->paginate($perPage)->withQueryString();

            $rows = [
                'data' => collect($paginator->items())->map(fn (Payslip $p) => [
                    'id' => $p->id,
                    'employee_no' => $p->user?->employeePayroll?->employee_no,
                    'name' => $p->user?->name,
                    'business_location' => $run->businessLocation?->name,
                    'net_pay' => (int) $p->net_pay,
                    'last_notified_at' => null,
                ])->all(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'total' => $paginator->total(),
                    'per_page' => $perPage,
                ],
            ];
        }

        return Inertia::render('Admin/Payroll/Reports/Payslips', [
            'runs' => $runs,
            'selectedRunId' => $run?->id,
            'filters' => $filters,
            'perPage' => $perPage,
            'rows' => $rows,
            'businessLocations' => BusinessLocation::orderBy('sort_order')->get(['id', 'name']),
        ]);
    }

    /** スライドプレビュー用のJSON（明細ビューデータ）。 */
    public function preview(Payslip $payslip, PayslipPdfService $service)
    {
        return response()->json(['slip' => $service->viewData($payslip)]);
    }

    /** 単票PDF。 */
    public function pdf(Payslip $payslip, PayslipPdfService $service)
    {
        $payslip->loadMissing(['user:id,name', 'payrollRun:id,period_key']);
        $name = $this->pdfFileName($payslip);

        return $this->pdfResponse($service->render($payslip), $name);
    }

    /** 選択した明細を1ファイル（複数ページ）にまとめたPDF。 */
    public function batchPdf(Request $request, PayslipPdfService $service)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'run' => ['nullable', 'integer'],
        ]);

        $payslips = Payslip::query()
            ->whereIn('payslips.id', $validated['ids'])
            ->with(['user:id,name', 'user.employeePayroll:id,user_id,employee_no'])
            ->leftJoin('employee_payrolls', 'employee_payrolls.user_id', '=', 'payslips.user_id')
            ->orderByRaw('employee_payrolls.employee_no IS NULL, LENGTH(employee_payrolls.employee_no), employee_payrolls.employee_no ASC')
            ->orderBy('payslips.id')
            ->select('payslips.*')
            ->get();

        abort_if($payslips->isEmpty(), 404);

        $run = $validated['run'] ? PayrollRun::find($validated['run']) : $payslips->first()->payrollRun;
        $suffix = $run?->payment_date?->format('Y年m月d日支給') ?? ($run?->period_key ?? '');
        $name = trim("給与明細_{$suffix}") . '.pdf';

        return $this->pdfResponse($service->renderBatch($payslips), $name);
    }

    private function runLabel(PayrollRun $run): string
    {
        $pay = $run->payment_date?->format('Y年m月d日') ?? $run->period_key;
        $closing = $run->closing_date?->format('Y年m月d日');

        return $closing ? "{$pay}支給（{$closing}〆）" : "{$pay}支給";
    }

    private function pdfFileName(Payslip $payslip): string
    {
        $name = $payslip->user?->name ?? ('user_' . $payslip->user_id);
        $period = $payslip->payrollRun?->period_key ?? '';
        $base = trim("給与明細_{$name}_{$period}");

        return preg_replace('/[\/\\\\:*?"<>|]/', '_', $base) . '.pdf';
    }

    private function pdfResponse(string $binary, string $jpName)
    {
        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"payslip.pdf\"; filename*=UTF-8''" . rawurlencode($jpName),
        ]);
    }
}
