<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Models\User;
use App\Services\Payroll\Reports\WageLedgerCsvExporter;
use App\Services\Payroll\Reports\WageLedgerService;
use App\Services\Payroll\Reports\WithholdingBookService;
use App\Services\Payroll\Reports\WithholdingSlipService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * 帳票の一括出力ジョブ。
 * - 源泉徴収簿: 従業員別PDFを「従業員名_番号/源泉徴収簿_年.pdf」階層でZIP化
 * - 賃金台帳: 全従業員を1つのCSV（従業員ごとにブロックを連結）で出力
 * - 労働者名簿: 従業員別PDFをZIP化
 * - 退職者の源泉徴収票: 退職者別PDFをZIP化
 *
 * 参照: 資料/設計書 25/26/29/30
 */
class GenerateReportArchive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    private const EMPLOYMENT_TYPES = [
        'executive' => '役員',
        'employee_executive' => '使用人兼務役員',
        'full_time' => '正社員',
        'contract' => '契約社員',
        'entrusted' => '嘱託',
        'part_time' => 'パート',
        'arbeit' => 'アルバイト',
        'dispatch' => '派遣',
        'other' => 'その他',
    ];

    public function __construct(public int $exportId) {}

    public function handle(
        WithholdingBookService $withholding,
        WageLedgerService $ledger,
        WageLedgerCsvExporter $csvExporter,
        WithholdingSlipService $slip,
    ): void {
        $export = ReportExport::find($this->exportId);
        if (! $export) {
            return;
        }

        $export->update(['status' => 'processing']);

        try {
            match ($export->report_type) {
                'withholding_book' => $this->buildWithholdingZip($export, $withholding),
                'wage_ledger' => $this->buildWageLedgerCsv($export, $ledger, $csvExporter),
                'roster' => $this->buildRosterZip($export),
                'tax_slip' => $this->buildTaxSlipZip($export, $slip),
                default => throw new \RuntimeException("未対応の帳票種別: {$export->report_type}"),
            };
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
        ReportExport::where('id', $this->exportId)
            ->where('status', '!=', 'completed')
            ->update([
                'status' => 'failed',
                'error_message' => Str::limit($e->getMessage(), 500),
            ]);
    }

    private function buildWithholdingZip(ReportExport $export, WithholdingBookService $service): void
    {
        $employees = $service->employeeList($export->business_location_id);

        $this->pdfZip(
            $export,
            $employees,
            fn ($emp) => [
                $this->dirName($emp['name'], $emp['employee_no']),
                "源泉徴収簿_{$export->year}.pdf",
                Pdf::loadView('payslips.withholding_book', [
                    'year' => $export->year,
                    'book' => $service->build((int) $emp['id'], $export->year, $export->business_location_id),
                ])->setPaper('a4', 'portrait')->output(),
            ],
            sprintf('源泉徴収簿_%s_%d.zip', $export->businessLocation?->name ?? '全事業所', $export->year),
        );
    }

    private function buildRosterZip(ReportExport $export): void
    {
        $employees = User::query()
            ->whereHas('employeePayroll', function ($q) use ($export) {
                if ($export->business_location_id) {
                    $q->where('business_location_id', $export->business_location_id);
                }
            })
            ->with('employeePayroll:id,user_id,employee_no,employment_type')
            ->orderByDesc('users.is_active')
            ->orderByEmployeeNo()
            ->get();

        $this->pdfZip(
            $export,
            $employees,
            fn (User $u) => [
                $this->dirName($u->name, $u->employeePayroll?->employee_no),
                '労働者名簿.pdf',
                Pdf::loadView('payslips.worker_roster', [
                    'name' => $u->name,
                    'birthDate' => $u->birth_date?->format('Y年n月j日'),
                    'postalCode' => $u->postal_code,
                    'address' => $u->address,
                    'hireDate' => $u->joined_at?->format('Y年n月j日'),
                    'isActive' => (bool) $u->is_active,
                    'employmentType' => self::EMPLOYMENT_TYPES[$u->employeePayroll?->employment_type] ?? '',
                ])->setPaper('a4', 'portrait')->output(),
            ],
            sprintf('労働者名簿_%s.zip', $export->businessLocation?->name ?? '全事業所'),
        );
    }

    private function buildTaxSlipZip(ReportExport $export, WithholdingSlipService $slip): void
    {
        $employees = User::query()
            ->where('is_active', false)
            ->whereHas('employeePayroll', function ($q) use ($export) {
                if ($export->business_location_id) {
                    $q->where('business_location_id', $export->business_location_id);
                }
            })
            ->with(['employeePayroll.businessLocation:id,name'])
            ->orderByEmployeeNo()
            ->get();

        $this->pdfZip(
            $export,
            $employees,
            fn (User $u) => [
                $this->dirName($u->name, $u->employeePayroll?->employee_no),
                "源泉徴収票_{$export->year}.pdf",
                Pdf::loadView('payslips.withholding_slip', array_merge($slip->build($u, $export->year), ['retiree' => true]))
                    ->setPaper('a4', 'portrait')->output(),
            ],
            sprintf('退職者源泉徴収票_%s_%d.zip', $export->businessLocation?->name ?? '全事業所', $export->year),
        );
    }

    /**
     * 従業員コレクションを1件ずつPDF化してZIPにまとめる共通処理。
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $employees
     * @param  callable  $renderer  各要素を [ディレクトリ名, ファイル名, PDFバイナリ] に変換する
     */
    private function pdfZip(ReportExport $export, $employees, callable $renderer, string $fileName): void
    {
        $export->update(['total_count' => $employees->count()]);

        if ($employees->isEmpty()) {
            $export->update(['status' => 'failed', 'error_message' => '対象従業員がいません。']);

            return;
        }

        $relativeZip = 'report_archives/' . Str::uuid()->toString() . '.zip';
        Storage::disk('local')->makeDirectory('report_archives');
        $absoluteZip = Storage::disk('local')->path($relativeZip);

        $zip = new ZipArchive();
        if ($zip->open($absoluteZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('ZIPファイルを作成できませんでした。');
        }

        $processed = 0;
        foreach ($employees as $emp) {
            [$dir, $name, $binary] = $renderer($emp);
            $zip->addFromString("{$dir}/{$name}", $binary);

            $processed++;
            if ($processed % 5 === 0) {
                $export->update(['processed_count' => $processed]);
            }
        }
        $zip->close();

        $export->update([
            'status' => 'completed',
            'processed_count' => $processed,
            'file_path' => $relativeZip,
            'file_name' => $fileName,
            'file_size' => Storage::disk('local')->size($relativeZip),
            'completed_at' => now(),
        ]);
    }

    private function buildWageLedgerCsv(ReportExport $export, WageLedgerService $service, WageLedgerCsvExporter $csvExporter): void
    {
        $employees = $service->employeeList($export->business_location_id);
        $export->update(['total_count' => $employees->count()]);

        if ($employees->isEmpty()) {
            $export->update(['status' => 'failed', 'error_message' => '対象従業員がいません。']);

            return;
        }

        $period = $service->resolvePeriod(['period_mode' => 'calendar', 'year' => $export->year]);
        $lines = [];
        $processed = 0;
        foreach ($employees as $emp) {
            $matrix = $service->build((int) $emp['id'], $period, $export->business_location_id);
            array_push($lines, ...$csvExporter->employeeBlockLines($matrix));
            $lines[] = '';

            $processed++;
            if ($processed % 10 === 0) {
                $export->update(['processed_count' => $processed]);
            }
        }

        $relativeCsv = 'report_archives/'.Str::uuid()->toString().'.csv';
        Storage::disk('local')->makeDirectory('report_archives');
        Storage::disk('local')->put($relativeCsv, $csvExporter->encode($lines));

        $export->update([
            'status' => 'completed',
            'processed_count' => $processed,
            'file_path' => $relativeCsv,
            'file_name' => sprintf('賃金台帳_%s_%d.csv', $export->businessLocation?->name ?? '全事業所', $export->year),
            'file_size' => Storage::disk('local')->size($relativeCsv),
            'completed_at' => now(),
        ]);
    }

    private function dirName(?string $name, ?string $employeeNo): string
    {
        $label = ($name ?? 'unknown') . ($employeeNo ? '_' . $employeeNo : '');

        return preg_replace('/[\/\\\\:*?"<>|]/', '_', $label);
    }
}
