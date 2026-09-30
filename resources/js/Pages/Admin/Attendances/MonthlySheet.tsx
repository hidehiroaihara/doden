import AttendanceEditForm, {
    AttendanceFormValues,
    attendanceFormValuesFromPayload,
    buildAttendanceSubmitPayload,
    emptyAttendanceFormValues,
} from '@/Components/Admin/AttendanceEditForm';
import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import {
    DAY_TYPE_LABEL,
    type DayType,
    type HolidayInfo,
    dayTypeHeaderBg,
    dayTypeRowBg,
    dayTypeTitle,
} from '@/lib/holiday';
import {
    currentMonthKey,
    monthLabel as fmtMonthLabel,
    useMonthClosingDay,
} from '@/lib/monthPeriod';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useCallback, useState } from 'react';

interface DayCell {
    in: string | null;
    out: string | null;
    out_next_day?: boolean;
    store?: string | null;
    /** 退勤店舗（出勤店舗と異なる場合のみ設定される）。 */
    out_store?: string | null;
    attendance_id?: number;
    missing_out: boolean;
}

interface DayColumn {
    date: string;
    day: number;
    month: number;
    dow: string;
    is_weekend: boolean;
    /** 年度設定に基づく休日区分。 */
    day_type: DayType;
    /** 祝日名（内閣府の祝日・会社独自の休日）。 */
    holiday_label: string | null;
}

interface Row {
    user_id: number;
    user_name: string;
    department: string | null;
    // 各日は打刻シフトの配列（複数シフト対応）。未打刻の日は null。
    cells: Record<string, DayCell[] | null>;
    work_days: number;
}

interface Department {
    id: number;
    name: string;
}

interface Props {
    rows: Row[];
    days: DayColumn[];
    monthKey: string;
    monthLabel: string;
    prevMonth: string;
    nextMonth: string;
    departments: Department[];
    filters: { search: string; department_id: string };
}

interface ModalState {
    mode: 'create' | 'edit';
    userId: number;
    userName: string;
    workDate: string;
    attendanceId?: number;
    departmentName?: string | null;
}

export default function MonthlySheet({
    rows,
    days,
    monthKey,
    monthLabel,
    prevMonth,
    nextMonth,
    departments,
    filters,
}: Props) {
    const closingDay = useMonthClosingDay();
    const canWrite = useAdminPermission('attendances');
    const pageErrors = usePage().props.errors as Record<string, string>;
    const [search, setSearch] = useState(filters.search || '');
    const [departmentId, setDepartmentId] = useState(filters.department_id || '');
    const [modal, setModal] = useState<ModalState | null>(null);
    const [formValues, setFormValues] = useState<AttendanceFormValues | null>(null);
    const [loadingForm, setLoadingForm] = useState(false);
    const [processing, setProcessing] = useState(false);

    const navigate = (params: Record<string, string>) => {
        router.get(
            route('admin.attendances.monthly'),
            {
                month: monthKey,
                search: search || undefined,
                department_id: departmentId || undefined,
                ...params,
            },
            { preserveState: false, preserveScroll: true },
        );
    };

    const goToMonth = (m: string) => navigate({ month: m });
    const handleSearch = () => navigate({});

    const monthOptions = (() => {
        const opts: { value: string; label: string }[] = [];
        const today = new Date();
        for (let i = 0; i < 24; i++) {
            const d = new Date(today.getFullYear(), today.getMonth() - i, 1);
            const v = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
            opts.push({ value: v, label: fmtMonthLabel(v, closingDay) });
        }
        return opts;
    })();

    const userMonthHref = (userId: number) =>
        route('admin.users.attendances', { user: userId, month: monthKey });

    const dowColor = (col: DayColumn) => {
        if (col.dow === '日') return 'text-red-500';
        if (col.dow === '土') return 'text-blue-500';
        return 'text-gray-500';
    };

    const holidayInfo = (col: DayColumn): HolidayInfo => ({ type: col.day_type, label: col.holiday_label });

    // 表内では「祝」だけなので、祝日名は表の下にまとめて出す。
    const monthHolidays = days.filter((col) => col.holiday_label);

    const formatOutTime = (cell: DayCell) => {
        if (!cell.out) return cell.missing_out ? '未' : '--:--';
        return cell.out_next_day ? `${cell.out}翌` : cell.out;
    };

    const closeModal = () => {
        setModal(null);
        setFormValues(null);
        setLoadingForm(false);
    };

    const openCell = useCallback(
        async (row: Row, date: string, cell: DayCell | null) => {
            if (!canWrite) return;

            if (cell?.attendance_id) {
                setModal({
                    mode: 'edit',
                    userId: row.user_id,
                    userName: row.user_name,
                    workDate: date,
                    attendanceId: cell.attendance_id,
                    departmentName: cell.store,
                });
                setFormValues(null);
                setLoadingForm(true);

                try {
                    const res = await fetch(route('admin.attendances.form-data', cell.attendance_id), {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    if (!res.ok) throw new Error('load failed');
                    const data = await res.json();
                    setFormValues(attendanceFormValuesFromPayload(data));
                    setModal((m) =>
                        m
                            ? {
                                  ...m,
                                  departmentName: data.department_name ?? m.departmentName,
                              }
                            : m,
                    );
                } catch {
                    closeModal();
                } finally {
                    setLoadingForm(false);
                }
            } else {
                setModal({
                    mode: 'create',
                    userId: row.user_id,
                    userName: row.user_name,
                    workDate: date,
                    departmentName: row.department,
                });
                setFormValues(
                    emptyAttendanceFormValues({
                        user_id: String(row.user_id),
                        work_date: date,
                    }),
                );
            }
        },
        [canWrite],
    );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!modal || !formValues) return;

        setProcessing(true);
        const meta = { return_to: 'monthly', return_month: monthKey };
        const payload = buildAttendanceSubmitPayload(formValues, meta);

        if (modal.mode === 'edit' && modal.attendanceId) {
            router.put(route('admin.attendances.update', modal.attendanceId), payload, {
                preserveScroll: true,
                onSuccess: () => closeModal(),
                onFinish: () => setProcessing(false),
            });
        } else {
            router.post(route('admin.attendances.store'), payload, {
                preserveScroll: true,
                onSuccess: () => closeModal(),
                onFinish: () => setProcessing(false),
            });
        }
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">月別打刻表</h2>}>
            <Head title={`${monthLabel} 月別打刻表`} />

            <div className="p-4 lg:p-6 space-y-4">
                <div className="flex flex-wrap items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-100">
                    <button
                        onClick={() => goToMonth(prevMonth)}
                        className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100"
                    >
                        <i className="fa-solid fa-chevron-left text-xs" />
                        前月
                    </button>

                    <div className="flex items-center gap-2">
                        <select
                            value={monthKey}
                            onChange={(e) => goToMonth(e.target.value)}
                            className="rounded-lg border-gray-300 text-sm font-bold text-gray-800 focus:border-teal-500 focus:ring-teal-500"
                        >
                            {monthOptions.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                        <button
                            onClick={() => goToMonth(currentMonthKey(closingDay))}
                            className="rounded-lg bg-teal-50 px-3 py-1.5 text-xs font-bold text-teal-700 transition hover:bg-teal-100"
                        >
                            今月
                        </button>
                    </div>

                    <button
                        onClick={() => goToMonth(nextMonth)}
                        className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100"
                    >
                        翌月
                        <i className="fa-solid fa-chevron-right text-xs" />
                    </button>

                    <div className="ml-auto flex flex-wrap items-center gap-2">
                        <select
                            value={departmentId}
                            onChange={(e) => setDepartmentId(e.target.value)}
                            className="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="">全部門</option>
                            {departments.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name}
                                </option>
                            ))}
                        </select>
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                            placeholder="氏名・従業員番号"
                            className="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <button
                            type="button"
                            onClick={handleSearch}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700"
                        >
                            <i className="fa-solid fa-magnifying-glass text-xs" /> 検索
                        </button>
                        <Link
                            href={route('admin.attendances.index')}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-200"
                        >
                            <i className="fa-solid fa-list text-xs" /> 打刻一覧
                        </Link>
                    </div>
                </div>

                <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div className="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5">
                        <i className="fa-solid fa-table-cells text-teal-500 text-sm" />
                        <h3 className="text-sm font-bold text-gray-700">{monthLabel}</h3>
                        <span className="ml-auto text-[11px] text-gray-400">{rows.length}名</span>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full border-separate border-spacing-0 text-sm">
                            <thead>
                                <tr className="bg-gray-50 text-[11px] font-semibold text-gray-500">
                                    <th className="sticky left-0 z-20 min-w-36 border-b border-gray-200 bg-gray-50 px-3 py-2 text-left">
                                        従業員
                                    </th>
                                    <th className="border-b border-gray-200 px-2 py-2 text-center whitespace-nowrap">
                                        出勤
                                    </th>
                                    {days.map((col) => {
                                        const info = holidayInfo(col);
                                        const headerBg = dayTypeHeaderBg(info) || (col.is_weekend ? 'bg-gray-100' : '');
                                        return (
                                            <th
                                                key={col.date}
                                                className={`border-b border-l border-gray-200 px-2 py-1 text-center whitespace-nowrap ${headerBg}`}
                                                title={col.day_type !== 'weekday' ? dayTypeTitle(info) : undefined}
                                            >
                                                <div className="tabular-nums text-gray-700">{col.day}</div>
                                                <div className={`text-[10px] ${dowColor(col)}`}>{col.dow}</div>
                                                {col.day_type !== 'weekday' && (
                                                    <div
                                                        className={`text-[9px] font-bold leading-tight ${
                                                            col.day_type === 'legal' ? 'text-rose-600' : 'text-amber-600'
                                                        }`}
                                                    >
                                                        {col.holiday_label ? '祝' : col.day_type === 'legal' ? '法' : '所'}
                                                    </div>
                                                )}
                                            </th>
                                        );
                                    })}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={days.length + 2}
                                            className="px-4 py-8 text-center text-gray-400"
                                        >
                                            対象の従業員がいません
                                        </td>
                                    </tr>
                                ) : (
                                    rows.map((row) => (
                                        <tr key={row.user_id} className="hover:bg-teal-50/30">
                                            <td className="sticky left-0 z-10 border-b border-gray-100 bg-white px-3 py-2">
                                                <Link
                                                    href={userMonthHref(row.user_id)}
                                                    className="font-medium text-gray-800 transition hover:text-teal-600 whitespace-nowrap"
                                                    title="この従業員の月別詳細を開く"
                                                >
                                                    {row.user_name}
                                                </Link>
                                                {row.department && (
                                                    <div className="text-[10px] text-gray-400">{row.department}</div>
                                                )}
                                            </td>
                                            <td className="border-b border-gray-100 px-2 py-2 text-center tabular-nums text-gray-600">
                                                {row.work_days}
                                                <span className="text-[10px] text-gray-400">日</span>
                                            </td>
                                            {days.map((col) => {
                                                const shifts = row.cells[col.date] ?? [];
                                                const hasShifts = shifts.length > 0;
                                                const clickable = canWrite;
                                                const info = holidayInfo(col);
                                                const cellBg = dayTypeRowBg(info) || (col.is_weekend ? 'bg-gray-50/60' : '');
                                                return (
                                                    <td
                                                        key={col.date}
                                                        className={`border-b border-l border-gray-100 px-1.5 py-1 text-center align-middle ${cellBg}`}
                                                        title={!hasShifts && col.day_type !== 'weekday' ? dayTypeTitle(info) : undefined}
                                                    >
                                                        {hasShifts ? (
                                                            <div className="flex flex-col items-stretch gap-0.5">
                                                                {shifts.map((cell, idx) => (
                                                                    <div
                                                                        key={cell.attendance_id ?? idx}
                                                                        className={`leading-tight rounded ${clickable ? 'cursor-pointer hover:bg-teal-50/80' : ''} ${idx > 0 ? 'border-t border-dashed border-gray-200 pt-0.5' : ''}`}
                                                                        onClick={() => clickable && openCell(row, col.date, cell)}
                                                                        title={clickable ? 'クリックで打刻を編集' : undefined}
                                                                    >
                                                                        <div className="font-mono text-[11px] text-green-600">
                                                                            {cell.in ?? '--:--'}
                                                                        </div>
                                                                        <div
                                                                            className={`font-mono text-[11px] ${
                                                                                cell.out
                                                                                    ? 'text-blue-600'
                                                                                    : 'text-amber-500'
                                                                            }`}
                                                                        >
                                                                            {formatOutTime(cell)}
                                                                        </div>
                                                                        {cell.store && (
                                                                            <div
                                                                                className="truncate text-[10px] text-gray-400"
                                                                                title={cell.out_store ? `${cell.store} → ${cell.out_store}` : cell.store}
                                                                            >
                                                                                {cell.store}
                                                                                {cell.out_store && (
                                                                                    <span className="text-indigo-400">→{cell.out_store}</span>
                                                                                )}
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                ))}
                                                                {clickable && (
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => openCell(row, col.date, null)}
                                                                        className="mt-0.5 text-[10px] text-gray-300 transition hover:text-teal-500"
                                                                        title="この日にシフトを追加"
                                                                    >
                                                                        <i className="fa-solid fa-plus" />
                                                                    </button>
                                                                )}
                                                            </div>
                                                        ) : (
                                                            <div
                                                                className={`${clickable ? 'cursor-pointer hover:bg-teal-50/80' : ''}`}
                                                                onClick={() => clickable && openCell(row, col.date, null)}
                                                                title={clickable ? 'クリックで打刻を登録' : undefined}
                                                            >
                                                                <span className="text-gray-200">・</span>
                                                            </div>
                                                        )}
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="space-y-1.5 text-[11px] text-gray-400">
                    <p>
                        <span className="font-mono text-green-600">上段</span>=出勤 /{' '}
                        <span className="font-mono text-blue-600">下段</span>=退勤（
                        <span className="text-amber-500">未</span>=退勤打刻なし、
                        <span className="font-mono text-blue-600">翌</span>=翌日退勤）。
                        {canWrite && ' セルをクリックすると打刻の登録・編集ができます。'}
                    </p>
                    <p className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span className="inline-flex items-center gap-1">
                            <span className="inline-block h-3 w-3 rounded border border-amber-200 bg-amber-100" />
                            {DAY_TYPE_LABEL.prescribed}
                        </span>
                        <span className="inline-flex items-center gap-1">
                            <span className="inline-block h-3 w-3 rounded border border-rose-200 bg-rose-100" />
                            {DAY_TYPE_LABEL.legal}
                        </span>
                        <span>
                            <span className="font-bold text-amber-600">祝</span>=祝日 /{' '}
                            <span className="font-bold text-amber-600">所</span>=所定休日 /{' '}
                            <span className="font-bold text-rose-600">法</span>=法定休日
                        </span>
                    </p>
                    {monthHolidays.length > 0 && (
                        <p className="text-gray-500">
                            <i className="fa-regular fa-calendar-check mr-1 text-amber-500" />
                            {monthHolidays.map((col) => `${col.month}/${col.day} ${col.holiday_label}`).join(' ・ ')}
                        </p>
                    )}
                </div>
            </div>

            {modal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={closeModal}>
                    <div
                        className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-xl"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="mb-4 flex items-start justify-between gap-3 border-b border-gray-100 pb-3">
                            <div>
                                <h3 className="text-base font-bold text-gray-800">
                                    {modal.mode === 'edit' ? '打刻修正' : '打刻登録'}
                                </h3>
                                <p className="mt-0.5 text-sm text-gray-500">
                                    {modal.userName} / {modal.workDate}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={closeModal}
                                className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                            >
                                <i className="fa-solid fa-xmark" />
                            </button>
                        </div>

                        {loadingForm || !formValues ? (
                            <div className="flex items-center justify-center py-12 text-sm text-gray-400">
                                <i className="fa-solid fa-spinner fa-spin mr-2" />
                                読み込み中…
                            </div>
                        ) : (
                            <form onSubmit={submit}>
                                <AttendanceEditForm
                                    mode={modal.mode}
                                    values={formValues}
                                    onChange={setFormValues}
                                    errors={pageErrors}
                                    userName={modal.userName}
                                    departmentName={modal.departmentName}
                                    showUserSelect={false}
                                    showWorkDate={false}
                                />
                                <div className="mt-6 flex justify-end gap-2 border-t border-gray-100 pt-4">
                                    <button
                                        type="button"
                                        onClick={closeModal}
                                        className="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100"
                                    >
                                        キャンセル
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="rounded-lg bg-teal-600 px-5 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50"
                                    >
                                        {modal.mode === 'edit' ? '保存' : '登録'}
                                    </button>
                                </div>
                            </form>
                        )}
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}
