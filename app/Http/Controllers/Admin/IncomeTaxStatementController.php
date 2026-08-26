<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\IncomeTaxStatementOverride;
use App\Services\Payroll\Reports\IncomeTaxStatementOverlay;
use App\Services\Payroll\Reports\IncomeTaxStatementPdfService;
use App\Services\Payroll\Reports\IncomeTaxStatementService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * 所得税徴収高計算書（国税庁公式 PDF + FPDI 転記）。
 */
class IncomeTaxStatementController extends Controller
{
    public function __construct(
        private IncomeTaxStatementService $statement,
        private IncomeTaxStatementPdfService $pdfService,
        private IncomeTaxStatementOverlay $overlay,
    ) {}

    public function show(Request $request)
    {
        $payload = $this->buildPayload($request);

        return Inertia::render('Admin/Payroll/Reports/IncomeTaxStatement', [
            ...$payload,
            'options' => [
                'years' => range((int) now()->format('Y'), (int) now()->format('Y') - 5),
                'businessLocations' => BusinessLocation::orderBy('sort_order')->get(['id', 'name']),
            ],
        ]);
    }

    public function preview(Request $request)
    {
        $payload = $this->buildPayload($request);
        $test = $request->boolean('test');
        $print = $this->printViewData($payload, $test);

        return response()->view('payslips.income_tax_statement_print', [
            ...$print,
            'backgroundSrc' => $print['backgroundUrl'],
            'preview' => true,
            'viewMode' => 'browser-print-view',
            'layoutCss' => resource_path('css/income-tax-statement-form-browser-print.css'),
        ]);
    }

    public function pdf(Request $request)
    {
        $payload = $this->buildPayload($request);
        $test = $request->boolean('test');

        return $this->pdfResponse($payload, $test ? 'test' : 'full', $test);
    }

    public function updateOverrides(Request $request)
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'form_type' => ['required', 'in:general,special'],
            'location_id' => ['nullable', 'integer', 'exists:business_locations,id'],
            'data' => ['required', 'array'],
            'data.daily_worker' => ['nullable', 'array'],
            'data.retirement' => ['nullable', 'array'],
            'data.professional_fee' => ['nullable', 'array'],
            'data.year_end_adjustment_shortage' => ['nullable', 'integer', 'min:0'],
            'data.year_end_adjustment_overpayment' => ['nullable', 'integer', 'min:0'],
            'data.late_payment_tax' => ['nullable', 'integer', 'min:0'],
            'data.remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $locationId = $validated['location_id'] ?? null;
        $merged = array_replace_recursive(
            IncomeTaxStatementOverride::defaultData(),
            $validated['data'],
        );

        IncomeTaxStatementOverride::updateOrCreate(
            [
                'year' => $validated['year'],
                'month' => $validated['month'],
                'form_type' => $validated['form_type'],
                'business_location_id' => $locationId,
            ],
            ['data' => $merged],
        );

        return back()->with('success', '手入力内容を保存しました。');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pdfResponse(array $payload, string $filenameSuffix, bool $test = false): Response
    {
        $print = $this->printViewData($payload, $test);

        $binary = $this->pdfService->generateWithOverlay(
            $print['overlay'],
            $print['backgroundPath'],
            $print['periodLabel'],
            $print['modeLabel'],
        );

        $year = (int) ($payload['year'] ?? now()->format('Y'));

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="income_tax_statement_'.$year.'_'.$filenameSuffix.'.pdf"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{overlay: array<string, mixed>, backgroundUrl: string, backgroundPath: string, periodLabel: string, modeLabel: string}
     */
    private function printViewData(array $payload, bool $test = false): array
    {
        $mode = $payload['mode'];
        $template = config('income_tax_statement.image_template');
        $templateKey = $mode === 'special' ? 'special' : 'normal';
        $relative = $template[$templateKey] ?? $template['normal'];
        $backgroundPath = public_path($relative);

        if (! is_readable($backgroundPath)) {
            throw new \RuntimeException("背景画像が見つかりません: {$backgroundPath}");
        }

        $formType = ($payload['form_type'] ?? 'general') === 'special' ? '納期特例分' : '一般分';

        return [
            'overlay' => $test
                ? $this->overlay->buildTest((int) $payload['year'])
                : $this->overlay->build(
                    $payload['form'],
                    $payload['report'],
                    (int) $payload['year'],
                ),
            'backgroundUrl' => asset($relative),
            'backgroundPath' => $backgroundPath,
            'periodLabel' => $test ? (string) $payload['periodLabel'].'（テスト）' : (string) $payload['periodLabel'],
            'modeLabel' => $test ? $formType.'・見た目確認' : $formType,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Request $request): array
    {
        $formType = $request->query('mode') === 'special' ? 'special' : 'general';
        $year = (int) ($request->query('year') ?: now()->format('Y'));
        $month = (int) ($request->query('month') ?: now()->format('n'));
        $locationId = $request->query('location') ? (int) $request->query('location') : null;

        [$periods, $periodLabel, $reportMonth] = $this->targetPeriods($request, $formType, $year);
        $aggregate = $this->statement->aggregate($periods, $locationId);
        $salaryPaymentDate = $this->statement->resolvePaymentDate($periods, 'salary');
        $bonusPaymentDate = $this->statement->resolvePaymentDate($periods, 'bonus');

        $overrideRecord = IncomeTaxStatementOverride::findFor($year, $reportMonth, $formType, $locationId);
        $overrides = IncomeTaxStatementOverride::mergedData($overrideRecord);

        $report = $this->statement->buildReport(
            $aggregate,
            $year,
            $reportMonth,
            $formType,
            $periodLabel,
            $salaryPaymentDate,
            $bonusPaymentDate,
            $overrides,
            $locationId,
        );

        $reportArray = $report->toArray();
        $mode = $formType === 'special' ? 'special' : 'normal';
        $imageTemplate = config('income_tax_statement.image_template');
        $templateKey = $mode === 'special' ? 'special' : 'normal';

        return [
            'mode' => $mode,
            'form_type' => $formType,
            'year' => $year,
            'month' => $month,
            'report_month' => $reportMonth,
            'half' => $request->query('half', 'first'),
            'periodLabel' => $periodLabel,
            'location_id' => $locationId,
            'result' => $aggregate,
            'report' => $reportArray,
            'form' => $this->statement->buildFormFromReport($reportArray, $mode),
            'background_url' => asset($imageTemplate[$templateKey] ?? $imageTemplate['normal']),
            'image_layout' => [
                'width_px' => (int) ($imageTemplate['width_px'] ?? 1024),
                'height_px' => (int) ($imageTemplate['height_px'] ?? 535),
                'fields' => config('income_tax_statement.image_fields'),
                'digit' => config('income_tax_statement.digit'),
            ],
            'overrides' => $overrides,
        ];
    }

    /**
     * @return array{0: array<int, string>, 1: string, 2: int}
     */
    private function targetPeriods(Request $request, string $formType, int $year): array
    {
        if ($formType === 'special') {
            $half = $request->query('half', 'first');
            if ($half === 'second') {
                $months = range(7, 12);
                $label = "{$year}年7月〜12月分";
                $reportMonth = 12;
            } else {
                $months = range(1, 6);
                $label = "{$year}年1月〜6月分";
                $reportMonth = 6;
            }
        } else {
            $month = (int) ($request->query('month') ?: now()->format('n'));
            $months = [$month];
            $label = sprintf('%d年%d月分', $year, $month);
            $reportMonth = $month;
        }

        $periods = array_map(fn ($m) => sprintf('%d-%02d', $year, $m), $months);

        return [$periods, $label, $reportMonth];
    }
}
