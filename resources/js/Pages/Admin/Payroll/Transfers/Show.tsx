import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link } from '@inertiajs/react';

interface Row {
    user_name: string | null;
    bank_name: string | null;
    branch_name: string | null;
    account_type: string;
    account_number: string | null;
    account_holder_kana: string | null;
    amount: number;
    remark: string | null;
}

interface Props {
    run: {
        id: number;
        period_key: string;
        pay_type: string;
        business_location: string | null;
        payment_date: string | null;
        status: string;
    };
    rows: Row[];
}

const yen = (v: number) => `¥${v.toLocaleString()}`;

export default function TransferListShow({ run, rows }: Props) {
    const total = rows.reduce((s, r) => s + r.amount, 0);
    const unregistered = rows.filter((r) => r.remark).length;

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">給与振込一覧表</h2>}>
            <Head title={`給与振込一覧表 ${run.period_key}`} />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-6xl space-y-5">
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                        <div className="flex items-center gap-3">
                            <Link href={route('admin.payroll.runs.show', run.id)}
                                className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition hover:bg-gray-50">
                                <i className="fa-solid fa-arrow-left" />
                            </Link>
                            <div>
                                <div className="text-lg font-bold text-gray-900">{run.period_key}</div>
                                <p className="text-xs text-gray-400">
                                    {run.business_location ?? '全事業所'}
                                    {run.payment_date && ` ・ 支給日 ${run.payment_date}`}
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <a href={route('admin.payroll.transfers.pdf', run.id)} target="_blank" rel="noopener noreferrer"
                                className="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                                <i className="fa-solid fa-file-pdf" />
                                PDF
                            </a>
                            <a href={route('admin.payroll.transfers.fb-data', run.id)}
                                className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700">
                                <i className="fa-solid fa-file-arrow-down" />
                                全銀FBデータ
                            </a>
                        </div>
                    </div>

                    {unregistered > 0 && (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                            <i className="fa-solid fa-triangle-exclamation mr-1.5" />
                            口座未登録の従業員が {unregistered} 名います。FBデータには含まれません（従業員給与の支払情報を登録してください）。
                        </div>
                    )}

                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">従業員名</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">振込先金融機関</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">支店</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">種目</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">口座番号</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">口座名義人</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">振込額</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">備考</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {rows.map((r, i) => (
                                        <tr key={i} className="hover:bg-gray-50">
                                            <td className="px-4 py-2.5 text-sm font-medium text-gray-800">{r.user_name}</td>
                                            <td className="px-4 py-2.5 text-sm text-gray-600">{r.bank_name || <span className="text-gray-300">—</span>}</td>
                                            <td className="px-4 py-2.5 text-sm text-gray-600">{r.branch_name || <span className="text-gray-300">—</span>}</td>
                                            <td className="px-4 py-2.5 text-sm text-gray-600">{r.account_type}</td>
                                            <td className="px-4 py-2.5 text-sm text-gray-600">{r.account_number || <span className="text-gray-300">—</span>}</td>
                                            <td className="px-4 py-2.5 text-sm text-gray-600">{r.account_holder_kana || <span className="text-gray-300">—</span>}</td>
                                            <td className="px-4 py-2.5 text-right text-sm tabular-nums text-gray-700">{yen(r.amount)}</td>
                                            <td className="px-4 py-2.5 text-sm text-red-600">{r.remark}</td>
                                        </tr>
                                    ))}
                                    {rows.length === 0 && (
                                        <tr><td colSpan={8} className="px-6 py-12 text-center text-sm text-gray-400">明細がありません。先に給与計算を実行してください。</td></tr>
                                    )}
                                    {rows.length > 0 && (
                                        <tr className="bg-gray-50 font-bold">
                                            <td className="px-4 py-3 text-sm text-gray-800" colSpan={6}>合計</td>
                                            <td className="px-4 py-3 text-right text-sm tabular-nums text-teal-700">{yen(total)}</td>
                                            <td className="px-4 py-3 text-sm text-gray-500">{rows.length}件</td>
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
