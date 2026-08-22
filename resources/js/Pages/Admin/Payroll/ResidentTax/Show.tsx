import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link } from '@inertiajs/react';

interface Group {
    municipality: string;
    designation_number: string | null;
    count: number;
    amount: number;
}

interface Props {
    run: {
        id: number;
        period_key: string;
        business_location: string | null;
        payment_date: string | null;
    };
    groups: Group[];
    total: number;
}

const yen = (v: number) => `¥${v.toLocaleString()}`;

export default function ResidentTaxShow({ run, groups, total }: Props) {
    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">住民税徴収額一覧表</h2>}>
            <Head title={`住民税徴収額一覧表 ${run.period_key}`} />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-3xl space-y-5">
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                        <div className="flex items-center gap-3">
                            <Link href={route('admin.payroll.runs.show', run.id)}
                                className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition hover:bg-gray-50">
                                <i className="fa-solid fa-arrow-left" />
                            </Link>
                            <div>
                                <div className="text-lg font-bold text-gray-900">{run.period_key}</div>
                                <p className="text-xs text-gray-400">{run.business_location ?? '全事業所'}</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <a href={route('admin.payroll.resident-tax.pdf', run.id)} target="_blank" rel="noopener noreferrer"
                                className="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                                <i className="fa-solid fa-file-pdf" />
                                PDF
                            </a>
                            <a href={route('admin.payroll.resident-tax.csv', run.id)}
                                className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700">
                                <i className="fa-solid fa-file-csv" />
                                CSV出力
                            </a>
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">市区町村</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">指定番号</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">人数</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">納付額</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {groups.map((g, i) => (
                                        <tr key={i} className="hover:bg-gray-50">
                                            <td className="px-4 py-2.5 text-sm font-medium text-gray-800">{g.municipality}</td>
                                            <td className="px-4 py-2.5 text-sm text-gray-600">{g.designation_number || <span className="text-amber-500">未登録</span>}</td>
                                            <td className="px-4 py-2.5 text-right text-sm tabular-nums text-gray-600">{g.count}</td>
                                            <td className="px-4 py-2.5 text-right text-sm tabular-nums text-gray-700">{yen(g.amount)}</td>
                                        </tr>
                                    ))}
                                    {groups.length === 0 && (
                                        <tr><td colSpan={4} className="px-6 py-12 text-center text-sm text-gray-400">住民税の控除がある明細がありません。</td></tr>
                                    )}
                                    {groups.length > 0 && (
                                        <tr className="bg-gray-50 font-bold">
                                            <td className="px-4 py-3 text-sm text-gray-800" colSpan={3}>合計</td>
                                            <td className="px-4 py-3 text-right text-sm tabular-nums text-teal-700">{yen(total)}</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
