<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GeneratePayslipArchive;
use App\Models\BusinessLocation;
use App\Models\PayslipExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * 給与明細ZIP一括出力（従業員/月/PDF 階層）。
 * 期間を指定して非同期ジョブを起動し、進捗をポーリング、完了後にZIPをダウンロードする。
 *
 * 参照: 資料/設計書 19_給与明細
 */
class PayslipExportController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Payroll/Exports/Index', [
            'exports' => $this->exportList(),
            'options' => [
                'businessLocations' => BusinessLocation::orderBy('sort_order')->get(['id', 'name']),
                'defaultPeriod' => now()->format('Y-m'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'period_from' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'period_to' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'business_location_id' => ['nullable', 'exists:business_locations,id'],
        ]);

        if ($validated['period_from'] > $validated['period_to']) {
            return back()->withErrors(['period_to' => '終了月は開始月以降にしてください。']);
        }

        $export = PayslipExport::create([
            'period_from' => $validated['period_from'],
            'period_to' => $validated['period_to'],
            'business_location_id' => $validated['business_location_id'] ?? null,
            'status' => 'queued',
            'requested_by' => Auth::guard('admin')->id(),
        ]);

        // 同期実行: キューワーカー常駐なしでもリクエスト内で生成〜完了させる。
        // ジョブ側は例外時に status=failed を記録するため、ここでの例外は握りつぶし画面へ状態を返す。
        try {
            GeneratePayslipArchive::dispatchSync($export->id);
        } catch (\Throwable $e) {
            report($e);
        }

        $export->refresh();

        if ($export->status === 'completed') {
            return back()->with('success', 'ZIP出力が完了しました。出力履歴からダウンロードしてください。');
        }

        return back()->with('error', $export->error_message ?: 'ZIP出力に失敗しました。');
    }

    /** ポーリング用: 出力ジョブの最新状態をJSONで返す。 */
    public function status()
    {
        return response()->json(['exports' => $this->exportList()]);
    }

    public function download(PayslipExport $export)
    {
        abort_unless($export->status === 'completed' && $export->file_path, 404);
        abort_unless(Storage::disk('local')->exists($export->file_path), 404);

        return Storage::disk('local')->download($export->file_path, $export->file_name);
    }

    public function destroy(PayslipExport $export)
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
        return PayslipExport::with('businessLocation:id,name')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (PayslipExport $e) => [
                'id' => $e->id,
                'period_from' => $e->period_from,
                'period_to' => $e->period_to,
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
