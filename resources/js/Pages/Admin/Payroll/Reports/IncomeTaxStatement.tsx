import AdminLayout from '@/Layouts/AdminLayout';
import IncomeTaxStatementForm, { type IncomeTaxForm } from '@/Pages/Admin/Payroll/Reports/IncomeTaxStatementForm';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface RowData {
    payment_date: string | null;
    employee_count: number;
    payment_amount: number;
    tax_amount: number;
    source?: string;
}

interface Overrides {
    daily_worker: RowData;
    retirement: RowData;
    professional_fee: RowData;
    year_end_adjustment_shortage: number;
    year_end_adjustment_overpayment: number;
    late_payment_tax: number;
    remarks: string;
}

interface Bucket {
    count: number;
    amount: number;
    tax: number;
}

interface Props {
    mode: 'normal' | 'special';
    form_type: 'general' | 'special';
    year: number;
    month: number;
    report_month: number;
    half: string;
    periodLabel: string;
    location_id: number | null;
    result: { salary: Bucket; bonus: Bucket; total: Bucket };
    report: Record<string, unknown>;
    form: IncomeTaxForm;
    background_url: string;
    overrides: Overrides;
    options: { years: number[]; businessLocations: { id: number; name: string }[] };
}

const emptyRow = (): RowData => ({
    payment_date: null,
    employee_count: 0,
    payment_amount: 0,
    tax_amount: 0,
});

export default function IncomeTaxStatement({
    mode, form_type, year, month, report_month, half, periodLabel,
    location_id, result, report, form: statementForm, background_url, overrides, options,
}: Props) {
    const [manualOpen, setManualOpen] = useState(false);

    const query = useMemo(() => {
        const p: Record<string, string | number> = { year, month, half };
        if (mode === 'special') p.mode = 'special';
        if (location_id) p.location = location_id;
        return p;
    }, [mode, year, month, half, location_id]);

    const previewUrl = useMemo(() => {
        const u = new URL(route('admin.payroll.reports.income-tax-statement.preview'), window.location.origin);
        Object.entries(query).forEach(([k, v]) => {
            if (v !== undefined && v !== '') u.searchParams.set(k, String(v));
        });
        return u.pathname + u.search;
    }, [query]);

    const pdfUrl = useMemo(() => {
        const u = new URL(route('admin.payroll.reports.income-tax-statement.pdf'), window.location.origin);
        Object.entries(query).forEach(([k, v]) => {
            if (v !== undefined && v !== '') u.searchParams.set(k, String(v));
        });
        return u.pathname + u.search;
    }, [query]);

    const reload = (params: Record<string, string | number | undefined>) =>
        router.get(route('admin.payroll.reports.income-tax-statement'), { ...query, ...params }, { preserveState: true, preserveScroll: true });

    const form = useForm({
        year,
        month: report_month,
        form_type,
        location_id,
        data: overrides,
    });

    const saveOverrides = () => {
        form.put(route('admin.payroll.reports.income-tax-statement.overrides'), {
            preserveScroll: true,
            onSuccess: () => reload({}),
        });
    };

    const patchRow = (key: 'daily_worker' | 'retirement' | 'professional_fee', field: keyof RowData, value: string | number) => {
        form.setData('data', {
            ...form.data.data,
            [key]: { ...form.data.data[key], [field]: value },
        });
    };

    const hasData = result.total.count > 0;

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">所得税徴収高計算書</h2>}>
            <Head title="所得税徴収高計算書" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto flex max-w-6xl flex-col gap-4 lg:flex-row">
                    <aside className="w-full shrink-0 space-y-3 lg:w-56">
                        <Link href={route('admin.payroll.reports.index')}
                            className="inline-flex items-center gap-2 text-sm text-gray-500 transition hover:text-teal-700">
                            <i className="fa-solid fa-arrow-left" /> 帳票一覧へ戻る
                        </Link>

                        <div className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                            <label className="mb-1 block text-xs font-medium text-gray-500">帳票種別</label>
                            <div className="mb-3 flex rounded-lg border border-gray-200 p-0.5">
                                <button type="button"
                                    onClick={() => reload({ mode: undefined })}
                                    className={`flex-1 rounded-md px-2 py-1.5 text-xs font-medium transition ${mode === 'normal' ? 'bg-gray-800 text-white' : 'text-gray-600 hover:bg-gray-50'}`}>
                                    一般分
                                </button>
                                <button type="button"
                                    onClick={() => reload({ mode: 'special' })}
                                    className={`flex-1 rounded-md px-2 py-1.5 text-xs font-medium transition ${mode === 'special' ? 'bg-gray-800 text-white' : 'text-gray-600 hover:bg-gray-50'}`}>
                                    納期特例
                                </button>
                            </div>

                            <label className="mb-1 block text-xs font-medium text-gray-500">年</label>
                            <select value={year} onChange={(e) => reload({ year: e.target.value })}
                                className="mb-3 w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                {options.years.map((y) => <option key={y} value={y}>{y}年</option>)}
                            </select>

                            {mode === 'special' ? (
                                <>
                                    <label className="mb-1 block text-xs font-medium text-gray-500">期間</label>
                                    <select value={half} onChange={(e) => reload({ half: e.target.value })}
                                        className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                        <option value="first">1月〜6月分</option>
                                        <option value="second">7月〜12月分</option>
                                    </select>
                                </>
                            ) : (
                                <>
                                    <label className="mb-1 block text-xs font-medium text-gray-500">月</label>
                                    <select value={month} onChange={(e) => reload({ month: e.target.value })}
                                        className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                        {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
                                            <option key={m} value={m}>{m}月</option>
                                        ))}
                                    </select>
                                </>
                            )}

                            <p className="mt-3 text-[11px] leading-relaxed text-gray-400">{periodLabel}</p>
                            {!hasData && (
                                <p className="mt-2 rounded-lg bg-amber-50 px-2 py-1.5 text-[11px] text-amber-700">
                                    確定済みの給与・賞与がありません。
                                </p>
                            )}
                        </div>

                        <div className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                            <button type="button" onClick={() => setManualOpen(!manualOpen)}
                                className="flex w-full items-center justify-between text-sm font-semibold text-gray-700">
                                手入力項目
                                <i className={`fa-solid fa-chevron-${manualOpen ? 'up' : 'down'} text-xs text-gray-400`} />
                            </button>

                            {manualOpen && (
                                <div className="mt-3 space-y-4 border-t border-gray-100 pt-3">
                                    {(['daily_worker', 'retirement', 'professional_fee'] as const).map((key) => {
                                        const labels = { daily_worker: '日雇労務者', retirement: '退職手当等', professional_fee: '税理士等の報酬' };
                                        const row = form.data.data[key] ?? emptyRow();
                                        return (
                                            <div key={key} className="space-y-1.5">
                                                <p className="text-[11px] font-medium text-gray-600">{labels[key]}</p>
                                                <div className="grid grid-cols-2 gap-1.5">
                                                    <input type="number" min={0} placeholder="人員"
                                                        value={row.employee_count || ''}
                                                        onChange={(e) => patchRow(key, 'employee_count', Number(e.target.value) || 0)}
                                                        className="rounded border-gray-300 text-xs" />
                                                    <input type="number" min={0} placeholder="税額"
                                                        value={row.tax_amount || ''}
                                                        onChange={(e) => patchRow(key, 'tax_amount', Number(e.target.value) || 0)}
                                                        className="rounded border-gray-300 text-xs" />
                                                    <input type="number" min={0} placeholder="支給額"
                                                        value={row.payment_amount || ''}
                                                        onChange={(e) => patchRow(key, 'payment_amount', Number(e.target.value) || 0)}
                                                        className="col-span-2 rounded border-gray-300 text-xs" />
                                                </div>
                                            </div>
                                        );
                                    })}

                                    <div className="grid grid-cols-2 gap-1.5">
                                        <div>
                                            <label className="text-[10px] text-gray-500">年調不足税額</label>
                                            <input type="number" min={0}
                                                value={form.data.data.year_end_adjustment_shortage || ''}
                                                onChange={(e) => form.setData('data', { ...form.data.data, year_end_adjustment_shortage: Number(e.target.value) || 0 })}
                                                className="w-full rounded border-gray-300 text-xs" />
                                        </div>
                                        <div>
                                            <label className="text-[10px] text-gray-500">年調超過税額</label>
                                            <input type="number" min={0}
                                                value={form.data.data.year_end_adjustment_overpayment || ''}
                                                onChange={(e) => form.setData('data', { ...form.data.data, year_end_adjustment_overpayment: Number(e.target.value) || 0 })}
                                                className="w-full rounded border-gray-300 text-xs" />
                                        </div>
                                    </div>

                                    <div>
                                        <label className="text-[10px] text-gray-500">延滞税</label>
                                        <input type="number" min={0}
                                            value={form.data.data.late_payment_tax || ''}
                                            onChange={(e) => form.setData('data', { ...form.data.data, late_payment_tax: Number(e.target.value) || 0 })}
                                            className="w-full rounded border-gray-300 text-xs" />
                                    </div>

                                    <div>
                                        <label className="text-[10px] text-gray-500">摘要</label>
                                        <textarea rows={3}
                                            value={form.data.data.remarks}
                                            onChange={(e) => form.setData('data', { ...form.data.data, remarks: e.target.value })}
                                            className="w-full rounded border-gray-300 text-xs" />
                                    </div>

                                    <button type="button" onClick={saveOverrides} disabled={form.processing}
                                        className="w-full rounded-lg bg-gray-800 px-3 py-2 text-xs font-semibold text-white transition hover:bg-gray-700 disabled:opacity-50">
                                        手入力を保存
                                    </button>
                                </div>
                            )}
                        </div>
                    </aside>

                    <div className="min-w-0 flex-1">
                        <div className="mx-auto w-full min-w-3xl max-w-3xl overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-3">
                                <h3 className="text-base font-bold text-gray-800">所得税徴収高計算書</h3>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            const w = window.open(previewUrl, '_blank');
                                            w?.addEventListener('load', () => w.print());
                                        }}
                                        className="inline-flex items-center rounded-lg border border-stone-300 bg-stone-100 px-4 py-2 text-sm font-semibold text-stone-800 transition hover:bg-stone-200"
                                    >
                                        印刷
                                    </button>
                                    <a
                                        href={pdfUrl}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center rounded-lg border border-stone-300 bg-stone-100 px-4 py-2 text-sm font-semibold text-stone-800 transition hover:bg-stone-200"
                                    >
                                        PDF
                                    </a>
                                </div>
                            </div>

                            <div className="bg-stone-50 p-5">
                                <div className="mx-auto min-w-182 max-w-182 overflow-x-auto">
                                    <IncomeTaxStatementForm
                                        backgroundUrl={background_url}
                                        form={statementForm}
                                        report={report}
                                        year={year}
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
