import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface Employee {
    id: number;
    name: string;
    employee_no: string | null;
    is_active: boolean;
}

interface SalaryRow {
    month: number;
    payment_date: string | null;
    gross: number;
    social: number;
    after_social: number;
    dependents: number;
    tax: number;
}

interface BonusRow {
    period: string;
    payment_date: string | null;
    gross: number;
    social: number;
    after_social: number;
    tax: number;
    rate: number;
}

interface Book {
    employee: {
        name: string | null;
        employee_no: string | null;
        tax_table_label: string;
        dependents: number;
        business_location: string | null;
        department: string | null;
    };
    salary: { rows: SalaryRow[]; totals: { gross: number; social: number; after: number; tax: number } };
    bonus: { rows: BonusRow[]; totals: { gross: number; social: number; after: number; tax: number } };
}

interface Props {
    year: number;
    selectedUserId: number | null;
    employees: Employee[];
    book: Book | null;
    options: { years: number[]; businessLocations: { id: number; name: string }[] };
}

const yen = (v: number) => (v || 0).toLocaleString();

export default function WithholdingBook({ year, selectedUserId, employees, book, options }: Props) {
    const [search, setSearch] = useState('');
    const filtered = useMemo(
        () => employees.filter((e) => `${e.employee_no ?? ''} ${e.name}`.toLowerCase().includes(search.toLowerCase())),
        [employees, search],
    );

    const reload = (params: Record<string, string | number | undefined>) =>
        router.get(route('admin.payroll.reports.withholding-book'), { year, user: selectedUserId ?? undefined, ...params }, { preserveState: true, preserveScroll: true });

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">源泉徴収簿</h2>}>
            <Head title="源泉徴収簿" />

            <div className="px-4 py-6 sm:p-6">
                <div className="flex items-center gap-3 pb-4">
                    <Link href={route('admin.payroll.reports.index')}
                        className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition hover:bg-gray-50">
                        <i className="fa-solid fa-arrow-left" />
                    </Link>
                    <select value={year} onChange={(e) => reload({ year: e.target.value })}
                        className="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        {options.years.map((y) => <option key={y} value={y}>{y}年分</option>)}
                    </select>
                    <Link href={route('admin.payroll.report-exports.index')}
                        className="ml-auto inline-flex items-center gap-2 rounded-lg border border-teal-600 px-4 py-2 text-sm font-semibold text-teal-700 transition hover:bg-teal-50">
                        <i className="fa-solid fa-layer-group" /> PDF一括作成
                    </Link>
                    {selectedUserId && (
                        <a href={route('admin.payroll.reports.withholding-book.pdf', { user: selectedUserId, year })} target="_blank" rel="noopener noreferrer"
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

                    <div className="space-y-4">
                        {book ? (
                            <>
                                <div className="flex flex-wrap items-center gap-3 rounded-2xl bg-white p-4 text-sm shadow-sm ring-1 ring-gray-100">
                                    <span className="text-lg font-bold text-gray-900">{book.employee.name}</span>
                                    <span className="rounded-full border border-teal-600 px-2.5 py-0.5 text-xs font-semibold text-teal-700">{book.employee.tax_table_label}</span>
                                    <span className="text-gray-400">扶養親族等の数 {book.employee.dependents}</span>
                                    {book.employee.business_location && <span className="text-gray-400">{book.employee.business_location}</span>}
                                </div>

                                <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                                    <div className="border-b border-gray-100 px-4 py-2.5 text-sm font-bold text-gray-700">給料・手当等</div>
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full text-xs">
                                            <thead className="bg-gray-50">
                                                <tr>
                                                    <th className="px-3 py-2 text-center font-semibold text-gray-500">月</th>
                                                    <th className="px-3 py-2 text-center font-semibold text-gray-500">支給月日</th>
                                                    <th className="px-3 py-2 text-right font-semibold text-gray-500">総支給金額</th>
                                                    <th className="px-3 py-2 text-right font-semibold text-gray-500">社会保険料等</th>
                                                    <th className="px-3 py-2 text-right font-semibold text-gray-500">社保控除後</th>
                                                    <th className="px-3 py-2 text-center font-semibold text-gray-500">扶養数</th>
                                                    <th className="px-3 py-2 text-right font-semibold text-gray-500">算出税額</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-50">
                                                {book.salary.rows.map((r) => (
                                                    <tr key={r.month} className="hover:bg-gray-50">
                                                        <td className="px-3 py-1.5 text-center text-gray-700">{r.month}</td>
                                                        <td className="px-3 py-1.5 text-center text-gray-500">{r.payment_date ?? ''}</td>
                                                        <td className="px-3 py-1.5 text-right tabular-nums text-gray-600">{yen(r.gross)}</td>
                                                        <td className="px-3 py-1.5 text-right tabular-nums text-gray-600">{yen(r.social)}</td>
                                                        <td className="px-3 py-1.5 text-right tabular-nums text-gray-600">{yen(r.after_social)}</td>
                                                        <td className="px-3 py-1.5 text-center text-gray-500">{r.dependents}</td>
                                                        <td className="px-3 py-1.5 text-right tabular-nums text-gray-600">{yen(r.tax)}</td>
                                                    </tr>
                                                ))}
                                                <tr className="bg-gray-100 font-bold">
                                                    <td className="px-3 py-2 text-center text-gray-800" colSpan={2}>計</td>
                                                    <td className="px-3 py-2 text-right tabular-nums text-gray-800">{yen(book.salary.totals.gross)}</td>
                                                    <td className="px-3 py-2 text-right tabular-nums text-gray-800">{yen(book.salary.totals.social)}</td>
                                                    <td className="px-3 py-2 text-right tabular-nums text-gray-800">{yen(book.salary.totals.after)}</td>
                                                    <td />
                                                    <td className="px-3 py-2 text-right tabular-nums text-gray-800">{yen(book.salary.totals.tax)}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                                    <div className="border-b border-gray-100 px-4 py-2.5 text-sm font-bold text-gray-700">賞与等</div>
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full text-xs">
                                            <thead className="bg-gray-50">
                                                <tr>
                                                    <th className="px-3 py-2 text-center font-semibold text-gray-500">支給期間</th>
                                                    <th className="px-3 py-2 text-center font-semibold text-gray-500">支給月日</th>
                                                    <th className="px-3 py-2 text-right font-semibold text-gray-500">総支給金額</th>
                                                    <th className="px-3 py-2 text-right font-semibold text-gray-500">社会保険料等</th>
                                                    <th className="px-3 py-2 text-right font-semibold text-gray-500">社保控除後</th>
                                                    <th className="px-3 py-2 text-center font-semibold text-gray-500">税率(%)</th>
                                                    <th className="px-3 py-2 text-right font-semibold text-gray-500">算出税額</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-50">
                                                {book.bonus.rows.map((r) => (
                                                    <tr key={r.period} className="hover:bg-gray-50">
                                                        <td className="px-3 py-1.5 text-center text-gray-700">{r.period}</td>
                                                        <td className="px-3 py-1.5 text-center text-gray-500">{r.payment_date ?? ''}</td>
                                                        <td className="px-3 py-1.5 text-right tabular-nums text-gray-600">{yen(r.gross)}</td>
                                                        <td className="px-3 py-1.5 text-right tabular-nums text-gray-600">{yen(r.social)}</td>
                                                        <td className="px-3 py-1.5 text-right tabular-nums text-gray-600">{yen(r.after_social)}</td>
                                                        <td className="px-3 py-1.5 text-center text-gray-500">{r.rate.toFixed(2)}</td>
                                                        <td className="px-3 py-1.5 text-right tabular-nums text-gray-600">{yen(r.tax)}</td>
                                                    </tr>
                                                ))}
                                                {book.bonus.rows.length === 0 && (
                                                    <tr><td colSpan={7} className="px-3 py-6 text-center text-gray-400">賞与の支給はありません</td></tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <p className="rounded-xl bg-gray-50 px-4 py-3 text-xs text-gray-400">
                                    ※ 源泉徴収簿の左側（月次実績）のみを自動反映しています。右側（各種控除額・年末調整①〜㉞）は年末調整確定後に別途記入してください。
                                </p>
                            </>
                        ) : (
                            <div className="rounded-2xl bg-white p-12 text-center text-sm text-gray-400 shadow-sm ring-1 ring-gray-100">従業員を選択してください。</div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
