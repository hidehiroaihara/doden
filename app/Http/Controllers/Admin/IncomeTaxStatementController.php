<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\PayrollRun;
use App\Services\Payroll\Reports\PayrollReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * 所得税徴収高計算書（納付書）。
 * 「俸給・給料等」「賞与（役員賞与を除く）」区分ごとに、対象期間の支給人員・支給額・税額を集計する。
 * 通常版（月次）と納期特例版（半年：1〜6月分／7〜12月分）に対応。
 *
 * 参照: 資料/設計書 23_所得税徴収高計算書 / 24_納期特例
 */
class IncomeTaxStatementController extends Controller
{
    public function __construct(private PayrollReportService $reports) {}

    public function show(Request $request)
    {
        $mode = $request->query('mode') === 'special' ? 'special' : 'normal';
        $year = (int) ($request->query('year') ?: now()->format('Y'));
        $locationId = $request->query('location');

        [$periods, $periodLabel] = $this->targetPeriods($request, $mode, $year);

        $result = $this->aggregate($periods, $locationId);

        return Inertia::render('Admin/Payroll/Reports/IncomeTaxStatement', [
            'mode' => $mode,
            'year' => $year,
            'month' => (int) ($request->query('month') ?: now()->format('n')),
            'half' => $request->query('half', 'first'),
            'periodLabel' => $periodLabel,
            'result' => $result,
            'options' => [
                'years' => range((int) now()->format('Y'), (int) now()->format('Y') - 5),
                'businessLocations' => BusinessLocation::orderBy('sort_order')->get(['id', 'name']),
            ],
        ]);
    }

    public function pdf(Request $request)
    {
        $mode = $request->query('mode') === 'special' ? 'special' : 'normal';
        $year = (int) ($request->query('year') ?: now()->format('Y'));
        [$periods, $periodLabel] = $this->targetPeriods($request, $mode, $year);
        $result = $this->aggregate($periods, $request->query('location'));

        $pdf = Pdf::loadView('payslips.income_tax_statement', [
            'periodLabel' => $periodLabel,
            'mode' => $mode,
            'result' => $result,
        ])->setPaper('a4', 'portrait');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="income_tax_statement_' . $year . '.pdf"',
        ]);
    }

    /**
     * @return array{0: array<int, string>, 1: string}
     */
    private function targetPeriods(Request $request, string $mode, int $year): array
    {
        if ($mode === 'special') {
            $half = $request->query('half', 'first');
            if ($half === 'second') {
                $months = range(7, 12);
                $label = "{$year}年7月〜12月分";
            } else {
                $months = range(1, 6);
                $label = "{$year}年1月〜6月分";
            }
        } else {
            $month = (int) ($request->query('month') ?: now()->format('n'));
            $months = [$month];
            $label = sprintf('%d年%d月分', $year, $month);
        }

        $periods = array_map(fn ($m) => sprintf('%d-%02d', $year, $m), $months);

        return [$periods, $label];
    }

    /**
     * @param  array<int, string>  $periods
     * @return array<string, array{count:int, amount:int, tax:int}>
     */
    private function aggregate(array $periods, $locationId): array
    {
        $runs = PayrollRun::query()
            ->whereIn('period_key', $periods)
            ->when($locationId, fn ($q) => $q->where('business_location_id', $locationId))
            ->with(['payslips.items'])
            ->get();

        $result = [
            'salary' => ['count' => 0, 'amount' => 0, 'tax' => 0],
            'bonus' => ['count' => 0, 'amount' => 0, 'tax' => 0],
        ];

        foreach ($runs as $run) {
            $bucket = $run->pay_type === 'bonus' ? 'bonus' : 'salary';
            foreach ($run->payslips as $p) {
                $s = $this->reports->summarize($p);
                if ($s['gross'] <= 0) {
                    continue;
                }
                $result[$bucket]['count']++;
                $result[$bucket]['amount'] += $s['gross'];
                $result[$bucket]['tax'] += $s['income_tax'];
            }
        }

        $result['total'] = [
            'count' => $result['salary']['count'] + $result['bonus']['count'],
            'amount' => $result['salary']['amount'] + $result['bonus']['amount'],
            'tax' => $result['salary']['tax'] + $result['bonus']['tax'],
        ];

        return $result;
    }
}
