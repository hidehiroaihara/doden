<?php

namespace App\Services\Payroll\Reports;

use setasign\Fpdi\Tcpdf\Fpdi;
use TCPDF_FONTS;

/**
 * FPDI + TCPDF 用の最小 PDF ドキュメント。
 */
class IncomeTaxStatementPdf extends Fpdi
{
    public function Header(): void {}

    public function Footer(): void {}
}
