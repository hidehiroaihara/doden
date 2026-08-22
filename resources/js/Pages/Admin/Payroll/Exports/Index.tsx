import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface ExportRow {
    id: number;
    period_from: string;
    period_to: string;
    business_location: string | null;
    status: string;
    progress: number;
    total_count: number;
    processed_count: number;
    file_name: string | null;
    file_size: number | null;
    error_message: string | null;
    created_at: string | null;
    completed_at: string | null;
}

interface Props {
    exports: ExportRow[];
    options: {
        businessLocations: { id: number; name: string }[];
        defaultPeriod: string;
    };
}

const STATUS: Record<string, { label: string; badge: string }> = {
    queued: { label: '待機中', badge: 'bg-gray-100 text-gray-600' },
    processing: { label: '処理中', badge: 'bg-blue-100 text-blue-700' },
    completed: { label: '完了', badge: 'bg-green-100 text-green-700' },
    failed: { label: '失敗', badge: 'bg-red-100 text-red-700' },
};

const fmtSize = (b: number | null) => {
    if (!b) return '—';
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(0)} KB`;
    return `${(b / 1024 / 1024).toFixed(1)} MB`;
};

export default function PayslipExportsIndex({ exports: initialExports, options }: Props) {
    const canWrite = useAdminPermission('payroll');
    const [exports, setExports] = useState<ExportRow[]>(initialExports);
    const timerRef = useRef<number | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm({
        period_from: options.defaultPeriod,
        period_to: options.defaultPeriod,
        business_location_id: '' as string | number,
    });

    const hasActive = exports.some((e) => e.status === 'queued' || e.status === 'processing');

    // 処理中ジョブがある間だけ状態をポーリング
    useEffect(() => {
        if (!hasActive) {
            if (timerRef.current) window.clearInterval(timerRef.current);
            return;
        }
        timerRef.current = window.setInterval(async () => {
            try {
                const res = await fetch(route('admin.payroll.exports.status'), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (res.ok) {
                    const json = await res.json();
                    setExports(json.exports);
                }
            } catch {
                /* noop */
            }
        }, 2500);
        return () => {
            if (timerRef.current) window.clearInterval(timerRef.current);
        };
    }, [hasActive]);

    useEffect(() => {
        setExports(initialExports);
    }, [initialExports]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('admin.payroll.exports.store'), {
            preserveScroll: true,
            onSuccess: () => reset('business_location_id'),
        });
    };

    const remove = (row: ExportRow) => {
        if (confirm('この出力履歴（ZIP）を削除しますか？')) {
            router.delete(route('admin.payroll.exports.destroy', row.id), { preserveScroll: true });
        }
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">給与明細ZIP出力</h2>}>
            <Head title="給与明細ZIP出力" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-5xl space-y-6">
                    {!canWrite && (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                            <i className="fa-solid fa-eye mr-1.5" />
                            閲覧のみのアクセスです。出力の開始はできません。
                        </div>
                    )}

                    {canWrite && (
                        <form onSubmit={submit} className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                            <p className="mb-4 text-sm text-gray-500">
                                指定期間の給与明細を「従業員 / 月 / PDF」の階層でZIPにまとめて出力します。
                            </p>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <label className="mb-1 block text-xs font-medium text-gray-500">開始月</label>
                                    <input type="month" className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                        value={data.period_from} onChange={(e) => setData('period_from', e.target.value)} />
                                    {errors.period_from && <p className="mt-1 text-xs text-red-600">{errors.period_from}</p>}
                                </div>
                                <div>
                                    <label className="mb-1 block text-xs font-medium text-gray-500">終了月</label>
                                    <input type="month" className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                        value={data.period_to} onChange={(e) => setData('period_to', e.target.value)} />
                                    {errors.period_to && <p className="mt-1 text-xs text-red-600">{errors.period_to}</p>}
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
                            </div>
                            <div className="mt-4 flex justify-end">
                                <button type="submit" disabled={processing}
                                    className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                                    <i className="fa-solid fa-file-zipper" />
                                    ZIP出力を開始
                                </button>
                            </div>
                        </form>
                    )}

                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                            <h3 className="text-sm font-bold text-gray-700">出力履歴</h3>
                            {hasActive && <span className="text-xs text-blue-600"><i className="fa-solid fa-spinner fa-spin mr-1" />処理中…自動更新</span>}
                        </div>
                        <div className="divide-y divide-gray-100">
                            {exports.map((row) => {
                                const st = STATUS[row.status] ?? STATUS.queued;
                                return (
                                    <div key={row.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                        <div className="min-w-35 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium text-gray-800">{row.period_from} 〜 {row.period_to}</span>
                                                <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${st.badge}`}>{st.label}</span>
                                            </div>
                                            <div className="text-xs text-gray-400">
                                                {row.business_location ?? '全事業所'}
                                                {row.created_at && ` ・ ${row.created_at}`}
                                            </div>
                                        </div>

                                        {row.status === 'processing' && (
                                            <div className="min-w-40 flex-1">
                                                <div className="h-2 overflow-hidden rounded-full bg-gray-100">
                                                    <div className="h-full rounded-full bg-teal-500 transition-all" style={{ width: `${row.progress}%` }} />
                                                </div>
                                                <div className="mt-1 text-[10px] text-gray-400">{row.processed_count} / {row.total_count}件</div>
                                            </div>
                                        )}

                                        {row.status === 'failed' && (
                                            <div className="min-w-40 flex-1 text-xs text-red-600">{row.error_message}</div>
                                        )}

                                        {row.status === 'completed' && (
                                            <div className="text-xs text-gray-400">{row.total_count}件 ・ {fmtSize(row.file_size)}</div>
                                        )}

                                        <div className="flex items-center gap-1">
                                            {row.status === 'completed' && (
                                                <a href={route('admin.payroll.exports.download', row.id)}
                                                    className="inline-flex items-center gap-1 rounded-lg bg-teal-50 px-3 py-1.5 text-xs font-medium text-teal-700 transition hover:bg-teal-100">
                                                    <i className="fa-solid fa-download" />
                                                    ダウンロード
                                                </a>
                                            )}
                                            {canWrite && row.status !== 'processing' && row.status !== 'queued' && (
                                                <button onClick={() => remove(row)}
                                                    className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-600 transition hover:bg-red-50">
                                                    <i className="fa-solid fa-trash-can" />
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                            {exports.length === 0 && (
                                <div className="px-6 py-12 text-center text-sm text-gray-400">
                                    <i className="fa-solid fa-file-zipper mb-2 text-2xl" />
                                    <p>出力履歴がありません。</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
