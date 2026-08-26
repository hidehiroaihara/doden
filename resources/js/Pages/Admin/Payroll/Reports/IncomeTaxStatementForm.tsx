import '../../../../../css/income-tax-statement-form.css';

interface FormPayer {
    address: string;
    prefecture: string;
    name: string;
    phone: string;
    phone_digits: string[];
}

export interface IncomeTaxForm {
    corporate_number: string[];
    reiwa: string[];
    tax_office_sign: string[];
    tax_office_number: string[];
    payment_date: { era: string[]; month: string[]; day: string[] };
    bonus_payment_date: { era: string[]; month: string[]; day: string[] };
    due_period: { era: string[]; month: string[] };
    salary: { count: string[]; amount: string[]; tax: string[] };
    bonus: { count: string[]; amount: string[]; tax: string[]; amount_value?: number };
    principal_tax: string[];
    total_tax: string[];
    payer: FormPayer;
}

interface ReportRow {
    payment_date?: string | null;
    employee_count?: number;
    payment_amount?: number;
    tax_amount?: number;
}

interface ReportData {
    daily_worker?: ReportRow;
    retirement?: ReportRow;
    professional_fee?: ReportRow;
    executive_bonus?: ReportRow;
    year_end_adjustment_shortage?: number;
    year_end_adjustment_overpayment?: number;
    late_payment_tax?: number;
    remarks?: string;
}

interface Props {
    backgroundUrl: string;
    form: IncomeTaxForm;
    report: ReportData;
    year: number;
}

function padDigits(value: number | string | null | undefined, length: number, padChar = ' '): string[] {
    const raw = String(value ?? '').replace(/\D/g, '');
    const padded = raw.padStart(length, padChar);

    return [...padded].slice(-length);
}

function annualYearDigits(calendarYear: number): string[] {
    const reiwa = Math.max(1, calendarYear - 2018);

    return padDigits(reiwa, 2, '0');
}

function corporateNumberDigits(corporateNumber: string[]): string[] {
    const value = corporateNumber.join('').replace(/\s/g, '');

    return padDigits(value, 5, ' ');
}

function paymentDateDigits(era: string[], month: string[], day: string[]): string[] {
    const eraVal = era.join('').replace(/\D/g, '');
    const monthVal = month.join('').replace(/\D/g, '');
    const dayVal = day.join('').replace(/\D/g, '');

    if (eraVal === '' && monthVal === '' && dayVal === '') {
        return padDigits('', 6);
    }

    return [
        ...padDigits(eraVal || '0', 2, '0'),
        ...padDigits(monthVal || '0', 2, '0'),
        ...padDigits(dayVal || '0', 2, '0'),
    ];
}

function duePeriodDigits(era: string[], month: string[]): string[] {
    const eraVal = era.join('').replace(/\D/g, '');
    const monthVal = month.join('').replace(/\D/g, '');

    if (eraVal === '' && monthVal === '') {
        return padDigits('', 4);
    }

    return [
        ...padDigits(eraVal || '0', 2, '0'),
        ...padDigits(monthVal || '0', 2, '0'),
    ];
}

function paymentDigitsFromDate(reiwa: string[], paymentDate: string | null | undefined): string[] {
    if (!paymentDate) {
        return padDigits('', 6);
    }

    const date = new Date(`${paymentDate}T00:00:00`);
    const eraVal = reiwa.join('').replace(/\D/g, '');

    return [
        ...padDigits(eraVal || '0', 2, '0'),
        ...padDigits(date.getMonth() + 1, 2, '0'),
        ...padDigits(date.getDate(), 2, '0'),
    ];
}

function countColumn(count: number): string[] {
    return padDigits(count, 5);
}

function amountColumn(amount: number): string[] {
    return padDigits(amount, 11);
}

function taxColumn(tax: number): string[] {
    return padDigits(tax, 10);
}

function principalColumn(tax: string[]): string[] {
    const value = tax.filter((d) => d.trim() !== '').join('') || '0';

    return padDigits(value, 10);
}

function totalColumn(tax: string[]): string[] {
    const value = tax.filter((d) => d.trim() !== '').join('') || '0';
    const digits = padDigits(value, 5);

    return [...padDigits('', 5), '¥', ...digits];
}

function phoneParts(phone: string): [string, string, string] {
    const parts = phone.split(/\D+/).filter(Boolean);

    return [parts[0] ?? '', parts[1] ?? '', parts[2] ?? ''];
}

function NumberCells({ digits }: { digits: string[] }) {
    return (
        <>
            {digits.map((digit, index) => (
                <div key={index} className="number">{digit.trim() !== '' ? digit : '\u00a0'}</div>
            ))}
        </>
    );
}

function DataRow({
    rowClass,
    paymentClass,
    countClass,
    amountClass,
    taxClass,
    payment,
    count,
    amount,
    tax,
}: {
    rowClass: string;
    paymentClass: string;
    countClass: string;
    amountClass: string;
    taxClass: string;
    payment: string[];
    count: string[];
    amount: string[];
    tax: string[];
}) {
    return (
        <div className={rowClass}>
            <div className={`${paymentClass} row-column-1`}>
                <NumberCells digits={payment} />
            </div>
            <div className={`${countClass} row-column-2`}>
                <NumberCells digits={count} />
            </div>
            <div className={`${amountClass} row-column-3`}>
                <NumberCells digits={amount} />
            </div>
            <div className={`${taxClass} row-column-4`}>
                <NumberCells digits={tax} />
            </div>
        </div>
    );
}

export default function IncomeTaxStatementForm({ backgroundUrl, form, report, year }: Props) {
    const annualYear = annualYearDigits(year);
    const corporateNumber = corporateNumberDigits(form.corporate_number);
    const salaryPayment = paymentDateDigits(
        form.payment_date.era,
        form.payment_date.month,
        form.payment_date.day,
    );
    const bonusPayment = paymentDateDigits(
        form.bonus_payment_date.era,
        form.bonus_payment_date.month,
        form.bonus_payment_date.day,
    );
    const dailyWorker = report.daily_worker ?? {};
    const retirement = report.retirement ?? {};
    const professionalFee = report.professional_fee ?? {};
    const executiveBonus = report.executive_bonus ?? {};
    const [tel1, tel2, tel3] = phoneParts(form.payer.phone);
    const addressText = form.payer.address || form.payer.prefecture;
    const dueDigits = duePeriodDigits(form.due_period.era, form.due_period.month);

    return (
        <div className="income-tax-statement-form levied-income-tax-amount-statements-container web-view">
            <div className="bg-container">
                <img src={backgroundUrl} alt="所得税徴収高計算書" draggable={false} />
            </div>

            <div className="row-container">
                <div className="row-01">
                    <div className="column-annual-year">
                        <NumberCells digits={annualYear} />
                    </div>
                    <div className="column-tax-office-name" />
                    <div className="column-tax-office-number">
                        <NumberCells digits={corporateNumber} />
                    </div>
                </div>

                <DataRow
                    rowClass="row-02"
                    paymentClass="column-payroll-paid-start-at"
                    countClass="column-payroll-number-of-payroll-employees"
                    amountClass="column-total-payroll-tax-amount"
                    taxClass="column-total-payroll-income-tax"
                    payment={salaryPayment}
                    count={countColumn(Number(form.salary.count.join('').trim() || 0))}
                    amount={amountColumn(Number(form.salary.amount.join('').trim() || 0))}
                    tax={taxColumn(Number(form.salary.tax.join('').trim() || 0))}
                />

                <DataRow
                    rowClass="row-03"
                    paymentClass="column-bonus-paid-start-at"
                    countClass="column-number-of-bonus-employees"
                    amountClass="column-total-bonus-tax-amount"
                    taxClass="column-total-bonus-income-tax"
                    payment={bonusPayment}
                    count={countColumn(Number(form.bonus.count.join('').trim() || 0))}
                    amount={amountColumn(Number(form.bonus.amount.join('').trim() || 0))}
                    tax={taxColumn(Number(form.bonus.tax.join('').trim() || 0))}
                />

                <DataRow
                    rowClass="row-04"
                    paymentClass="column-day-laborer-payroll-paid-start_at"
                    countClass="column-number-of-day-laborers"
                    amountClass="column-total-day-laborer-payroll-tax-amount"
                    taxClass="column-total-day-laborer-payroll-income-tax"
                    payment={paymentDigitsFromDate(form.reiwa, dailyWorker.payment_date)}
                    count={countColumn(dailyWorker.employee_count ?? 0)}
                    amount={amountColumn(dailyWorker.payment_amount ?? 0)}
                    tax={taxColumn(dailyWorker.tax_amount ?? 0)}
                />

                <DataRow
                    rowClass="row-05"
                    paymentClass="column-retirement-allowance-paid-start-at"
                    countClass="column-number-of-retirement-allowance-employees"
                    amountClass="column-total-retirement-allowance-tax-amount"
                    taxClass="column-total-retirement-allowance-income-tax"
                    payment={paymentDigitsFromDate(form.reiwa, retirement.payment_date)}
                    count={countColumn(retirement.employee_count ?? 0)}
                    amount={amountColumn(retirement.payment_amount ?? 0)}
                    tax={taxColumn(retirement.tax_amount ?? 0)}
                />

                <DataRow
                    rowClass="row-06"
                    paymentClass="column-professional-fee-paid-start-at"
                    countClass="column-number-of-professional-fee-employees"
                    amountClass="column-total-professional-fee-tax-amount"
                    taxClass="column-total-professional-fee-income-tax"
                    payment={paymentDigitsFromDate(form.reiwa, professionalFee.payment_date)}
                    count={countColumn(professionalFee.employee_count ?? 0)}
                    amount={amountColumn(professionalFee.payment_amount ?? 0)}
                    tax={taxColumn(professionalFee.tax_amount ?? 0)}
                />

                <div className="bottom-row">
                    <DataRow
                        rowClass="row-07"
                        paymentClass="column-executive-bonus-paid-start-at"
                        countClass="column-number-of-executive-bonus-employees"
                        amountClass="column-total-executive-bonus-tax-amount"
                        taxClass="column-total-executive-bonus-income-tax"
                        payment={paymentDigitsFromDate(form.reiwa, executiveBonus.payment_date)}
                        count={countColumn(executiveBonus.employee_count ?? 0)}
                        amount={amountColumn(executiveBonus.payment_amount ?? 0)}
                        tax={taxColumn(executiveBonus.tax_amount ?? 0)}
                    />

                    <div className="row-08">
                        <div className="column-total-shortage-of-income-tax-by-year-end-tax-adjustment row-column-4">
                            <NumberCells digits={taxColumn(report.year_end_adjustment_shortage ?? 0)} />
                        </div>
                    </div>

                    <div className="row-09">
                        <div className="column-total-excess-of-income-tax-by-year-end-tax-adjustment row-column-4">
                            <NumberCells digits={taxColumn(report.year_end_adjustment_overpayment ?? 0)} />
                        </div>
                    </div>

                    <div className="row-10">
                        <div className="column-main-income-tax-amount row-column-4">
                            <NumberCells digits={principalColumn(form.principal_tax)} />
                        </div>
                    </div>

                    <div className="row-11">
                        <div className="column-late-payment-tax-amount row-column-4">
                            <NumberCells digits={taxColumn(report.late_payment_tax ?? 0)} />
                        </div>
                    </div>

                    <div className="row-12">
                        <div className="column-total-income-tax-amount row-column-4">
                            <NumberCells digits={totalColumn(form.total_tax)} />
                        </div>
                    </div>
                </div>

                <div className="row-13">
                    <div className="column-tel-block">
                        <div className="column-withholding-agent-tel1">{tel1}</div>
                        <div className="column-withholding-agent-tel2">{tel2}</div>
                        <div className="column-withholding-agent-tel3">{tel3}</div>
                    </div>
                    <div className="column-withholding-agent-address">{addressText}</div>
                    <div className="column-withholding-agent-name">{form.payer.name}</div>
                    <div className="column-summary">{report.remarks ?? ''}</div>
                </div>

                <div className="row-14">
                    <div className="column-start-at">
                        <NumberCells digits={dueDigits} />
                    </div>
                </div>
            </div>
        </div>
    );
}
