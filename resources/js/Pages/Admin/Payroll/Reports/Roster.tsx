import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router } from '@inertiajs/react';

interface Row {
    id: number;
    name: string;
    employee_no: string | null;
    business_location: string | null;
    employment_type: string;
    pay_type: string;
    status: string;
}

interface Props {
    includeRetired: boolean;
    rows: Row[];
}

export default function Roster({ includeRetired, rows }: Props) {
    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">労働者名簿</h2>}>
            <Head title="労働者名簿" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-5xl space-y-5">
                    <div className="flex items-center justify-between gap-3">
                        <Link href={route('admin.payroll.reports.index')}
                            className="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-800">
                            <i className="fa-solid fa-arrow-left" /> 帳票一覧へ戻る
                        </Link>
                        <div className="flex items-center gap-4">
                            <label className="inline-flex items-center gap-2 text-sm text-gray-600">
                                <input type="checkbox" checked={includeRetired}
                                    onChange={(e) => router.get(route('admin.payroll.reports.roster'), { include_retired: e.target.checked ? 1 : undefined }, { preserveScroll: true })}
                                    className="rounded border-gray-300 text-teal-600 focus:ring-teal-500" />
                                退職者を表示
                            </label>
                            <Link href={route('admin.payroll.report-exports.index')}
                                className="inline-flex items-center gap-2 rounded-lg bg-teal-50 px-3 py-1.5 text-sm font-semibold text-teal-700 transition hover:bg-teal-100">
                                <i className="fa-solid fa-layer-group" /> PDF一括作成
                            </Link>
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">所属事業所</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">氏名</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">従業員番号</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">契約種別</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">給与区分</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">在籍状況</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">操作</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {rows.map((r) => (
                                        <tr key={r.id} className="hover:bg-gray-50">
                                            <td className="px-4 py-2.5 text-sm text-gray-600">{r.business_location ?? '—'}</td>
                                            <td className="px-4 py-2.5 text-sm font-medium text-gray-800">{r.name}</td>
                                            <td className="px-4 py-2.5 text-sm text-gray-500">{r.employee_no ?? '—'}</td>
                                            <td className="px-4 py-2.5 text-sm text-gray-600">{r.employment_type}</td>
                                            <td className="px-4 py-2.5 text-sm text-gray-600">{r.pay_type}</td>
                                            <td className="px-4 py-2.5 text-sm">
                                                <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${r.status === '在籍中' ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500'}`}>{r.status}</span>
                                            </td>
                                            <td className="px-4 py-2.5 text-right">
                                                <a href={route('admin.payroll.reports.roster.pdf', r.id)} target="_blank" rel="noopener noreferrer"
                                                    className="inline-flex items-center gap-1.5 text-sm font-semibold text-teal-700 hover:text-teal-800">
                                                    <i className="fa-solid fa-print" /> 印刷する
                                                </a>
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
            </div>
        </AdminLayout>
    );
}
