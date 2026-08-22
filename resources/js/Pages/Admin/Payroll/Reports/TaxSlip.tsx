import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router } from '@inertiajs/react';

interface Row {
    id: number;
    name: string;
    employee_no: string | null;
    business_location: string | null;
}

interface Props {
    year: number;
    rows: Row[];
    options: { years: number[] };
}

export default function TaxSlip({ year, rows, options }: Props) {
    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">退職者の源泉徴収票</h2>}>
            <Head title="退職者の源泉徴収票" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-3xl space-y-5">
                    <div className="flex flex-wrap items-center gap-3">
                        <Link href={route('admin.payroll.reports.index')}
                            className="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-800">
                            <i className="fa-solid fa-arrow-left" /> 帳票一覧へ戻る
                        </Link>
                        <select value={year} onChange={(e) => router.get(route('admin.payroll.reports.tax-slip'), { year: e.target.value }, { preserveScroll: true })}
                            className="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            {options.years.map((y) => <option key={y} value={y}>{y}年分</option>)}
                        </select>
                        <Link href={route('admin.payroll.report-exports.index')}
                            className="ml-auto inline-flex items-center gap-2 rounded-lg bg-teal-50 px-3 py-1.5 text-sm font-semibold text-teal-700 transition hover:bg-teal-100">
                            <i className="fa-solid fa-layer-group" /> PDF一括作成
                        </Link>
                    </div>

                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">従業員番号</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">氏名</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">事業所</th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">操作</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {rows.map((r) => (
                                    <tr key={r.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-2.5 text-sm text-gray-500">{r.employee_no ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-sm font-medium text-gray-800">{r.name}</td>
                                        <td className="px-4 py-2.5 text-sm text-gray-600">{r.business_location ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-right">
                                            <a href={route('admin.payroll.reports.tax-slip.pdf', { user: r.id, year })} target="_blank" rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1.5 text-sm font-semibold text-teal-700 hover:text-teal-800">
                                                <i className="fa-solid fa-file-pdf" /> 源泉徴収票
                                            </a>
                                        </td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <tr><td colSpan={4} className="px-6 py-12 text-center text-sm text-gray-400">退職者がいません。</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
