import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react';

interface Employee {
    id: number;
    name: string;
    employee_no: string | null;
    is_active: boolean;
}

type CellValue = number | string;

interface Row {
    key?: string;
    code?: string;
    name: string;
    format: 'yen' | 'hours' | 'days' | 'count' | 'text';
    values: Record<number, CellValue>;
    total: CellValue;
}

interface Section {
    type: string;
    title: string;
    rows: Row[];
}

interface MonthCol {
    month: number;
    label: string;
    period: string;
    has_data: boolean;
}

interface EmployeeMeta {
    id: number | null;
    name: string | null;
    employee_no: string | null;
    business_location: string | null;
    department: string | null;
    pay_type_label: string;
    tax_table_label: string;
    dependents_count: number;
}

interface Matrix {
    year: number;
    period?: PeriodConfig;
    months: MonthCol[];
    sections: Section[];
    employee: EmployeeMeta;
}

interface PeriodConfig {
    mode: 'calendar' | 'fiscal' | 'manual';
    label: string;
    year: number;
    fiscal_year: number | null;
    from: string | null;
    to: string | null;
}

type PeriodMode = PeriodConfig['mode'];

interface PeriodDraft {
    mode: PeriodMode;
    year: number;
    fiscalYear: number;
    fromYear: number;
    fromMonth: number;
    toYear: number;
    toMonth: number;
}

interface CatalogItem {
    key: string;
    code: string;
    name: string;
    is_active: boolean;
}

interface CatalogGroup {
    key: string;
    title: string;
    items: CatalogItem[];
}

interface DisplaySettings {
    includeZero: boolean;
    hiddenKeys: Set<string>;
}

interface Props {
    period: PeriodConfig;
    year: number;
    selectedUserId: number | null;
    selectedLocationId: number | null;
    employees: Employee[];
    matrix: Matrix | null;
    displayItemCatalog: { groups: CatalogGroup[] };
    options: { years: number[]; businessLocations: { id: number; name: string }[] };
}

const MONTHS = Array.from({ length: 12 }, (_, i) => i + 1);

function periodToDraft(period: PeriodConfig): PeriodDraft {
    const [fromYear = period.year, fromMonth = 1] = (period.from ?? `${period.year}-01`).split('-').map(Number);
    const [toYear = period.year, toMonth = 12] = (period.to ?? `${period.year}-12`).split('-').map(Number);

    return {
        mode: period.mode,
        year: period.year,
        fiscalYear: period.fiscal_year ?? period.year,
        fromYear,
        fromMonth,
        toYear,
        toMonth,
    };
}

function draftToQuery(draft: PeriodDraft): Record<string, string | number> {
    if (draft.mode === 'calendar') {
        return { period_mode: 'calendar', year: draft.year };
    }
    if (draft.mode === 'fiscal') {
        return { period_mode: 'fiscal', fiscal_year: draft.fiscalYear };
    }
    const pad = (n: number) => String(n).padStart(2, '0');
    return {
        period_mode: 'manual',
        from: `${draft.fromYear}-${pad(draft.fromMonth)}`,
        to: `${draft.toYear}-${pad(draft.toMonth)}`,
    };
}

function fiscalYearLabel(fiscalYear: number): string {
    return `${fiscalYear}年04月01日 ～ ${fiscalYear + 1}年03月31日`;
}

function periodQueryParams(period: PeriodConfig): Record<string, string | number | undefined> {
    const draft = periodToDraft(period);
    return draftToQuery(draft);
}

async function downloadFile(url: string, fallbackName: string): Promise<void> {
    const res = await fetch(url, { credentials: 'same-origin' });
    if (!res.ok) {
        const message = res.status === 422 ? '対象従業員がいません。' : 'ファイルの作成に失敗しました。';
        alert(message);
        return;
    }
    const blob = await res.blob();
    const cd = res.headers.get('Content-Disposition') ?? '';
    const m = cd.match(/filename\*=UTF-8''([^;]+)/);
    const filename = m ? decodeURIComponent(m[1]) : fallbackName;
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(link.href);
}

const STORAGE_KEY = 'wage_ledger_display_settings';
const checkboxClass = 'h-4 w-4 rounded border-gray-300 text-teal-600 focus:ring-teal-500';

/** 値を書式化する。0 / 空 は空欄にして MF の見た目に合わせる。 */
const fmt = (v: CellValue, format: Row['format']): string => {
    if (format === 'text') return typeof v === 'string' ? v : '';
    const n = typeof v === 'number' ? v : Number(v);
    if (!n) return '';
    switch (format) {
        case 'yen':
            return n.toLocaleString();
        case 'hours':
            return n.toFixed(2);
        case 'days':
            return n.toFixed(1);
        case 'count':
            return String(n);
        default:
            return String(n);
    }
};

const toolBtn = 'inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50';

function chunk<T>(items: T[], size: number): T[][] {
    const rows: T[][] = [];
    for (let i = 0; i < items.length; i += size) {
        rows.push(items.slice(i, i + size));
    }
    return rows;
}

function loadSettings(): DisplaySettings {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return { includeZero: true, hiddenKeys: new Set() };
        const parsed = JSON.parse(raw) as { includeZero?: boolean; hiddenKeys?: string[] };
        return {
            includeZero: parsed.includeZero !== false,
            hiddenKeys: new Set(parsed.hiddenKeys ?? []),
        };
    } catch {
        return { includeZero: true, hiddenKeys: new Set() };
    }
}

function inactiveKeysFromCatalog(catalog: CatalogGroup[]): Set<string> {
    const keys = new Set<string>();
    for (const group of catalog) {
        for (const item of group.items) {
            if (!item.is_active) keys.add(item.key);
        }
    }
    return keys;
}

/** 基本設定で無効な項目は常に非表示・変更不可に固定する。 */
function normalizeSettings(settings: DisplaySettings, catalog: CatalogGroup[]): DisplaySettings {
    const hiddenKeys = new Set(settings.hiddenKeys);
    for (const key of inactiveKeysFromCatalog(catalog)) {
        hiddenKeys.add(key);
    }
    return { ...settings, hiddenKeys };
}

function saveSettings(settings: DisplaySettings): void {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({
        includeZero: settings.includeZero,
        hiddenKeys: Array.from(settings.hiddenKeys),
    }));
}

function isRowVisible(row: Row, hiddenKeys: Set<string>, includeZero: boolean): boolean {
    if (row.key && hiddenKeys.has(row.key)) return false;
    if (!includeZero && row.format !== 'text') {
        const total = typeof row.total === 'number' ? row.total : Number(row.total);
        if (!total) return false;
    }
    return true;
}

export default function WageLedger({ period, year, selectedUserId, selectedLocationId, employees, matrix, displayItemCatalog, options }: Props) {
    const [search, setSearch] = useState('');
    const [showPeriod, setShowPeriod] = useState(false);
    const [periodDraft, setPeriodDraft] = useState<PeriodDraft>(() => periodToDraft(period));
    const [showItemSettings, setShowItemSettings] = useState(false);
    const [bulkBusy, setBulkBusy] = useState(false);
    const [bulkOpen, setBulkOpen] = useState(false);
    const [bulkSelect, setBulkSelect] = useState<'csv' | 'pdf' | null>(null);
    const bulkRef = useRef<HTMLDivElement>(null);
    const [transposed, setTransposed] = useState(false);
    const [fullscreen, setFullscreen] = useState(false);
    const [displaySettings, setDisplaySettings] = useState<DisplaySettings>(() =>
        normalizeSettings(loadSettings(), displayItemCatalog.groups),
    );
    const [draftSettings, setDraftSettings] = useState<DisplaySettings>(() =>
        normalizeSettings(loadSettings(), displayItemCatalog.groups),
    );

    const lockedKeys = useMemo(() => inactiveKeysFromCatalog(displayItemCatalog.groups), [displayItemCatalog.groups]);

    useEffect(() => {
        saveSettings(displaySettings);
    }, [displaySettings]);

    useEffect(() => {
        setDisplaySettings((prev) => normalizeSettings(prev, displayItemCatalog.groups));
    }, [displayItemCatalog.groups]);

    useEffect(() => {
        setPeriodDraft(periodToDraft(period));
    }, [period]);

    useEffect(() => {
        if (!bulkOpen) return;
        const onClick = (e: MouseEvent) => {
            if (bulkRef.current && !bulkRef.current.contains(e.target as Node)) {
                setBulkOpen(false);
            }
        };
        document.addEventListener('mousedown', onClick);
        return () => document.removeEventListener('mousedown', onClick);
    }, [bulkOpen]);

    const bulkQuery = useMemo(
        () => ({ ...periodQueryParams(period), location: selectedLocationId ?? undefined }),
        [period, selectedLocationId],
    );

    const openBulkSelect = (format: 'pdf' | 'csv') => {
        setBulkOpen(false);
        setBulkSelect(format);
    };

    const runBulkDownload = async (format: 'pdf' | 'csv', userIds: number[]) => {
        setBulkSelect(null);
        setBulkBusy(true);
        try {
            const routeName = format === 'pdf'
                ? 'admin.payroll.reports.wage-ledger.bulk-pdf'
                : 'admin.payroll.reports.wage-ledger.bulk-csv';
            const fallback = format === 'pdf' ? 'wage_ledger.zip' : 'wage_ledger.csv';
            await downloadFile(route(routeName, { ...bulkQuery, users: userIds }), fallback);
        } finally {
            setBulkBusy(false);
        }
    };

    const filtered = useMemo(
        () => employees.filter((e) => `${e.employee_no ?? ''} ${e.name}`.toLowerCase().includes(search.toLowerCase())),
        [employees, search],
    );

    const reload = (params: Record<string, string | number | undefined | null> = {}) =>
        router.get(
            route('admin.payroll.reports.wage-ledger'),
            {
                ...periodQueryParams(period),
                user: Object.prototype.hasOwnProperty.call(params, 'user') ? params.user ?? undefined : selectedUserId ?? undefined,
                location: Object.prototype.hasOwnProperty.call(params, 'location') ? params.location ?? undefined : selectedLocationId ?? undefined,
                ...params,
            },
            {
                preserveScroll: true,
                only: ['matrix', 'selectedUserId', 'year', 'period', 'selectedLocationId', 'employees'],
            },
        );

    const openPeriodModal = () => {
        setPeriodDraft(periodToDraft(period));
        setShowPeriod(true);
    };

    const applyPeriod = () => {
        setShowPeriod(false);
        reload(draftToQuery(periodDraft));
    };

    const periodLabel = matrix?.period?.label ?? period.label;
    const exportQuery = { ...periodQueryParams(period), location: selectedLocationId ?? undefined };
    const pdfUrl = selectedUserId ? route('admin.payroll.reports.wage-ledger.pdf', { user: selectedUserId, ...exportQuery }) : '#';
    const csvUrl = selectedUserId ? route('admin.payroll.reports.wage-ledger.csv', { user: selectedUserId, ...exportQuery }) : '#';

    const hasAnyData = useMemo(() => (matrix?.months ?? []).some((mo) => mo.has_data), [matrix]);
    const settingsActive = !displaySettings.includeZero || displaySettings.hiddenKeys.size > 0;

    const openItemSettings = () => {
        setDraftSettings(normalizeSettings(displaySettings, displayItemCatalog.groups));
        setShowItemSettings(true);
    };

    const saveItemSettings = () => {
        setDisplaySettings(normalizeSettings(draftSettings, displayItemCatalog.groups));
        setShowItemSettings(false);
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">賃金台帳（{periodLabel}）</h2>}>
            <Head title="賃金台帳" />

            <div className="px-4 py-6 sm:p-6">
                <div className="flex flex-wrap items-center gap-2 pb-3">
                    <Link href={route('admin.payroll.reports.index')}
                        className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition hover:bg-gray-50">
                        <i className="fa-solid fa-arrow-left" />
                    </Link>
                    <button type="button" onClick={openPeriodModal} className={toolBtn}>
                        <i className="fa-solid fa-calendar-days" /> 期間変更
                    </button>
                    <button type="button" onClick={() => setTransposed((v) => !v)}
                        className={`${toolBtn} ${transposed ? 'border-teal-500 bg-teal-50 text-teal-700' : ''}`}>
                        <i className="fa-solid fa-table-cells" /> 行列入れ替え
                    </button>
                    <button type="button" onClick={openItemSettings}
                        className={`${toolBtn} ${settingsActive ? 'border-teal-500 bg-teal-50 text-teal-700' : ''}`}>
                        <i className="fa-solid fa-sliders" /> 表示項目設定
                    </button>
                    {options.businessLocations.length > 0 && (
                        <div className="flex items-center gap-2">
                            <span className="text-xs font-medium text-gray-500">事業所</span>
                            <select
                                value={selectedLocationId ?? ''}
                                onChange={(e) => reload({ location: e.target.value === '' ? undefined : e.target.value, user: undefined })}
                                className="rounded-lg border-gray-300 py-1.5 text-sm focus:border-teal-500 focus:ring-teal-500"
                            >
                                <option value="">全事業所</option>
                                {options.businessLocations.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                            </select>
                        </div>
                    )}
                    <div className="relative ml-auto" ref={bulkRef}>
                        <button
                            type="button"
                            disabled={bulkBusy}
                            onClick={() => setBulkOpen((v) => !v)}
                            className={`${toolBtn} ${bulkBusy ? 'opacity-60' : ''}`}
                        >
                            <i className={`fa-solid ${bulkBusy ? 'fa-spinner fa-spin' : 'fa-layer-group'}`} />
                            一括作成
                            <i className="fa-solid fa-chevron-down text-[10px] text-gray-400" />
                        </button>
                        {bulkOpen && (
                            <div className="absolute right-0 z-30 mt-1 w-52 rounded-xl border border-gray-200 bg-white p-1 shadow-lg">
                                <button
                                    type="button"
                                    disabled={bulkBusy}
                                    onClick={() => openBulkSelect('pdf')}
                                    className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-50 disabled:text-gray-300"
                                >
                                    <i className="fa-solid fa-file-pdf w-4 text-center" />
                                    PDFの一括作成
                                </button>
                                <button
                                    type="button"
                                    disabled={bulkBusy}
                                    onClick={() => openBulkSelect('csv')}
                                    className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-50 disabled:text-gray-300"
                                >
                                    <i className="fa-solid fa-file-csv w-4 text-center" />
                                    CSVの一括作成
                                </button>
                            </div>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-[280px_1fr]">
                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div className="border-b border-gray-100 p-3">
                            <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="従業員番号 / 氏名"
                                className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                        </div>
                        <ul className="max-h-150 divide-y divide-gray-50 overflow-y-auto">
                            {filtered.map((e) => (
                                <li key={e.id}>
                                    <button onClick={() => reload({ user: e.id })}
                                        className={`flex w-full items-center justify-between px-4 py-2.5 text-left text-sm transition hover:bg-gray-50 ${selectedUserId === e.id ? 'bg-teal-50 font-semibold text-teal-700' : 'text-gray-700'}`}>
                                        <span>{e.name}</span>
                                        {!e.is_active && <span className="text-xs text-gray-400">退職</span>}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div className="min-w-0 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        {matrix ? (
                            <div key={`ledger-${selectedUserId ?? 'none'}-${periodLabel}`}>
                                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-gray-100 px-4 py-3 text-sm">
                                    <span className="font-bold text-gray-800">{matrix.employee.name}</span>
                                    {matrix.employee.employee_no && <span className="text-gray-500">No. {matrix.employee.employee_no}</span>}
                                    {matrix.employee.business_location && <span className="text-gray-500"><i className="fa-solid fa-building mr-1" />{matrix.employee.business_location}</span>}
                                    {matrix.employee.department && <span className="text-gray-500"><i className="fa-solid fa-store mr-1" />{matrix.employee.department}</span>}
                                    <span className="rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{matrix.employee.pay_type_label}</span>
                                    <span className="rounded-md bg-teal-50 px-2 py-0.5 text-xs text-teal-700">{matrix.employee.tax_table_label}</span>
                                    <span className="text-xs text-gray-500">扶養 {matrix.employee.dependents_count} 人</span>
                                    <div className="ml-auto flex items-center gap-2">
                                        <button type="button" onClick={() => setFullscreen(true)} className={toolBtn}>
                                            <i className="fa-solid fa-up-right-and-down-left-from-center" /> 大きな画面で表示
                                        </button>
                                        {selectedUserId && (
                                            <a href={pdfUrl} target="_blank" rel="noopener noreferrer" className={toolBtn}>
                                                <i className="fa-solid fa-print" /> 印刷
                                            </a>
                                        )}
                                        {selectedUserId && (
                                            <a href={csvUrl} className={toolBtn}>
                                                <i className="fa-solid fa-file-csv" /> CSVダウンロード
                                            </a>
                                        )}
                                    </div>
                                </div>
                                {!hasAnyData && (
                                    <div className="border-b border-amber-100 bg-amber-50 px-4 py-2.5 text-xs text-amber-700">
                                        <i className="fa-solid fa-circle-info mr-1.5" />
                                        {periodLabel}の確定済み給与・賞与データがありません。給与計算を確定すると各月に反映されます。
                                    </div>
                                )}
                                <MatrixTable
                                    key={`matrix-${selectedUserId ?? 'none'}-${periodLabel}`}
                                    matrix={matrix}
                                    hiddenKeys={displaySettings.hiddenKeys}
                                    includeZero={displaySettings.includeZero}
                                    transposed={transposed}
                                    maxHeightClass="max-h-[70vh]"
                                />
                            </div>
                        ) : (
                            <div className="p-12 text-center text-sm text-gray-400">従業員を選択してください。</div>
                        )}
                    </div>
                </div>
            </div>

            {showPeriod && (
                <PeriodChangeModal
                    draft={periodDraft}
                    years={options.years}
                    onChange={setPeriodDraft}
                    onApply={applyPeriod}
                    onClose={() => setShowPeriod(false)}
                />
            )}

            {showItemSettings && (
                <DisplaySettingsModal
                    catalog={displayItemCatalog.groups}
                    lockedKeys={lockedKeys}
                    settings={draftSettings}
                    onChange={setDraftSettings}
                    onSave={saveItemSettings}
                    onClose={() => setShowItemSettings(false)}
                />
            )}

            {bulkSelect && (
                <BulkSelectModal
                    format={bulkSelect}
                    employees={employees}
                    onConfirm={(ids) => runBulkDownload(bulkSelect, ids)}
                    onClose={() => setBulkSelect(null)}
                />
            )}

            {fullscreen && matrix && (
                <div className="fixed inset-0 z-50 flex flex-col bg-black/40 p-4" onClick={() => setFullscreen(false)}>
                    <div className="mx-auto flex h-full w-full max-w-400 flex-col overflow-hidden rounded-2xl bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                            <div className="flex items-center gap-3 text-sm">
                                <span className="font-bold text-gray-800">{matrix.employee.name}</span>
                                <span className="text-gray-400">{periodLabel}</span>
                            </div>
                            <div className="flex items-center gap-2">
                                <button type="button" onClick={() => setTransposed((v) => !v)}
                                    className={`${toolBtn} ${transposed ? 'border-teal-500 bg-teal-50 text-teal-700' : ''}`}>
                                    <i className="fa-solid fa-table-cells" /> 行列入れ替え
                                </button>
                                <button onClick={() => setFullscreen(false)} className="rounded px-2 py-1 text-gray-400 hover:bg-gray-100"><i className="fa-solid fa-xmark text-lg" /></button>
                            </div>
                        </div>
                        <div className="min-h-0 flex-1">
                            <MatrixTable
                                matrix={matrix}
                                hiddenKeys={displaySettings.hiddenKeys}
                                includeZero={displaySettings.includeZero}
                                transposed={transposed}
                                maxHeightClass="h-full"
                            />
                        </div>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}

/** 一括作成の対象者を選択するモーダル（MF準拠・3列チェックボックス）。 */
function BulkSelectModal({ format, employees, onConfirm, onClose }: {
    format: 'csv' | 'pdf';
    employees: Employee[];
    onConfirm: (ids: number[]) => void;
    onClose: () => void;
}) {
    const [selected, setSelected] = useState<Set<number>>(() => new Set(employees.map((e) => e.id)));

    const allChecked = employees.length > 0 && selected.size === employees.length;
    const toggle = (id: number) =>
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    const toggleAll = () =>
        setSelected(() => (allChecked ? new Set() : new Set(employees.map((e) => e.id))));

    const columns = chunk(employees, Math.ceil(employees.length / 3) || 1);
    const title = format === 'pdf' ? 'PDFの一括作成' : 'CSVの一括作成';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
            <div className="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                    <h3 className="text-sm font-bold text-gray-800">{title}</h3>
                    <button onClick={onClose} className="rounded px-1.5 py-0.5 text-gray-400 hover:bg-gray-100"><i className="fa-solid fa-xmark text-lg" /></button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    <p className="mb-3 text-sm text-gray-600">対象者を選択してください。</p>
                    <label className="mb-3 inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                        <input type="checkbox" className={checkboxClass} checked={allChecked} onChange={toggleAll} />
                        全選択
                    </label>

                    {employees.length === 0 ? (
                        <p className="rounded-lg bg-gray-50 px-4 py-6 text-center text-sm text-gray-400">対象の従業員がいません。</p>
                    ) : (
                        <div className="overflow-hidden rounded-lg border border-gray-200">
                            <div className="grid grid-cols-1 sm:grid-cols-3">
                                {columns.map((col, ci) => (
                                    <div key={ci} className={ci > 0 ? 'sm:border-l sm:border-gray-100' : ''}>
                                        <div className="flex bg-gray-50 text-xs font-bold text-gray-600">
                                            <span className="w-14 border-b border-gray-200 px-2 py-2 text-center">対象</span>
                                            <span className="flex-1 border-b border-gray-200 px-2 py-2">氏名</span>
                                        </div>
                                        {col.map((e) => (
                                            <label key={e.id} className="flex cursor-pointer items-center border-b border-gray-100 text-sm hover:bg-gray-50">
                                                <span className="w-14 px-2 py-2 text-center">
                                                    <input type="checkbox" className={checkboxClass} checked={selected.has(e.id)} onChange={() => toggle(e.id)} />
                                                </span>
                                                <span className="flex-1 px-2 py-2 text-gray-700">{e.name}</span>
                                            </label>
                                        ))}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                <div className="flex items-center justify-center gap-3 border-t border-gray-100 bg-gray-50/50 px-5 py-3">
                    <button
                        type="button"
                        disabled={selected.size === 0}
                        onClick={() => onConfirm(Array.from(selected))}
                        className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-6 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50"
                    >
                        <i className="fa-solid fa-file-arrow-down" /> 作成（{selected.size}名）
                    </button>
                    <button type="button" onClick={onClose} className="rounded-lg border border-gray-200 px-6 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">
                        キャンセル
                    </button>
                </div>
            </div>
        </div>
    );
}

function PeriodChangeModal({ draft, years, onChange, onApply, onClose }: {
    draft: PeriodDraft;
    years: number[];
    onChange: (draft: PeriodDraft) => void;
    onApply: () => void;
    onClose: () => void;
}) {
    const selectClass = (enabled: boolean) =>
        `rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500 ${enabled ? '' : 'cursor-not-allowed bg-gray-100 text-gray-400'}`;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
            <div className="w-full max-w-lg rounded-xl bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <div className="border-b border-gray-100 px-5 py-4">
                    <h3 className="text-base font-bold text-gray-800">対象期間を変更する</h3>
                </div>

                <div className="space-y-5 px-5 py-5">
                    <label className="flex cursor-pointer items-start gap-3">
                        <input
                            type="radio"
                            name="period_mode"
                            className="mt-1 h-4 w-4 border-gray-300 text-teal-600 focus:ring-teal-500"
                            checked={draft.mode === 'calendar'}
                            onChange={() => onChange({ ...draft, mode: 'calendar' })}
                        />
                        <div className="min-w-0 flex-1">
                            <div className="text-sm font-medium text-gray-800">暦年で指定する（1月～12月）</div>
                            <select
                                value={draft.year}
                                disabled={draft.mode !== 'calendar'}
                                onChange={(e) => onChange({ ...draft, year: Number(e.target.value) })}
                                className={`${selectClass(draft.mode === 'calendar')} mt-2 w-full max-w-xs`}
                            >
                                {years.map((y) => <option key={y} value={y}>{y}年</option>)}
                            </select>
                        </div>
                    </label>

                    <label className="flex cursor-pointer items-start gap-3">
                        <input
                            type="radio"
                            name="period_mode"
                            className="mt-1 h-4 w-4 border-gray-300 text-teal-600 focus:ring-teal-500"
                            checked={draft.mode === 'fiscal'}
                            onChange={() => onChange({ ...draft, mode: 'fiscal' })}
                        />
                        <div className="min-w-0 flex-1">
                            <div className="text-sm font-medium text-gray-800">年度で指定する（4月～3月）</div>
                            <select
                                value={draft.fiscalYear}
                                disabled={draft.mode !== 'fiscal'}
                                onChange={(e) => onChange({ ...draft, fiscalYear: Number(e.target.value) })}
                                className={`${selectClass(draft.mode === 'fiscal')} mt-2 w-full max-w-xs`}
                            >
                                {years.map((y) => (
                                    <option key={y} value={y}>{fiscalYearLabel(y)}</option>
                                ))}
                            </select>
                        </div>
                    </label>

                    <label className="flex cursor-pointer items-start gap-3">
                        <input
                            type="radio"
                            name="period_mode"
                            className="mt-1 h-4 w-4 border-gray-300 text-teal-600 focus:ring-teal-500"
                            checked={draft.mode === 'manual'}
                            onChange={() => onChange({ ...draft, mode: 'manual' })}
                        />
                        <div className="min-w-0 flex-1">
                            <div className="text-sm font-medium text-gray-800">手動で指定する（最大12ヶ月）</div>
                            <div className={`mt-2 flex flex-wrap items-center gap-2 ${draft.mode !== 'manual' ? 'opacity-60' : ''}`}>
                                <select
                                    value={draft.fromYear}
                                    disabled={draft.mode !== 'manual'}
                                    onChange={(e) => onChange({ ...draft, fromYear: Number(e.target.value) })}
                                    className={`${selectClass(draft.mode === 'manual')} w-24`}
                                >
                                    {years.map((y) => <option key={`from-y-${y}`} value={y}>{y}</option>)}
                                </select>
                                <select
                                    value={draft.fromMonth}
                                    disabled={draft.mode !== 'manual'}
                                    onChange={(e) => onChange({ ...draft, fromMonth: Number(e.target.value) })}
                                    className={`${selectClass(draft.mode === 'manual')} w-20`}
                                >
                                    {MONTHS.map((m) => <option key={`from-m-${m}`} value={m}>{m}月</option>)}
                                </select>
                                <span className="text-sm text-gray-400">～</span>
                                <select
                                    value={draft.toYear}
                                    disabled={draft.mode !== 'manual'}
                                    onChange={(e) => onChange({ ...draft, toYear: Number(e.target.value) })}
                                    className={`${selectClass(draft.mode === 'manual')} w-24`}
                                >
                                    {years.map((y) => <option key={`to-y-${y}`} value={y}>{y}</option>)}
                                </select>
                                <select
                                    value={draft.toMonth}
                                    disabled={draft.mode !== 'manual'}
                                    onChange={(e) => onChange({ ...draft, toMonth: Number(e.target.value) })}
                                    className={`${selectClass(draft.mode === 'manual')} w-20`}
                                >
                                    {MONTHS.map((m) => <option key={`to-m-${m}`} value={m}>{m}月</option>)}
                                </select>
                            </div>
                        </div>
                    </label>
                </div>

                <div className="flex items-center gap-3 border-t border-gray-100 px-5 py-4">
                    <button
                        type="button"
                        onClick={onApply}
                        className="rounded-lg bg-amber-600 px-6 py-2 text-sm font-semibold text-white hover:bg-amber-700"
                    >
                        変更
                    </button>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-gray-300 px-6 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
                    >
                        キャンセル
                    </button>
                </div>
            </div>
        </div>
    );
}

function DisplaySettingsModal({ catalog, lockedKeys, settings, onChange, onSave, onClose }: {
    catalog: CatalogGroup[];
    lockedKeys: Set<string>;
    settings: DisplaySettings;
    onChange: (settings: DisplaySettings) => void;
    onSave: () => void;
    onClose: () => void;
}) {
    const toggleKey = (key: string) => {
        if (lockedKeys.has(key)) return;
        const hiddenKeys = new Set(settings.hiddenKeys);
        if (hiddenKeys.has(key)) hiddenKeys.delete(key);
        else hiddenKeys.add(key);
        onChange(normalizeSettings({ ...settings, hiddenKeys }, catalog));
    };

    const setGroupVisibility = (group: CatalogGroup, visible: boolean) => {
        const hiddenKeys = new Set(settings.hiddenKeys);
        for (const item of group.items) {
            if (!item.is_active) continue;
            if (visible) hiddenKeys.delete(item.key);
            else hiddenKeys.add(item.key);
        }
        onChange(normalizeSettings({ ...settings, hiddenKeys }, catalog));
    };

    const groupCheckState = useCallback((group: CatalogGroup): 'checked' | 'unchecked' | 'indeterminate' => {
        const activeItems = group.items.filter((item) => item.is_active);
        if (activeItems.length === 0) return 'unchecked';
        const visibleCount = activeItems.filter((item) => !settings.hiddenKeys.has(item.key)).length;
        if (visibleCount === 0) return 'unchecked';
        if (visibleCount === activeItems.length) return 'checked';
        return 'indeterminate';
    }, [settings.hiddenKeys]);

    const showAllActive = () => {
        onChange(normalizeSettings({ includeZero: true, hiddenKeys: new Set(lockedKeys) }, catalog));
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
            <div className="flex max-h-[85vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                    <h3 className="text-base font-bold text-gray-800">表示項目を設定する</h3>
                    <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                        <i className="fa-solid fa-xmark text-lg" />
                    </button>
                </div>

                <div className="space-y-4 border-b border-gray-100 px-5 py-4">
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-relaxed text-amber-800">
                        <i className="fa-solid fa-circle-info mr-1.5" />
                        <span className="font-semibold">今回、基本設定に含まれていない項目</span>
                        （グレー表示・「未設定」マーク）は選択できません。将来の基本設定対応後に有効化できます。
                    </div>
                    <p className="text-sm leading-relaxed text-gray-600">
                        賃金台帳に表示する項目を設定します。<br />
                        設定はブラウザに保存されます。
                    </p>
                    <label className="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input
                            type="checkbox"
                            className={checkboxClass}
                            checked={settings.includeZero}
                            onChange={(e) => onChange({ ...settings, includeZero: e.target.checked })}
                        />
                        合計が0の項目を表示する
                        <span className="text-xs text-gray-400" title="チェックをつけると、項目合計が0時間や0円の項目も表示されます">
                            <i className="fa-solid fa-circle-question" />
                        </span>
                    </label>
                    <button
                        type="button"
                        onClick={showAllActive}
                        className="text-xs font-semibold text-teal-700 hover:text-teal-800"
                    >
                        すべて表示
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto px-5 py-4">
                    {catalog.map((group) => {
                        if (group.items.length === 0) return null;
                        const checkState = groupCheckState(group);
                        const hasActiveItems = group.items.some((item) => item.is_active);
                        return (
                            <table key={group.key} className="mb-6 w-full border-collapse text-sm">
                                <tbody>
                                    <tr className="border-b border-gray-100">
                                        <td className="w-8 py-2 pr-2 align-middle">
                                            <input
                                                type="checkbox"
                                                className={checkboxClass}
                                                checked={checkState === 'checked'}
                                                disabled={!hasActiveItems}
                                                ref={(el) => { if (el) el.indeterminate = checkState === 'indeterminate'; }}
                                                onChange={() => setGroupVisibility(group, checkState !== 'checked')}
                                            />
                                        </td>
                                        <td colSpan={5} className="py-2 text-left text-sm font-bold text-gray-800">{group.title}</td>
                                    </tr>
                                    {chunk(group.items, 3).map((rowItems, ri) => (
                                        <tr key={ri} className="border-b border-gray-50">
                                            {rowItems.map((item) => {
                                                const locked = !item.is_active;
                                                return (
                                                    <Fragment key={item.key}>
                                                        <td className="w-8 py-2 pr-2 align-middle">
                                                            <input
                                                                type="checkbox"
                                                                className={`${checkboxClass} ${locked ? 'opacity-40' : ''}`}
                                                                checked={!locked && !settings.hiddenKeys.has(item.key)}
                                                                disabled={locked}
                                                                onChange={() => toggleKey(item.key)}
                                                            />
                                                        </td>
                                                        <td className={`w-1/3 py-2 pr-4 text-left text-sm ${locked ? 'text-gray-400' : 'text-gray-700'}`}>
                                                            <span className="inline-flex items-center gap-1.5">
                                                                {item.name}
                                                                {locked && (
                                                                    <span className="rounded bg-gray-200 px-1.5 py-0.5 text-[10px] font-medium text-gray-500">未設定</span>
                                                                )}
                                                            </span>
                                                        </td>
                                                    </Fragment>
                                                );
                                            })}
                                            {rowItems.length < 3 && Array.from({ length: (3 - rowItems.length) * 2 }).map((_, i) => (
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
                    <button type="button" onClick={onSave}
                        className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-8 py-2.5 text-sm font-semibold text-white hover:bg-teal-700">
                        保存
                    </button>
                </div>
            </div>
        </div>
    );
}

/** 賃金台帳のマトリクス表。通常表示と行列入れ替え（トランスポーズ）表示に対応。 */
function MatrixTable({ matrix, hiddenKeys, includeZero, transposed, maxHeightClass }: {
    matrix: Matrix;
    hiddenKeys: Set<string>;
    includeZero: boolean;
    transposed: boolean;
    maxHeightClass: string;
}) {
    const sections = useMemo(
        () => matrix.sections
            .map((section) => ({
                ...section,
                rows: section.rows.filter((row) => isRowVisible(row, hiddenKeys, includeZero)),
            }))
            .filter((section) => section.rows.length > 0),
        [matrix.sections, hiddenKeys, includeZero],
    );

    const stickyThCorner = 'sticky left-0 top-0 z-30 border-r border-gray-200 bg-gray-50';
    const stickyTh = 'sticky top-0 z-20 bg-gray-50';
    const stickyLeftTd = 'sticky left-0 z-10 border-r border-gray-100 bg-white';

    if (transposed) {
        const flat = sections.flatMap((s) => s.rows.map((row, ri) => ({ section: s, row, key: row.key ?? `${s.type}-${ri}` })));
        return (
            <div className={`${maxHeightClass} overflow-auto`}>
                <table className="w-max border-collapse text-xs">
                    <thead>
                        <tr>
                            <th rowSpan={2} className={`${stickyThCorner} w-24 min-w-24 px-3 py-2 text-left font-semibold text-gray-500`}>月度</th>
                            {sections.map((s) => (
                                <th key={s.type} colSpan={s.rows.length} className={`${stickyTh} border-l border-gray-200 px-2 py-1.5 text-center font-bold text-teal-700`}>{s.title}</th>
                            ))}
                        </tr>
                        <tr>
                            {flat.map(({ row, key }) => (
                                <th key={key} className={`${stickyTh} top-8 w-28 min-w-28 whitespace-nowrap px-2 py-1.5 text-right font-semibold text-gray-500`}>{row.name}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {matrix.months.map((mo) => (
                            <tr key={mo.month} className="hover:bg-gray-50">
                                <td className={`${stickyLeftTd} whitespace-nowrap px-3 py-1.5 font-medium text-gray-700`}>
                                    <div>{mo.label}</div>
                                    <div className="text-[10px] font-normal text-gray-400">{mo.period}</div>
                                </td>
                                {flat.map(({ row, key }) => (
                                    <td key={key} className="px-2 py-1.5 text-right tabular-nums text-gray-600">{fmt(row.values[mo.month], row.format)}</td>
                                ))}
                            </tr>
                        ))}
                        <tr className="bg-teal-50/40 font-semibold">
                            <td className={`${stickyLeftTd} bg-teal-50/40 px-3 py-1.5 text-gray-800`}>合計</td>
                            {flat.map(({ row, key }) => (
                                <td key={key} className="px-2 py-1.5 text-right tabular-nums text-gray-800">{fmt(row.total, row.format)}</td>
                            ))}
                        </tr>
                    </tbody>
                </table>
            </div>
        );
    }

    const colCount = matrix.months.length + 2;
    return (
        <div className={`${maxHeightClass} overflow-auto`}>
            <table className="w-max border-collapse text-xs">
                <thead className="sticky top-0 z-20 bg-gray-50">
                    <tr>
                        <th className="sticky left-0 z-30 w-44 min-w-44 max-w-44 border-r border-gray-200 bg-gray-50 px-3 py-2 text-left font-semibold text-gray-500">項目</th>
                        {matrix.months.map((mo) => (
                            <th key={mo.month} className="w-24 min-w-24 px-2 py-1.5 text-right font-semibold text-gray-500">
                                <div>{mo.label}</div>
                                <div className="font-normal text-[10px] text-gray-400">{mo.period}</div>
                            </th>
                        ))}
                        <th className="w-24 min-w-24 border-l border-gray-200 bg-gray-50 px-2 py-2 text-right font-semibold text-gray-500">合計</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                    {sections.map((section) => (
                        <Fragment key={section.type}>
                            <tr className="bg-teal-50/60">
                                <td className="sticky left-0 z-10 border-r border-gray-200 bg-teal-50/60 px-3 py-1.5 text-left font-bold text-teal-700">{section.title}</td>
                                <td colSpan={colCount - 1} className="bg-teal-50/60 px-3 py-1.5" />
                            </tr>
                            {section.rows.map((row, ri) => (
                                <tr key={row.key ?? `${section.type}-${ri}`} className="hover:bg-gray-50">
                                    <td className="sticky left-0 z-10 w-44 min-w-44 max-w-44 whitespace-nowrap border-r border-gray-100 bg-white px-3 py-1.5 font-medium text-gray-700">{row.name}</td>
                                    {matrix.months.map((mo) => (
                                        <td key={mo.month} className="px-2 py-1.5 text-right tabular-nums text-gray-600">{fmt(row.values[mo.month], row.format)}</td>
                                    ))}
                                    <td className="border-l border-gray-100 px-2 py-1.5 text-right font-semibold tabular-nums text-gray-800">{fmt(row.total, row.format)}</td>
                                </tr>
                            ))}
                        </Fragment>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
