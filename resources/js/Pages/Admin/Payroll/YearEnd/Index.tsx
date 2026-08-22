import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router } from '@inertiajs/react';

interface Row {
    id: number;
    name: string;
    employee_no: string | null;
    tax_table: string;
    gross_total: number;
    withheld_tax: number;
    has_record: boolean;
    status: string | null;
    status_label: string | null;
    adjustment_amount: number | null;
}

interface Props {
    year: number;
    rows: Row[];
    options: { years: number[] };
}

const yen = (v: number | null) => (v ?? 0).toLocaleString();

const STATUS_BADGE: Record<string, string> = {
    draft: 'bg-gray-100 text-gray-600',
    confirmed: 'bg-blue-100 text-blue-700',
    reflected: 'bg-green-100 text-green-700',
};

export default function YearEndIndex({ year, rows, options }: Props) {
    const adjustmentLabel = (row: Row) => {
        if (!row.has_record || row.adjustment_amount === null) return '—';
        const v = row.adjustment_amount;
        if (v > 0) return <span className="text-red-600">不足 {yen(v)}円</span>;
        if (v < 0) return <span className="text-teal-700">還付 {yen(-v)}円</span>;
        return <span className="text-gray-500">過不足なし</span>;
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">年末調整</h2>}>
            <Head title="年末調整" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-5xl space-y-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Link href={route('admin.payroll.reports.index')}
                            className="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-800">
                            <i className="fa-solid fa-arrow-left" /> 帳票一覧へ戻る
                        </Link>
                        <select value={year} onChange={(e) => router.get(route('admin.payroll.year-end.index'), { year: e.target.value }, { preserveScroll: true })}
                            className="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            {options.years.map((y) => <option key={y} value={y}>{y}年分</option>)}
                        </select>
                    </div>

                    <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        <i className="fa-solid fa-circle-info mr-1.5" />
                        確定済みの給与・賞与から年間の給与総額・源泉所得税・社会保険料を集計します。各従業員の申告控除を入力すると年調年税額・過不足税額を計算し、12月給与などのバッチへ「年調過不足税額」として反映できます。
                    </div>

                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">従業員番号</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">氏名</th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">年間給与総額</th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">徴収済所得税</th>
                                    <th className="px-4 py-3 text-center text-xs font-semibold text-gray-500">過不足</th>
                                    <th className="px-4 py-3 text-center text-xs font-semibold text-gray-500">状態</th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">操作</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {rows.map((r) => (
                                    <tr key={r.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-2.5 text-sm text-gray-500">{r.employee_no ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-sm font-medium text-gray-800">
                                            {r.name}
                                            {r.tax_table !== 'kou' && <span className="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500">乙欄</span>}
                                        </td>
                                        <td className="px-4 py-2.5 text-right text-sm tabular-nums text-gray-700">{yen(r.gross_total)}</td>
                                        <td className="px-4 py-2.5 text-right text-sm tabular-nums text-gray-700">{yen(r.withheld_tax)}</td>
                                        <td className="px-4 py-2.5 text-center text-sm tabular-nums">{adjustmentLabel(r)}</td>
                                        <td className="px-4 py-2.5 text-center">
                                            {r.status ? (
                                                <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_BADGE[r.status] ?? 'bg-gray-100 text-gray-600'}`}>{r.status_label}</span>
                                            ) : (
                                                <span className="text-xs text-gray-400">未計算</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5 text-right">
                                            <Link href={route('admin.payroll.year-end.edit', { user: r.id, year })}
                                                className="inline-flex items-center gap-1.5 text-sm font-semibold text-teal-700 hover:text-teal-800">
                                                <i className="fa-solid fa-pen-to-square" /> {r.has_record ? '編集' : '計算する'}
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <tr><td colSpan={7} className="px-6 py-12 text-center text-sm text-gray-400">従業員がいません。</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
