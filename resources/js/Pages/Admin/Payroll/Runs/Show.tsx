import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

interface Item {
    id: number;
    code: string;
    name: string;
    category: string | null;
    amount: number | null;
    minutes: number | null;
    quantity: number | null;
    is_manual_override: boolean;
}

interface PayslipData {
    id: number;
    user_id: number;
    user_name: string | null;
    employee_no: string | null;
    employment_type_label: string | null;
    department: string | null;
    total_earnings: number;
    total_deductions: number;
    net_pay: number;
    is_confirmed: boolean;
    remarks: string | null;
    calculated_at: string | null;
    earnings: Item[];
    deductions: Item[];
    attendances: Item[];
}

interface BonusInputRow {
    user_id: number;
    user_name: string;
    gross_amount: number;
    previous_month_taxable: number;
}

interface PeriodRun {
    id: number;
    period_key: string;
    closing_date: string | null;
    payment_date: string | null;
    status: string;
}

interface PreviousRun {
    period_key: string;
    total_earnings: number;
    total_deductions: number;
    net_pay: number;
    by_user: Record<number, { total_earnings: number; total_deductions: number; net_pay: number }>;
}

interface Props {
    run: {
        id: number;
        period_key: string;
        pay_type: string;
        business_location: string | null;
        status: string;
        closing_date: string | null;
        payment_date: string | null;
        publish_date: string | null;
        finalized_at: string | null;
        memo: string | null;
    };
    payslips: PayslipData[];
    eligibleCount: number;
    bonusInputs: BonusInputRow[];
    periodRuns: PeriodRun[];
    previousRun: PreviousRun | null;
    attendanceCategories: Record<string, string>;
    summary: {
        total_earnings: number;
        total_deductions: number;
        net_pay: number;
        confirmed_count: number;
    };
}

const yen = (v: number | null | undefined) => `¥${(v ?? 0).toLocaleString()}`;
const num = (v: number | null | undefined) => (v ?? 0).toLocaleString();

const STATUS: Record<string, { label: string; badge: string }> = {
    draft: { label: '下書き', badge: 'bg-gray-100 text-gray-600' },
    calculated: { label: '計算済', badge: 'bg-blue-100 text-blue-700' },
    finalized: { label: '確定', badge: 'bg-green-100 text-green-700' },
};

/** 勤怠4象限の表示順（設計書§3-5）。 */
const ATTENDANCE_ORDER = ['fixed_work', 'attendance', 'actual_work', 'leave'];

function fmtJpDate(d: string | null): string {
    if (!d) return '';
    const [y, m, day] = d.split('-');
    return `${y}年${m}月${day}日`;
}

function periodLabel(r: { period_key: string; payment_date: string | null; closing_date: string | null }): string {
    const pay = r.payment_date ? `${fmtJpDate(r.payment_date)}支給` : `${r.period_key} 支給`;
    const close = r.closing_date ? `（${fmtJpDate(r.closing_date)}〆）` : '';
    return `${pay}${close}`;
}

function fmtAttendance(item: Item): string {
    if (item.minutes != null) return `${(item.minutes / 60).toFixed(2)}`;
    if (item.quantity != null) return `${item.quantity}`;
    return '';
}

/** 勤怠項目が時間ベース(分)か回数ベース(数量)かを判定する。 */
function attUnit(item: Item): 'time' | 'count' {
    if (item.minutes != null) return 'time';
    if (item.quantity != null) return 'count';
    return 'time';
}

/** 勤怠項目の編集用の表示値（時間ベースは「時間」、回数ベースはそのまま）。 */
function attDisplayValue(item: Item): number {
    if (item.minutes != null) return Math.round((item.minutes / 60) * 100) / 100;
    if (item.quantity != null) return item.quantity;
    return 0;
}

/** 表示値（時間 or 回数）を表示用文字列へ整形する。 */
function fmtAttValue(item: Item, v: number): string {
    return attUnit(item) === 'time' ? v.toFixed(2) : `${v}`;
}

/** クリック外で閉じるドロップダウン。 */
function Dropdown({
    label, icon, children, align = 'right', panelClass = 'w-72',
}: {
    label: React.ReactNode;
    icon?: string;
    children: (close: () => void) => React.ReactNode;
    align?: 'left' | 'right';
    panelClass?: string;
}) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!open) return;
        const onClick = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', onClick);
        return () => document.removeEventListener('mousedown', onClick);
    }, [open]);

    return (
        <div className="relative" ref={ref}>
            <button onClick={() => setOpen((v) => !v)}
                className="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                {icon && <i className={`fa-solid ${icon}`} />}
                {label}
                <i className="fa-solid fa-chevron-down text-[10px] text-gray-400" />
            </button>
            {open && (
                <div className={`absolute z-30 mt-1 ${align === 'right' ? 'right-0' : 'left-0'} ${panelClass} rounded-xl border border-gray-200 bg-white p-1 shadow-lg`}>
                    {children(() => setOpen(false))}
                </div>
            )}
        </div>
    );
}

function MenuItem({ icon, label, onClick, disabled, danger }: {
    icon: string; label: string; onClick?: () => void; disabled?: boolean; danger?: boolean;
}) {
    return (
        <button onClick={onClick} disabled={disabled}
            className={`flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm transition ${
                disabled ? 'cursor-not-allowed text-gray-300'
                    : danger ? 'text-red-600 hover:bg-red-50' : 'text-gray-700 hover:bg-gray-50'
            }`}>
            <i className={`fa-solid ${icon} w-4 text-center`} />
            {label}
        </button>
    );
}

export default function PayrollRunShow({
    run, payslips, eligibleCount, bonusInputs, periodRuns, previousRun, attendanceCategories, summary,
}: Props) {
    const canWrite = useAdminPermission('payroll');
    const isFinalized = run.status === 'finalized';
    const editable = canWrite && !isFinalized;
    const isBonus = run.pay_type === 'bonus';

    const [bonus, setBonus] = useState<BonusInputRow[]>(bonusInputs);
    useEffect(() => setBonus(bonusInputs), [bonusInputs]);
    const [showBonusPanel, setShowBonusPanel] = useState(isBonus && payslips.length === 0);
    const patchBonus = (userId: number, key: 'gross_amount' | 'previous_month_taxable', value: number) =>
        setBonus((prev) => prev.map((b) => (b.user_id === userId ? { ...b, [key]: value } : b)));
    const saveBonus = () => {
        router.put(route('admin.payroll.runs.bonus-inputs', run.id), { inputs: bonus } as never, { preserveScroll: true });
    };

    const [selectedId, setSelectedId] = useState<number | null>(payslips[0]?.id ?? null);
    const selected = useMemo(() => payslips.find((p) => p.id === selectedId) ?? null, [payslips, selectedId]);

    const [viewMode, setViewMode] = useState<'detail' | 'bulk'>('detail');
    const [showCompare, setShowCompare] = useState(false);
    const [showDatesModal, setShowDatesModal] = useState(false);

    // 従業員一覧の絞り込み・並び替え
    const [keyword, setKeyword] = useState('');
    const [deptFilter, setDeptFilter] = useState('');
    const [confirmFilter, setConfirmFilter] = useState<'all' | 'confirmed' | 'unconfirmed'>('all');
    const [sortKey, setSortKey] = useState<'employee_no' | 'name' | 'net_pay'>('employee_no');

    const departments = useMemo(
        () => Array.from(new Set(payslips.map((p) => p.department).filter((d): d is string => !!d))).sort(),
        [payslips],
    );

    const filteredPayslips = useMemo(() => {
        const kw = keyword.trim().toLowerCase();
        const rows = payslips.filter((p) => {
            if (kw && !(`${p.user_name ?? ''} ${p.employee_no ?? ''}`.toLowerCase().includes(kw))) return false;
            if (deptFilter && p.department !== deptFilter) return false;
            if (confirmFilter === 'confirmed' && !p.is_confirmed) return false;
            if (confirmFilter === 'unconfirmed' && p.is_confirmed) return false;
            return true;
        });
        return [...rows].sort((a, b) => {
            if (sortKey === 'net_pay') return b.net_pay - a.net_pay;
            if (sortKey === 'name') return (a.user_name ?? '').localeCompare(b.user_name ?? '', 'ja');
            return (a.employee_no ?? '').localeCompare(b.employee_no ?? '', 'ja', { numeric: true });
        });
    }, [payslips, keyword, deptFilter, confirmFilter, sortKey]);

    // 手入力上書き用のローカル金額state
    const [amounts, setAmounts] = useState<Record<number, number>>({});
    // 勤怠の手入力上書き用state（時間ベースは「時間」、回数ベースは「数量」を保持）
    const [attValues, setAttValues] = useState<Record<number, number>>({});
    const [remarks, setRemarks] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [processing, setProcessing] = useState(false);

    // 選択明細が変わった時、およびサーバ再読込（保存・自動計算に戻す等）でデータが
    // 更新された時に、編集用stateをサーバの値へ同期する。
    useEffect(() => {
        if (!selected) return;
        const map: Record<number, number> = {};
        [...selected.earnings, ...selected.deductions].forEach((i) => { map[i.id] = i.amount ?? 0; });
        setAmounts(map);
        const attMap: Record<number, number> = {};
        selected.attendances.forEach((a) => { attMap[a.id] = attDisplayValue(a); });
        setAttValues(attMap);
        setRemarks(selected.remarks ?? '');
        setConfirmed(selected.is_confirmed);
    }, [selected]); // eslint-disable-line react-hooks/exhaustive-deps

    const st = STATUS[run.status] ?? STATUS.draft;

    const calculate = () => {
        setProcessing(true);
        router.post(route('admin.payroll.runs.calculate', run.id), {}, { preserveScroll: true, onFinish: () => setProcessing(false) });
    };
    const finalize = () => {
        if (confirm('このバッチを確定します。確定後は再計算・編集ができません。よろしいですか？')) {
            router.post(route('admin.payroll.runs.finalize', run.id), {}, { preserveScroll: true });
        }
    };
    const reopen = () => {
        if (confirm('確定を解除しますか？再計算・編集が可能になります。')) {
            router.post(route('admin.payroll.runs.reopen', run.id), {}, { preserveScroll: true });
        }
    };
    const resetOverrides = () => {
        if (confirm('手入力・取込データを破棄し、一括で自動計算結果に戻します。よろしいですか？')) {
            router.post(route('admin.payroll.runs.reset-overrides', run.id), {}, { preserveScroll: true });
        }
    };

    const savePayslip = () => {
        if (!selected) return;
        setProcessing(true);
        const items = [...selected.earnings, ...selected.deductions].map((i) => ({ id: i.id, amount: amounts[i.id] ?? 0 }));
        const attItems = selected.attendances.map((a) => {
            const v = attValues[a.id] ?? 0;
            return attUnit(a) === 'time'
                ? { id: a.id, minutes: Math.round(v * 60) }
                : { id: a.id, quantity: v };
        });
        router.put(
            route('admin.payroll.runs.payslips.update', { run: run.id, payslip: selected.id }),
            { items: [...items, ...attItems], remarks, is_confirmed: confirmed } as never,
            { preserveScroll: true, onFinish: () => setProcessing(false) },
        );
    };

    const liveTotals = useMemo(() => {
        if (!selected) return { earnings: 0, deductions: 0 };
        const earnings = selected.earnings.reduce((s, i) => s + (amounts[i.id] ?? 0), 0);
        const deductions = selected.deductions.reduce((s, i) => s + (amounts[i.id] ?? 0), 0);
        return { earnings, deductions };
    }, [selected, amounts]);

    // CSVインポート（ファイル選択→POST）
    const importInputRef = useRef<HTMLInputElement>(null);
    const [importType, setImportType] = useState<'earning' | 'deduction'>('earning');
    const triggerImport = (type: 'earning' | 'deduction') => {
        setImportType(type);
        importInputRef.current?.click();
    };
    const onImportFile = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        router.post(
            route('admin.payroll.runs.csv.import', run.id),
            { type: importType, file } as never,
            { forceFormData: true, preserveScroll: true },
        );
        e.target.value = '';
    };
    const csvUrl = (type: string) => route('admin.payroll.runs.csv.download', { run: run.id, type });

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">給与計算</h2>}>
            <Head title={`給与計算 ${run.period_key}`} />

            <input ref={importInputRef} type="file" accept=".csv,text/csv" className="hidden" onChange={onImportFile} />

            <div className="px-4 py-6 sm:p-6">
                {/* ヘッダ操作バー（MF準拠） */}
                <div className="mb-5 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <Link href={route('admin.payroll.runs.index')}
                                className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition hover:bg-gray-50">
                                <i className="fa-solid fa-arrow-left" />
                            </Link>
                            {/* 支給期間セレクタ */}
                            <Dropdown align="left" panelClass="w-80"
                                label={<span className="text-base font-bold text-gray-900">{periodLabel(run)}</span>}>
                                {(close) => (
                                    <div className="max-h-80 overflow-y-auto">
                                        {periodRuns.map((r) => (
                                            <button key={r.id}
                                                onClick={() => { close(); if (r.id !== run.id) router.get(route('admin.payroll.runs.show', r.id)); }}
                                                className={`flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm transition ${
                                                    r.id === run.id ? 'bg-teal-50 text-teal-700' : 'text-gray-700 hover:bg-gray-50'
                                                }`}>
                                                <span>{periodLabel(r)}</span>
                                                {r.status === 'finalized' && <i className="fa-solid fa-check text-xs text-green-500" />}
                                            </button>
                                        ))}
                                        {periodRuns.length === 0 && <p className="px-3 py-4 text-center text-xs text-gray-400">期間がありません。</p>}
                                    </div>
                                )}
                            </Dropdown>
                            <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${st.badge}`}>{st.label}</span>
                            <span className="text-sm text-gray-500">対象者数：{payslips.length || eligibleCount}</span>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            {/* メモ */}
                            <MemoDropdown runId={run.id} initial={run.memo} editable={canWrite} />

                            {/* 表示切替 */}
                            <Dropdown label="表示切替" icon="fa-solid fa-table-cells">
                                {(close) => (
                                    <>
                                        <MenuItem icon="fa-list" label="通常モード" onClick={() => { setViewMode('detail'); close(); }} />
                                        <MenuItem icon="fa-table-list" label="一括入力モード" onClick={() => { setViewMode('bulk'); close(); }} />
                                        <a href={`${route('admin.payroll.reports.summary')}?run=${run.id}`}
                                            className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-50">
                                            <i className="fa-solid fa-table-columns w-4 text-center" />支給控除一覧表形式
                                        </a>
                                    </>
                                )}
                            </Dropdown>

                            {/* 振込業務 */}
                            {payslips.length > 0 && (
                                <Dropdown label="振込業務" icon="fa-solid fa-building-columns">
                                    {() => (
                                        <>
                                            <a href={route('admin.payroll.transfers.show', run.id)}
                                                className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-50">
                                                <i className="fa-solid fa-list w-4 text-center" />給与振込一覧表
                                            </a>
                                            <a href={route('admin.payroll.transfers.fb-data', run.id)}
                                                className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-50">
                                                <i className="fa-solid fa-file-arrow-down w-4 text-center" />給与振込FBデータ出力
                                            </a>
                                            <a href={route('admin.payroll.resident-tax.show', run.id)}
                                                className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-50">
                                                <i className="fa-solid fa-city w-4 text-center" />住民税一覧表
                                            </a>
                                            <a href={route('admin.payroll.resident-tax.csv', run.id)}
                                                className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-50">
                                                <i className="fa-solid fa-file-csv w-4 text-center" />住民税CSV
                                            </a>
                                        </>
                                    )}
                                </Dropdown>
                            )}

                            {/* メニュー */}
                            <Dropdown label="メニュー" icon="fa-solid fa-ellipsis">
                                {(close) => (
                                    <>
                                        <MenuItem icon="fa-calendar-days" label="締め日/支給日/公開日の変更"
                                            disabled={!canWrite} onClick={() => { setShowDatesModal(true); close(); }} />
                                        <div className="my-1 border-t border-gray-100" />
                                        {payslips.length > 0 && (
                                            <>
                                                <a href={csvUrl('earning')} className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-50"><i className="fa-solid fa-file-csv w-4 text-center" />支給CSVダウンロード</a>
                                                <a href={csvUrl('deduction')} className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-50"><i className="fa-solid fa-file-csv w-4 text-center" />控除CSVダウンロード</a>
                                                <a href={csvUrl('attendance')} className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-50"><i className="fa-solid fa-file-csv w-4 text-center" />勤怠CSVダウンロード</a>
                                                {editable && (
                                                    <>
                                                        <div className="my-1 border-t border-gray-100" />
                                                        <MenuItem icon="fa-file-import" label="支給CSVインポート" onClick={() => { triggerImport('earning'); close(); }} />
                                                        <MenuItem icon="fa-file-import" label="控除CSVインポート" onClick={() => { triggerImport('deduction'); close(); }} />
                                                        <MenuItem icon="fa-rotate-left" label="一括で自動計算に戻す" onClick={() => { resetOverrides(); close(); }} />
                                                    </>
                                                )}
                                            </>
                                        )}
                                        {isFinalized && canWrite && (
                                            <>
                                                <div className="my-1 border-t border-gray-100" />
                                                <MenuItem icon="fa-lock-open" label="給与の確定を取消" danger onClick={() => { reopen(); close(); }} />
                                            </>
                                        )}
                                    </>
                                )}
                            </Dropdown>

                            {previousRun && (
                                <button onClick={() => setShowCompare(true)}
                                    className="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                                    <i className="fa-solid fa-scale-balanced" />前月比較
                                </button>
                            )}

                            {canWrite && (
                                <>
                                    {isBonus && !isFinalized && (
                                        <button onClick={() => setShowBonusPanel((v) => !v)}
                                            className="inline-flex items-center gap-2 rounded-lg border border-teal-600 px-3 py-2 text-sm font-semibold text-teal-700 transition hover:bg-teal-50">
                                            <i className="fa-solid fa-yen-sign" />賞与額入力
                                        </button>
                                    )}
                                    {!isFinalized && (
                                        <button onClick={calculate} disabled={processing}
                                            className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                                            <i className="fa-solid fa-calculator" />
                                            {isBonus ? '賞与計算を実行' : '給与計算を実行'}（対象{eligibleCount}名）
                                        </button>
                                    )}
                                    {!isFinalized && payslips.length > 0 && (
                                        <button onClick={finalize}
                                            className="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-green-700">
                                            <i className="fa-solid fa-lock" />確定処理
                                        </button>
                                    )}
                                    {isFinalized && (
                                        <button onClick={reopen}
                                            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                                            <i className="fa-solid fa-lock-open" />確定解除
                                        </button>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                    <p className="mt-2 text-xs text-gray-400">
                        {run.business_location ?? '全事業所'} ・ {isBonus ? '賞与' : '給与'}
                        {run.closing_date && ` ・ 締め日 ${run.closing_date}`}
                        {run.payment_date && ` ・ 支給日 ${run.payment_date}`}
                        {run.publish_date && ` ・ 公開日 ${run.publish_date}`}
                        {run.finalized_at && ` ・ 確定 ${run.finalized_at}`}
                    </p>
                </div>

                {/* 賞与額入力パネル */}
                {isBonus && showBonusPanel && (
                    <div className="mb-5 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                            <div>
                                <h3 className="text-sm font-bold text-gray-700">賞与額入力</h3>
                                <p className="text-xs text-gray-400">総支給額と前月給与（社保控除後）を入力し、保存後に「賞与計算を実行」してください。</p>
                            </div>
                            {editable && (
                                <button onClick={saveBonus}
                                    className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700">
                                    <i className="fa-solid fa-floppy-disk" />賞与額を保存
                                </button>
                            )}
                        </div>
                        <div className="max-h-96 overflow-y-auto">
                            <table className="min-w-full divide-y divide-gray-100">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500">従業員</th>
                                        <th className="px-4 py-2 text-right text-xs font-semibold text-gray-500">賞与総支給額</th>
                                        <th className="px-4 py-2 text-right text-xs font-semibold text-gray-500">前月給与(社保控除後)</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50">
                                    {bonus.map((b) => (
                                        <tr key={b.user_id}>
                                            <td className="px-4 py-2 text-sm text-gray-700">{b.user_name}</td>
                                            <td className="px-4 py-2 text-right">
                                                <input type="number" min="0" disabled={!editable}
                                                    className="w-32 rounded-lg border-gray-300 text-right text-sm tabular-nums shadow-sm focus:border-teal-500 focus:ring-teal-500 disabled:bg-gray-50"
                                                    value={b.gross_amount}
                                                    onChange={(e) => patchBonus(b.user_id, 'gross_amount', Number(e.target.value))} />
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                <input type="number" min="0" disabled={!editable}
                                                    className="w-32 rounded-lg border-gray-300 text-right text-sm tabular-nums shadow-sm focus:border-teal-500 focus:ring-teal-500 disabled:bg-gray-50"
                                                    value={b.previous_month_taxable}
                                                    onChange={(e) => patchBonus(b.user_id, 'previous_month_taxable', Number(e.target.value))} />
                                            </td>
                                        </tr>
                                    ))}
                                    {bonus.length === 0 && (
                                        <tr><td colSpan={3} className="px-4 py-8 text-center text-xs text-gray-400">対象従業員がいません。</td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {payslips.length === 0 ? (
                    <div className="rounded-2xl bg-white px-6 py-16 text-center text-sm text-gray-400 shadow-sm ring-1 ring-gray-100">
                        <i className="fa-solid fa-calculator mb-3 text-3xl" />
                        <p>まだ計算されていません。「給与計算を実行」で対象{eligibleCount}名を計算します。</p>
                    </div>
                ) : viewMode === 'bulk' ? (
                    <BulkInput run={run} payslips={payslips} editable={editable} onExit={() => setViewMode('detail')} />
                ) : (
                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-[340px_1fr]">
                        {/* 左: 従業員一覧 */}
                        <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                            <div className="space-y-2 border-b border-gray-100 p-3">
                                <div className="relative">
                                    <i className="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400" />
                                    <input value={keyword} onChange={(e) => setKeyword(e.target.value)} placeholder="従業員番号 / 氏名"
                                        className="w-full rounded-lg border-gray-300 pl-8 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500" />
                                </div>
                                <div className="grid grid-cols-2 gap-2">
                                    <select value={deptFilter} onChange={(e) => setDeptFilter(e.target.value)}
                                        className="rounded-lg border-gray-300 text-xs shadow-sm focus:border-teal-500 focus:ring-teal-500">
                                        <option value="">全部門</option>
                                        {departments.map((d) => <option key={d} value={d}>{d}</option>)}
                                    </select>
                                    <select value={confirmFilter} onChange={(e) => setConfirmFilter(e.target.value as typeof confirmFilter)}
                                        className="rounded-lg border-gray-300 text-xs shadow-sm focus:border-teal-500 focus:ring-teal-500">
                                        <option value="all">確認状況：すべて</option>
                                        <option value="confirmed">確認済のみ</option>
                                        <option value="unconfirmed">未確認のみ</option>
                                    </select>
                                </div>
                                <select value={sortKey} onChange={(e) => setSortKey(e.target.value as typeof sortKey)}
                                    className="w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-teal-500 focus:ring-teal-500">
                                    <option value="employee_no">並び：従業員番号順</option>
                                    <option value="name">並び：氏名順</option>
                                    <option value="net_pay">並び：差引支給額が高い順</option>
                                </select>
                            </div>
                            <div className="flex items-center justify-between px-4 py-2 text-xs text-gray-400">
                                <span>該当 {filteredPayslips.length} 名 / 全 {payslips.length} 名</span>
                                <span>確認済 {summary.confirmed_count} / {payslips.length}</span>
                            </div>
                            <div className="max-h-150 overflow-y-auto">
                                {filteredPayslips.map((p) => (
                                    <button key={p.id} onClick={() => setSelectedId(p.id)}
                                        className={`flex w-full items-center justify-between gap-2 border-b border-gray-50 px-4 py-3 text-left transition ${
                                            p.id === selectedId ? 'bg-teal-50' : 'hover:bg-gray-50'
                                        }`}>
                                        <span className={`flex h-6 w-6 flex-none items-center justify-center rounded-full text-xs ${
                                            p.is_confirmed ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-300'
                                        }`}>
                                            <i className="fa-solid fa-check" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-1.5">
                                                {p.employee_no && <span className="flex-none text-[11px] font-medium tabular-nums text-gray-500">{p.employee_no}</span>}
                                                <span className="truncate text-sm font-semibold text-gray-900">{p.user_name}</span>
                                                {p.employment_type_label && (
                                                    <span className="flex-none rounded bg-teal-50 px-1.5 py-0.5 text-[10px] font-semibold text-teal-700 ring-1 ring-teal-100">{p.employment_type_label}</span>
                                                )}
                                            </div>
                                            <div className="mt-0.5 truncate text-xs text-gray-600">
                                                <span className="text-gray-500">{p.department ?? '—'}</span>
                                                <span className="mx-1 text-gray-300">・</span>
                                                差引 <span className="font-semibold text-gray-800 tabular-nums">{yen(p.net_pay)}</span>
                                            </div>
                                        </div>
                                        <i className="fa-solid fa-chevron-right text-[10px] text-gray-400" />
                                    </button>
                                ))}
                                {filteredPayslips.length === 0 && (
                                    <p className="px-4 py-10 text-center text-xs text-gray-400">条件に一致する従業員がいません。</p>
                                )}
                            </div>
                        </div>

                        {/* 右: 選択明細 */}
                        {selected && (
                            <div className="space-y-5">
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white px-5 py-4 shadow-sm ring-1 ring-gray-100">
                                    <div className="flex items-center gap-3">
                                        <span className="flex h-11 w-11 items-center justify-center rounded-full bg-teal-50 text-teal-600">
                                            <i className="fa-solid fa-user" />
                                        </span>
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <Link href={route('admin.users.show', selected.user_id)} className="text-base font-bold text-teal-700 hover:underline">{selected.user_name}</Link>
                                                {selected.employment_type_label && (
                                                    <span className="inline-flex items-center rounded bg-teal-50 px-1.5 py-0.5 text-[11px] font-semibold text-teal-700 ring-1 ring-teal-100">{selected.employment_type_label}</span>
                                                )}
                                                {selected.is_confirmed
                                                    ? <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700"><i className="fa-solid fa-circle-check" />確認済</span>
                                                    : <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">未確認</span>}
                                            </div>
                                            <p className="text-xs text-gray-500">
                                                {selected.employee_no && `No.${selected.employee_no} ・ `}{selected.department ?? '部門未設定'}
                                                {selected.calculated_at && ` ・ 計算 ${selected.calculated_at}`}
                                            </p>
                                        </div>
                                    </div>
                                    <Link href={route('admin.users.show', selected.user_id)}
                                        className="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-50">
                                        <i className="fa-solid fa-id-card" />従業員情報
                                    </Link>
                                </div>

                                {/* 上段: 支給 / 控除 / 差引合計 */}
                                <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                                    <ItemColumn runId={run.id} payslipId={selected.id} title="支給" items={selected.earnings} total={liveTotals.earnings}
                                        editable={editable} amounts={amounts} setAmounts={setAmounts} />
                                    <ItemColumn runId={run.id} payslipId={selected.id} title="控除" items={selected.deductions} total={liveTotals.deductions}
                                        editable={editable} amounts={amounts} setAmounts={setAmounts} />
                                    <NetColumn earnings={liveTotals.earnings} deductions={liveTotals.deductions} />
                                </div>

                                {/* 備考・確認・保存 */}
                                <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                                    <label className="mb-1 block text-xs font-medium text-gray-500">備考（給与明細に反映されます）</label>
                                    <textarea rows={2} disabled={!editable}
                                        className="w-full rounded-lg border-gray-300 text-[13px] shadow-sm focus:border-teal-500 focus:ring-teal-500 disabled:bg-gray-50"
                                        value={remarks} onChange={(e) => setRemarks(e.target.value)} />
                                    <div className="mt-3 flex items-center justify-between">
                                        <label className="inline-flex items-center gap-2 text-sm text-gray-700">
                                            <input type="checkbox" className="h-4 w-4 rounded border-gray-300 text-teal-600 focus:ring-teal-500"
                                                disabled={!editable} checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} />
                                            この明細を確認済みにする
                                        </label>
                                        {editable && (
                                            <button onClick={savePayslip} disabled={processing}
                                                className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                                                <i className="fa-solid fa-floppy-disk" />保存する
                                            </button>
                                        )}
                                    </div>
                                </div>

                                {/* 勤怠 4象限 */}
                                <AttendanceQuadrants runId={run.id} payslipId={selected.id} attendances={selected.attendances}
                                    categories={attendanceCategories} editable={editable} attValues={attValues} setAttValues={setAttValues} />
                            </div>
                        )}
                    </div>
                )}
            </div>

            {showDatesModal && (
                <DatesModal run={run} onClose={() => setShowDatesModal(false)} />
            )}
            {showCompare && previousRun && (
                <ComparePanel current={summary} previous={previousRun} payslips={payslips} onClose={() => setShowCompare(false)} />
            )}
        </AdminLayout>
    );
}

function ItemColumn({
    runId, payslipId, title, items, total, editable, amounts, setAmounts,
}: {
    runId: number;
    payslipId: number;
    title: string;
    items: Item[];
    total: number;
    editable: boolean;
    amounts: Record<number, number>;
    setAmounts: React.Dispatch<React.SetStateAction<Record<number, number>>>;
}) {
    const [editingId, setEditingId] = useState<number | null>(null);

    const revert = (itemId: number) => {
        router.post(
            route('admin.payroll.runs.items.revert', { run: runId, payslip: payslipId, item: itemId }),
            {},
            { preserveScroll: true },
        );
    };

    return (
        <div className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
            <div className="flex items-center justify-center gap-1.5 bg-gray-100 px-3 py-1.5 text-center text-[13px] font-bold text-gray-600">
                {title}
                {editable && (
                    <span
                        className="inline-flex text-gray-400"
                        title="金額をクリックすると、任意に修正ができます"
                    >
                        <i className="fa-solid fa-pen text-[10px]" aria-hidden="true" />
                    </span>
                )}
            </div>
            <table className="min-w-full">
                <tbody className="divide-y divide-gray-100">
                    {items.map((i) => (
                        <tr key={i.id}>
                            <td className="px-3 py-1.5 text-[13px] text-gray-700">{i.name}</td>
                            <td className="py-1 pr-3 text-right">
                                <div className="flex items-center justify-end gap-2">
                                    {i.is_manual_override && editable && (
                                        <button type="button" onClick={() => revert(i.id)}
                                            className="text-[13px] text-amber-500 transition hover:text-amber-600"
                                            title="自動計算の金額に戻す">
                                            <i className="fa-solid fa-reply" />
                                        </button>
                                    )}
                                    {editable && editingId === i.id ? (
                                        <input type="number" autoFocus
                                            className="w-24 rounded border-gray-200 px-2 py-0.5 text-right text-[13px] tabular-nums shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                            value={amounts[i.id] ?? 0}
                                            onChange={(e) => setAmounts((prev) => ({ ...prev, [i.id]: Number(e.target.value) }))}
                                            onBlur={() => setEditingId(null)}
                                            onKeyDown={(e) => { if (e.key === 'Enter') setEditingId(null); }} />
                                    ) : (
                                        <span
                                            className={`text-[13px] tabular-nums text-gray-800 ${editable ? 'cursor-pointer rounded px-2 py-0.5 hover:bg-teal-50' : ''}`}
                                            title={editable ? 'クリックして修正' : undefined}
                                            onClick={() => editable && setEditingId(i.id)}>
                                            {num(amounts[i.id] ?? i.amount ?? 0)}
                                        </span>
                                    )}
                                </div>
                            </td>
                        </tr>
                    ))}
                    {items.length === 0 && (
                        <tr><td className="px-3 py-4 text-center text-xs text-gray-400">項目なし</td></tr>
                    )}
                </tbody>
                <tfoot>
                    <tr className="border-t border-gray-200 bg-gray-100">
                        <td className="px-3 py-1.5 text-[13px] font-bold text-gray-700">合計</td>
                        <td className="px-3 py-1.5 text-right text-[13px] font-bold tabular-nums text-gray-900">{num(total)}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

function NetColumn({ earnings, deductions }: { earnings: number; deductions: number }) {
    const net = earnings - deductions;
    return (
        <div className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
            <div className="bg-gray-100 px-3 py-1.5 text-center text-[13px] font-bold text-gray-600">差引合計</div>
            <table className="min-w-full">
                <tbody className="divide-y divide-gray-100">
                    <tr>
                        <td className="px-3 py-1.5 text-[13px] text-gray-700">振込支給額</td>
                        <td className="px-3 py-1.5 text-right text-[13px] tabular-nums text-gray-900">{num(net)}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr className="border-t border-gray-200 bg-teal-600">
                        <td className="px-3 py-1.5 text-[13px] font-bold text-teal-50">差引支給合計</td>
                        <td className="px-3 py-1.5 text-right text-[13px] font-bold tabular-nums text-white">{num(net)}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

function AttendanceQuadrants({
    runId, payslipId, attendances, categories, editable, attValues, setAttValues,
}: {
    runId: number;
    payslipId: number;
    attendances: Item[];
    categories: Record<string, string>;
    editable: boolean;
    attValues: Record<number, number>;
    setAttValues: React.Dispatch<React.SetStateAction<Record<number, number>>>;
}) {
    const [editingId, setEditingId] = useState<number | null>(null);

    const revert = (itemId: number) => {
        router.post(
            route('admin.payroll.runs.items.revert', { run: runId, payslip: payslipId, item: itemId }),
            {},
            { preserveScroll: true },
        );
    };

    const grouped = useMemo(() => {
        const map: Record<string, Item[]> = {};
        for (const a of attendances) {
            const key = a.category && categories[a.category] ? a.category : 'actual_work';
            (map[key] ??= []).push(a);
        }
        return map;
    }, [attendances, categories]);

    const keys = useMemo(() => {
        const extra = Object.keys(grouped).filter((k) => !ATTENDANCE_ORDER.includes(k));
        return [...ATTENDANCE_ORDER, ...extra];
    }, [grouped]);

    return (
        <div className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
            <div className="flex items-center gap-1.5 border-b border-gray-100 px-3 py-2 text-[13px] font-bold text-gray-700">
                <i className="fa-solid fa-clock text-teal-600" />勤怠
                {editable && (
                    <span
                        className="inline-flex text-gray-400"
                        title="時間・日数をクリックすると、任意に修正ができます"
                    >
                        <i className="fa-solid fa-pen text-[10px]" aria-hidden="true" />
                    </span>
                )}
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2">
                {keys.map((key, idx) => (
                    <div key={key} className={`border-gray-100 ${idx % 2 === 0 ? 'md:border-r' : ''} border-b`}>
                        <div className="bg-gray-100 px-3 py-1.5 text-xs font-bold text-gray-500">{categories[key] ?? key}</div>
                        <table className="min-w-full">
                            <tbody className="divide-y divide-gray-100">
                                {(grouped[key] ?? []).map((a) => (
                                    <tr key={a.id}>
                                        <td className="px-3 py-1.5 text-[13px] text-gray-700">{a.name}</td>
                                        <td className="py-1 pr-3 text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                {a.is_manual_override && editable && (
                                                    <button type="button" onClick={() => revert(a.id)}
                                                        className="text-[13px] text-amber-500 transition hover:text-amber-600"
                                                        title="自動計算の値に戻す">
                                                        <i className="fa-solid fa-reply" />
                                                    </button>
                                                )}
                                                {editable && editingId === a.id ? (
                                                    <input type="number" step={attUnit(a) === 'time' ? '0.01' : '1'} min="0" autoFocus
                                                        className="w-20 rounded border-gray-200 px-2 py-0.5 text-right text-[13px] tabular-nums shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                                        value={attValues[a.id] ?? 0}
                                                        onChange={(e) => setAttValues((prev) => ({ ...prev, [a.id]: Number(e.target.value) }))}
                                                        onBlur={() => setEditingId(null)}
                                                        onKeyDown={(e) => { if (e.key === 'Enter') setEditingId(null); }} />
                                                ) : (
                                                    <span
                                                        className={`text-[13px] tabular-nums text-gray-600 ${editable ? 'cursor-pointer rounded px-2 py-0.5 hover:bg-teal-50' : ''}`}
                                                        title={editable ? 'クリックして修正' : undefined}
                                                        onClick={() => editable && setEditingId(a.id)}>
                                                        {fmtAttValue(a, attValues[a.id] ?? attDisplayValue(a))}
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {(grouped[key] ?? []).length === 0 && (
                                    <tr><td className="px-3 py-2 text-xs text-gray-300">&nbsp;</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                ))}
            </div>
        </div>
    );
}

function MemoDropdown({ runId, initial, editable }: { runId: number; initial: string | null; editable: boolean }) {
    const [memo, setMemo] = useState(initial ?? '');
    useEffect(() => setMemo(initial ?? ''), [initial]);
    const save = (close: () => void) => {
        router.patch(route('admin.payroll.runs.memo.update', runId), { memo } as never, { preserveScroll: true, onSuccess: close });
    };
    return (
        <Dropdown label="メモ" icon="fa-solid fa-note-sticky" panelClass="w-80">
            {(close) => (
                <div className="p-2">
                    <textarea rows={4} disabled={!editable}
                        className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 disabled:bg-gray-50"
                        placeholder="この期間のメモ（担当者用）" value={memo} onChange={(e) => setMemo(e.target.value)} />
                    <p className="mt-1 text-[11px] text-gray-400">記載内容は従業員の給与明細には表示されません。</p>
                    {editable && (
                        <button onClick={() => save(close)}
                            className="mt-2 w-full rounded-lg bg-teal-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-teal-700">
                            保存
                        </button>
                    )}
                </div>
            )}
        </Dropdown>
    );
}

function DatesModal({ run, onClose }: {
    run: { id: number; closing_date: string | null; payment_date: string | null; publish_date: string | null };
    onClose: () => void;
}) {
    const [closing, setClosing] = useState(run.closing_date ?? '');
    const [payment, setPayment] = useState(run.payment_date ?? '');
    const [publish, setPublish] = useState(run.publish_date ?? '');
    const save = () => {
        router.patch(route('admin.payroll.runs.dates.update', run.id),
            { closing_date: closing || null, payment_date: payment || null, publish_date: publish || null } as never,
            { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/30" onClick={onClose} />
            <div className="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <h3 className="mb-4 text-base font-bold text-gray-800">締め日 / 支給日 / 公開日の変更</h3>
                <div className="space-y-3">
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500">締め日</label>
                        <input type="date" value={closing} onChange={(e) => setClosing(e.target.value)}
                            className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500" />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500">支給日</label>
                        <input type="date" value={payment} onChange={(e) => setPayment(e.target.value)}
                            className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500" />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500">公開日</label>
                        <input type="date" value={publish} onChange={(e) => setPublish(e.target.value)}
                            className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500" />
                    </div>
                </div>
                <div className="mt-5 flex justify-end gap-2">
                    <button onClick={onClose} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">キャンセル</button>
                    <button onClick={save} className="rounded-lg bg-teal-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-teal-700">保存する</button>
                </div>
            </div>
        </div>
    );
}

function ComparePanel({ current, previous, payslips, onClose }: {
    current: { total_earnings: number; total_deductions: number; net_pay: number };
    previous: PreviousRun;
    payslips: PayslipData[];
    onClose: () => void;
}) {
    const diff = (a: number, b: number) => {
        const d = a - b;
        const cls = d > 0 ? 'text-green-600' : d < 0 ? 'text-red-600' : 'text-gray-400';
        const sign = d > 0 ? '+' : '';
        return <span className={`tabular-nums ${cls}`}>{sign}{num(d)}</span>;
    };
    return (
        <div className="fixed inset-0 z-50 flex justify-end">
            <div className="absolute inset-0 bg-black/30" onClick={onClose} />
            <div className="relative flex h-full w-full max-w-2xl flex-col bg-white shadow-2xl">
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                    <h3 className="text-base font-bold text-gray-800">前月比較（{previous.period_key} → 当月）</h3>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-700"><i className="fa-solid fa-xmark text-lg" /></button>
                </div>
                <div className="border-b border-gray-100 p-5">
                    <div className="grid grid-cols-3 gap-3 text-center">
                        {[
                            { label: '支給合計', cur: current.total_earnings, prev: previous.total_earnings },
                            { label: '控除合計', cur: current.total_deductions, prev: previous.total_deductions },
                            { label: '差引支給額', cur: current.net_pay, prev: previous.net_pay },
                        ].map((c) => (
                            <div key={c.label} className="rounded-xl bg-gray-50 p-3">
                                <p className="text-xs text-gray-400">{c.label}</p>
                                <p className="mt-0.5 text-sm font-bold tabular-nums text-gray-900">{yen(c.cur)}</p>
                                <p className="text-xs">{diff(c.cur, c.prev)}</p>
                            </div>
                        ))}
                    </div>
                </div>
                <div className="flex-1 overflow-y-auto">
                    <table className="min-w-full">
                        <thead className="sticky top-0 bg-gray-50">
                            <tr>
                                <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500">従業員</th>
                                <th className="px-4 py-2 text-right text-xs font-semibold text-gray-500">当月 差引</th>
                                <th className="px-4 py-2 text-right text-xs font-semibold text-gray-500">前月 差引</th>
                                <th className="px-4 py-2 text-right text-xs font-semibold text-gray-500">差額</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {payslips.map((p) => {
                                const prev = previous.by_user[p.user_id];
                                return (
                                    <tr key={p.id}>
                                        <td className="px-4 py-2 text-sm text-gray-700">{p.user_name}</td>
                                        <td className="px-4 py-2 text-right text-sm tabular-nums text-gray-700">{num(p.net_pay)}</td>
                                        <td className="px-4 py-2 text-right text-sm tabular-nums text-gray-400">{prev ? num(prev.net_pay) : '—'}</td>
                                        <td className="px-4 py-2 text-right text-sm">{prev ? diff(p.net_pay, prev.net_pay) : '—'}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

function BulkInput({ run, payslips, editable, onExit }: {
    run: { id: number };
    payslips: PayslipData[];
    editable: boolean;
    onExit: () => void;
}) {
    // 一括入力する項目（支給・控除の項目名から選択）
    const itemNames = useMemo(() => {
        const names: { name: string; type: 'earning' | 'deduction' }[] = [];
        const seen = new Set<string>();
        for (const p of payslips) {
            for (const e of p.earnings) if (!seen.has(`e:${e.name}`)) { seen.add(`e:${e.name}`); names.push({ name: e.name, type: 'earning' }); }
            for (const d of p.deductions) if (!seen.has(`d:${d.name}`)) { seen.add(`d:${d.name}`); names.push({ name: d.name, type: 'deduction' }); }
        }
        return names;
    }, [payslips]);

    const [target, setTarget] = useState(itemNames[0]?.name ?? '');
    const targetType = itemNames.find((n) => n.name === target)?.type ?? 'earning';

    // 対象項目の payslip別 item を取得
    const rows = useMemo(() => payslips.map((p) => {
        const list = targetType === 'earning' ? p.earnings : p.deductions;
        const item = list.find((i) => i.name === target);
        return { payslip: p, item };
    }), [payslips, target, targetType]);

    const [values, setValues] = useState<Record<number, number>>({});
    useEffect(() => {
        const map: Record<number, number> = {};
        rows.forEach((r) => { if (r.item) map[r.item.id] = r.item.amount ?? 0; });
        setValues(map);
    }, [target]); // eslint-disable-line react-hooks/exhaustive-deps

    const save = () => {
        const items = rows.filter((r) => r.item).map((r) => ({ id: r.item!.id, amount: values[r.item!.id] ?? 0 }));
        router.put(route('admin.payroll.runs.bulk-update', run.id), { items } as never, { preserveScroll: true });
    };

    return (
        <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 p-4">
                <div className="flex items-center gap-3">
                    <h3 className="text-sm font-bold text-gray-700">一括入力モード</h3>
                    <select value={target} onChange={(e) => setTarget(e.target.value)}
                        className="rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                        {itemNames.map((n) => <option key={`${n.type}:${n.name}`} value={n.name}>{n.type === 'earning' ? '[支給] ' : '[控除] '}{n.name}</option>)}
                    </select>
                </div>
                <div className="flex items-center gap-2">
                    <button onClick={onExit} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">通常モードに戻る</button>
                    {editable && (
                        <button onClick={save} className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-teal-700">
                            <i className="fa-solid fa-floppy-disk" />更新する
                        </button>
                    )}
                </div>
            </div>
            <div className="max-h-150 overflow-y-auto">
                <table className="min-w-full divide-y divide-gray-100">
                    <thead className="sticky top-0 bg-gray-50">
                        <tr>
                            <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500">従業員番号</th>
                            <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500">氏名</th>
                            <th className="px-4 py-2 text-right text-xs font-semibold text-gray-500">{target || '項目'}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {rows.map(({ payslip: p, item }) => (
                            <tr key={p.id}>
                                <td className="px-4 py-2 text-sm tabular-nums text-gray-400">{p.employee_no ?? '—'}</td>
                                <td className="px-4 py-2 text-sm text-gray-700">{p.user_name}</td>
                                <td className="px-4 py-2 text-right">
                                    {item ? (
                                        editable ? (
                                            <input type="number"
                                                className="w-32 rounded-lg border-gray-300 text-right text-sm tabular-nums shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                                value={values[item.id] ?? 0}
                                                onChange={(e) => setValues((prev) => ({ ...prev, [item.id]: Number(e.target.value) }))} />
                                        ) : (
                                            <span className="text-sm tabular-nums text-gray-700">{num(values[item.id] ?? item.amount ?? 0)}</span>
                                        )
                                    ) : <span className="text-xs text-gray-300">—</span>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
