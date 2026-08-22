import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router } from '@inertiajs/react';

interface Bucket {
    count: number;
    amount: number;
    tax: number;
}

interface Props {
    mode: 'normal' | 'special';
    year: number;
    month: number;
    half: string;
    periodLabel: string;
    result: { salary: Bucket; bonus: Bucket; total: Bucket };
    options: { years: number[]; businessLocations: { id: number; name: string }[] };
}

const yen = (v: number) => `¥${(v || 0).toLocaleString()}`;

export default function IncomeTaxStatement({ mode, year, month, half, periodLabel, result, options }: Props) {
    const reload = (params: Record<string, string | number | undefined>) =>
        router.get(route('admin.payroll.reports.income-tax-statement'), { mode: mode === 'special' ? 'special' : undefined, year, month, half, ...params }, { preserveState: true, preserveScroll: true });

    const rows = [
        { label: '俸給・給料等', b: result.salary },
        { label: '賞与（役員賞与を除く）', b: result.bonus },
    ];

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">所得税徴収高計算書{mode === 'special' ? '（納特）' : ''}</h2>}>
            <Head title="所得税徴収高計算書" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-3xl space-y-5">
                    <div className="flex flex-wrap items-center gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                        <Link href={route('admin.payroll.reports.index')}
                            className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition hover:bg-gray-50">
                            <i className="fa-solid fa-arrow-left" />
                        </Link>
                        <select value={year} onChange={(e) => reload({ year: e.target.value })}
                            className="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            {options.years.map((y) => <option key={y} value={y}>{y}年</option>)}
                        </select>
                        {mode === 'special' ? (
                            <select value={half} onChange={(e) => reload({ half: e.target.value })}
                                className="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                <option value="first">1月〜6月分</option>
                                <option value="second">7月〜12月分</option>
                            </select>
                        ) : (
                            <select value={month} onChange={(e) => reload({ month: e.target.value })}
                                className="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => <option key={m} value={m}>{m}月分</option>)}
                            </select>
                        )}
                        <a href={route('admin.payroll.reports.income-tax-statement.pdf', { mode: mode === 'special' ? 'special' : undefined, year, month, half })} target="_blank" rel="noopener noreferrer"
                            className="ml-auto inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                            <i className="fa-solid fa-file-pdf" /> PDF
                        </a>
                    </div>

                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div className="border-b border-gray-100 px-5 py-3 text-sm font-bold text-gray-700">対象期間: {periodLabel}</div>
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-5 py-3 text-left text-xs font-semibold text-gray-500">区分</th>
                                    <th className="px-5 py-3 text-right text-xs font-semibold text-gray-500">支給人員</th>
                                    <th className="px-5 py-3 text-right text-xs font-semibold text-gray-500">支給額</th>
                                    <th className="px-5 py-3 text-right text-xs font-semibold text-gray-500">税額</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {rows.map((r) => (
                                    <tr key={r.label} className="hover:bg-gray-50">
                                        <td className="px-5 py-3 text-sm font-medium text-gray-800">{r.label}</td>
                                        <td className="px-5 py-3 text-right text-sm tabular-nums text-gray-600">{r.b.count} 人</td>
                                        <td className="px-5 py-3 text-right text-sm tabular-nums text-gray-600">{yen(r.b.amount)}</td>
                                        <td className="px-5 py-3 text-right text-sm tabular-nums text-gray-600">{yen(r.b.tax)}</td>
                                    </tr>
                                ))}
                                <tr className="bg-gray-100 font-bold">
                                    <td className="px-5 py-3 text-sm text-gray-800">合計（本税）</td>
                                    <td className="px-5 py-3 text-right text-sm tabular-nums text-gray-800">{result.total.count} 人</td>
                                    <td className="px-5 py-3 text-right text-sm tabular-nums text-gray-800">{yen(result.total.amount)}</td>
                                    <td className="px-5 py-3 text-right text-sm tabular-nums text-teal-700">{yen(result.total.tax)}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
