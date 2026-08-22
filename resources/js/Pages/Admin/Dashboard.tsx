import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link } from '@inertiajs/react';
import type { EmploymentStatus, UserStatusHistory } from '@/types';

interface MonthlySummaryItem {
    user_id: number;
    user_name: string;
    work_days: number;
    total_work_hours: string;
    total_work_minutes: number;
    rounded_work_hours: string;
    avg_per_day: string;
    missing_clock_out: number;
    late_count?: number;
    early_leave_count?: number;
    overtime_hours?: string;
    overtime_minutes?: number;
}

const STATUS_BADGE: Record<EmploymentStatus | 'none', string> = {
    active:   'bg-green-100 text-green-700',
    pre_join: 'bg-blue-100 text-blue-700',
    retired:  'bg-red-100 text-red-700',
    none:     'bg-gray-100 text-gray-500',
};

interface Props {
    stats: {
        totalUsers: number;
        todayClockIns: number;
        todayClockOuts: number;
    };
    monthlySummary: MonthlySummaryItem[];
    hasSchedule: boolean;
    currentMonth: string;
    recentStatusChanges: UserStatusHistory[];
}

export default function AdminDashboard({ stats, monthlySummary, hasSchedule, currentMonth, recentStatusChanges }: Props) {
    const cards = [
        {
            label: 'アクティブユーザー',
            value: stats.totalUsers,
            unit: '名',
            accent: 'from-teal-400 to-teal-600',
            bg: 'bg-teal-50',
            text: 'text-teal-700',
            icon: (
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                </svg>
            ),
        },
        {
            label: '本日の出勤',
            value: stats.todayClockIns,
            unit: '名',
            accent: 'from-blue-400 to-blue-600',
            bg: 'bg-blue-50',
            text: 'text-blue-700',
            icon: (
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25V9m-3 0h13.5M4.5 9v9.75A2.25 2.25 0 006.75 21h10.5a2.25 2.25 0 002.25-2.25V9" />
                </svg>
            ),
        },
        {
            label: '本日の退勤',
            value: stats.todayClockOuts,
            unit: '名',
            accent: 'from-purple-400 to-purple-600',
            bg: 'bg-purple-50',
            text: 'text-purple-700',
            icon: (
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25V9m-3 0h13.5M4.5 9v9.75A2.25 2.25 0 006.75 21h10.5a2.25 2.25 0 002.25-2.25V9" />
                </svg>
            ),
        },
    ];

    const clockInRate = stats.totalUsers > 0
        ? ((stats.todayClockIns / stats.totalUsers) * 100).toFixed(1)
        : '0.0';

    const clockOutRate = stats.todayClockIns > 0
        ? ((stats.todayClockOuts / stats.todayClockIns) * 100).toFixed(1)
        : '0.0';

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">ダッシュボード</h2>}>
            <Head title="管理者ダッシュボード" />

            <div className="px-4 py-6 sm:p-6">
                {/* Stat Cards */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    {cards.map((card) => (
                        <div key={card.label} className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                            <div className="flex items-center gap-4 p-5">
                                <div className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl ${card.bg} ${card.text}`}>
                                    {card.icon}
                                </div>
                                <div>
                                    <p className="text-xs font-medium text-gray-500">{card.label}</p>
                                    <p className="mt-0.5 text-2xl font-bold text-gray-800">
                                        {card.value}
                                        <span className="ml-0.5 text-sm font-medium text-gray-400">{card.unit}</span>
                                    </p>
                                </div>
                            </div>
                            <div className={`h-1 bg-linear-to-r ${card.accent}`} />
                        </div>
                    ))}
                </div>

                {/* Rate Summary */}
                <div className="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                        <p className="text-sm font-medium text-gray-500">出勤率</p>
                        <div className="mt-2 flex items-end gap-2">
                            <span className="text-4xl font-bold text-gray-800">{clockInRate}</span>
                            <span className="mb-1 text-lg font-medium text-gray-400">%</span>
                        </div>
                        <p className="mt-1 text-xs text-gray-400">
                            {stats.todayClockIns} / {stats.totalUsers} 名
                        </p>
                        <div className="mt-3 h-2 overflow-hidden rounded-full bg-gray-100">
                            <div
                                className="h-full rounded-full bg-linear-to-r from-teal-400 to-teal-600 transition-all"
                                style={{ width: `${Math.min(parseFloat(clockInRate), 100)}%` }}
                            />
                        </div>
                    </div>
                    <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                        <p className="text-sm font-medium text-gray-500">退勤率</p>
                        <div className="mt-2 flex items-end gap-2">
                            <span className="text-4xl font-bold text-gray-800">{clockOutRate}</span>
                            <span className="mb-1 text-lg font-medium text-gray-400">%</span>
                        </div>
                        <p className="mt-1 text-xs text-gray-400">
                            {stats.todayClockOuts} / {stats.todayClockIns} 名
                        </p>
                        <div className="mt-3 h-2 overflow-hidden rounded-full bg-gray-100">
                            <div
                                className="h-full rounded-full bg-linear-to-r from-purple-400 to-purple-600 transition-all"
                                style={{ width: `${Math.min(parseFloat(clockOutRate), 100)}%` }}
                            />
                        </div>
                    </div>
                </div>

                {/* Quick Links */}
                <div className="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <Link
                        href={route('admin.attendances.index', {
                            date_from: new Date().toISOString().slice(0, 10),
                            date_to: new Date().toISOString().slice(0, 10),
                        })}
                        className="group flex items-center gap-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100 hover:ring-teal-200 transition"
                    >
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-600 group-hover:bg-teal-100 transition">
                            <i className="fa-solid fa-calendar-day text-xl" />
                        </div>
                        <div>
                            <p className="text-sm font-bold text-gray-800">本日の打刻一覧</p>
                            <p className="text-xs text-gray-500">今日の出退勤記録を確認</p>
                        </div>
                        <i className="fa-solid fa-chevron-right ml-auto text-gray-300 group-hover:text-teal-500 transition" />
                    </Link>
                    <Link
                        href={route('admin.monthly-summary')}
                        className="group flex items-center gap-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100 hover:ring-blue-200 transition"
                    >
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600 group-hover:bg-blue-100 transition">
                            <i className="fa-solid fa-chart-bar text-xl" />
                        </div>
                        <div>
                            <p className="text-sm font-bold text-gray-800">今月の月次サマリ</p>
                            <p className="text-xs text-gray-500">今月の勤務集計を確認</p>
                        </div>
                        <i className="fa-solid fa-chevron-right ml-auto text-gray-300 group-hover:text-blue-500 transition" />
                    </Link>
                </div>

                {/* Recent Status Changes */}
                {recentStatusChanges.length > 0 && (
                    <div className="mt-6 rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div className="flex items-center gap-3 border-b border-gray-100 px-6 py-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-purple-100">
                                <i className="fa-solid fa-clock-rotate-left text-purple-600" />
                            </div>
                            <div>
                                <h3 className="text-base font-bold text-gray-800">最近のステータス変更</h3>
                                <p className="text-xs text-gray-400">直近10件</p>
                            </div>
                        </div>
                        <div className="divide-y divide-gray-50">
                            {recentStatusChanges.map((h) => (
                                <div key={h.id} className="flex items-center gap-4 px-6 py-3">
                                    <Link
                                        href={route('admin.users.show', h.user_id)}
                                        className="w-28 shrink-0 truncate text-sm font-medium text-gray-800 hover:text-teal-600 transition"
                                    >
                                        {h.user_name}
                                    </Link>
                                    <div className="flex flex-1 flex-wrap items-center gap-2">
                                        <span className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ${STATUS_BADGE[(h.from_status as EmploymentStatus) ?? 'none'] ?? STATUS_BADGE.none}`}>
                                            {h.from_label}
                                        </span>
                                        <i className="fa-solid fa-arrow-right text-[10px] text-gray-300" />
                                        <span className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ${STATUS_BADGE[h.to_status as EmploymentStatus] ?? STATUS_BADGE.none}`}>
                                            {h.to_label}
                                        </span>
                                    </div>
                                    <p className="shrink-0 text-xs text-gray-400">{h.changed_at}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Monthly Summary Table */}
                <div className="mt-6 rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-orange-100">
                                <i className="fa-solid fa-chart-bar text-orange-600" />
                            </div>
                            <div>
                                <h3 className="text-base font-bold text-gray-800">{currentMonth} 月次サマリ</h3>
                                <p className="text-xs text-gray-500">ユーザーごとの今月の勤務集計</p>
                            </div>
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-[11px] font-semibold text-gray-500">
                                <tr>
                                    <th className="sticky left-0 z-10 bg-gray-50 px-4 py-2.5 text-left">ユーザー</th>
                                    <th className="px-2 py-2.5 text-center whitespace-nowrap">出勤日数</th>
                                    <th className="px-2 py-2.5 text-center whitespace-nowrap">総労働</th>
                                    <th className="px-2 py-2.5 text-center whitespace-nowrap">丸め後</th>
                                    <th className="px-2 py-2.5 text-center whitespace-nowrap">平均/日</th>
                                    {hasSchedule && (
                                        <>
                                            <th className="px-2 py-2.5 text-center whitespace-nowrap">遅刻</th>
                                            <th className="px-2 py-2.5 text-center whitespace-nowrap">早退</th>
                                            <th className="px-2 py-2.5 text-center whitespace-nowrap">残業</th>
                                        </>
                                    )}
                                    <th className="px-2 py-2.5 text-center whitespace-nowrap">退勤忘れ</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {monthlySummary.length === 0 ? (
                                    <tr>
                                        <td colSpan={hasSchedule ? 9 : 6} className="px-4 py-8 text-center text-gray-400">
                                            アクティブユーザーがいません
                                        </td>
                                    </tr>
                                ) : (
                                    monthlySummary.map((row) => (
                                        <tr key={row.user_id} className="hover:bg-gray-50/50 transition">
                                            <td className="sticky left-0 z-10 bg-white px-4 py-2.5">
                                                <Link
                                                    href={route('admin.users.attendances', row.user_id)}
                                                    className="font-medium text-gray-800 hover:text-teal-600 transition whitespace-nowrap"
                                                >
                                                    {row.user_name}
                                                </Link>
                                            </td>
                                            <td className="px-2 py-2.5 text-center tabular-nums text-gray-600">
                                                {row.work_days}<span className="text-[10px] text-gray-400">日</span>
                                            </td>
                                            <td className="px-2 py-2.5 text-center font-mono text-xs text-gray-700">
                                                {row.total_work_hours}
                                            </td>
                                            <td className="px-2 py-2.5 text-center font-mono text-xs text-gray-700">
                                                {row.rounded_work_hours}
                                            </td>
                                            <td className="px-2 py-2.5 text-center font-mono text-xs text-gray-500">
                                                {row.avg_per_day}
                                            </td>
                                            {hasSchedule && (
                                                <>
                                                    <td className="px-2 py-2.5 text-center">
                                                        {row.late_count! > 0 ? (
                                                            <span className="inline-block rounded-full bg-yellow-50 px-2 py-0.5 text-[11px] font-bold text-yellow-700">
                                                                {row.late_count}回
                                                            </span>
                                                        ) : (
                                                            <span className="text-gray-300">—</span>
                                                        )}
                                                    </td>
                                                    <td className="px-2 py-2.5 text-center">
                                                        {row.early_leave_count! > 0 ? (
                                                            <span className="inline-block rounded-full bg-orange-50 px-2 py-0.5 text-[11px] font-bold text-orange-700">
                                                                {row.early_leave_count}回
                                                            </span>
                                                        ) : (
                                                            <span className="text-gray-300">—</span>
                                                        )}
                                                    </td>
                                                    <td className="px-2 py-2.5 text-center font-mono text-xs">
                                                        {row.overtime_minutes! > 0 ? (
                                                            <span className="font-bold text-red-600">{row.overtime_hours}</span>
                                                        ) : (
                                                            <span className="text-gray-300">—</span>
                                                        )}
                                                    </td>
                                                </>
                                            )}
                                            <td className="px-2 py-2.5 text-center">
                                                {row.missing_clock_out > 0 ? (
                                                    <span className="inline-flex items-center gap-0.5 rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-bold text-red-600">
                                                        <i className="fa-solid fa-triangle-exclamation text-[9px]" />
                                                        {row.missing_clock_out}件
                                                    </span>
                                                ) : (
                                                    <span className="text-gray-300">—</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
