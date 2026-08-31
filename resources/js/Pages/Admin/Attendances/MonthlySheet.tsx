import AdminLayout from '@/Layouts/AdminLayout';
import {
    currentMonthKey,
    monthLabel as fmtMonthLabel,
    useMonthClosingDay,
} from '@/lib/monthPeriod';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface DayCell {
    in: string | null;
    out: string | null;
    store?: string | null;
    attendance_id: number;
    missing_out: boolean;
}

interface DayColumn {
    date: string;
    day: number;
    month: number;
    dow: string;
    is_weekend: boolean;
}

interface Row {
    user_id: number;
    user_name: string;
    department: string | null;
    cells: Record<string, DayCell | null>;
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
    const [search, setSearch] = useState(filters.search || '');
    const [departmentId, setDepartmentId] = useState(filters.department_id || '');

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

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">月別打刻表</h2>}>
            <Head title={`${monthLabel} 月別打刻表`} />

            <div className="p-4 lg:p-6 space-y-4">
                {/* Toolbar */}
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

                {/* Sheet */}
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
                                    {days.map((col) => (
                                        <th
                                            key={col.date}
                                            className={`border-b border-l border-gray-200 px-2 py-1 text-center whitespace-nowrap ${
                                                col.is_weekend ? 'bg-gray-100' : ''
                                            }`}
                                        >
                                            <div className="tabular-nums text-gray-700">{col.day}</div>
                                            <div className={`text-[10px] ${dowColor(col)}`}>{col.dow}</div>
                                        </th>
                                    ))}
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
                                                const cell = row.cells[col.date];
                                                return (
                                                    <td
                                                        key={col.date}
                                                        className={`border-b border-l border-gray-100 px-1.5 py-1 text-center align-middle ${
                                                            col.is_weekend ? 'bg-gray-50/60' : ''
                                                        }`}
                                                    >
                                                        {cell && cell.in ? (
                                                            <div className="leading-tight">
                                                                <div className="font-mono text-[11px] text-green-600">
                                                                    {cell.in}
                                                                </div>
                                                                <div
                                                                    className={`font-mono text-[11px] ${
                                                                        cell.out
                                                                            ? 'text-blue-600'
                                                                            : 'text-amber-500'
                                                                    }`}
                                                                >
                                                                    {cell.out ?? (cell.missing_out ? '未' : '--:--')}
                                                                </div>
                                                                {cell.store && (
                                                                    <div className="truncate text-[10px] text-gray-400" title={cell.store}>
                                                                        {cell.store}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        ) : (
                                                            <span className="text-gray-200">・</span>
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

                <p className="text-[11px] text-gray-400">
                    <span className="font-mono text-green-600">上段</span>=出勤 /{' '}
                    <span className="font-mono text-blue-600">下段</span>=退勤（
                    <span className="text-amber-500">未</span>=退勤打刻なし）。
                    <span className="text-gray-400">打刻店舗</span>は退勤時刻の下に表示。
                    従業員名の下は主所属店舗。従業員名をクリックすると月別の詳細ページへ移動します。
                </p>
            </div>
        </AdminLayout>
    );
}
