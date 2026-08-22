import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router } from '@inertiajs/react';

interface Row {
    employee_no: string | null;
    name: string;
    dependents: number;
    target_count: number;
    total_reduction: number;
    applied: number;
    remaining: number;
}

interface Props {
    year: number;
    perPerson: number;
    measureConfigured: boolean;
    rows: Row[];
    options: { years: number[] };
}

const yen = (v: number) => (v || 0).toLocaleString();

export default function FlatTax({ year, perPerson, measureConfigured, rows, options }: Props) {
    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">定額減税 各人別控除事績簿</h2>}>
            <Head title="各人別控除事績簿" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-4xl space-y-5">
                    <div className="flex flex-wrap items-center gap-3">
                        <Link href={route('admin.payroll.reports.index')}
                            className="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-800">
                            <i className="fa-solid fa-arrow-left" /> 帳票一覧へ戻る
                        </Link>
                        <select value={year} onChange={(e) => router.get(route('admin.payroll.reports.flat-tax'), { year: e.target.value }, { preserveScroll: true })}
                            className="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            {options.years.map((y) => <option key={y} value={y}>令和{y - 2018}年分</option>)}
                        </select>
                        <Link href={route('admin.payroll.tax-measures.index')}
                            className="ml-auto inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                            <i className="fa-solid fa-sliders" /> 制度マスタ
                        </Link>
                        <a href={route('admin.payroll.reports.flat-tax.csv', { year })}
                            className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700">
                            <i className="fa-solid fa-file-csv" /> CSVダウンロード
                        </a>
                    </div>

                    {!measureConfigured && (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            <i className="fa-solid fa-triangle-exclamation mr-1.5" />
                            この年分の定額減税は税制措置マスタに未登録です。
                            <Link href={route('admin.payroll.tax-measures.index')} className="ml-1 font-semibold underline">制度マスタ</Link>
                            で適用期間・金額を登録すると、給与計算に自動適用されます。
                        </div>
                    )}

                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">従業員番号</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">従業員</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">扶養人数</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">減税対象人数</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">減税総額</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">控除済累計</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">控除残額</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {rows.map((r, i) => (
                                        <tr key={i} className="hover:bg-gray-50">
                                            <td className="px-4 py-2.5 text-sm text-gray-500">{r.employee_no ?? '—'}</td>
                                            <td className="px-4 py-2.5 text-sm font-medium text-gray-800">{r.name}</td>
                                            <td className="px-4 py-2.5 text-right text-sm tabular-nums text-gray-600">{r.dependents}</td>
                                            <td className="px-4 py-2.5 text-right text-sm tabular-nums text-gray-600">{r.target_count}</td>
                                            <td className="px-4 py-2.5 text-right text-sm tabular-nums text-gray-700">{yen(r.total_reduction)}</td>
                                            <td className="px-4 py-2.5 text-right text-sm tabular-nums text-gray-600">{yen(r.applied)}</td>
                                            <td className="px-4 py-2.5 text-right text-sm tabular-nums font-semibold text-teal-700">{yen(r.remaining)}</td>
                                        </tr>
                                    ))}
                                    {rows.length === 0 && (
                                        <tr><td colSpan={7} className="px-6 py-12 text-center text-sm text-gray-400">対象従業員がいません。</td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <p className="text-xs text-gray-400">
                        ※ 定額減税は本人＋同一生計配偶者・扶養親族1人につき {yen(perPerson)} 円（所得税）。控除済累計は給与計算結果に計上された定額減税額の合計です。
                    </p>
                </div>
            </div>
        </AdminLayout>
    );
}
