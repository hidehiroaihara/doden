import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface RunOption {
    id: number;
    period_key: string;
    label: string;
    business_location: string | null;
}

interface Row {
    id: number;
    employee_no: string | number | null;
    name: string | null;
    business_location: string | null;
    net_pay: number;
    last_notified_at: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Filters {
    emp_no: string;
    last_name: string;
    first_name: string;
    location: string | number;
    corrected: boolean;
    exclude_zero: boolean;
}

interface SlipData {
    id: number;
    alignRows: number;
    columnMinHeight: number;
    title: string;
    paymentDate: string | null;
    targetPeriod: string | null;
    userName: string;
    businessLocation: string | null;
    department: string | null;
    employeeNo: string | number | null;
    showAttendance: boolean;
    attendances: { name: string; value: string }[];
    earnings: { name: string; amount: number }[];
    deductions: { name: string; amount: number }[];
    totalEarnings: number;
    totalDeductions: number;
    netPay: number;
    payments: { name: string; amount: number }[];
    relatedInfo: { label: string; value: string }[];
    ytd: { taxable: number; social: number; income_tax: number } | null;
    remarks: string | null;
}

interface Props {
    runs: RunOption[];
    selectedRunId: number | null;
    filters: Filters;
    perPage: number;
    rows: {
        data: Row[];
        links: PaginationLink[];
        meta: {
            current_page: number;
            last_page: number;
            from: number | null;
            to: number | null;
            total: number;
            per_page: number;
        } | null;
    };
    businessLocations: { id: number; name: string }[];
}

const yen = (n: number) => `${n.toLocaleString('ja-JP')}円`;

/** XSRF-TOKEN Cookie を読み、バイナリPOSTのダウンロードを行う。 */
async function postDownload(url: string, body: Record<string, unknown>) {
    const cookie = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    const token = cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/pdf',
            'X-XSRF-TOKEN': token,
        },
        body: JSON.stringify(body),
    });
    if (!res.ok) {
        alert('PDFの作成に失敗しました。');
        return;
    }
    const blob = await res.blob();
    const cd = res.headers.get('Content-Disposition') ?? '';
    const m = cd.match(/filename\*=UTF-8''([^;]+)/);
    const filename = m ? decodeURIComponent(m[1]) : 'payslip.pdf';
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(link.href);
}

export default function PayslipsReport({ runs, selectedRunId, filters, perPage, rows, businessLocations }: Props) {
    const [form, setForm] = useState<Filters>(filters);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [preview, setPreview] = useState<SlipData | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [batchBusy, setBatchBusy] = useState(false);
    const [banner, setBanner] = useState<string | null>(null);

    const pageIds = useMemo(() => rows.data.map((r) => r.id), [rows.data]);
    const allChecked = pageIds.length > 0 && pageIds.every((id) => selected.has(id));

    const visit = (extra: Record<string, unknown>) => {
        router.get(
            route('admin.payroll.reports.payslips'),
            {
                run: selectedRunId ?? undefined,
                emp_no: form.emp_no || undefined,
                last_name: form.last_name || undefined,
                first_name: form.first_name || undefined,
                location: form.location || undefined,
                corrected: form.corrected ? 1 : undefined,
                exclude_zero: form.exclude_zero ? 1 : undefined,
                per_page: perPage,
                ...extra,
            },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const changeRun = (id: string) => {
        setSelected(new Set());
        visit({ run: id, page: 1 });
    };

    const search = (e: React.FormEvent) => {
        e.preventDefault();
        visit({ page: 1 });
    };

    const toggleAll = () => {
        const next = new Set(selected);
        if (allChecked) {
            pageIds.forEach((id) => next.delete(id));
        } else {
            pageIds.forEach((id) => next.add(id));
        }
        setSelected(next);
    };

    const toggleOne = (id: number) => {
        const next = new Set(selected);
        next.has(id) ? next.delete(id) : next.add(id);
        setSelected(next);
    };

    const openPreview = async (id: number) => {
        setPreviewLoading(true);
        setPreview(null);
        try {
            const res = await fetch(route('admin.payroll.reports.payslips.preview', id), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (res.ok) {
                const json = await res.json();
                setPreview(json.slip);
            }
        } finally {
            setPreviewLoading(false);
        }
    };

    const runBatchPdf = async () => {
        if (selected.size === 0) return;
        setBatchBusy(true);
        try {
            await postDownload(route('admin.payroll.reports.payslips.batch-pdf'), {
                ids: Array.from(selected),
                run: selectedRunId,
            });
            setBanner(`${selected.size}件の給与明細PDFを作成しました。`);
        } finally {
            setBatchBusy(false);
        }
    };

    const printPreview = () => {
        window.print();
    };

    const perPageSelect = (
        <label className="flex items-center gap-1 text-xs text-gray-500">
            表示件数:
            <select
                className="rounded-md border-gray-300 py-1 text-xs shadow-sm focus:border-teal-500 focus:ring-teal-500"
                value={perPage}
                onChange={(e) => visit({ per_page: e.target.value, page: 1 })}
            >
                {[25, 50, 100].map((n) => (
                    <option key={n} value={n}>{n}件</option>
                ))}
            </select>
        </label>
    );

    const pager = rows.meta && rows.meta.last_page > 1 && (
        <nav className="flex flex-wrap items-center gap-1">
            {rows.links.map((l, i) => (
                <button
                    key={i}
                    disabled={!l.url}
                    onClick={() => l.url && router.get(l.url, {}, { preserveScroll: true, preserveState: true })}
                    className={`min-w-8 rounded-md px-2 py-1 text-xs ${
                        l.active ? 'bg-teal-600 text-white' : l.url ? 'text-gray-600 hover:bg-gray-100' : 'text-gray-300'
                    }`}
                    dangerouslySetInnerHTML={{ __html: l.label }}
                />
            ))}
        </nav>
    );

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">給与明細</h2>}>
            <Head title="給与明細" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-6xl space-y-4">
                    {banner && (
                        <div className="flex items-center justify-between rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700">
                            <span><i className="fa-solid fa-circle-check mr-1.5" />{banner}</span>
                            <button onClick={() => setBanner(null)} className="text-sky-400 hover:text-sky-600"><i className="fa-solid fa-xmark" /></button>
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-3">
                        <Link href={route('admin.payroll.reports.index')} className="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700">
                            <i className="fa-solid fa-chevron-left" />戻る
                        </Link>
                        <select
                            className="rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                            value={selectedRunId ?? ''}
                            onChange={(e) => changeRun(e.target.value)}
                        >
                            {runs.length === 0 && <option value="">給与バッチがありません</option>}
                            {runs.map((r) => (
                                <option key={r.id} value={r.id}>{r.label}</option>
                            ))}
                        </select>
                    </div>

                    <form onSubmit={search} className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                            <div>
                                <label className="mb-1 block text-xs font-medium text-gray-500">従業員番号</label>
                                <input className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                    value={form.emp_no} onChange={(e) => setForm({ ...form, emp_no: e.target.value })} />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-medium text-gray-500">姓</label>
                                <input className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                    value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-medium text-gray-500">名</label>
                                <input className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                    value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-medium text-gray-500">所属事業所</label>
                                <select className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                    value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })}>
                                    <option value="">指定なし</option>
                                    {businessLocations.map((l) => (
                                        <option key={l.id} value={l.id}>{l.name}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                        <div className="mt-3 flex flex-wrap items-center gap-4">
                            <label className="flex items-center gap-2 text-sm text-gray-600">
                                <span className="text-xs text-gray-400">給与修正</span>
                                <input type="checkbox" className="rounded border-gray-300 text-teal-600 focus:ring-teal-500"
                                    checked={form.corrected} onChange={(e) => setForm({ ...form, corrected: e.target.checked })} />
                                修正した従業員のみを表示
                            </label>
                            <label className="flex items-center gap-2 text-sm text-gray-600">
                                <span className="text-xs text-gray-400">差引支給額</span>
                                <input type="checkbox" className="rounded border-gray-300 text-teal-600 focus:ring-teal-500"
                                    checked={form.exclude_zero} onChange={(e) => setForm({ ...form, exclude_zero: e.target.checked })} />
                                0円の従業員を除く
                            </label>
                            <button type="submit" className="ml-auto inline-flex items-center gap-2 rounded-lg bg-teal-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-teal-700">
                                <i className="fa-solid fa-magnifying-glass" />絞り込み
                            </button>
                        </div>
                    </form>

                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <button onClick={toggleAll} className="text-sm font-medium text-teal-700 hover:text-teal-800">
                                <i className={`fa-solid ${allChecked ? 'fa-square-check' : 'fa-square'} mr-1`} />すべて選択
                            </button>
                            <button
                                onClick={runBatchPdf}
                                disabled={selected.size === 0 || batchBusy}
                                className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-40"
                            >
                                <i className={`fa-solid ${batchBusy ? 'fa-spinner fa-spin' : 'fa-file-pdf'}`} />
                                PDFの一括作成{selected.size > 0 ? `（${selected.size}）` : ''}
                            </button>
                        </div>
                        <div className="flex items-center gap-4">
                            {pager}
                            {perPageSelect}
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-gray-50 text-xs text-gray-500">
                                <tr>
                                    <th className="w-10 px-3 py-2"></th>
                                    <th className="px-3 py-2 text-left font-medium">従業員番号</th>
                                    <th className="px-3 py-2 text-left font-medium">氏名</th>
                                    <th className="px-3 py-2 text-left font-medium">所属事業所</th>
                                    <th className="px-3 py-2 text-right font-medium">差引支給額</th>
                                    <th className="px-3 py-2 text-left font-medium">最終通知日時</th>
                                    <th className="w-10 px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {rows.data.map((r) => (
                                    <tr key={r.id} className="hover:bg-teal-50/40">
                                        <td className="px-3 py-2 text-center">
                                            <input type="checkbox" className="rounded border-gray-300 text-teal-600 focus:ring-teal-500"
                                                checked={selected.has(r.id)} onChange={() => toggleOne(r.id)} />
                                        </td>
                                        <td className="px-3 py-2 text-gray-600">{r.employee_no ?? '—'}</td>
                                        <td className="px-3 py-2">
                                            <button onClick={() => openPreview(r.id)} className="font-medium text-teal-700 hover:underline">
                                                {r.name ?? '—'}
                                            </button>
                                        </td>
                                        <td className="px-3 py-2 text-gray-600">{r.business_location ?? '—'}</td>
                                        <td className="px-3 py-2 text-right tabular-nums text-gray-800">{yen(r.net_pay)}</td>
                                        <td className="px-3 py-2 text-gray-400">{r.last_notified_at ?? '-'}</td>
                                        <td className="px-3 py-2 text-center">
                                            <a href={route('admin.payroll.reports.payslips.pdf', r.id)}
                                                className="text-gray-400 hover:text-teal-600" title="PDF">
                                                <i className="fa-solid fa-file-pdf" />
                                            </a>
                                        </td>
                                    </tr>
                                ))}
                                {rows.data.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-6 py-12 text-center text-sm text-gray-400">
                                            <i className="fa-solid fa-file-invoice mb-2 text-2xl" />
                                            <p>該当する給与明細がありません。</p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex items-center justify-between">
                        <span className="text-xs text-gray-400">
                            {rows.meta && rows.meta.total > 0 && `${rows.meta.from}–${rows.meta.to} / ${rows.meta.total}件`}
                        </span>
                        <div className="flex items-center gap-4">
                            {pager}
                            {perPageSelect}
                        </div>
                    </div>
                </div>
            </div>

            {(preview || previewLoading) && (
                <PreviewPanel
                    slip={preview}
                    loading={previewLoading}
                    onClose={() => setPreview(null)}
                    onPrint={printPreview}
                />
            )}

            <style>{`
                @media print {
                    body * { visibility: hidden !important; }
                    #payslip-print-area, #payslip-print-area * { visibility: visible !important; }
                    #payslip-print-area { position: absolute; left: 0; top: 0; width: 100%; padding: 0; }
                    .no-print { display: none !important; }
                }
            `}</style>
        </AdminLayout>
    );
}

function PreviewPanel({
    slip,
    loading,
    onClose,
    onPrint,
}: {
    slip: SlipData | null;
    loading: boolean;
    onClose: () => void;
    onPrint: () => void;
}) {
    return (
        <div className="fixed inset-0 z-50 flex justify-end">
            <div className="no-print absolute inset-0 bg-black/30" onClick={onClose} />
            <div className="relative flex h-full w-full max-w-3xl flex-col bg-gray-100 shadow-2xl">
                <div className="no-print flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3">
                    <div className="flex items-center gap-3">
                        <button onClick={onClose} className="text-gray-400 hover:text-gray-700"><i className="fa-solid fa-xmark text-lg" /></button>
                        <span className="text-base font-bold text-gray-800">{slip?.userName ?? ''}</span>
                    </div>
                    <div className="flex items-center gap-2">
                        <button onClick={onPrint} disabled={!slip}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:opacity-40">
                            <i className="fa-solid fa-print" />印刷
                        </button>
                        {slip && (
                            <a href={route('admin.payroll.reports.payslips.pdf', slip.id)}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                                <i className="fa-solid fa-download" />PDF
                            </a>
                        )}
                        <button disabled title="未対応"
                            className="inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-sm font-medium text-gray-300">
                            <i className="fa-regular fa-bell" />通知の再送信
                        </button>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto p-6">
                    {loading && (
                        <div className="py-20 text-center text-gray-400"><i className="fa-solid fa-spinner fa-spin mr-2" />読み込み中…</div>
                    )}
                    {slip && (
                        <div id="payslip-print-area" className="mx-auto max-w-4xl bg-white p-8 shadow-sm print:shadow-none">
                            <PayslipDocument slip={slip} />
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

const NAVY = '#1f3f6b';
const HEAD = '#2b5a9c';
const STRIPE = '#eef4fb';
const TOTAL_BG = '#dbe6f4';
const BORDER = '#c3d0e0';

function Column({
    title,
    items,
    total,
    minHeight,
    className = '',
}: {
    title: string;
    items: { name: string; value: string }[];
    total?: { name: string; value: string };
    minHeight?: number;
    className?: string;
}) {
    return (
        <div
            className={`flex flex-col border bg-white ${className}`}
            style={{ borderColor: BORDER, minHeight, height: minHeight }}
        >
            <div className="shrink-0 px-2 py-1.5 text-center text-[11px] font-bold text-white" style={{ background: HEAD }}>{title}</div>
            <div className="flex min-h-0 flex-1 flex-col text-[10px] leading-snug">
                {items.map((it, i) => (
                    <div key={i} className="flex shrink-0 justify-between px-2 py-1.5" style={{ background: i % 2 === 0 ? STRIPE : undefined }}>
                        <span className="text-gray-700">{it.name}</span>
                        <span className="tabular-nums text-gray-900">{it.value}</span>
                    </div>
                ))}
                <div className="min-h-0 flex-1" aria-hidden="true" />
                {total && (
                    <div className="flex shrink-0 justify-between border-t px-2 py-1.5 font-bold" style={{ borderColor: '#b8c9e0', background: TOTAL_BG }}>
                        <span className="text-gray-700">{total.name}</span>
                        <span className="tabular-nums text-gray-900">{total.value}</span>
                    </div>
                )}
            </div>
        </div>
    );
}

function PayslipDocument({ slip }: { slip: SlipData }) {
    const fmt = (n: number) => n.toLocaleString('ja-JP');

    return (
        <div className="text-[11px] text-gray-900">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0 flex-1">
                    <div className="text-[17px] font-bold leading-snug" style={{ color: NAVY }}>{slip.title}</div>
                    <div className="mt-1 text-[9.5px] leading-relaxed text-gray-600">
                        {slip.paymentDate && <div>支給日：{slip.paymentDate}</div>}
                        {slip.targetPeriod && <div>対象期間：{slip.targetPeriod}</div>}
                    </div>
                    <div className="mt-2.5 text-[17px] font-bold">{slip.userName} 様</div>
                    <div className="mt-0.5 text-[9.5px] leading-relaxed text-gray-600">
                        {slip.businessLocation && <div>所属：{slip.businessLocation}</div>}
                        {slip.department !== null && <div>部門：{slip.department}</div>}
                        {slip.employeeNo && <div>従業員番号：{slip.employeeNo}</div>}
                    </div>
                </div>
                <div className="h-[66px] w-[66px] shrink-0 rounded-lg border border-gray-300" />
            </div>

            <div className="mt-1.5 text-right">
                <span className="text-[10px] text-gray-600">差引支給額</span>
                <span className="ml-3 text-[21px] font-bold">{fmt(slip.netPay)}</span>
                <span className="ml-1 text-[10px] text-gray-600">円</span>
            </div>

            <hr className="mb-3 mt-1 border-t-2" style={{ borderColor: NAVY }} />

            <div className="grid grid-cols-4 items-stretch gap-1.5">
                {slip.showAttendance && (
                    <Column
                        title="勤怠"
                        items={slip.attendances.length ? slip.attendances : [{ name: '—', value: '' }]}
                        minHeight={slip.columnMinHeight}
                    />
                )}
                <Column
                    title="支給"
                    items={slip.earnings.map((e) => ({ name: e.name, value: fmt(e.amount) }))}
                    total={{ name: '支給合計', value: fmt(slip.totalEarnings) }}
                    minHeight={slip.columnMinHeight}
                />
                <Column
                    title="控除"
                    items={slip.deductions.map((d) => ({ name: d.name, value: fmt(d.amount) }))}
                    total={{ name: '控除合計', value: fmt(slip.totalDeductions) }}
                    minHeight={slip.columnMinHeight}
                />
                <div className="flex h-full flex-col gap-1.5">
                    <Column
                        title="当月支払"
                        items={slip.payments.map((p) => ({ name: p.name, value: fmt(p.amount) }))}
                        minHeight={slip.columnMinHeight}
                        className="flex-1"
                    />
                    {slip.relatedInfo.length > 0 && (
                        <Column title="給与関連情報" items={slip.relatedInfo.map((r) => ({ name: r.label, value: r.value }))} />
                    )}
                </div>
            </div>

            {slip.ytd && (
                <div className="mt-1.5 grid grid-cols-4 gap-1.5">
                    <div />
                    <Column title="本年累計" items={[
                        { name: '課税支給額', value: fmt(slip.ytd.taxable) },
                        { name: '社会保険料', value: fmt(slip.ytd.social) },
                        { name: '所得税', value: fmt(slip.ytd.income_tax) },
                    ]} />
                    <div /><div />
                </div>
            )}

            {slip.remarks && (
                <div className="mt-3 text-[10px] text-gray-600">
                    備考
                    <div className="mt-1 min-h-6 whitespace-pre-wrap border border-gray-200 p-2">{slip.remarks}</div>
                </div>
            )}
        </div>
    );
}
