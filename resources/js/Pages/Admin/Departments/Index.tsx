import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface StoreTerminal {
    id: number;
    name: string;
    terminal_id: string;
    terminal_key: string;
    is_active: boolean;
}

interface DepartmentRow {
    id: number;
    name: string;
    business_location_id: number | null;
    business_location_name: string | null;
    sort_order: number;
    users_count: number;
    terminals: StoreTerminal[];
}

interface BusinessLocationOption {
    id: number;
    name: string;
}

interface Props {
    departments: DepartmentRow[];
    businessLocations: BusinessLocationOption[];
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

/** URL 1行分の表示（コピー / 開く付き）。 */
function UrlRow({
    label,
    url,
    copied,
    onCopy,
    icon,
    tone = 'gray',
    children,
}: {
    label: string;
    url: string;
    copied: boolean;
    onCopy: () => void;
    icon: string;
    tone?: 'gray' | 'teal';
    children?: React.ReactNode;
}) {
    const bg = tone === 'teal' ? 'bg-teal-50' : 'bg-gray-50';
    const labelColor = tone === 'teal' ? 'text-teal-600' : 'text-gray-400';
    return (
        <div className={`flex flex-wrap items-center gap-2 rounded-xl ${bg} px-3 py-2`}>
            <span className={`text-[11px] font-semibold ${labelColor}`}>
                <i className={`fa-solid ${icon} mr-1`} />{label}
            </span>
            <code className="min-w-0 flex-1 truncate text-xs text-gray-600">{url}</code>
            <div className="flex items-center gap-1">
                <button onClick={onCopy}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-600 transition hover:bg-gray-50">
                    <i className={`fa-solid ${copied ? 'fa-check text-teal-600' : 'fa-copy'}`} />
                    {copied ? 'コピーしました' : 'コピー'}
                </button>
                <a href={url} target="_blank" rel="noopener noreferrer"
                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-600 transition hover:bg-gray-50">
                    <i className="fa-solid fa-arrow-up-right-from-square" /> 開く
                </a>
                {children}
            </div>
        </div>
    );
}

export default function DepartmentsIndex({ departments, businessLocations }: Props) {
    const canWrite = useAdminPermission('users');
    const [showAdd, setShowAdd] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
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

    // 打刻URL生成
    const normalUrl = (row: DepartmentRow) => route('home.store', row.id);
    const authUrl = (row: DepartmentRow, terminal: StoreTerminal) => {
        const base = route('home.store', row.id);
        const sep = base.includes('?') ? '&' : '?';
        return `${base}${sep}terminal_id=${encodeURIComponent(terminal.terminal_id)}&terminal_key=${encodeURIComponent(terminal.terminal_key)}`;
    };

    const [copiedKey, setCopiedKey] = useState<string | null>(null);
    const copy = async (key: string, url: string) => {
        try {
            await navigator.clipboard.writeText(url);
            setCopiedKey(key);
            window.setTimeout(() => setCopiedKey((c) => (c === key ? null : c)), 2000);
        } catch {
            window.prompt('打刻URL', url);
        }
    };

    const issueTerminal = (row: DepartmentRow) => {
        router.post(route('admin.departments.terminals.store', row.id), {}, { preserveScroll: true });
    };
    const reissueTerminal = (terminal: StoreTerminal) => {
        if (!window.confirm('この認証URLを再発行しますか？以前のURLは無効になります。')) return;
        router.post(route('admin.departments.terminals.reissue', terminal.id), {}, { preserveScroll: true });
    };
    const deleteTerminal = (terminal: StoreTerminal) => {
        if (!window.confirm('この認証URLを削除しますか？この端末からのアクセスはできなくなります。')) return;
        router.delete(route('admin.departments.terminals.destroy', terminal.id), { preserveScroll: true });
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">店舗管理</h2>}>
            <Head title="店舗管理" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-4xl space-y-5">
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-gray-500">
                            店舗（部門）と打刻端末をまとめて管理します。ユーザーの所属や打刻画面の店舗選択に反映されます。
                        </p>
                        {canWrite && (
                            <button onClick={() => setShowAdd((v) => !v)}
                                className="inline-flex shrink-0 items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700">
                                <i className="fa-solid fa-plus" /> 新規追加
                            </button>
                        )}
                    </div>

                    <div className="rounded-2xl bg-blue-50 px-4 py-3 text-xs leading-relaxed text-blue-700 ring-1 ring-blue-100">
                        <i className="fa-solid fa-circle-info mr-1" />
                        <span className="font-semibold">打刻URLの使い分け</span>：
                        <span className="mx-1 font-semibold">通常URL</span>は店内など許可IPからのみ開けます。
                        <span className="mx-1 font-semibold">認証URL</span>は端末キーを含むため、IP制限に関係なくどこからでも開けます（直打ち用）。
                    </div>

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

                                        {/* 通常URL（許可IPからのみ） */}
                                        <UrlRow label="通常URL（IP許可）" url={normalUrl(d)} icon="fa-link"
                                            copied={copiedKey === `n-${d.id}`} onCopy={() => copy(`n-${d.id}`, normalUrl(d))} />

                                        {/* 認証URL（端末キー付き＝どこからでも開ける） */}
                                        {d.terminals.map((t) => (
                                            <UrlRow key={t.id} label={`認証URL（${t.name}）`} url={authUrl(d, t)} icon="fa-key" tone="teal"
                                                copied={copiedKey === `t-${t.id}`} onCopy={() => copy(`t-${t.id}`, authUrl(d, t))}>
                                                {canWrite && (
                                                    <>
                                                        <button onClick={() => reissueTerminal(t)} title="再発行"
                                                            className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-600 transition hover:bg-gray-50">
                                                            <i className="fa-solid fa-rotate" />
                                                        </button>
                                                        <button onClick={() => deleteTerminal(t)} title="削除"
                                                            className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-red-600 transition hover:bg-red-50">
                                                            <i className="fa-solid fa-trash-can" />
                                                        </button>
                                                    </>
                                                )}
                                            </UrlRow>
                                        ))}

                                        {canWrite && (
                                            <button onClick={() => issueTerminal(d)}
                                                className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-teal-300 px-3 py-1.5 text-xs font-semibold text-teal-600 transition hover:bg-teal-50">
                                                <i className="fa-solid fa-plus" /> 認証URLを発行
                                            </button>
                                        )}
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
