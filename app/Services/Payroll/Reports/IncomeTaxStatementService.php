<?php

namespace App\Services\Payroll\Reports;

use App\Data\Payroll\Reports\IncomeTaxStatementReport;
use App\Models\BusinessLocation;
use App\Models\IncomeTaxStatementOverride;
use App\Models\PayrollRun;
use App\Models\Setting;
use App\Models\YearEndAdjustment;
use Illuminate\Support\Carbon;

/**
 * 所得税徴収高計算書（MF 風確認帳票）の集計・帳票データ組み立て。
 * 計算処理と PDF レイアウトは分離し、buildReport() が中間 DTO を返す。
 */
class IncomeTaxStatementService
{
    public function __construct(private PayrollReportService $reports) {}

    /**
     * @param  array<int, string>  $periodKeys  YYYY-MM
     * @return array{
     *   salary: array{count:int, amount:int, tax:int},
     *   bonus: array{count:int, amount:int, tax:int},
     *   total: array{count:int, amount:int, tax:int},
     * }
     */
    public function aggregate(array $periodKeys, ?int $locationId = null): array
    {
        $runs = PayrollRun::query()
            ->whereIn('period_key', $periodKeys)
            ->where('status', 'finalized')
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

    /**
     * 帳票用 DTO を組み立てる（自動集計 + 会社設定 + 手入力 overrides）。
     *
     * @param  array<string, mixed>  $overrides  IncomeTaxStatementOverride::mergedData()
     */
    public function buildReport(
        array $aggregate,
        int $year,
        int $month,
        string $formType,
        string $periodLabel,
        ?string $salaryPaymentDate = null,
        ?string $bonusPaymentDate = null,
        array $overrides = [],
        ?int $locationId = null,
    ): IncomeTaxStatementReport {
        $reiwa = max(1, $year - 2018);
        $main = BusinessLocation::query()->where('is_main', true)->first()
            ?? BusinessLocation::query()->orderBy('sort_order')->first();

        $salaryPay = $salaryPaymentDate
            ? Carbon::parse($salaryPaymentDate)
            : Carbon::create($year, $month, 1)->endOfMonth();

        $yea = $this->resolveYearEndAdjustments($year, $month, $formType);

        $shortage = (int) ($overrides['year_end_adjustment_shortage'] ?? 0);
        $overpayment = (int) ($overrides['year_end_adjustment_overpayment'] ?? 0);
        if ($shortage === 0 && $overpayment === 0 && ($yea['shortage'] > 0 || $yea['overpayment'] > 0)) {
            $shortage = $yea['shortage'];
            $overpayment = $yea['overpayment'];
        }

        $detailTax = $aggregate['total']['tax']
            + (int) ($overrides['daily_worker']['tax_amount'] ?? 0)
            + (int) ($overrides['retirement']['tax_amount'] ?? 0)
            + (int) ($overrides['professional_fee']['tax_amount'] ?? 0);

        $principalTax = max(0, $detailTax + $shortage - $overpayment);
        $latePaymentTax = (int) ($overrides['late_payment_tax'] ?? 0);
        $totalTax = $principalTax + $latePaymentTax;

        $duePeriodLabel = $this->duePeriodLabel($year, $month, $formType, $reiwa);

        return new IncomeTaxStatementReport([
            'form_type' => $formType,
            'form_type_label' => $formType === 'special' ? '納期特例分' : '一般分',
            'year' => $year,
            'month' => $month,
            'reiwa' => $reiwa,
            'period_label' => $periodLabel,
            'due_period_label' => $duePeriodLabel,
            'tax_office_name' => Setting::getValue('tax_office_name', ''),
            'reference_number' => Setting::getValue('corporate_individual_number', ''),
            'tax_office_sign' => Setting::getValue('tax_office_sign_number', ''),
            'tax_office_number' => Setting::getValue('tax_office_number', ''),
            'salary' => $this->rowFromAggregate($aggregate['salary'], $salaryPay->toDateString()),
            'bonus' => $this->rowFromAggregate(
                $aggregate['bonus'],
                $aggregate['bonus']['count'] > 0 && $bonusPaymentDate ? $bonusPaymentDate : null,
            ),
            'daily_worker' => $this->manualRow($overrides['daily_worker'] ?? []),
            'retirement' => $this->manualRow($overrides['retirement'] ?? []),
            'professional_fee' => $this->manualRow($overrides['professional_fee'] ?? []),
            'year_end_adjustment_shortage' => $shortage,
            'year_end_adjustment_overpayment' => $overpayment,
            'year_end_adjustment_source' => ($yea['shortage'] || $yea['overpayment']) ? 'calculated' : 'manual',
            'principal_tax' => $principalTax,
            'late_payment_tax' => $latePaymentTax,
            'total_tax' => $totalTax,
            'company' => [
                'postal_code' => $main?->postal_code ?? '',
                'address' => trim(($main?->prefecture ?? '').' '.($main?->address ?? '')),
                'name' => $main?->name ?? '',
                'representative_name' => '',
                'phone' => $this->resolvePhone($main),
            ],
            'remarks' => (string) ($overrides['remarks'] ?? ''),
        ]);
    }

    /** 対象月の給与バッチ支払日（なければ null）。 */
    public function resolvePaymentDate(array $periodKeys, string $payType = 'salary'): ?string
    {
        $run = PayrollRun::query()
            ->whereIn('period_key', $periodKeys)
            ->where('pay_type', $payType)
            ->where('status', 'finalized')
            ->orderByDesc('payment_date')
            ->first();

        return $run?->payment_date?->toDateString();
    }

    /**
     * @return array{shortage:int, overpayment:int}
     */
    private function resolveYearEndAdjustments(int $year, int $month, string $formType): array
    {
        if ($formType !== 'general' || $month !== 12) {
            return ['shortage' => 0, 'overpayment' => 0];
        }

        $adjustments = YearEndAdjustment::query()
            ->where('year', $year)
            ->whereIn('status', ['confirmed', 'reflected'])
            ->pluck('adjustment_amount');

        $shortage = 0;
        $overpayment = 0;
        foreach ($adjustments as $amount) {
            $amount = (int) $amount;
            if ($amount > 0) {
                $shortage += $amount;
            } elseif ($amount < 0) {
                $overpayment += abs($amount);
            }
        }

        return ['shortage' => $shortage, 'overpayment' => $overpayment];
    }

    /**
     * @param  array{count:int, amount:int, tax:int}  $bucket
     * @return array<string, mixed>
     */
    private function rowFromAggregate(array $bucket, ?string $paymentDate): array
    {
        return [
            'payment_date' => $paymentDate,
            'employee_count' => $bucket['count'],
            'payment_amount' => $bucket['amount'],
            'tax_amount' => $bucket['tax'],
            'source' => 'calculated',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function manualRow(array $row): array
    {
        return [
            'payment_date' => $row['payment_date'] ?? null,
            'employee_count' => (int) ($row['employee_count'] ?? 0),
            'payment_amount' => (int) ($row['payment_amount'] ?? 0),
            'tax_amount' => (int) ($row['tax_amount'] ?? 0),
            'source' => 'manual',
        ];
    }

    private function duePeriodLabel(int $year, int $month, string $formType, int $reiwa): string
    {
        if ($formType === 'special') {
            if ($month === 6) {
                return sprintf('令和%d年01月〜令和%d年06月', $reiwa, $reiwa);
            }

            return sprintf('令和%d年07月〜令和%d年12月', $reiwa, $reiwa);
        }

        return sprintf('令和%d年%02d月', $reiwa, $month);
    }

    private function resolvePhone(?BusinessLocation $main): string
    {
        if (! $main) {
            return '';
        }

        if (preg_match('/(\d{2,4}[-\s]?\d{2,4}[-\s]?\d{3,4})/', (string) $main->note, $m)) {
            return preg_replace('/\s+/', '-', trim($m[1]));
        }

        return '';
    }

    /**
     * 様式背景画像の URL / ファイルパス（Web プレビュー・DomPDF 用）。
     */
    public function backgroundSrc(string $mode, bool $forPdf = false): string
    {
        $key = $mode === 'special' ? 'special' : 'normal';
        $relative = config("income_tax_statement.backgrounds.{$key}");

        return $forPdf ? public_path($relative) : asset($relative);
    }

    /**
     * 複写式 3 連それぞれの上端 Y 座標（mm）。
     *
     * @return array<int, float>
     */
    public function slipTops(): array
    {
        return config('income_tax_statement.slip_tops', [0, 98.9, 198.0]);
    }

    /** Web プレビュー用の連上端（中連のみ）。 */
    public function previewSlipTop(): float
    {
        return (float) config('income_tax_statement.preview_slip_top', 98.9);
    }

    /**
     * 帳票 DTO から国税庁様式への転記データ（桁分割）を生成する。
     *
     * @param  array<string, mixed>  $report  buildReport()->toArray()
     * @return array<string, mixed>
     */
    public function buildFormFromReport(array $report, string $mode): array
    {
        $reiwa = (int) ($report['reiwa'] ?? 1);
        $month = (int) ($report['month'] ?? 1);
        $year = (int) ($report['year'] ?? 2019 + $reiwa);
        $formType = $report['form_type'] ?? 'general';

        $salaryPay = ! empty($report['salary']['payment_date'])
            ? Carbon::parse($report['salary']['payment_date'])
            : Carbon::create($year, $month, 1)->endOfMonth();

        $bonusPay = ! empty($report['bonus']['payment_date'])
            ? Carbon::parse($report['bonus']['payment_date'])
            : $salaryPay;

        $corpNo = (string) ($report['reference_number'] ?? '');
        $taxOfficeSign = (string) ($report['tax_office_sign'] ?? '');
        $taxOfficeNo = (string) ($report['tax_office_number'] ?? '000');

        $phone = (string) ($report['company']['phone'] ?? '');
        $address = (string) ($report['company']['address'] ?? '');
        $prefecture = '';
        if ($address !== '' && preg_match('/^(.{2,3}[都道府県])/u', $address, $m)) {
            $prefecture = $m[1];
        }

        $dueMonth = $formType === 'special' ? 0 : $month;

        $salaryCount = (int) ($report['salary']['employee_count'] ?? 0);
        $salaryAmount = (int) ($report['salary']['payment_amount'] ?? 0);
        $salaryTax = (int) ($report['salary']['tax_amount'] ?? 0);
        $bonusCount = (int) ($report['bonus']['employee_count'] ?? 0);
        $bonusAmount = (int) ($report['bonus']['payment_amount'] ?? 0);
        $bonusTax = (int) ($report['bonus']['tax_amount'] ?? 0);

        $principalTax = (int) ($report['principal_tax'] ?? 0);
        $totalTax = (int) ($report['total_tax'] ?? 0);

        return [
            'reiwa' => $this->padDigits($reiwa, 2),
            'corporate_number' => $this->padDigits($corpNo, 5),
            'tax_office_sign' => $this->padDigits($taxOfficeSign !== '' ? $taxOfficeSign : '0', 3, padChar: '0'),
            'tax_office_number' => $this->padDigits($taxOfficeNo, 3, padChar: '0'),
            'payment_date' => [
                'era' => $this->padDigits($reiwa, 2, padChar: '0'),
                'month' => $this->padDigits($salaryPay->month, 2, padChar: '0'),
                'day' => $this->padDigits($salaryPay->day, 2, padChar: '0'),
            ],
            'bonus_payment_date' => [
                'era' => $this->padDigits($reiwa, 2, padChar: '0'),
                'month' => $this->padDigits($bonusPay->month, 2, padChar: '0'),
                'day' => $this->padDigits($bonusPay->day, 2, padChar: '0'),
            ],
            'due_period' => [
                'era' => $this->padDigits($reiwa, 2, padChar: '0'),
                'month' => $this->padDigits($dueMonth, 2, padChar: '0'),
            ],
            'salary' => [
                'count' => $this->padDigits($salaryCount, 3),
                'amount' => $this->padDigits($salaryAmount, 9),
                'tax' => $this->padDigits($salaryTax, 9),
            ],
            'bonus' => [
                'count' => $this->padDigits($bonusCount, 3),
                'amount' => $this->padDigits($bonusAmount, 9),
                'tax' => $this->padDigits($bonusTax, 9),
                'amount_value' => $bonusAmount,
            ],
            'principal_tax' => $this->padDigits($principalTax, 9),
            'total_tax' => $this->padDigits($totalTax, 9),
            'payer' => [
                'address' => $address,
                'prefecture' => $prefecture,
                'name' => (string) ($report['company']['name'] ?? ''),
                'phone' => $phone,
                'phone_digits' => $this->phoneDigits($phone),
            ],
            'mode' => $mode,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function padDigits(int|string|null $value, int $length, string $padChar = ' '): array
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? ''));
        $digits = str_pad($digits, $length, $padChar, STR_PAD_LEFT);

        return mb_str_split($digits);
    }

    /**
     * @return array<int, string>
     */
    private function phoneDigits(string $phone): array
    {
        $parts = preg_split('/\D+/', $phone, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_merge(
            $this->padDigits($parts[0] ?? '', 3, ' '),
            $this->padDigits($parts[1] ?? '', 3, ' '),
            $this->padDigits($parts[2] ?? '', 4, ' '),
        );
    }
}
