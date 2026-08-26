{{-- 所得税徴収高計算書オーバーレイ（web-view / browser-print-view / pdf-view） --}}
@php($viewMode = $viewMode ?? 'web-view')
<div class="income-tax-statement-form levied-income-tax-amount-statements-container {{ $viewMode }}">
    <div class="bg-container">
        <img src="{{ $backgroundSrc }}" alt="所得税徴収高計算書">
    </div>

    <div class="row-container">
        <div class="row-01">
            <div class="column-annual-year">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['annual_year']])
            </div>
            <div class="column-tax-office-name"></div>
            <div class="column-tax-office-number">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['corporate_number']])
            </div>
        </div>

        <div class="row-02">
            <div class="column-payroll-paid-start-at row-column-1">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['salary_payment']])
            </div>
            <div class="column-payroll-number-of-payroll-employees row-column-2">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['salary_count']])
            </div>
            <div class="column-total-payroll-tax-amount row-column-3">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['salary_amount']])
            </div>
            <div class="column-total-payroll-income-tax row-column-4">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['salary_tax']])
            </div>
        </div>

        <div class="row-03">
            <div class="column-bonus-paid-start-at row-column-1">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['bonus_payment']])
            </div>
            <div class="column-number-of-bonus-employees row-column-2">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['bonus_count']])
            </div>
            <div class="column-total-bonus-tax-amount row-column-3">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['bonus_amount']])
            </div>
            <div class="column-total-bonus-income-tax row-column-4">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['bonus_tax']])
            </div>
        </div>

        <div class="row-04">
            <div class="column-day-laborer-payroll-paid-start_at row-column-1">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['daily_worker']['payment']])
            </div>
            <div class="column-number-of-day-laborers row-column-2">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['daily_worker']['count']])
            </div>
            <div class="column-total-day-laborer-payroll-tax-amount row-column-3">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['daily_worker']['amount']])
            </div>
            <div class="column-total-day-laborer-payroll-income-tax row-column-4">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['daily_worker']['tax']])
            </div>
        </div>

        <div class="row-05">
            <div class="column-retirement-allowance-paid-start-at row-column-1">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['retirement']['payment']])
            </div>
            <div class="column-number-of-retirement-allowance-employees row-column-2">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['retirement']['count']])
            </div>
            <div class="column-total-retirement-allowance-tax-amount row-column-3">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['retirement']['amount']])
            </div>
            <div class="column-total-retirement-allowance-income-tax row-column-4">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['retirement']['tax']])
            </div>
        </div>

        <div class="row-06">
            <div class="column-professional-fee-paid-start-at row-column-1">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['professional_fee']['payment']])
            </div>
            <div class="column-number-of-professional-fee-employees row-column-2">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['professional_fee']['count']])
            </div>
            <div class="column-total-professional-fee-tax-amount row-column-3">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['professional_fee']['amount']])
            </div>
            <div class="column-total-professional-fee-income-tax row-column-4">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['professional_fee']['tax']])
            </div>
        </div>

        <div class="bottom-row">
            <div class="row-07">
                <div class="column-executive-bonus-paid-start-at row-column-1">
                    @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['executive_bonus']['payment']])
                </div>
                <div class="column-number-of-executive-bonus-employees row-column-2">
                    @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['executive_bonus']['count']])
                </div>
                <div class="column-total-executive-bonus-tax-amount row-column-3">
                    @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['executive_bonus']['amount']])
                </div>
                <div class="column-total-executive-bonus-income-tax row-column-4">
                    @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['executive_bonus']['tax']])
                </div>
            </div>

            <div class="row-08">
                <div class="column-total-shortage-of-income-tax-by-year-end-tax-adjustment row-column-4">
                    @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['shortage_tax']])
                </div>
            </div>

            <div class="row-09">
                <div class="column-total-excess-of-income-tax-by-year-end-tax-adjustment row-column-4">
                    @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['overpayment_tax']])
                </div>
            </div>

            <div class="row-10">
                <div class="column-main-income-tax-amount row-column-4">
                    @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['principal_tax']])
                </div>
            </div>

            <div class="row-11">
                <div class="column-late-payment-tax-amount row-column-4">
                    @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['late_payment_tax']])
                </div>
            </div>

            <div class="row-12">
                <div class="column-total-income-tax-amount row-column-4">
                    @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['total_tax']])
                </div>
            </div>
        </div>

        <div class="row-13">
            <div class="column-tel-block">
                <div class="column-withholding-agent-tel1">{{ $overlay['tel1'] }}</div>
                <div class="column-withholding-agent-tel2">{{ $overlay['tel2'] }}</div>
                <div class="column-withholding-agent-tel3">{{ $overlay['tel3'] }}</div>
            </div>
            <div class="column-withholding-agent-address">{{ $overlay['address'] }}</div>
            <div class="column-withholding-agent-name">{{ $overlay['payer_name'] }}</div>
            <div class="column-summary">{{ $overlay['remarks'] }}</div>
        </div>

        <div class="row-14">
            <div class="column-start-at">
                @include('payslips.partials.income-tax-number-cells', ['digits' => $overlay['due_period']])
            </div>
        </div>
    </div>
</div>
