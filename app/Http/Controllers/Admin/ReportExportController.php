<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportArchive;
use App\Models\BusinessLocation;
use App\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * 帳票の一括出力（源泉徴収簿PDF一括 / 賃金台帳CSV一括）。
 * 年・事業所を指定して非同期ジョブを起動し、進捗をポーリング、完了後にファイルをダウンロードする。
 *
 * 参照: 資料/設計書 26_賃金台帳 / 30_源泉徴収簿
 */
class ReportExportController extends Controller
{
    /** report_type => format の対応（対応外の種別は弾く） */
    private const FORMATS = [
        'withholding_book' => 'pdf_zip',
        'wage_ledger' => 'csv',
        'roster' => 'pdf_zip',
        'tax_slip' => 'pdf_zip',
    ];

    public function index()
    {
        return Inertia::render('Admin/Payroll/Reports/BulkExports', [
            'exports' => $this->exportList(),
            'options' => [
                'businessLocations' => BusinessLocation::orderBy('sort_order')->get(['id', 'name']),
                'currentYear' => (int) now()->format('Y'),
                'reportTypes' => [
                    ['value' => 'withholding_book', 'label' => '源泉徴収簿（PDF一括作成）'],
                    ['value' => 'wage_ledger', 'label' => '賃金台帳（CSV一括作成）'],
                    ['value' => 'roster', 'label' => '労働者名簿（PDF一括作成）'],
                    ['value' => 'tax_slip', 'label' => '退職者の源泉徴収票（PDF一括作成）'],
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'report_type' => ['required', 'in:withholding_book,wage_ledger,roster,tax_slip'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'business_location_id' => ['nullable', 'exists:business_locations,id'],
        ]);

        $export = ReportExport::create([
            'report_type' => $validated['report_type'],
            'format' => self::FORMATS[$validated['report_type']],
            'year' => $validated['year'],
            'business_location_id' => $validated['business_location_id'] ?? null,
            'status' => 'queued',
            'requested_by' => Auth::guard('admin')->id(),
        ]);

        GenerateReportArchive::dispatch($export->id);

        return back()->with('success', '一括作成を開始しました。完了までしばらくお待ちください。');
    }

    /** ポーリング用: 出力ジョブの最新状態をJSONで返す。 */
    public function status()
    {
        return response()->json(['exports' => $this->exportList()]);
    }

    public function download(ReportExport $export)
    {
        abort_unless($export->status === 'completed' && $export->file_path, 404);
        abort_unless(Storage::disk('local')->exists($export->file_path), 404);

        return Storage::disk('local')->download($export->file_path, $export->file_name);
    }

    public function destroy(ReportExport $export)
    {
        if ($export->file_path && Storage::disk('local')->exists($export->file_path)) {
            Storage::disk('local')->delete($export->file_path);
        }
        $export->delete();

        return back()->with('success', '出力履歴を削除しました。');
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function exportList()
    {
        return ReportExport::with('businessLocation:id,name')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (ReportExport $e) => [
                'id' => $e->id,
                'report_type' => $e->report_type,
                'type_label' => $e->typeLabel(),
                'format' => $e->format,
                'year' => $e->year,
                'business_location' => $e->businessLocation?->name,
                'status' => $e->status,
                'progress' => $e->progressPercent(),
                'total_count' => $e->total_count,
                'processed_count' => $e->processed_count,
                'file_name' => $e->file_name,
                'file_size' => $e->file_size,
                'error_message' => $e->error_message,
                'created_at' => $e->created_at?->format('Y-m-d H:i'),
                'completed_at' => $e->completed_at?->format('Y-m-d H:i'),
            ]);
    }
}
