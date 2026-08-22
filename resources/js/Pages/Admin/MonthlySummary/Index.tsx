import AdminLayout from '@/Layouts/AdminLayout';
import {
    currentMonthKey,
    monthLabel as fmtMonthLabel,
    useMonthClosingDay,
} from '@/lib/monthPeriod';
import { Head, Link, router } from '@inertiajs/react';

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

interface CompanyTotal {
    work_days: number;
    total_work: string;
    rounded_work: string;
    total_break: string;
    avg_per_day: string;
    missing_clock_out: number;
    user_count: number;
    late_count?: number;
    early_leave_count?: number;
    overtime?: string;
}

interface Props {
    monthlySummary: MonthlySummaryItem[];
    companyTotal: CompanyTotal;
    hasSchedule: boolean;
    monthLabel: string;
    monthKey: string;
    prevMonth: string;
    nextMonth: string;
}

function CompanyTotalTable({ companyTotal, hasSchedule }: { companyTotal: CompanyTotal; hasSchedule: boolean }) {
    return (
        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
            <div className="flex items-center gap-2 border-b border-gray-100 px-4 py-2">
                <i className="fa-solid fa-building text-teal-500 text-sm" />
                <h3 className="text-sm font-bold text-gray-700">会社全体</h3>
                <span className="ml-auto text-[11px] text-gray-400">{companyTotal.user_count}名</span>
            </div>
            <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead className="bg-gray-50 text-[11px] font-semibold text-gray-500">
                    <tr>
                        <th className="px-3 py-1.5 text-center whitespace-nowrap">延べ出勤</th>
                        <th className="px-3 py-1.5 text-center whitespace-nowrap">労働合計</th>
                        <th className="px-3 py-1.5 text-center whitespace-nowrap">丸め後</th>
                        <th className="px-3 py-1.5 text-center whitespace-nowrap">休憩合計</th>
                        <th className="px-3 py-1.5 text-center whitespace-nowrap">平均/人日</th>
                        {hasSchedule && (
                            <>
                                <th className="px-3 py-1.5 text-center whitespace-nowrap">遅刻</th>
                                <th className="px-3 py-1.5 text-center whitespace-nowrap">早退</th>
                                <th className="px-3 py-1.5 text-center whitespace-nowrap">残業合計</th>
                            </>
                        )}
                        <th className="px-3 py-1.5 text-center whitespace-nowrap">退勤忘れ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr className="font-bold">
                        <td className="px-3 py-2 text-center tabular-nums text-gray-800">{companyTotal.work_days}日</td>
                        <td className="px-3 py-2 text-center font-mono text-xs text-gray-800">{companyTotal.total_work}</td>
                        <td className="px-3 py-2 text-center font-mono text-xs text-gray-800">{companyTotal.rounded_work}</td>
                        <td className="px-3 py-2 text-center font-mono text-xs text-gray-600">{companyTotal.total_break}</td>
                        <td className="px-3 py-2 text-center font-mono text-xs text-blue-700">{companyTotal.avg_per_day}</td>
                        {hasSchedule && (
                            <>
                                <td className="px-3 py-2 text-center text-yellow-700">{companyTotal.late_count}回</td>
                                <td className="px-3 py-2 text-center text-orange-700">{companyTotal.early_leave_count}回</td>
                                <td className="px-3 py-2 text-center font-mono text-xs text-red-600">{companyTotal.overtime}</td>
                            </>
                        )}
                        <td className={`px-3 py-2 text-center ${companyTotal.missing_clock_out > 0 ? 'text-red-600' : 'text-gray-400'}`}>
                            {companyTotal.missing_clock_out > 0 ? `${companyTotal.missing_clock_out}件` : '—'}
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>
    );
}

export default function MonthlySummaryIndex({ monthlySummary, companyTotal, hasSchedule, monthLabel, monthKey, prevMonth, nextMonth }: Props) {
    const closingDay = useMonthClosingDay();

    const goToMonth = (m: string) => {
        router.get(route('admin.monthly-summary'), { month: m }, { preserveState: false });
    };

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

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">月次サマリ</h2>}>
            <Head title={`${monthLabel} 月次サマリ`} />

            <div className="p-4 lg:p-6 space-y-4">
                {/* Month Navigation */}
                <div className="flex items-center justify-between rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-100">
                    <button
                        onClick={() => goToMonth(prevMonth)}
                        className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 transition"
                    >
                        <i className="fa-solid fa-chevron-left text-xs" />
                        前月
                    </button>

                    <div className="flex items-center gap-3">
                        <select
                            value={monthKey}
                            onChange={(e) => goToMonth(e.target.value)}
                            className="rounded-lg border-gray-300 text-sm font-bold text-gray-800 focus:border-teal-500 focus:ring-teal-500"
                        >
                            {monthOptions.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                        </select>
                        <button
                            onClick={() => goToMonth(currentMonthKey(closingDay))}
                            className="rounded-lg bg-teal-50 px-3 py-1.5 text-xs font-bold text-teal-700 hover:bg-teal-100 transition"
                        >
                            今月
                        </button>
                    </div>

                    <button
                        onClick={() => goToMonth(nextMonth)}
                        className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 transition"
                    >
                        翌月
                        <i className="fa-solid fa-chevron-right text-xs" />
                    </button>

                    <a
                        href={`${route('admin.monthly-summary.export-csv')}?month=${monthKey}`}
                        className="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white hover:bg-green-700 transition"
                    >
                        <i className="fa-solid fa-file-csv text-xs" />
                        CSV出力
                    </a>
                </div>

                {/* Company Total */}
                <CompanyTotalTable companyTotal={companyTotal} hasSchedule={hasSchedule} />

                {/* Per-User Table */}
                <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div className="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5">
                        <i className="fa-solid fa-users text-teal-500 text-sm" />
                        <h3 className="text-sm font-bold text-gray-700">ユーザー別集計</h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-[11px] font-semibold text-gray-500">
                                <tr>
                                    <th className="sticky left-0 z-10 bg-gray-50 px-4 py-2.5 text-left whitespace-nowrap">ユーザー</th>
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
                                {/* Company Total Footer Row */}
                                {monthlySummary.length > 0 && (
                                    <tr className="bg-teal-50/50 font-bold">
                                        <td className="sticky left-0 z-10 bg-teal-50/50 px-4 py-2.5 text-teal-800 whitespace-nowrap">
                                            <i className="fa-solid fa-building text-xs mr-1.5" />合計
                                        </td>
                                        <td className="px-2 py-2.5 text-center tabular-nums text-teal-700">
                                            {companyTotal.work_days}<span className="text-[10px] font-normal">日</span>
                                        </td>
                                        <td className="px-2 py-2.5 text-center font-mono text-xs text-teal-700">
                                            {companyTotal.total_work}
                                        </td>
                                        <td className="px-2 py-2.5 text-center font-mono text-xs text-teal-700">
                                            {companyTotal.rounded_work}
                                        </td>
                                        <td className="px-2 py-2.5 text-center font-mono text-xs text-teal-600">
                                            {companyTotal.avg_per_day}
                                        </td>
                                        {hasSchedule && (
                                            <>
                                                <td className="px-2 py-2.5 text-center text-[11px] text-yellow-700">
                                                    {companyTotal.late_count! > 0 ? `${companyTotal.late_count}回` : '—'}
                                                </td>
                                                <td className="px-2 py-2.5 text-center text-[11px] text-orange-700">
                                                    {companyTotal.early_leave_count! > 0 ? `${companyTotal.early_leave_count}回` : '—'}
                                                </td>
                                                <td className="px-2 py-2.5 text-center font-mono text-xs text-red-600">
                                                    {companyTotal.overtime}
                                                </td>
                                            </>
                                        )}
                                        <td className="px-2 py-2.5 text-center text-[11px]">
                                            {companyTotal.missing_clock_out > 0 ? (
                                                <span className="text-red-600">{companyTotal.missing_clock_out}件</span>
                                            ) : (
                                                <span className="text-gray-300">—</span>
                                            )}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
