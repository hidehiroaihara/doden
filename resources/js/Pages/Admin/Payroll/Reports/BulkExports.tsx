import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface ExportRow {
    id: number;
    report_type: string;
    type_label: string;
    format: string;
    year: number;
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
        currentYear: number;
        reportTypes: { value: string; label: string }[];
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

export default function BulkExports({ exports: initialExports, options }: Props) {
    const canWrite = useAdminPermission('payroll');
    const [exports, setExports] = useState<ExportRow[]>(initialExports);
    const timerRef = useRef<number | null>(null);

    const years = Array.from({ length: 6 }, (_, i) => options.currentYear - i);

    const { data, setData, post, processing } = useForm({
        report_type: options.reportTypes[0]?.value ?? 'withholding_book',
        year: options.currentYear,
        business_location_id: '' as string | number,
    });

    const hasActive = exports.some((e) => e.status === 'queued' || e.status === 'processing');

    useEffect(() => {
        if (!hasActive) {
            if (timerRef.current) window.clearInterval(timerRef.current);
            return;
        }
        timerRef.current = window.setInterval(async () => {
            try {
                const res = await fetch(route('admin.payroll.report-exports.status'), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (res.ok) setExports((await res.json()).exports);
            } catch {
                /* noop */
            }
        }, 2500);
        return () => {
            if (timerRef.current) window.clearInterval(timerRef.current);
        };
    }, [hasActive]);

    useEffect(() => setExports(initialExports), [initialExports]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('admin.payroll.report-exports.store'), { preserveScroll: true });
    };

    const remove = (row: ExportRow) => {
        if (confirm('この出力履歴を削除しますか？')) {
            router.delete(route('admin.payroll.report-exports.destroy', row.id), { preserveScroll: true });
        }
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">帳票の一括作成</h2>}>
            <Head title="帳票の一括作成" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-5xl space-y-6">
                    <Link href={route('admin.payroll.reports.index')}
                        className="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-800">
                        <i className="fa-solid fa-arrow-left" /> 帳票一覧へ戻る
                    </Link>

                    {!canWrite && (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                            <i className="fa-solid fa-eye mr-1.5" />
                            閲覧のみのアクセスです。一括作成の開始はできません。
                        </div>
                    )}

                    {canWrite && (
                        <form onSubmit={submit} className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                            <p className="mb-4 text-sm text-gray-500">
                                対象年・事業所を指定して、全従業員分の帳票をまとめて作成します（賃金台帳は1つのCSV、その他はPDFをZIP）。労働者名簿は退職者も含めて出力します。
                            </p>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <label className="mb-1 block text-xs font-medium text-gray-500">帳票種別</label>
                                    <select className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                        value={data.report_type} onChange={(e) => setData('report_type', e.target.value)}>
                                        {options.reportTypes.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-1 block text-xs font-medium text-gray-500">対象年</label>
                                    <select className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                        value={data.year} onChange={(e) => setData('year', Number(e.target.value))}>
                                        {years.map((y) => <option key={y} value={y}>{y}年</option>)}
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-1 block text-xs font-medium text-gray-500">事業所</label>
                                    <select className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                        value={data.business_location_id} onChange={(e) => setData('business_location_id', e.target.value)}>
                                        <option value="">全事業所</option>
                                        {options.businessLocations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                                    </select>
                                </div>
                            </div>
                            <div className="mt-4 flex justify-end">
                                <button type="submit" disabled={processing}
                                    className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                                    <i className="fa-solid fa-layer-group" />
                                    一括作成を開始
                                </button>
                            </div>
                        </form>
                    )}

                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                            <h3 className="text-sm font-bold text-gray-700">作成履歴</h3>
                            {hasActive && <span className="text-xs text-blue-600"><i className="fa-solid fa-spinner fa-spin mr-1" />処理中…自動更新</span>}
                        </div>
                        <div className="divide-y divide-gray-100">
                            {exports.map((row) => {
                                const st = STATUS[row.status] ?? STATUS.queued;
                                return (
                                    <div key={row.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                        <div className="min-w-35 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium text-gray-800">{row.type_label} ・ {row.year}年</span>
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
                                                <div className="mt-1 text-[10px] text-gray-400">{row.processed_count} / {row.total_count}名</div>
                                            </div>
                                        )}

                                        {row.status === 'failed' && (
                                            <div className="min-w-40 flex-1 text-xs text-red-600">{row.error_message}</div>
                                        )}

                                        {row.status === 'completed' && (
                                            <div className="text-xs text-gray-400">{row.total_count}名 ・ {fmtSize(row.file_size)}</div>
                                        )}

                                        <div className="flex items-center gap-1">
                                            {row.status === 'completed' && (
                                                <a href={route('admin.payroll.report-exports.download', row.id)}
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
                                    <i className="fa-solid fa-layer-group mb-2 text-2xl" />
                                    <p>作成履歴がありません。</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
