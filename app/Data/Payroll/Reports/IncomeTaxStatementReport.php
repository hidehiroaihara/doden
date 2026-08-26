<?php

namespace App\Data\Payroll\Reports;

/**
 * 所得税徴収高計算書の帳票用中間データ（MF 風確認帳票向け）。
 *
 * @see 開発指示書 §18
 */
class IncomeTaxStatementReport
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $data) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
