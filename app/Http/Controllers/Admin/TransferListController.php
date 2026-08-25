<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Services\Payroll\ZenginFormatService;
use Barryvdh\DomPDF\Facade\Pdf;
use Inertia\Inertia;

/**
 * 給与振込一覧表（支払業務）。
 * 指定バッチの全従業員の振込先・振込額を一覧表示し、PDF／全銀FBデータを出力する。
 *
 * 参照: 資料/設計書 20_給与振込一覧表
 */
class TransferListController extends Controller
{
    public function __construct(private ZenginFormatService $zengin) {}

    public function show(PayrollRun $run)
    {
        $run->load('businessLocation:id,name');

        return Inertia::render('Admin/Payroll/Transfers/Show', [
            'run' => [
                'id' => $run->id,
                'period_key' => $run->period_key,
                'pay_type' => $run->pay_type,
                'business_location' => $run->businessLocation?->name,
                'payment_date' => $run->payment_date?->toDateString(),
                'status' => $run->status,
            ],
            'rows' => $this->rows($run),
        ]);
    }

    public function pdf(PayrollRun $run)
    {
        $run->load('businessLocation:id,name');
        $rows = $this->rows($run);

        $pdf = Pdf::loadView('payslips.transfer_list', [
            'period' => $run->period_key,
            'paymentDate' => $run->payment_date?->toDateString(),
            'businessLocation' => $run->businessLocation?->name,
            'rows' => $rows,
            'total' => array_sum(array_map(fn ($r) => $r['amount'], $rows)),
            'count' => count($rows),
        ])->setPaper('a4', 'landscape');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="transfer_list_' . $run->period_key . '.pdf"',
        ]);
    }

    public function fbData(PayrollRun $run)
    {
        $content = $this->zengin->build($run);
        $fileName = 'kyuyo_fb_data_' . str_replace('-', '_', $run->period_key) . '.txt';

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=Shift_JIS',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(PayrollRun $run): array
    {
        return $run->payslips()
            ->with(['user:id,name', 'user.employeePayroll'])
            ->orderByEmployeeNo()
            ->get()
            ->map(function (Payslip $p) {
                $ep = $p->user?->employeePayroll;
                $hasAccount = $ep && filled($ep->bank_code) && filled($ep->branch_code) && filled($ep->account_number);

                return [
                    'user_name' => $p->user?->name,
                    'bank_name' => $ep?->bank_name,
                    'branch_name' => $ep?->branch_name,
                    'account_type' => $this->accountTypeLabel($ep?->account_type),
                    'account_number' => $ep?->account_number,
                    'account_holder_kana' => $ep?->account_holder_kana,
                    'amount' => (int) $p->net_pay,
                    'remark' => $hasAccount ? null : '口座未登録',
                ];
            })
            ->values()
            ->all();
    }

    private function accountTypeLabel(?string $type): string
    {
        return match ($type) {
            'checking' => '当座',
            'savings' => '貯蓄',
            default => '普通',
        };
    }
}
