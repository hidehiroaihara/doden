import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useMemo, useState } from 'react';

type ColumnGroup = 'earning' | 'deduction' | 'total';

interface Column {
    key: string;
    label: string;
    group: ColumnGroup;
    is_active: boolean;
}

interface Row {
    is_subtotal?: boolean;
    employee_no?: string | null;
    name: string;
    values: Record<string, number>;
}

interface Pattern {
    id: number;
    name: string;
    hidden_columns: string[];
}

interface Props {
    group: 'none' | 'department';
    run: { id: number; period_key: string; business_location: string | null; payment_date: string | null } | null;
    options: {
        runs: { id: number; label: string }[];
        businessLocations: { id: number; name: string }[];
    };
    table: { columns: Column[]; rows: Row[]; totals: Record<string, number> };
    patterns: Pattern[];
    columnGroups: Record<ColumnGroup, string>;
}

const GROUP_ORDER: ColumnGroup[] = ['earning', 'deduction', 'total'];

const yen = (v: number) => (v || 0).toLocaleString();

const checkboxClass = 'h-4 w-4 rounded border-gray-300 text-teal-600 focus:ring-teal-500';

/** 左固定2列の幅（2列目の left は1列目の幅と一致させる） */
const STICKY_COL1 = 'sticky left-0 z-20 w-28 min-w-28 max-w-28';
const STICKY_COL2 = 'sticky left-28 z-20 w-32 min-w-32 max-w-32 shadow-[2px_0_4px_-2px_rgba(0,0,0,0.08)]';

function chunk<T>(items: T[], size: number): T[][] {
    const rows: T[][] = [];
    for (let i = 0; i < items.length; i += size) {
        rows.push(items.slice(i, i + size));
    }
    return rows;
}

export default function Summary({ group, run, options, table, patterns, columnGroups }: Props) {
    const canWrite = useAdminPermission('payroll');
    const [hidden, setHidden] = useState<Set<string>>(new Set());
    const [showColumnModal, setShowColumnModal] = useState(false);
    const [activePattern, setActivePattern] = useState<number | ''>('');

    const reload = (params: Record<string, string | number | undefined>) => {
        router.get(route('admin.payroll.reports.summary'), { group: group === 'department' ? 'department' : undefined, run: run?.id, ...params }, { preserveState: true, preserveScroll: true });
    };

    const visibleColumns = useMemo(() => table.columns.filter((c) => !hidden.has(c.key)), [table.columns, hidden]);
    const hiddenList = useMemo(() => Array.from(hidden), [hidden]);

    const columnsByGroup = useMemo(() => {
        const map: Record<ColumnGroup, Column[]> = { earning: [], deduction: [], total: [] };
        for (const c of table.columns) {
            map[c.group]?.push(c);
        }
        return map;
    }, [table.columns]);

    const toggleColumn = (key: string) => {
        setActivePattern('');
        setHidden((prev) => {
            const next = new Set(prev);
            next.has(key) ? next.delete(key) : next.add(key);
            return next;
        });
    };

    const setGroupVisibility = (groupKey: ColumnGroup, visible: boolean) => {
        setActivePattern('');
        const keys = columnsByGroup[groupKey].map((c) => c.key);
        setHidden((prev) => {
            const next = new Set(prev);
            for (const key of keys) {
                if (visible) {
                    next.delete(key);
                } else {
                    next.add(key);
                }
            }
            return next;
        });
    };

    const groupCheckState = (groupKey: ColumnGroup): 'checked' | 'unchecked' | 'indeterminate' => {
        const cols = columnsByGroup[groupKey];
        if (cols.length === 0) return 'unchecked';
        const visibleCount = cols.filter((c) => !hidden.has(c.key)).length;
        if (visibleCount === 0) return 'unchecked';
        if (visibleCount === cols.length) return 'checked';
        return 'indeterminate';
    };

    const applyPattern = (id: number | '') => {
        setActivePattern(id);
        if (id === '') {
            setHidden(new Set());
            return;
        }
        const p = patterns.find((x) => x.id === id);
        setHidden(new Set(p?.hidden_columns ?? []));
    };

    const savePattern = () => {
        const name = window.prompt('表示パターン名を入力してください');
        if (!name) return;
        router.post(route('admin.payroll.reports.summary.patterns.store'), { name, hidden_columns: hiddenList }, { preserveScroll: true });
    };

    const deletePattern = (id: number) => {
        if (!window.confirm('この表示パターンを削除しますか？')) return;
        router.delete(route('admin.payroll.reports.summary.patterns.destroy', id), { preserveScroll: true });
    };

    const csvHref = run
        ? route('admin.payroll.reports.summary.csv', {
              run: run.id,
              group: group === 'department' ? 'department' : undefined,
              hidden: hiddenList,
          })
        : '#';

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">支給控除一覧表{group === 'department' ? '（部門別）' : ''}</h2>}>
            <Head title="支給控除一覧表" />

            <div className="px-4 py-6 sm:p-6">
                <div className="space-y-5">
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                        <div className="flex flex-wrap items-center gap-3">
                            <Link href={route('admin.payroll.reports.index')}
                                className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition hover:bg-gray-50">
                                <i className="fa-solid fa-arrow-left" />
                            </Link>
                            <select value={run?.id ?? ''} onChange={(e) => reload({ run: e.target.value })}
                                className="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                {options.runs.map((r) => (
                                    <option key={r.id} value={r.id}>{r.label}</option>
                                ))}
                            </select>
                            <div className="inline-flex overflow-hidden rounded-lg border border-gray-200">
                                <button onClick={() => reload({ group: undefined })}
                                    className={`px-3 py-1.5 text-sm font-semibold ${group === 'none' ? 'bg-teal-600 text-white' : 'bg-white text-gray-600'}`}>通常</button>
                                <button onClick={() => reload({ group: 'department' })}
                                    className={`px-3 py-1.5 text-sm font-semibold ${group === 'department' ? 'bg-teal-600 text-white' : 'bg-white text-gray-600'}`}>部門別</button>
                            </div>
                        </div>
                        {run && (
                            <div className="flex flex-wrap items-center gap-2">
                                <select value={activePattern} onChange={(e) => applyPattern(e.target.value === '' ? '' : Number(e.target.value))}
                                    className="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                    <option value="">表示パターン: すべて表示</option>
                                    {patterns.map((p) => (
                                        <option key={p.id} value={p.id}>{p.name}</option>
                                    ))}
                                </select>
                                <button onClick={() => setShowColumnModal(true)}
                                    className="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                                    <i className="fa-solid fa-table-columns" />
                                    表示項目設定{hidden.size > 0 ? `（${hidden.size}件非表示）` : ''}
                                </button>
                                <a href={csvHref}
                                    className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700">
                                    <i className="fa-solid fa-file-csv" />
                                    CSVダウンロード
                                </a>
                            </div>
                        )}
                    </div>

                    {run && showColumnModal && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setShowColumnModal(false)}>
                            <div
                                className="flex max-h-[85vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl bg-white shadow-xl"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                                    <h3 className="text-base font-bold text-gray-800">表示項目を設定する</h3>
                                    <button type="button" onClick={() => setShowColumnModal(false)} className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                                        <i className="fa-solid fa-xmark text-lg" />
                                    </button>
                                </div>

                                <div className="space-y-4 border-b border-gray-100 px-5 py-4">
                                    <p className="text-sm leading-relaxed text-gray-600">
                                        支給控除一覧表に表示する項目を設定します。<br />
                                        基本設定で無効に設定されている項目は、グレーアウトで表示されます。
                                    </p>
                                    <div className="flex flex-wrap items-center gap-3">
                                        <select value={activePattern} onChange={(e) => applyPattern(e.target.value === '' ? '' : Number(e.target.value))}
                                            className="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                            <option value="">表示パターン呼出</option>
                                            {patterns.map((p) => (
                                                <option key={p.id} value={p.id}>{p.name}</option>
                                            ))}
                                        </select>
                                        <button type="button" onClick={() => { setHidden(new Set()); setActivePattern(''); }}
                                            className="text-xs font-semibold text-teal-700 hover:text-teal-800">すべて表示</button>
                                        {canWrite && (
                                            <button type="button" onClick={savePattern}
                                                className="inline-flex items-center gap-1.5 rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-600">
                                                <i className="fa-solid fa-floppy-disk" /> 現在の表示を保存
                                            </button>
                                        )}
                                    </div>
                                    {patterns.length > 0 && (
                                        <div className="flex flex-wrap gap-2">
                                            {patterns.map((p) => (
                                                <span key={p.id} className="inline-flex items-center gap-2 rounded-full bg-gray-100 py-1 pl-3 pr-1.5 text-xs text-gray-700">
                                                    <button type="button" onClick={() => applyPattern(p.id)} className="font-semibold hover:text-teal-700">{p.name}</button>
                                                    {canWrite && (
                                                        <button type="button" onClick={() => deletePattern(p.id)} className="text-gray-400 hover:text-red-600">
                                                            <i className="fa-solid fa-xmark" />
                                                        </button>
                                                    )}
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                <div className="flex-1 overflow-y-auto px-5 py-4">
                                    {GROUP_ORDER.map((groupKey) => {
                                        const cols = columnsByGroup[groupKey];
                                        if (cols.length === 0) return null;
                                        const checkState = groupCheckState(groupKey);
                                        return (
                                            <table key={groupKey} className="mb-6 w-full border-collapse text-sm">
                                                <tbody>
                                                    <tr className="border-b border-gray-100">
                                                        <td className="w-8 py-2 pr-2 align-middle">
                                                            <input
                                                                type="checkbox"
                                                                className={checkboxClass}
                                                                checked={checkState === 'checked'}
                                                                ref={(el) => { if (el) el.indeterminate = checkState === 'indeterminate'; }}
                                                                onChange={() => setGroupVisibility(groupKey, checkState !== 'checked')}
                                                            />
                                                        </td>
                                                        <td colSpan={5} className="py-2 text-left text-sm font-bold text-gray-800">
                                                            {columnGroups[groupKey]}
                                                        </td>
                                                    </tr>
                                                    {chunk(cols, 3).map((rowCols, ri) => (
                                                        <tr key={ri} className="border-b border-gray-50">
                                                            {rowCols.map((c) => (
                                                                <Fragment key={c.key}>
                                                                    <td className="w-8 py-2 pr-2 align-middle">
                                                                        <input type="checkbox" className={checkboxClass}
                                                                            checked={!hidden.has(c.key)} onChange={() => toggleColumn(c.key)} />
                                                                    </td>
                                                                    <td className={`w-1/3 py-2 pr-4 text-left text-sm ${c.is_active ? 'text-gray-700' : 'text-gray-400'}`}>
                                                                        {c.label}
                                                                    </td>
                                                                </Fragment>
                                                            ))}
                                                            {rowCols.length < 3 && Array.from({ length: (3 - rowCols.length) * 2 }).map((_, i) => (
                                                                <td key={`pad-${ri}-${i}`} className="py-2" />
                                                            ))}
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        );
                                    })}
                                </div>

                                <div className="border-t border-gray-100 px-5 py-4 text-center">
                                    <button type="button" onClick={() => setShowColumnModal(false)}
                                        className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-8 py-2.5 text-sm font-semibold text-white hover:bg-teal-700">
                                        保存
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}

                    {run ? (
                        <div className="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                            <div className="overflow-x-auto">
                                <table className="min-w-full border-collapse text-sm">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className={`${STICKY_COL1} bg-gray-50 px-3 py-2.5 text-left text-xs font-semibold text-gray-500`}>従業員番号</th>
                                            <th className={`${STICKY_COL2} bg-gray-50 px-3 py-2.5 text-left text-xs font-semibold text-gray-500`}>従業員</th>
                                            {visibleColumns.map((c) => (
                                                <th key={c.key} className="whitespace-nowrap px-3 py-2.5 text-right text-xs font-semibold text-gray-500">{c.label}</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {table.rows.map((row, i) =>
                                            row.is_subtotal ? (
                                                <tr key={`sub-${i}`} className="bg-teal-50 font-semibold">
                                                    <td className={`${STICKY_COL1} bg-teal-50 px-3 py-2`} />
                                                    <td className={`${STICKY_COL2} whitespace-nowrap bg-teal-50 px-3 py-2 text-teal-700`}>【{row.name}】</td>
                                                    {visibleColumns.map((c) => (
                                                        <td key={c.key} className="bg-teal-50 px-3 py-2 text-right tabular-nums text-teal-700">{yen(row.values[c.key])}</td>
                                                    ))}
                                                </tr>
                                            ) : (
                                                <tr key={i} className="hover:bg-gray-50">
                                                    <td className={`${STICKY_COL1} bg-white px-3 py-2 text-gray-500`}>{row.employee_no}</td>
                                                    <td className={`${STICKY_COL2} whitespace-nowrap bg-white px-3 py-2 font-medium text-gray-800`}>{row.name}</td>
                                                    {visibleColumns.map((c) => (
                                                        <td key={c.key} className="px-3 py-2 text-right tabular-nums text-gray-600">{yen(row.values[c.key])}</td>
                                                    ))}
                                                </tr>
                                            ),
                                        )}
                                        <tr className="bg-gray-100 font-bold">
                                            <td className={`${STICKY_COL1} bg-gray-100 px-3 py-2.5`} />
                                            <td className={`${STICKY_COL2} bg-gray-100 px-3 py-2.5 text-gray-800`}>合計</td>
                                            {visibleColumns.map((c) => (
                                                <td key={c.key} className="px-3 py-2.5 text-right tabular-nums text-gray-800">{yen(table.totals[c.key])}</td>
                                            ))}
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ) : (
                        <div className="rounded-2xl bg-white p-12 text-center text-sm text-gray-400 shadow-sm ring-1 ring-gray-100">給与バッチがありません。</div>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
