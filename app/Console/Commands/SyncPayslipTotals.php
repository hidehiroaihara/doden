<?php

namespace App\Console\Commands;

use App\Models\Payslip;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 明細行の合計と、明細に保存された支給合計・控除合計・差引支給額がずれているものを検出・修復する。
 * （再計算時に手入力の控除が合計へ反映されていなかった不具合で生じたずれの修復用）
 * 金額の再計算は行わず、画面の表に出ている明細行の合計をそのまま採用する。
 */
class SyncPayslipTotals extends Command
{
    protected $signature = 'payroll:sync-payslip-totals
        {--run= : 対象の給与バッチID（省略時は全バッチ）}
        {--include-finalized : 確定済みバッチも対象にする}
        {--apply : 実際に修復する（省略時は一覧表示のみ）}';

    protected $description = '明細行と合計（差引支給額など）がずれている給与明細を検出・修復する';

    public function handle(): int
    {
        $query = Payslip::query()->with(['payrollRun', 'user'])->orderBy('payroll_run_id')->orderBy('id');
        if ($runId = $this->option('run')) {
            $query->where('payroll_run_id', $runId);
        }
        if (! $this->option('include-finalized')) {
            $query->whereHas('payrollRun', fn ($q) => $q->where('status', '!=', 'finalized'));
        }

        $rows = [];
        $mismatched = [];
        foreach ($query->cursor() as $payslip) {
            $totals = $payslip->items()->reorder()
                ->whereIn('item_type', ['earning', 'deduction'])
                ->selectRaw('item_type, COALESCE(SUM(amount),0) as total')
                ->groupBy('item_type')
                ->pluck('total', 'item_type');
            $earnings = (int) ($totals['earning'] ?? 0);
            $deductions = (int) ($totals['deduction'] ?? 0);
            $net = $earnings - $deductions;

            if ($earnings === (int) $payslip->total_earnings
                && $deductions === (int) $payslip->total_deductions
                && $net === (int) $payslip->net_pay) {
                continue;
            }

            $mismatched[] = $payslip;
            $rows[] = [
                $payslip->payroll_run_id,
                $payslip->payrollRun?->period_key,
                $payslip->user?->name,
                number_format((int) $payslip->net_pay),
                number_format($net),
                $payslip->payrollRun?->status,
            ];
        }

        if ($mismatched === []) {
            $this->info('ずれている明細はありません。');

            return self::SUCCESS;
        }

        $this->table(['バッチID', '期間', '氏名', '現在の差引', '明細行の差引', '状態'], $rows);

        if (! $this->option('apply')) {
            $this->warn(count($mismatched) . ' 件のずれがあります。修復するには --apply を付けて実行してください。');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($mismatched) {
            foreach ($mismatched as $payslip) {
                $payslip->syncTotalsFromItems();
            }
        });

        $this->info(count($mismatched) . ' 件を修復しました。');

        return self::SUCCESS;
    }
}
