<?php

namespace App\Jobs;

use App\Models\Payslip;
use App\Models\PayslipExport;
use App\Services\Payroll\PayslipPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * 指定期間(period_from〜period_to)の給与明細を
 * 「従業員/月/PDF」階層でZIP化する非同期ジョブ。
 *
 * 参照: 資料/設計書 19_給与明細（ZIP一括出力）
 */
class GeneratePayslipArchive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(public int $exportId) {}

    public function handle(PayslipPdfService $pdfService): void
    {
        $export = PayslipExport::find($this->exportId);
        if (! $export) {
            return;
        }

        $export->update(['status' => 'processing']);

        try {
            $payslips = $this->targetPayslips($export);
            $export->update(['total_count' => $payslips->count()]);

            if ($payslips->isEmpty()) {
                $export->update([
                    'status' => 'failed',
                    'error_message' => '対象期間に給与明細がありません。',
                ]);

                return;
            }

            $relativeZip = 'payslip_archives/' . Str::uuid()->toString() . '.zip';
            $absoluteZip = Storage::disk('local')->path($relativeZip);
            Storage::disk('local')->makeDirectory('payslip_archives');

            $zip = new ZipArchive();
            if ($zip->open($absoluteZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('ZIPファイルを作成できませんでした。');
            }

            $processed = 0;
            foreach ($payslips as $payslip) {
                $employeeDir = $this->employeeDirName($payslip);
                $period = $payslip->payrollRun?->period_key ?? 'unknown';
                $pdfName = "給与明細_{$period}.pdf";

                $zip->addFromString(
                    "{$employeeDir}/{$period}/{$pdfName}",
                    $pdfService->render($payslip),
                );

                $processed++;
                if ($processed % 5 === 0) {
                    $export->update(['processed_count' => $processed]);
                }
            }

            $zip->close();

            $fileName = sprintf(
                '給与明細_%s_%s〜%s.zip',
                $export->businessLocation?->name ?? '全事業所',
                $export->period_from,
                $export->period_to,
            );

            $export->update([
                'status' => 'completed',
                'processed_count' => $processed,
                'file_path' => $relativeZip,
                'file_name' => $fileName,
                'file_size' => Storage::disk('local')->size($relativeZip),
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $export->update([
                'status' => 'failed',
                'error_message' => Str::limit($e->getMessage(), 500),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        PayslipExport::where('id', $this->exportId)
            ->where('status', '!=', 'completed')
            ->update([
                'status' => 'failed',
                'error_message' => Str::limit($e->getMessage(), 500),
            ]);
    }

    /**
     * 対象明細（期間内・事業所一致・確定バッチ優先で全明細）を取得。
     */
    private function targetPayslips(PayslipExport $export)
    {
        return Payslip::query()
            ->with(['user:id,name', 'user.employeePayroll:id,user_id,employee_no', 'items', 'payrollRun.businessLocation:id,name'])
            ->whereHas('payrollRun', function ($q) use ($export) {
                $q->whereBetween('period_key', [$export->period_from, $export->period_to]);
                if ($export->business_location_id) {
                    $q->where('business_location_id', $export->business_location_id);
                }
            })
            ->join('users', 'users.id', '=', 'payslips.user_id')
            ->leftJoin('employee_payrolls', 'employee_payrolls.user_id', '=', 'payslips.user_id')
            ->orderByRaw('employee_payrolls.employee_no IS NULL, LENGTH(employee_payrolls.employee_no), employee_payrolls.employee_no ASC')
            ->select('payslips.*')
            ->get();
    }

    private function employeeDirName(Payslip $payslip): string
    {
        $name = $payslip->user?->name ?? ('user_' . $payslip->user_id);
        $employeeNo = $payslip->user?->employeePayroll?->employee_no;
        $label = $employeeNo ? "{$name}_{$employeeNo}" : $name;

        // ZIP内パスに使えない文字を除去
        return preg_replace('/[\/\\\\:*?"<>|]/', '_', $label);
    }
}
