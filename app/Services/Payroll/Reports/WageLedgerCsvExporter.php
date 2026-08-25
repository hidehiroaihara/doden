<?php

namespace App\Services\Payroll\Reports;

/**
 * 賃金台帳 CSV（マネーフォワード クラウド給与準拠）。
 *
 * - ヘッダ: 賃金台帳 / 集計期間 / 事業所 / 部門 / 氏名 / 性別
 * - 項目列のみ（区分列なし）。支給・控除項目名に (支給)/(控除)  suffix
 * - 月度ヘッダ: 「N月度」+ 改行 + 期間
 * - 集計行の 0 は「0」、テキスト合計の空は「-」
 */
class WageLedgerCsvExporter
{
    /** @var array<int, string> */
    private const SUMMARY_SECTION_TYPES = [
        'balance_payment',
        'balance_deduction',
        'balances',
        'other_information',
        'group_absorptions',
    ];

    /**
     * 1従業員分の CSV 行（UTF-8）を生成する。
     *
     * @param  array<string, mixed>  $matrix
     * @return array<int, string>
     */
    public function employeeBlockLines(array $matrix): array
    {
        $employee = $matrix['employee'] ?? [];
        $periodLabel = $matrix['period']['label'] ?? sprintf('%d年01月01日 〜 %d年12月31日', $matrix['year'], $matrix['year']);

        $lines = [];
        $lines[] = $this->csvRow([
            '賃金台帳',
            '',
            '集計期間',
            $periodLabel,
            '事業所',
            $employee['business_location'] ?? '',
            '部門',
            $employee['department'] ?? '',
            '氏名',
            $employee['name'] ?? '',
            '性別',
            $employee['gender_label'] ?? '',
        ]);

        $monthHeaders = array_map(
            fn (array $mo) => $mo['label']."\n".$mo['period'],
            $matrix['months'],
        );
        $lines[] = $this->csvRow(array_merge(['項目'], $monthHeaders, ['合計']));

        foreach ($matrix['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                $cells = [$this->itemLabel($section['type'], $row['name'])];
                foreach ($matrix['months'] as $mo) {
                    $cells[] = $this->formatCell(
                        $row['values'][$mo['month']] ?? 0,
                        $row['format'],
                        $section['type'],
                        false,
                    );
                }
                $cells[] = $this->formatCell($row['total'], $row['format'], $section['type'], true);
                $lines[] = $this->csvRow($cells);
            }
        }

        return $lines;
    }

    /**
     * @param  array<int, string>  $lines
     */
    public function encode(array $lines): string
    {
        return mb_convert_encoding(implode("\r\n", $lines)."\r\n", 'SJIS-win', 'UTF-8');
    }

    private function itemLabel(string $sectionType, string $name): string
    {
        return match ($sectionType) {
            'earning' => str_ends_with($name, '(支給)') ? $name : $name.'(支給)',
            'deduction' => str_ends_with($name, '(控除)') ? $name : $name.'(控除)',
            default => $name,
        };
    }

    private function formatCell($value, string $format, string $sectionType, bool $isTotalColumn): string
    {
        if ($format === 'text') {
            $text = is_string($value) ? trim($value) : '';
            if ($text === '') {
                return $isTotalColumn ? '-' : '';
            }

            return $text;
        }

        $n = (float) $value;
        if (! $n) {
            return $this->isSummarySection($sectionType) ? '0' : '';
        }

        return match ($format) {
            'yen' => number_format($n),
            'hours' => number_format($n, 2),
            'days' => number_format($n, 1),
            'count' => (string) (int) $n,
            default => (string) $n,
        };
    }

    private function isSummarySection(string $sectionType): bool
    {
        return in_array($sectionType, self::SUMMARY_SECTION_TYPES, true);
    }

    /** @param array<int, string|int|float|null> $cells */
    private function csvRow(array $cells): string
    {
        return implode(',', array_map(
            fn ($v) => '"'.str_replace('"', '""', (string) $v).'"',
            $cells,
        ));
    }
}
