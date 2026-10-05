<?php

namespace App\Services\Payroll\Migration;

use Smalot\PdfParser\Parser;

/**
 * マネーフォワード クラウド給与の「給与明細書（一括PDF）」を1ページ＝1明細として読み取る。
 *
 * 旧システムからの過去分移行（MfPayslipImporter）専用。
 * 「当月支払」「給与関連情報」は参考値として保持するだけで、登録には使わない。
 */
class MfPayslipPdfParser
{
    private const SECTIONS = [
        '支給' => 'earning',
        '控除' => 'deduction',
        '当月支払' => 'payment',
        '給与関連情報' => 'info',
    ];

    private const TOTALS = [
        '支給合計' => 'total_earnings',
        '控除合計' => 'total_deductions',
    ];

    /** @return array<int, array<string, mixed>> */
    public function parseFile(string $path): array
    {
        $pages = (new Parser())->parseFile($path)->getPages();

        $slips = [];
        foreach ($pages as $i => $page) {
            $slips[] = $this->parsePage($page->getText(), $i + 1);
        }

        return $slips;
    }

    /**
     * @return array{
     *   page:int, name:?string, employee_no:?string, department:?string,
     *   payment_date:?string, period_start:?string, period_end:?string, period_key:?string,
     *   earnings: array<int, array{name:string, amount:int}>,
     *   deductions: array<int, array{name:string, amount:int}>,
     *   payment: array<int, array{name:string, amount:int}>,
     *   info: array<int, array{name:string, amount:int}>,
     *   total_earnings:?int, total_deductions:?int, net_pay:?int,
     *   errors: array<int, string>
     * }
     */
    public function parsePage(string $text, int $pageNo = 1): array
    {
        $slip = [
            'page' => $pageNo,
            'name' => null,
            'employee_no' => null,
            'department' => null,
            'payment_date' => null,
            'period_start' => null,
            'period_end' => null,
            'period_key' => null,
            'earnings' => [],
            'deductions' => [],
            'payment' => [],
            'info' => [],
            'total_earnings' => null,
            'total_deductions' => null,
            'net_pay' => null,
            'errors' => [],
        ];

        $lines = array_values(array_filter(
            array_map(fn ($l) => trim(str_replace("\u{3000}", ' ', $l)), preg_split('/\R/u', $text)),
            fn ($l) => $l !== '' && $l !== '<>',
        ));

        $section = null;
        $pendingTotal = null;
        $sectionKeys = ['earning' => 'earnings', 'deduction' => 'deductions', 'payment' => 'payment', 'info' => 'info'];

        foreach ($lines as $line) {
            if ($pendingTotal !== null) {
                $amount = $this->amount($line);
                if ($amount !== null) {
                    $slip[$pendingTotal] = $amount;
                    $pendingTotal = null;
                    continue;
                }
            }

            if ($slip['net_pay'] === null && $slip['payment_date'] === null) {
                $amount = $this->amount($line);
                if ($amount !== null) {
                    $slip['net_pay'] = $amount;
                    continue;
                }
            }

            if (preg_match('/^支給日：(.+)$/u', $line, $m)) {
                $slip['payment_date'] = $this->date($m[1]);
                continue;
            }
            if (preg_match('/^対象期間：(.+?)〜(.+)$/u', $line, $m)) {
                $slip['period_start'] = $this->date($m[1]);
                $slip['period_end'] = $this->date($m[2]);
                $slip['period_key'] = $slip['period_end'] ? substr($slip['period_end'], 0, 7) : null;
                continue;
            }
            if (preg_match('/^従業員番号：\s*(\S*)/u', $line, $m)) {
                $slip['employee_no'] = $m[1] !== '' ? $m[1] : null;
                continue;
            }
            if (preg_match('/^部門：(.*)$/u', $line, $m)) {
                $slip['department'] = trim($m[1]) !== '' ? trim($m[1]) : null;
                continue;
            }
            if ($slip['name'] === null && preg_match('/^(.+?)\s*様(\s|$)/u', $line, $m)) {
                $slip['name'] = trim($m[1]);
                continue;
            }

            if (isset(self::SECTIONS[$line])) {
                $section = self::SECTIONS[$line];
                continue;
            }
            if (preg_match('/^(支給合計|控除合計)(?:\s+\1)?(?:\s+(.+))?$/u', $line, $m)) {
                $key = self::TOTALS[$m[1]];
                $amount = isset($m[2]) ? $this->amount($m[2]) : null;
                if ($amount !== null) {
                    $slip[$key] = $amount;
                } else {
                    $pendingTotal = $key;
                }
                continue;
            }

            if ($section !== null && preg_match('/^(.+?)(?:\t|\s{1,})([-▲△]?[\d,]+(?:\s+[-▲△]?[\d,]+)?)$/u', $line, $m)) {
                $amount = $this->amount($m[2]);
                if ($amount !== null) {
                    $slip[$sectionKeys[$section]][] = ['name' => trim($m[1]), 'amount' => $amount];
                }
            }
        }

        $slip['errors'] = $this->validate($slip);

        return $slip;
    }

    /** @param array<string, mixed> $slip @return array<int, string> */
    private function validate(array $slip): array
    {
        $errors = [];
        foreach ([
            'employee_no' => '従業員番号',
            'name' => '氏名',
            'payment_date' => '支給日',
            'period_key' => '対象期間',
            'total_earnings' => '支給合計',
            'total_deductions' => '控除合計',
            'net_pay' => '差引支給額',
        ] as $key => $label) {
            if ($slip[$key] === null) {
                $errors[] = "{$label}が読み取れません";
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        $earnings = array_sum(array_column($slip['earnings'], 'amount'));
        if ($earnings !== $slip['total_earnings']) {
            $errors[] = sprintf('支給項目の合計 %s ≠ 支給合計 %s', number_format($earnings), number_format($slip['total_earnings']));
        }
        $deductions = array_sum(array_column($slip['deductions'], 'amount'));
        if ($deductions !== $slip['total_deductions']) {
            $errors[] = sprintf('控除項目の合計 %s ≠ 控除合計 %s', number_format($deductions), number_format($slip['total_deductions']));
        }
        if ($slip['total_earnings'] - $slip['total_deductions'] !== $slip['net_pay']) {
            $errors[] = sprintf(
                '支給合計 %s − 控除合計 %s ≠ 差引支給額 %s',
                number_format($slip['total_earnings']),
                number_format($slip['total_deductions']),
                number_format($slip['net_pay']),
            );
        }

        return $errors;
    }

    /** 「1,234」「▲500」、重複表示「449,710 449,710」を整数へ。数値でなければ null。 */
    private function amount(string $value): ?int
    {
        $token = preg_split('/\s+/u', trim($value))[0] ?? '';
        if (! preg_match('/^([-▲△]?)([\d,]+)$/u', $token, $m)) {
            return null;
        }
        $digits = str_replace(',', '', $m[2]);
        if ($digits === '') {
            return null;
        }
        $n = (int) $digits;

        return $m[1] !== '' ? -$n : $n;
    }

    /** 「2026（令和08）年09月30日」→ 2026-09-30 */
    private function date(string $value): ?string
    {
        if (! preg_match('/(\d{4})(?:（[^）]*）)?年\s*(\d{1,2})月\s*(\d{1,2})日/u', $value, $m)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }
}
