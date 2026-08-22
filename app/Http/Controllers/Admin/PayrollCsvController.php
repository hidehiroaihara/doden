<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use App\Models\PayrollRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 給与計算画面の「支給/控除/勤怠 CSVダウンロード・インポート」（MFメニュー準拠）。
 *
 * - ダウンロード: 従業員×項目のワイド形式CSV（type=earning/deduction/attendance）。
 * - インポート  : 支給・控除のみ対応（勤怠は自動計算のため読み取り専用）。
 *   従業員番号＋項目名で突合し、金額を手入力上書き(is_manual_override)として反映。
 */
class PayrollCsvController extends Controller
{
    private const TYPE_LABELS = [
        'earning' => '支給',
        'deduction' => '控除',
        'attendance' => '勤怠',
    ];

    public function download(Request $request, PayrollRun $run): StreamedResponse
    {
        $type = $request->query('type', 'earning');
        abort_unless(array_key_exists($type, self::TYPE_LABELS), 404);

        $payslips = $this->loadPayslips($run);
        $columns = $this->itemColumns($payslips, $type);

        $filename = sprintf('payroll_%s_%s.csv', $run->period_key, $type);

        return response()->streamDownload(function () use ($payslips, $columns, $type) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM（Excel対応）

            fputcsv($out, array_merge(['従業員番号', '氏名'], $columns));

            foreach ($payslips as $p) {
                $items = $p->items->where('item_type', $type)->keyBy('name');
                $row = [
                    $p->user?->employeePayroll?->employee_no ?? '',
                    $p->user?->name ?? '',
                ];
                foreach ($columns as $name) {
                    $item = $items->get($name);
                    $row[] = $item ? $this->cellValue($item, $type) : '';
                }
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(Request $request, PayrollRun $run)
    {
        if ($run->isFinalized()) {
            return back()->with('info', '確定済みのため取り込みできません。');
        }

        $validated = $request->validate([
            'type' => ['required', 'in:earning,deduction'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $type = $validated['type'];
        $rows = $this->parseCsv($request->file('file')->getRealPath());
        if (count($rows) < 2) {
            return back()->with('info', 'CSVにデータ行がありません。');
        }

        $header = array_map(fn ($h) => trim($h), array_shift($rows));
        $itemNames = array_slice($header, 2); // 先頭2列は 従業員番号・氏名

        $payslips = $this->loadPayslips($run)
            ->keyBy(fn (Payslip $p) => (string) ($p->user?->employeePayroll?->employee_no ?? ''));

        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $itemNames, $payslips, $type, &$updated, &$skipped) {
            foreach ($rows as $row) {
                $employeeNo = trim((string) ($row[0] ?? ''));
                $payslip = $employeeNo !== '' ? $payslips->get($employeeNo) : null;
                if (! $payslip) {
                    $skipped++;
                    continue;
                }

                $itemsByName = $payslip->items->where('item_type', $type)->keyBy('name');
                foreach ($itemNames as $i => $name) {
                    $raw = $row[$i + 2] ?? '';
                    if ($raw === '' || $raw === null) {
                        continue;
                    }
                    $item = $itemsByName->get($name);
                    if (! $item) {
                        continue;
                    }
                    $amount = (int) round((float) str_replace(',', '', (string) $raw));
                    $item->update(['amount' => $amount, 'is_manual_override' => true]);
                }

                $this->recalcTotals($payslip);
                $updated++;
            }
        });

        return back()->with('success', sprintf(
            '%sCSVを取り込みました（更新 %d 名 / スキップ %d 行）。',
            self::TYPE_LABELS[$type],
            $updated,
            $skipped,
        ));
    }

    /** @return \Illuminate\Support\Collection<int, Payslip> */
    private function loadPayslips(PayrollRun $run)
    {
        return $run->payslips()
            ->with([
                'user:id,name',
                'user.employeePayroll:id,user_id,employee_no',
                'items',
            ])
            ->get()
            ->sortBy(fn (Payslip $p) => $p->user?->employeePayroll?->employee_no ?? '')
            ->values();
    }

    /** @return array<int, string> ワイドCSVの項目列（名称の和集合・出現順）。 */
    private function itemColumns($payslips, string $type): array
    {
        $names = [];
        foreach ($payslips as $p) {
            foreach ($p->items->where('item_type', $type)->sortBy('sort_order') as $item) {
                if (! in_array($item->name, $names, true)) {
                    $names[] = $item->name;
                }
            }
        }

        return $names;
    }

    private function cellValue($item, string $type): string
    {
        if ($type === 'attendance') {
            if ($item->minutes !== null) {
                return number_format($item->minutes / 60, 2, '.', '');
            }
            if ($item->quantity !== null) {
                return (string) (float) $item->quantity;
            }

            return '';
        }

        return (string) (int) ($item->amount ?? 0);
    }

    /** @return array<int, array<int, string>> */
    private function parseCsv(string $path): array
    {
        $content = file_get_contents($path);
        // UTF-8 BOM 除去
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $rows = [];
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = $data;
        }
        fclose($handle);

        return $rows;
    }

    private function recalcTotals(Payslip $payslip): void
    {
        $totals = $payslip->items()
            ->reorder()
            ->selectRaw('item_type, COALESCE(SUM(amount),0) as total')
            ->groupBy('item_type')
            ->pluck('total', 'item_type');

        $earnings = (int) ($totals['earning'] ?? 0);
        $deductions = (int) ($totals['deduction'] ?? 0);

        $payslip->update([
            'total_earnings' => $earnings,
            'total_deductions' => $deductions,
            'net_pay' => $earnings - $deductions,
        ]);
    }
}
