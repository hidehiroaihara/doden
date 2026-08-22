import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useMemo, useState } from 'react';

interface Employee {
    id: number;
    name: string;
    employee_no: string | null;
    is_active: boolean;
}

interface Row {
    name: string;
    is_time: boolean;
    values: Record<number, number>;
    total: number;
}

interface Section {
    type: string;
    title: string;
    rows: Row[];
}

interface Props {
    year: number;
    selectedUserId: number | null;
    employees: Employee[];
    matrix: { months: string[]; sections: Section[] } | null;
    options: { years: number[]; businessLocations: { id: number; name: string }[] };
}

const fmt = (v: number, isTime: boolean) => (isTime ? (v || 0).toFixed(1) : (v || 0).toLocaleString());

export default function WageLedger({ year, selectedUserId, employees, matrix, options }: Props) {
    const [search, setSearch] = useState('');
    const filtered = useMemo(
        () => employees.filter((e) => `${e.employee_no ?? ''} ${e.name}`.toLowerCase().includes(search.toLowerCase())),
        [employees, search],
    );

    const reload = (params: Record<string, string | number | undefined>) =>
        router.get(route('admin.payroll.reports.wage-ledger'), { year, user: selectedUserId ?? undefined, ...params }, { preserveState: true, preserveScroll: true });

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">賃金台帳</h2>}>
            <Head title="賃金台帳" />

            <div className="px-4 py-6 sm:p-6">
                <div className="flex items-center gap-3 pb-4">
                    <Link href={route('admin.payroll.reports.index')}
                        className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition hover:bg-gray-50">
                        <i className="fa-solid fa-arrow-left" />
                    </Link>
                    <select value={year} onChange={(e) => reload({ year: e.target.value })}
                        className="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        {options.years.map((y) => <option key={y} value={y}>{y}年</option>)}
                    </select>
                    <Link href={route('admin.payroll.report-exports.index')}
                        className="ml-auto inline-flex items-center gap-2 rounded-lg border border-teal-600 px-4 py-2 text-sm font-semibold text-teal-700 transition hover:bg-teal-50">
                        <i className="fa-solid fa-layer-group" /> CSV一括作成
                    </Link>
                    {selectedUserId && (
                        <a href={route('admin.payroll.reports.wage-ledger.pdf', { user: selectedUserId, year })} target="_blank" rel="noopener noreferrer"
                            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                            <i className="fa-solid fa-file-pdf" /> PDF
                        </a>
                    )}
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-[280px_1fr]">
                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div className="border-b border-gray-100 p-3">
                            <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="従業員番号 / 氏名"
                                className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                        </div>
                        <ul className="max-h-150 divide-y divide-gray-50 overflow-y-auto">
                            {filtered.map((e) => (
                                <li key={e.id}>
                                    <button onClick={() => reload({ user: e.id })}
                                        className={`flex w-full items-center justify-between px-4 py-2.5 text-left text-sm transition hover:bg-gray-50 ${selectedUserId === e.id ? 'bg-teal-50 font-semibold text-teal-700' : 'text-gray-700'}`}>
                                        <span>{e.name}</span>
                                        {!e.is_active && <span className="text-xs text-gray-400">退職</span>}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        {matrix ? (
                            <div className="overflow-x-auto">
                                <table className="min-w-full border-collapse text-xs">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="sticky left-0 z-10 bg-gray-50 px-3 py-2 text-left font-semibold text-gray-500">項目</th>
                                            {matrix.months.map((m, i) => (
                                                <th key={m} className="px-2 py-2 text-right font-semibold text-gray-500">{i + 1}月度</th>
                                            ))}
                                            <th className="px-2 py-2 text-right font-semibold text-gray-500">合計</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-50">
                                        {matrix.sections.map((section) => (
                                            <Fragment key={section.type}>
                                                <tr className="bg-teal-50/60">
                                                    <td colSpan={14} className="px-3 py-1.5 text-left font-bold text-teal-700">{section.title}</td>
                                                </tr>
                                                {section.rows.map((row, ri) => (
                                                    <tr key={`${section.type}-${ri}`} className="hover:bg-gray-50">
                                                        <td className="sticky left-0 z-10 whitespace-nowrap bg-white px-3 py-1.5 font-medium text-gray-700">{row.name}</td>
                                                        {matrix.months.map((_, mi) => (
                                                            <td key={mi} className="px-2 py-1.5 text-right tabular-nums text-gray-600">{fmt(row.values[mi + 1], row.is_time)}</td>
                                                        ))}
                                                        <td className="px-2 py-1.5 text-right font-semibold tabular-nums text-gray-800">{fmt(row.total, row.is_time)}</td>
                                                    </tr>
                                                ))}
                                            </Fragment>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div className="p-12 text-center text-sm text-gray-400">従業員を選択してください。</div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
