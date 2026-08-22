<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Models\ResidentTaxMunicipality;
use Barryvdh\DomPDF\Facade\Pdf;
use Inertia\Inertia;

/**
 * 住民税徴収額一覧表（支払業務）。
 * 指定バッチの住民税控除額を納付先市区町村ごとに集計し、PDF／CSV（FB相当）を出力する。
 *
 * 参照: 資料/設計書 14_住民税 / 22_住民税徴収額一覧表
 */
class ResidentTaxReportController extends Controller
{
    public function show(PayrollRun $run)
    {
        $run->load('businessLocation:id,name');
        $groups = $this->aggregate($run);

        return Inertia::render('Admin/Payroll/ResidentTax/Show', [
            'run' => [
                'id' => $run->id,
                'period_key' => $run->period_key,
                'business_location' => $run->businessLocation?->name,
                'payment_date' => $run->payment_date?->toDateString(),
            ],
            'groups' => $groups,
            'total' => array_sum(array_map(fn ($g) => $g['amount'], $groups)),
        ]);
    }

    public function pdf(PayrollRun $run)
    {
        $run->load('businessLocation:id,name');
        $groups = $this->aggregate($run);

        $pdf = Pdf::loadView('payslips.resident_tax_list', [
            'period' => $run->period_key,
            'businessLocation' => $run->businessLocation?->name,
            'groups' => $groups,
            'total' => array_sum(array_map(fn ($g) => $g['amount'], $groups)),
        ])->setPaper('a4', 'portrait');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="resident_tax_' . $run->period_key . '.pdf"',
        ]);
    }

    public function csv(PayrollRun $run)
    {
        $groups = $this->aggregate($run);

        $rows = [];
        $rows[] = ['市区町村', '指定番号', '人数', '納付額'];
        foreach ($groups as $g) {
            $rows[] = [$g['municipality'], $g['designation_number'] ?? '', $g['count'], $g['amount']];
        }

        $csv = '';
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $row)) . "\r\n";
        }
        $content = mb_convert_encoding($csv, 'SJIS-win', 'UTF-8');

        $fileName = 'jyuminzei_' . str_replace('-', '_', $run->period_key) . '.csv';

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=Shift_JIS',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    /**
     * 住民税控除額を市区町村ごとに集計。
     *
     * @return array<int, array{municipality: string, designation_number: ?string, count: int, amount: int}>
     */
    private function aggregate(PayrollRun $run): array
    {
        $designations = ResidentTaxMunicipality::pluck('designation_number', 'name');

        $buckets = [];
        $payslips = $run->payslips()
            ->with(['user.employeePayroll', 'items' => fn ($q) => $q->where('code', 'resident_tax')])
            ->get();

        foreach ($payslips as $p) {
            $amount = (int) $p->items->where('code', 'resident_tax')->sum('amount');
            if ($amount === 0) {
                continue;
            }
            $muni = $p->user?->employeePayroll?->resident_tax_municipality ?: '未設定';

            if (! isset($buckets[$muni])) {
                $buckets[$muni] = ['municipality' => $muni, 'designation_number' => $designations[$muni] ?? null, 'count' => 0, 'amount' => 0];
            }
            $buckets[$muni]['count']++;
            $buckets[$muni]['amount'] += $amount;
        }

        ksort($buckets);

        return array_values($buckets);
    }
}
