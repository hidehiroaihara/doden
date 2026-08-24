import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface DepartmentRow {
    id: number;
    name: string;
    business_location_id: number | null;
    business_location_name: string | null;
    sort_order: number;
    users_count: number;
}

interface TerminalOption {
    id: number;
    name: string;
    terminal_id: string;
    terminal_key: string;
}

interface BusinessLocationOption {
    id: number;
    name: string;
}

interface Props {
    departments: DepartmentRow[];
    businessLocations: BusinessLocationOption[];
    terminals: TerminalOption[];
}

type FormShape = { name: string; business_location_id: number | null; sort_order: number };

function DepartmentForm({
    initial,
    businessLocations,
    onSubmit,
    submitLabel,
    processing,
    onCancel,
}: {
    initial: FormShape;
    businessLocations: BusinessLocationOption[];
    onSubmit: (data: FormShape) => void;
    submitLabel: string;
    processing: boolean;
    onCancel?: () => void;
}) {
    const [data, setData] = useState<FormShape>(initial);
    const set = <K extends keyof FormShape>(k: K, v: FormShape[K]) => setData((d) => ({ ...d, [k]: v }));

    return (
        <div className="space-y-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-12">
                <div className="sm:col-span-9">
                    <label className="mb-1 block text-xs font-medium text-gray-500">店舗名</label>
                    <input value={data.name} onChange={(e) => set('name', e.target.value)} placeholder="例: 渋谷店"
                        className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                </div>
                <div className="sm:col-span-3">
                    <label className="mb-1 block text-xs font-medium text-gray-500">表示順</label>
                    <input type="number" min={0} value={data.sort_order} onChange={(e) => set('sort_order', Number(e.target.value))}
                        className="w-full rounded-lg border-gray-300 text-right text-sm focus:border-teal-500 focus:ring-teal-500" />
                </div>
                <div className="sm:col-span-12">
                    <label className="mb-1 block text-xs font-medium text-gray-500">所属事業所</label>
                    <select value={data.business_location_id ?? ''}
                        onChange={(e) => set('business_location_id', e.target.value === '' ? null : Number(e.target.value))}
                        className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">未設定</option>
                        {businessLocations.map((b) => (
                            <option key={b.id} value={b.id}>{b.name}</option>
                        ))}
                    </select>
                    <p className="mt-1 text-[11px] text-gray-400">保険・労働保険の帰属先となる事業所に振り分けます。</p>
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-3">
                <button type="button" onClick={() => onSubmit(data)} disabled={processing || data.name.trim() === ''}
                    className="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-lg bg-teal-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                    <i className="fa-solid fa-floppy-disk" /> {submitLabel}
                </button>
                {onCancel && (
                    <button type="button" onClick={onCancel}
                        className="inline-flex shrink-0 items-center whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium text-gray-500 transition hover:bg-gray-50 hover:text-gray-700">
                        キャンセル
                    </button>
                )}
            </div>
        </div>
    );
}

export default function DepartmentsIndex({ departments, businessLocations, terminals }: Props) {
    const canWrite = useAdminPermission('users');
    const [showAdd, setShowAdd] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [selectedTerminal, setSelectedTerminal] = useState<number | null>(terminals[0]?.id ?? null);
    const addForm = useForm({});
    const editForm = useForm({});

    const create = (data: FormShape) => {
        addForm.transform(() => data as never);
        addForm.post(route('admin.departments.store'), { preserveScroll: true, onSuccess: () => setShowAdd(false) });
    };

    const update = (id: number, data: FormShape) => {
        editForm.transform(() => data as never);
        editForm.put(route('admin.departments.update', id), { preserveScroll: true, onSuccess: () => setEditingId(null) });
    };

    const remove = (row: DepartmentRow) => {
        const warn = row.users_count > 0
            ? `この店舗には ${row.users_count} 名のユーザーが所属しています。削除するとそのユーザーの店舗は未設定になります。削除しますか？`
            : 'この店舗を削除しますか？';
        if (!window.confirm(warn)) return;
        router.delete(route('admin.departments.destroy', row.id), { preserveScroll: true });
    };

    const [copiedId, setCopiedId] = useState<number | null>(null);
    const storeUrl = (row: DepartmentRow) => {
        const base = route('home.store', row.id);
        // 端末制限が有効な場合、terminal_id / terminal_key を付与してワンクリックで開けるようにする
        const terminal = terminals.find((t) => t.id === selectedTerminal);
        if (!terminal) return base;
        const sep = base.includes('?') ? '&' : '?';
        return `${base}${sep}terminal_id=${encodeURIComponent(terminal.terminal_id)}&terminal_key=${encodeURIComponent(terminal.terminal_key)}`;
    };
    const copyUrl = async (row: DepartmentRow) => {
        const url = storeUrl(row);
        try {
            await navigator.clipboard.writeText(url);
            setCopiedId(row.id);
            window.setTimeout(() => setCopiedId((c) => (c === row.id ? null : c)), 2000);
        } catch {
            window.prompt('この店舗の打刻URL', url);
        }
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">店舗管理</h2>}>
            <Head title="店舗管理" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-4xl space-y-5">
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-gray-500">
                            ユーザーの所属店舗（部門）を管理します。ユーザー作成・編集画面や打刻画面の店舗選択に反映されます。
                        </p>
                        {canWrite && (
                            <button onClick={() => setShowAdd((v) => !v)}
                                className="inline-flex shrink-0 items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700">
                                <i className="fa-solid fa-plus" /> 新規追加
                            </button>
                        )}
                    </div>

                    {terminals.length > 0 && (
                        <div className="flex flex-wrap items-center gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                            <span className="text-sm font-semibold text-gray-700">
                                <i className="fa-solid fa-tablet-screen-button mr-1.5 text-teal-600" />打刻URLに使う端末
                            </span>
                            <select value={selectedTerminal ?? ''} onChange={(e) => setSelectedTerminal(Number(e.target.value))}
                                className="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                {terminals.map((t) => (
                                    <option key={t.id} value={t.id}>{t.name}</option>
                                ))}
                            </select>
                            <span className="text-xs text-gray-400">
                                選択した端末の認証情報がURLに付与され、クリックするだけで打刻画面を開けます。
                            </span>
                        </div>
                    )}

                    {showAdd && canWrite && (
                        <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                            <h3 className="mb-4 text-sm font-bold text-gray-700">店舗を追加</h3>
                            <DepartmentForm initial={{ name: '', business_location_id: null, sort_order: departments.length }}
                                businessLocations={businessLocations} onSubmit={create}
                                submitLabel="追加" processing={addForm.processing} onCancel={() => setShowAdd(false)} />
                        </div>
                    )}

                    <div className="space-y-3">
                        {departments.map((d) => (
                            <div key={d.id} className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                                {editingId === d.id ? (
                                    <DepartmentForm initial={{ name: d.name, business_location_id: d.business_location_id, sort_order: d.sort_order }}
                                        businessLocations={businessLocations}
                                        onSubmit={(data) => update(d.id, data)} submitLabel="更新" processing={editForm.processing}
                                        onCancel={() => setEditingId(null)} />
                                ) : (
                                    <div className="space-y-3">
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div className="flex items-center gap-3">
                                                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-teal-100 text-teal-600">
                                                    <i className="fa-solid fa-store" />
                                                </span>
                                                <div>
                                                    <span className="font-semibold text-gray-800">{d.name}</span>
                                                    <div className="mt-0.5 text-xs text-gray-500">
                                                        <span className={d.business_location_name ? 'text-teal-600' : 'text-amber-500'}>
                                                            <i className="fa-solid fa-building mr-1" />
                                                            {d.business_location_name ?? '事業所未設定'}
                                                        </span>
                                                        <span className="mx-1.5 text-gray-300">|</span>
                                                        表示順 {d.sort_order} ・ 所属 {d.users_count} 名
                                                    </div>
                                                </div>
                                            </div>
                                            {canWrite && (
                                                <div className="flex items-center gap-1">
                                                    <button onClick={() => setEditingId(d.id)}
                                                        className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-600 transition hover:bg-gray-50">
                                                        <i className="fa-solid fa-pen" /> 編集
                                                    </button>
                                                    <button onClick={() => remove(d)}
                                                        className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-red-600 transition hover:bg-red-50">
                                                        <i className="fa-solid fa-trash-can" />
                                                    </button>
                                                </div>
                                            )}
                                        </div>

                                        {/* 店舗専用の打刻URL（端末へ割り当てる用途） */}
                                        <div className="flex flex-wrap items-center gap-2 rounded-xl bg-gray-50 px-3 py-2">
                                            <span className="text-[11px] font-semibold text-gray-400">
                                                <i className="fa-solid fa-link mr-1" />打刻URL
                                            </span>
                                            <code className="min-w-0 flex-1 truncate text-xs text-gray-600">{storeUrl(d)}</code>
                                            <div className="flex items-center gap-1">
                                                <button onClick={() => copyUrl(d)}
                                                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-600 transition hover:bg-gray-50">
                                                    <i className={`fa-solid ${copiedId === d.id ? 'fa-check text-teal-600' : 'fa-copy'}`} />
                                                    {copiedId === d.id ? 'コピーしました' : 'コピー'}
                                                </button>
                                                <a href={storeUrl(d)} target="_blank" rel="noopener noreferrer"
                                                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-600 transition hover:bg-gray-50">
                                                    <i className="fa-solid fa-arrow-up-right-from-square" /> 開く
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>
                        ))}
                        {departments.length === 0 && (
                            <div className="rounded-2xl bg-white p-12 text-center text-sm text-gray-400 shadow-sm ring-1 ring-gray-100">
                                店舗が登録されていません。
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
