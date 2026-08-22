import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface Measure {
    id: number;
    type: string;
    type_label: string;
    name: string;
    target_year: number;
    start_period: string;
    end_period: string | null;
    per_person_amount: number;
    is_active: boolean;
    note: string | null;
}

interface Props {
    measures: Measure[];
    options: { types: { value: string; label: string }[] };
}

type FormShape = {
    type: string;
    name: string;
    target_year: number;
    start_period: string;
    end_period: string;
    per_person_amount: number;
    is_active: boolean;
    note: string;
};

function MeasureForm({
    initial,
    types,
    canWrite,
    onSubmit,
    submitLabel,
    processing,
}: {
    initial: FormShape;
    types: { value: string; label: string }[];
    canWrite: boolean;
    onSubmit: (data: FormShape) => void;
    submitLabel: string;
    processing: boolean;
}) {
    const [data, setData] = useState<FormShape>(initial);
    const set = <K extends keyof FormShape>(k: K, v: FormShape[K]) => setData((d) => ({ ...d, [k]: v }));

    return (
        <div className="grid grid-cols-1 gap-3 md:grid-cols-12">
            <div className="md:col-span-3">
                <label className="mb-1 block text-xs font-medium text-gray-500">制度種別</label>
                <select value={data.type} onChange={(e) => set('type', e.target.value)}
                    className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    {types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </select>
            </div>
            <div className="md:col-span-5">
                <label className="mb-1 block text-xs font-medium text-gray-500">名称</label>
                <input value={data.name} onChange={(e) => set('name', e.target.value)}
                    className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
            </div>
            <div className="md:col-span-2">
                <label className="mb-1 block text-xs font-medium text-gray-500">対象年</label>
                <input type="number" value={data.target_year} onChange={(e) => set('target_year', Number(e.target.value))}
                    className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
            </div>
            <div className="md:col-span-2">
                <label className="mb-1 block text-xs font-medium text-gray-500">1人あたり控除額</label>
                <input type="number" value={data.per_person_amount} onChange={(e) => set('per_person_amount', Number(e.target.value))}
                    className="w-full rounded-lg border-gray-300 text-right text-sm focus:border-teal-500 focus:ring-teal-500" />
            </div>
            <div className="md:col-span-3">
                <label className="mb-1 block text-xs font-medium text-gray-500">適用開始 (YYYY-MM)</label>
                <input value={data.start_period} onChange={(e) => set('start_period', e.target.value)} placeholder="2024-06"
                    className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
            </div>
            <div className="md:col-span-3">
                <label className="mb-1 block text-xs font-medium text-gray-500">適用終了 (YYYY-MM・任意)</label>
                <input value={data.end_period} onChange={(e) => set('end_period', e.target.value)} placeholder="2024-12"
                    className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
            </div>
            <div className="flex items-end md:col-span-2">
                <label className="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" checked={data.is_active} onChange={(e) => set('is_active', e.target.checked)}
                        className="rounded border-gray-300 text-teal-600 focus:ring-teal-500" />
                    有効
                </label>
            </div>
            <div className="md:col-span-12">
                <label className="mb-1 block text-xs font-medium text-gray-500">備考</label>
                <textarea value={data.note} onChange={(e) => set('note', e.target.value)} rows={2}
                    className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
            </div>
            {canWrite && (
                <div className="md:col-span-12">
                    <button onClick={() => onSubmit(data)} disabled={processing}
                        className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                        <i className="fa-solid fa-floppy-disk" /> {submitLabel}
                    </button>
                </div>
            )}
        </div>
    );
}

const yen = (v: number) => (v || 0).toLocaleString();

export default function TaxMeasures({ measures, options }: Props) {
    const canWrite = useAdminPermission('payroll');
    const [showAdd, setShowAdd] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const addForm = useForm({});
    const editForm = useForm({});

    const create = (data: FormShape) => {
        addForm.transform(() => data as any);
        addForm.post(route('admin.payroll.tax-measures.store'), { preserveScroll: true, onSuccess: () => setShowAdd(false) });
    };

    const update = (id: number, data: FormShape) => {
        editForm.transform(() => data as any);
        editForm.put(route('admin.payroll.tax-measures.update', id), { preserveScroll: true, onSuccess: () => setEditingId(null) });
    };

    const remove = (id: number) => {
        if (!window.confirm('この税制措置を削除しますか？削除後は対象期間のバッチに自動適用されなくなります。')) return;
        router.delete(route('admin.payroll.tax-measures.destroy', id), { preserveScroll: true });
    };

    const blank: FormShape = {
        type: options.types[0]?.value ?? 'flat_tax_reduction',
        name: '', target_year: new Date().getFullYear(), start_period: '', end_period: '',
        per_person_amount: 30000, is_active: true, note: '',
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">税制措置マスタ</h2>}>
            <Head title="税制措置マスタ" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-5xl space-y-5">
                    <div className="flex items-center justify-between">
                        <Link href={route('admin.payroll.settings.index')}
                            className="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-800">
                            <i className="fa-solid fa-arrow-left" /> 給与設定へ戻る
                        </Link>
                        {canWrite && (
                            <button onClick={() => setShowAdd((v) => !v)}
                                className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700">
                                <i className="fa-solid fa-plus" /> 新規追加
                            </button>
                        )}
                    </div>

                    <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        <i className="fa-solid fa-circle-info mr-1.5" />
                        定額減税など期間限定の税制対応をここで管理します。有効かつ支給月が適用期間内のバッチに対して、給与計算エンジンが自動的に減税を適用します。
                    </div>

                    {showAdd && (
                        <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                            <h3 className="mb-4 text-sm font-bold text-gray-700">税制措置を追加</h3>
                            <MeasureForm initial={blank} types={options.types} canWrite={canWrite}
                                onSubmit={create} submitLabel="追加する" processing={addForm.processing} />
                        </div>
                    )}

                    <div className="space-y-3">
                        {measures.map((m) => (
                            <div key={m.id} className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                                {editingId === m.id ? (
                                    <>
                                        <div className="mb-4 flex items-center justify-between">
                                            <h3 className="text-sm font-bold text-gray-700">編集</h3>
                                            <button onClick={() => setEditingId(null)} className="text-xs text-gray-400 hover:text-gray-600">キャンセル</button>
                                        </div>
                                        <MeasureForm
                                            initial={{
                                                type: m.type, name: m.name, target_year: m.target_year,
                                                start_period: m.start_period, end_period: m.end_period ?? '',
                                                per_person_amount: m.per_person_amount, is_active: m.is_active, note: m.note ?? '',
                                            }}
                                            types={options.types} canWrite={canWrite}
                                            onSubmit={(data) => update(m.id, data)} submitLabel="更新する" processing={editForm.processing} />
                                    </>
                                ) : (
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-semibold text-gray-800">{m.name}</span>
                                                <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] text-gray-500">{m.type_label}</span>
                                                {m.is_active
                                                    ? <span className="rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-semibold text-green-700">有効</span>
                                                    : <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-500">無効</span>}
                                            </div>
                                            <div className="mt-1 text-xs text-gray-500">
                                                {m.target_year}年分 ・ 適用 {m.start_period} 〜 {m.end_period ?? '（無期限）'} ・ 1人あたり ¥{yen(m.per_person_amount)}
                                            </div>
                                            {m.note && <div className="mt-1 text-xs text-gray-400">{m.note}</div>}
                                        </div>
                                        {canWrite && (
                                            <div className="flex items-center gap-1">
                                                <button onClick={() => setEditingId(m.id)}
                                                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-600 transition hover:bg-gray-50">
                                                    <i className="fa-solid fa-pen" /> 編集
                                                </button>
                                                <button onClick={() => remove(m.id)}
                                                    className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-red-600 transition hover:bg-red-50">
                                                    <i className="fa-solid fa-trash-can" />
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        ))}
                        {measures.length === 0 && (
                            <div className="rounded-2xl bg-white p-12 text-center text-sm text-gray-400 shadow-sm ring-1 ring-gray-100">
                                税制措置が登録されていません。
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
