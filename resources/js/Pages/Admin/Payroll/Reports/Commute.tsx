import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link } from '@inertiajs/react';

interface Row {
    employee_no: string | null;
    name: string;
    business_location: string | null;
    taxable: number;
    non_taxable: number;
    monthly_total: number;
    annual_total: number;
}

interface Props {
    year: number;
    rows: Row[];
    options: { businessLocations: { id: number; name: string }[] };
}

const yen = (v: number) => (v || 0).toLocaleString();

export default function Commute({ year, rows }: Props) {
    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">通勤手当一覧（交通用具）</h2>}>
            <Head title="通勤手当一覧" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-4xl space-y-5">
                    <div className="flex items-center justify-between gap-3">
                        <Link href={route('admin.payroll.reports.index')}
                            className="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-800">
                            <i className="fa-solid fa-arrow-left" /> 帳票一覧へ戻る
                        </Link>
                        <a href={route('admin.payroll.reports.commute.csv')}
                            className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700">
                            <i className="fa-solid fa-file-csv" /> CSVダウンロード
                        </a>
                    </div>

                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">従業員番号</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">従業員</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">事業所</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">課税(月額)</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">非課税(月額)</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">月額計</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">年額計</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {rows.map((r, i) => (
                                        <tr key={i} className="hover:bg-gray-50">
                                            <td className="px-4 py-2.5 text-sm text-gray-500">{r.employee_no ?? '—'}</td>
                                            <td className="px-4 py-2.5 text-sm font-medium text-gray-800">{r.name}</td>
                                            <td className="px-4 py-2.5 text-sm text-gray-600">{r.business_location ?? '—'}</td>
                                            <td className="px-4 py-2.5 text-right text-sm tabular-nums text-gray-600">{yen(r.taxable)}</td>
                                            <td className="px-4 py-2.5 text-right text-sm tabular-nums text-gray-600">{yen(r.non_taxable)}</td>
                                            <td className="px-4 py-2.5 text-right text-sm tabular-nums text-gray-700">{yen(r.monthly_total)}</td>
                                            <td className="px-4 py-2.5 text-right text-sm tabular-nums text-gray-700">{yen(r.annual_total)}</td>
                                        </tr>
                                    ))}
                                    {rows.length === 0 && (
                                        <tr><td colSpan={7} className="px-6 py-12 text-center text-sm text-gray-400">通勤手当が設定された従業員がいません。</td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <p className="text-xs text-gray-400">※ {year}年分。金額は従業員給与情報の通勤手当設定（月額）に基づく年額換算です。</p>
                </div>
            </div>
        </AdminLayout>
    );
}
