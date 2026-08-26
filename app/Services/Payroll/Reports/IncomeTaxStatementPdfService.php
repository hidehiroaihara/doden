<?php

namespace App\Services\Payroll\Reports;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * 画面と同一 HTML/CSS を DomPDF で PDF 化する。
 */
class IncomeTaxStatementPdfService
{
    /** 画面キャリブレーション幅（max-w-182 = 728px） */
    private const PAGE_WIDTH_PT = 728;

    private const PAGE_HEIGHT_PT = 380;

    public function __construct(
        private IncomeTaxStatementOverlay $overlay,
    ) {}

    /**
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $report
     */
    public function generateWithOverlay(
        array $overlay,
        string $backgroundPath,
        string $periodLabel,
        string $modeLabel,
    ): string {
        $pdf = Pdf::loadView('payslips.income_tax_statement_print', [
            'overlay' => $overlay,
            'backgroundSrc' => $backgroundPath,
            'periodLabel' => $periodLabel,
            'modeLabel' => $modeLabel,
            'preview' => false,
            'viewMode' => 'pdf-view',
            'layoutCss' => resource_path('css/income-tax-statement-form-pdf.css'),
        ])
            ->setOption('isRemoteEnabled', true)
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('dpi', 72)
            ->setPaper([0, 0, self::PAGE_WIDTH_PT, self::PAGE_HEIGHT_PT]);

        return $pdf->output();
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $report
     */
    public function generate(
        array $form,
        array $report,
        int $year,
        string $backgroundPath,
        string $periodLabel,
        string $modeLabel,
    ): string {
        return $this->generateWithOverlay(
            $this->overlay->build($form, $report, $year),
            $backgroundPath,
            $periodLabel,
            $modeLabel,
        );
    }
}
