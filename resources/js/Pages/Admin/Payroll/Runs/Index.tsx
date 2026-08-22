import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface RunRow {
    id: number;
    period_key: string;
    pay_type: string;
    business_location: string | null;
    status: string;
    payslips_count: number;
    payment_date: string | null;
    finalized_at: string | null;
}

interface Props {
    runs: RunRow[];
    options: {
        businessLocations: { id: number; name: string }[];
        defaultPeriod: string;
    };
}

const STATUS: Record<string, { label: string; badge: string }> = {
    draft: { label: '下書き', badge: 'bg-gray-100 text-gray-600' },
    calculated: { label: '計算済', badge: 'bg-blue-100 text-blue-700' },
    finalized: { label: '確定', badge: 'bg-green-100 text-green-700' },
};

const PAY_TYPE: Record<string, string> = { salary: '給与', bonus: '賞与' };

export default function PayrollRunsIndex({ runs, options }: Props) {
    const canWrite = useAdminPermission('payroll');
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        period_key: options.defaultPeriod,
        business_location_id: '' as string | number,
        pay_type: 'salary',
        payment_date: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('admin.payroll.runs.store'));
    };

    const remove = (run: RunRow) => {
        if (confirm(`${run.period_key} のバッチを削除しますか？`)) {
            router.delete(route('admin.payroll.runs.destroy', run.id));
        }
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">給与計算</h2>}>
            <Head title="給与計算" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-6xl">
                    {!canWrite && (
                        <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                            <i className="fa-solid fa-eye mr-1.5" />
                            閲覧のみのアクセスです。
                        </div>
                    )}

                    <div className="mb-4 flex items-center justify-between">
                        <p className="text-sm text-gray-500">{runs.length}件のバッチ</p>
                        {canWrite && (
                            <button
                                onClick={() => setShowForm((v) => !v)}
                                className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700"
                            >
                                <i className={showForm ? 'fa-solid fa-xmark' : 'fa-solid fa-plus'} />
                                {showForm ? '閉じる' : '新規バッチ作成'}
                            </button>
                        )}
                    </div>

                    {showForm && canWrite && (
                        <form onSubmit={submit} className="mb-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <label className="mb-1 block text-xs font-medium text-gray-500">対象月</label>
                                    <input type="month" className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                        value={data.period_key} onChange={(e) => setData('period_key', e.target.value)} />
                                    {errors.period_key && <p className="mt-1 text-xs text-red-600">{errors.period_key}</p>}
                                </div>
                                <div>
                                    <label className="mb-1 block text-xs font-medium text-gray-500">事業所</label>
                                    <select className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                        value={data.business_location_id} onChange={(e) => setData('business_location_id', e.target.value)}>
                                        <option value="">全事業所</option>
                                        {options.businessLocations.map((l) => (
                                            <option key={l.id} value={l.id}>{l.name}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-1 block text-xs font-medium text-gray-500">種別</label>
                                    <select className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                        value={data.pay_type} onChange={(e) => setData('pay_type', e.target.value)}>
                                        <option value="salary">給与</option>
                                        <option value="bonus">賞与</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-1 block text-xs font-medium text-gray-500">支給日（任意）</label>
                                    <input type="date" className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                        value={data.payment_date} onChange={(e) => setData('payment_date', e.target.value)} />
                                </div>
                            </div>
                            <div className="mt-4 flex justify-end">
                                <button type="submit" disabled={processing}
                                    className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                                    <i className="fa-solid fa-plus" />
                                    作成する
                                </button>
                            </div>
                        </form>
                    )}

                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">対象月</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">種別</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">事業所</th>
                                        <th className="px-4 py-3 text-center text-xs font-semibold text-gray-500">明細数</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">支給日</th>
                                        <th className="px-4 py-3 text-center text-xs font-semibold text-gray-500">状態</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">操作</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {runs.map((r) => {
                                        const st = STATUS[r.status] ?? STATUS.draft;
                                        return (
                                            <tr key={r.id} className="hover:bg-gray-50">
                                                <td className="px-4 py-3 text-sm font-medium text-gray-900">
                                                    <Link href={route('admin.payroll.runs.show', r.id)} className="hover:text-teal-700">
                                                        {r.period_key}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3 text-sm text-gray-600">{PAY_TYPE[r.pay_type] ?? r.pay_type}</td>
                                                <td className="px-4 py-3 text-sm text-gray-600">{r.business_location ?? '全事業所'}</td>
                                                <td className="px-4 py-3 text-center text-sm tabular-nums text-gray-700">{r.payslips_count}</td>
                                                <td className="px-4 py-3 text-sm text-gray-600">{r.payment_date ?? '—'}</td>
                                                <td className="px-4 py-3 text-center">
                                                    <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${st.badge}`}>{st.label}</span>
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Link href={route('admin.payroll.runs.show', r.id)}
                                                            className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-teal-700 transition hover:bg-teal-50">
                                                            <i className="fa-solid fa-arrow-right-to-bracket" />
                                                            開く
                                                        </Link>
                                                        {canWrite && r.status !== 'finalized' && (
                                                            <button onClick={() => remove(r)}
                                                                className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-600 transition hover:bg-red-50">
                                                                <i className="fa-solid fa-trash-can" />
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {runs.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="px-6 py-12 text-center text-sm text-gray-400">
                                                <i className="fa-solid fa-calculator mb-2 text-2xl" />
                                                <p>給与計算バッチがありません。新規作成してください。</p>
                                            </td>
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
