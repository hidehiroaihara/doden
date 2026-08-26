<?php

namespace App\Services\Payroll\Reports;

use Illuminate\Support\Carbon;

/**
 * 所得税徴収高計算書オーバーレイ（画面・PDF 共通の HTML 転記データ）。
 */
class IncomeTaxStatementOverlay
{
    /**
     * @param  array<string, mixed>  $form   buildFormFromReport()
     * @param  array<string, mixed>  $report buildReport()->toArray()
     * @return array<string, mixed>
     */
    public function build(array $form, array $report, int $year): array
    {
        $dailyWorker = $report['daily_worker'] ?? [];
        $retirement = $report['retirement'] ?? [];
        $professionalFee = $report['professional_fee'] ?? [];
        $executiveBonus = $report['executive_bonus'] ?? [];
        $phoneParts = $this->phoneParts((string) ($form['payer']['phone'] ?? ''));

        return [
            'annual_year' => $this->annualYearDigits($year),
            'corporate_number' => $this->corporateNumberDigits($form['corporate_number'] ?? []),
            'salary_payment' => $this->paymentDateDigits(
                $form['payment_date']['era'] ?? [],
                $form['payment_date']['month'] ?? [],
                $form['payment_date']['day'] ?? [],
            ),
            'bonus_payment' => $this->paymentDateDigits(
                $form['bonus_payment_date']['era'] ?? [],
                $form['bonus_payment_date']['month'] ?? [],
                $form['bonus_payment_date']['day'] ?? [],
            ),
            'daily_worker' => $this->manualRowDigits($form['reiwa'] ?? [], $dailyWorker),
            'retirement' => $this->manualRowDigits($form['reiwa'] ?? [], $retirement),
            'professional_fee' => $this->manualRowDigits($form['reiwa'] ?? [], $professionalFee),
            'executive_bonus' => $this->manualRowDigits($form['reiwa'] ?? [], $executiveBonus),
            'salary_count' => $this->countColumn((int) implode('', $form['salary']['count'] ?? [])),
            'salary_amount' => $this->amountColumn((int) implode('', $form['salary']['amount'] ?? [])),
            'salary_tax' => $this->taxColumn((int) implode('', $form['salary']['tax'] ?? [])),
            'bonus_count' => $this->countColumn((int) implode('', $form['bonus']['count'] ?? [])),
            'bonus_amount' => $this->amountColumn((int) implode('', $form['bonus']['amount'] ?? [])),
            'bonus_tax' => $this->taxColumn((int) implode('', $form['bonus']['tax'] ?? [])),
            'shortage_tax' => $this->taxColumn((int) ($report['year_end_adjustment_shortage'] ?? 0)),
            'overpayment_tax' => $this->taxColumn((int) ($report['year_end_adjustment_overpayment'] ?? 0)),
            'principal_tax' => $this->principalColumn($form['principal_tax'] ?? []),
            'late_payment_tax' => $this->taxColumn((int) ($report['late_payment_tax'] ?? 0)),
            'total_tax' => $this->totalColumn($form['total_tax'] ?? []),
            'due_period' => $this->duePeriodDigits(
                $form['due_period']['era'] ?? [],
                $form['due_period']['month'] ?? [],
            ),
            'tel1' => $phoneParts[0],
            'tel2' => $phoneParts[1],
            'tel3' => $phoneParts[2],
            'address' => ($form['payer']['address'] ?? '') ?: ($form['payer']['prefecture'] ?? ''),
            'payer_name' => (string) ($form['payer']['name'] ?? ''),
            'remarks' => (string) ($report['remarks'] ?? ''),
        ];
    }

    /**
     * 見た目確認用：全桁・全文字を埋めたテストデータ。
     *
     * @return array<string, mixed>
     */
    public function buildTest(int $year): array
    {
        $manualRow = function (string $paymentDate): array {
            return [
                'payment' => $this->padDigits($paymentDate, 6, '0'),
                'count' => $this->countColumn(12345),
                'amount' => $this->amountColumn(12345678901),
                'tax' => $this->taxColumn(1234567890),
            ];
        };

        return [
            'annual_year' => $this->annualYearDigits($year),
            'corporate_number' => $this->padDigits('32309', 5),
            'salary_payment' => $this->padDigits('080830', 6, '0'),
            'bonus_payment' => $this->padDigits('081015', 6, '0'),
            'daily_worker' => $manualRow('080901'),
            'retirement' => $manualRow('081020'),
            'professional_fee' => $manualRow('081125'),
            'executive_bonus' => $manualRow('081230'),
            'salary_count' => $this->countColumn(99999),
            'salary_amount' => $this->amountColumn(98765432109),
            'salary_tax' => $this->taxColumn(9876543210),
            'bonus_count' => $this->countColumn(88888),
            'bonus_amount' => $this->amountColumn(87654321098),
            'bonus_tax' => $this->taxColumn(8765432109),
            'shortage_tax' => $this->taxColumn(1111111111),
            'overpayment_tax' => $this->taxColumn(2222222222),
            'principal_tax' => $this->taxColumn(3333333333),
            'late_payment_tax' => $this->taxColumn(4444444444),
            'total_tax' => $this->totalColumn($this->padDigits('55555', 5)),
            'due_period' => $this->padDigits('0808', 4, '0'),
            'tel1' => '048',
            'tel2' => '607',
            'tel3' => '1129',
            'address' => '東京都千代田区テスト1-2-3',
            'payer_name' => '株式会社テスト',
            'remarks' => '摘要テスト文字',
        ];
    }

    /**
     * @param  array<int, string>  $corporateNumber
     * @return array<int, string>
     */
    public function corporateNumberDigits(array $corporateNumber): array
    {
        $value = preg_replace('/\s/', '', implode('', $corporateNumber)) ?? '';

        return $this->padDigits($value, 5);
    }

    /**
     * @param  array<int, string>  $era
     * @param  array<int, string>  $month
     * @param  array<int, string>  $day
     * @return array<int, string>
     */
    public function paymentDateDigits(array $era, array $month, array $day): array
    {
        $eraVal = preg_replace('/\D/', '', implode('', $era)) ?? '';
        $monthVal = preg_replace('/\D/', '', implode('', $month)) ?? '';
        $dayVal = preg_replace('/\D/', '', implode('', $day)) ?? '';

        if ($eraVal === '' && $monthVal === '' && $dayVal === '') {
            return $this->padDigits('', 6);
        }

        return array_merge(
            $this->padDigits($eraVal !== '' ? $eraVal : '0', 2, '0'),
            $this->padDigits($monthVal !== '' ? $monthVal : '0', 2, '0'),
            $this->padDigits($dayVal !== '' ? $dayVal : '0', 2, '0'),
        );
    }

    /**
     * @param  array<int, string>  $reiwa
     * @param  array<string, mixed>  $row
     * @return array{payment: array<int, string>, count: array<int, string>, amount: array<int, string>, tax: array<int, string>}
     */
    public function manualRowDigits(array $reiwa, array $row): array
    {
        return [
            'payment' => $this->paymentDigitsFromDate($reiwa, $row['payment_date'] ?? null),
            'count' => $this->countColumn((int) ($row['employee_count'] ?? 0)),
            'amount' => $this->amountColumn((int) ($row['payment_amount'] ?? 0)),
            'tax' => $this->taxColumn((int) ($row['tax_amount'] ?? 0)),
        ];
    }

    /**
     * @param  array<int, string>  $reiwa
     * @return array<int, string>
     */
    public function paymentDigitsFromDate(array $reiwa, ?string $paymentDate): array
    {
        if (! $paymentDate) {
            return $this->padDigits('', 6);
        }

        $date = Carbon::parse($paymentDate);
        $eraVal = preg_replace('/\D/', '', implode('', $reiwa)) ?? '';

        return array_merge(
            $this->padDigits($eraVal !== '' ? $eraVal : '0', 2, '0'),
            $this->padDigits($date->month, 2, '0'),
            $this->padDigits($date->day, 2, '0'),
        );
    }

    /**
     * @param  array<int, string>  $era
     * @param  array<int, string>  $month
     * @return array<int, string>
     */
    public function duePeriodDigits(array $era, array $month): array
    {
        $eraVal = preg_replace('/\D/', '', implode('', $era)) ?? '';
        $monthVal = preg_replace('/\D/', '', implode('', $month)) ?? '';

        if ($eraVal === '' && $monthVal === '') {
            return $this->padDigits('', 4);
        }

        return array_merge(
            $this->padDigits($eraVal !== '' ? $eraVal : '0', 2, '0'),
            $this->padDigits($monthVal !== '' ? $monthVal : '0', 2, '0'),
        );
    }

    /**
     * @param  array<int, string>  $tax
     * @return array<int, string>
     */
    public function principalColumn(array $tax): array
    {
        $value = implode('', array_filter($tax, fn ($d) => trim($d) !== '')) ?: '0';

        return $this->padDigits($value, 10);
    }

    /**
     * @param  array<int, string>  $tax
     * @return array<int, string>
     */
    public function totalColumn(array $tax): array
    {
        $value = implode('', array_filter($tax, fn ($d) => trim($d) !== '')) ?: '0';

        return array_merge(
            $this->padDigits('', 5),
            ['¥'],
            $this->padDigits($value, 5),
        );
    }

    /**
     * @return array<int, string>
     */
    public function annualYearDigits(int $calendarYear): array
    {
        return $this->padDigits(max(1, $calendarYear - 2018), 2, '0');
    }

    /**
     * @return array<int, string>
     */
    public function countColumn(int $count): array
    {
        return $this->padDigits($count, 5);
    }

    /**
     * @return array<int, string>
     */
    public function amountColumn(int $amount): array
    {
        return $this->padDigits($amount, 11);
    }

    /**
     * @return array<int, string>
     */
    public function taxColumn(int $tax): array
    {
        return $this->padDigits($tax, 10);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    public function phoneParts(string $phone): array
    {
        $parts = preg_split('/\D+/', $phone, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [$parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? ''];
    }

    /**
     * @return array<int, string>
     */
    public function padDigits(int|string|null $value, int $length, string $padChar = ' '): array
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? '')) ?? '';
        $digits = str_pad($digits, $length, $padChar, STR_PAD_LEFT);

        return mb_str_split($digits);
    }
}
