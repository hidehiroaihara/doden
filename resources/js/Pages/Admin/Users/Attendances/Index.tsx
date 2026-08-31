import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import {
    monthLabel,
    monthPresetRanges,
    resolveMonthRange,
    useMonthClosingDay,
} from '@/lib/monthPeriod';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

interface BreakRecord {
    id: number;
    attendance_id: number;
    started_at: string;
    start_photo_path: string | null;
    ended_at: string | null;
    end_photo_path: string | null;
}

interface AttendanceItem {
    id: number;
    user_id: number;
    work_date: string;
    clock_in_at: string | null;
    clock_out_at: string | null;
    clock_in_photo_path: string | null;
    clock_out_photo_path: string | null;
    break_minutes: number | null;
    computed_break_minutes: number | null;
    attendance_breaks?: BreakRecord[];
    department?: { id: number; name: string } | null;
    user: { id: number; name: string };
}

function BreakDetailModal({ breaks, attendanceId, canWrite, onClose }: {
    breaks: BreakRecord[];
    attendanceId: number;
    canWrite: boolean;
    onClose: () => void;
}) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editStartTime, setEditStartTime] = useState('');
    const [editEndTime, setEditEndTime] = useState('');
    const [editErrors, setEditErrors] = useState<Record<string, string>>({});
    const [editSaving, setEditSaving] = useState(false);

    const fmtTime = (dt: string | null) => {
        if (!dt) return '--:--';
        return new Date(dt).toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
    };
    const calcMin = (b: BreakRecord) => {
        if (!b.ended_at) return null;
        return Math.floor((new Date(b.ended_at).getTime() - new Date(b.started_at).getTime()) / 60000);
    };

    const startEdit = (b: BreakRecord) => {
        setEditingId(b.id);
        setEditStartTime(fmtTime(b.started_at));
        setEditEndTime(b.ended_at ? fmtTime(b.ended_at) : '');
        setEditErrors({});
    };

    const cancelEdit = () => {
        setEditingId(null);
        setEditErrors({});
    };

    const saveEdit = (b: BreakRecord) => {
        setEditErrors({});
        setEditSaving(true);
        router.put(
            route('admin.attendances.breaks.update', { attendance: attendanceId, break: b.id }),
            { started_at: editStartTime, ended_at: editEndTime || null },
            {
                preserveScroll: true,
                onSuccess: () => { setEditingId(null); router.reload(); onClose(); },
                onError: (errors) => setEditErrors(errors as Record<string, string>),
                onFinish: () => setEditSaving(false),
            }
        );
    };

    const handleDeleteBreak = (b: BreakRecord, index: number) => {
        if (confirm(`休憩 ${index} を削除しますか？写真も含めて完全に削除されます。この操作は取り消せません。`)) {
            router.delete(
                route('admin.attendances.breaks.destroy', { attendance: attendanceId, break: b.id }),
                { onSuccess: () => { router.reload(); onClose(); } }
            );
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
            <div
                className="w-full max-w-md rounded-2xl bg-white shadow-2xl"
                onClick={e => e.stopPropagation()}
            >
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                    <h3 className="text-base font-bold text-gray-800">休憩詳細</h3>
                    <button onClick={onClose} className="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition">
                        <i className="fa-solid fa-xmark text-lg" />
                    </button>
                </div>
                <div className="divide-y divide-gray-100 px-5 py-3">
                    {breaks.length === 0 && (
                        <p className="py-4 text-center text-sm text-gray-400">休憩記録がありません</p>
                    )}
                    {breaks.map((b, i) => {
                        const mins = calcMin(b);
                        return (
                            <div key={b.id} className="py-3">
                                <div className="mb-2 flex items-center gap-2">
                                    <span className="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-bold text-orange-600">
                                        休憩 {i + 1}
                                    </span>
                                    {mins !== null && (
                                        <span className="text-xs text-gray-500">{mins}分</span>
                                    )}
                                    {b.ended_at === null && (
                                        <span className="rounded-full bg-yellow-100 px-2 py-0.5 text-[10px] font-bold text-yellow-600">未終了</span>
                                    )}
                                    {canWrite && (
                                        <span className="ml-auto flex items-center gap-1">
                                            <button
                                                onClick={() => startEdit(b)}
                                                className="rounded p-1 text-teal-500 hover:bg-teal-50 transition"
                                                title="終了時刻を修正"
                                            >
                                                <i className="fa-solid fa-pen text-xs" />
                                            </button>
                                            <button
                                                onClick={() => handleDeleteBreak(b, i + 1)}
                                                className="rounded p-1 text-red-400 hover:bg-red-50 transition"
                                                title="この休憩を削除"
                                            >
                                                <i className="fa-solid fa-trash-can text-xs" />
                                            </button>
                                        </span>
                                    )}
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    {/* 入 */}
                                    <div className="rounded-lg bg-orange-50 p-2 text-center">
                                        <p className="mb-0.5 text-[10px] font-semibold text-orange-500">入</p>
                                        <p className="font-mono text-sm font-bold text-gray-800">{fmtTime(b.started_at)}</p>
                                        {b.start_photo_path ? (
                                            <a
                                                href={route('admin.attendances.breaks.photo', { attendance: attendanceId, break: b.id, type: 'start' })}
                                                target="_blank"
                                                className="mt-1 inline-flex items-center gap-1 rounded-full bg-orange-100 px-2 py-0.5 text-[10px] text-orange-600 hover:bg-orange-200 transition"
                                            >
                                                <i className="fa-solid fa-camera" /> 写真
                                            </a>
                                        ) : (
                                            <span className="mt-1 inline-block text-[10px] text-gray-300">写真なし</span>
                                        )}
                                    </div>
                                    {/* 戻 */}
                                    <div className="rounded-lg bg-amber-50 p-2 text-center">
                                        <p className="mb-0.5 text-[10px] font-semibold text-amber-500">戻</p>
                                        <p className="font-mono text-sm font-bold text-gray-800">{fmtTime(b.ended_at)}</p>
                                        {b.end_photo_path ? (
                                            <a
                                                href={route('admin.attendances.breaks.photo', { attendance: attendanceId, break: b.id, type: 'end' })}
                                                target="_blank"
                                                className="mt-1 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] text-amber-600 hover:bg-amber-200 transition"
                                            >
                                                <i className="fa-solid fa-camera" /> 写真
                                            </a>
                                        ) : (
                                            <span className="mt-1 inline-block text-[10px] text-gray-300">写真なし</span>
                                        )}
                                    </div>
                                </div>
                                {editingId === b.id && (
                                    <div className="mt-2 rounded-lg bg-teal-50 px-3 py-2">
                                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                                            <label className="flex items-center gap-1.5 text-xs font-semibold text-orange-500">
                                                入り
                                                <input
                                                    type="time"
                                                    className={`rounded text-sm focus:ring-teal-400 ${editErrors.started_at ? 'border-red-400 focus:border-red-400' : 'border-gray-300 focus:border-teal-400'}`}
                                                    value={editStartTime}
                                                    onChange={e => setEditStartTime(e.target.value)}
                                                />
                                            </label>
                                            <label className="flex items-center gap-1.5 text-xs font-semibold text-amber-500">
                                                戻り
                                                <input
                                                    type="time"
                                                    className={`rounded text-sm focus:ring-teal-400 ${editErrors.ended_at ? 'border-red-400 focus:border-red-400' : 'border-gray-300 focus:border-teal-400'}`}
                                                    value={editEndTime}
                                                    onChange={e => setEditEndTime(e.target.value)}
                                                />
                                            </label>
                                        </div>
                                        {(editErrors.started_at || editErrors.ended_at) && (
                                            <ul className="mt-1.5 space-y-0.5 text-xs text-red-600">
                                                {editErrors.started_at && <li><i className="fa-solid fa-circle-exclamation mr-1" />{editErrors.started_at}</li>}
                                                {editErrors.ended_at && <li><i className="fa-solid fa-circle-exclamation mr-1" />{editErrors.ended_at}</li>}
                                            </ul>
                                        )}
                                        <div className="mt-2 flex gap-2">
                                            <button
                                                onClick={() => saveEdit(b)}
                                                disabled={editSaving}
                                                className="rounded bg-teal-500 px-3 py-1 text-xs font-semibold text-white hover:bg-teal-600 disabled:opacity-50 transition"
                                            >
                                                保存
                                            </button>
                                            <button
                                                onClick={cancelEdit}
                                                className="rounded bg-gray-200 px-3 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-300 transition"
                                            >
                                                キャンセル
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
                <div className="border-t border-gray-100 px-5 py-3 text-right">
                    <button onClick={onClose} className="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-200 transition">
                        閉じる
                    </button>
                </div>
            </div>
        </div>
    );
}

interface Summary {
    work_days: number;
    total_work: string;
    rounded_work: string;
    avg_per_day: string;
    total_break: string;
    missing_clock_out: number;
    scheduled_total?: string;
    overtime?: string;
    late_count?: number;
    late_time?: string;
    early_leave_count?: number;
    early_leave_time?: string;
}

interface Props {
    user: { id: number; name: string; customer_no?: string | null; break_minutes?: number | null };
    attendances: AttendanceItem[];
    summary: Summary;
    hasSchedule: boolean;
    scheduleInfo: { work_start_time: string; work_end_time: string; work_hours_per_day: number } | null;
    defaultBreakMinutes: number;
    salaryRoundMinutes: number;
    salaryRoundRule: string;
    calendarFrom: string | null;
    calendarTo: string | null;
    filters: { date_from?: string; date_to?: string; month?: string; year?: string };
}

function toDateStr(d: Date): string {
    const pad = (n: number) => n.toString().padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

function formatFilterLabel(from: string, to: string, month: string, year: string, closingDay?: number | null): string {
    if (month) {
        return monthLabel(month, closingDay);
    }
    if (year) return `${year}年`;
    if (!from && !to) return '全期間';
    const fmt = (s: string) => {
        const d = new Date(s + 'T00:00:00');
        if (isNaN(d.getTime())) return s;
        return `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()}`;
    };
    if (from && to && from === to) return fmt(from);
    if (from && to) return `${fmt(from)} 〜 ${fmt(to)}`;
    if (from) return `${fmt(from)} 〜`;
    return to ? `〜 ${fmt(to)}` : '全期間';
}

const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];
const WEEKDAY_COLORS: Record<number, string> = { 0: 'text-red-500', 6: 'text-blue-500' };
const WEEKDAY_ROW_BG: Record<number, string> = { 0: 'bg-red-50/30', 6: 'bg-blue-50/20' };

function DateDropdown({ dateFrom, dateTo, month, year, onChange }: {
    dateFrom: string; dateTo: string; month: string; year: string;
    onChange: (p: { date_from?: string; date_to?: string; month?: string; year?: string }) => void;
}) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const fromRef = useRef<HTMLInputElement>(null);
    const toRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const h = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false); };
        document.addEventListener('mousedown', h);
        return () => document.removeEventListener('mousedown', h);
    }, []);

    const closingDay = useMonthClosingDay();
    const today = new Date();
    const todayMonthKey = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`;

    const presets = (() => {
        const { thisMonth, lastMonth } = monthPresetRanges(closingDay);
        const lastMonthKey = (() => {
            const d = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
        })();
        return [
            { label: '今日', from: toDateStr(today), to: toDateStr(today) },
            { label: '昨日', from: toDateStr(new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1)), to: toDateStr(new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1)) },
            { label: '今月', from: thisMonth.from, to: thisMonth.to, month: todayMonthKey },
            { label: '先月', from: lastMonth.from, to: lastMonth.to, month: lastMonthKey },
            { label: '過去3ヶ月', from: toDateStr(new Date(today.getFullYear(), today.getMonth() - 3, today.getDate())), to: toDateStr(today) },
            { label: '全期間', from: '', to: '', month: '', year: '' },
        ];
    })();

    const monthOptions = (() => {
        const opts: { value: string; label: string }[] = [];
        for (let i = 0; i < 24; i++) {
            const d = new Date(today.getFullYear(), today.getMonth() - i, 1);
            const v = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
            opts.push({ value: v, label: monthLabel(v, closingDay) });
        }
        return opts;
    })();

    const yearOptions = (() => {
        const opts: { value: string; label: string }[] = [];
        for (let y = today.getFullYear(); y >= today.getFullYear() - 5; y--) opts.push({ value: String(y), label: `${y}年` });
        return opts;
    })();

    return (
        <div ref={ref} className="relative">
            <button type="button" onClick={() => setOpen(!open)}
                className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition ${open ? 'bg-teal-600 text-white' : 'bg-teal-50 text-teal-700 hover:bg-teal-100'}`}>
                <i className="fa-solid fa-calendar-days" />
                {formatFilterLabel(dateFrom, dateTo, month, year, closingDay)}
                <i className={`fa-solid fa-chevron-down text-[10px] transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>
            {open && (
                <div className="absolute left-0 top-full z-30 mt-2 w-80 rounded-xl bg-white p-4 shadow-xl ring-1 ring-gray-200">
                    <div className="mb-4 space-y-2 border-b border-gray-100 pb-3">
                        <div>
                            <label className="mb-1 block text-[11px] font-semibold text-gray-500">月を指定</label>
                            <select className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" value={month}
                                onChange={(e) => {
                                    const v = e.target.value;
                                    if (!v) { onChange({ month: '', year: '', date_from: '', date_to: '' }); return; }
                                    const r = resolveMonthRange(v, closingDay);
                                    onChange({ month: v, year: '', date_from: r.from, date_to: r.to });
                                }}>
                                <option value="">選択しない</option>
                                {monthOptions.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-[11px] font-semibold text-gray-500">年を指定</label>
                            <select className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" value={year}
                                onChange={(e) => {
                                    const v = e.target.value;
                                    if (!v) { onChange({ year: '', month: '', date_from: '', date_to: '' }); return; }
                                    onChange({ year: v, month: '', date_from: `${v}-01-01`, date_to: `${v}-12-31` });
                                }}>
                                <option value="">選択しない</option>
                                {yearOptions.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                            </select>
                        </div>
                    </div>
                    <div className="mb-3 grid grid-cols-2 gap-3">
                        {(['開始日', '終了日'] as const).map((label, i) => {
                            const ref2 = i === 0 ? fromRef : toRef;
                            const val = i === 0 ? dateFrom : dateTo;
                            const key = i === 0 ? 'date_from' : 'date_to';
                            return (
                                <div key={label}>
                                    <label className="mb-1 block text-[11px] font-semibold text-gray-500">{label}</label>
                                    <div className="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm hover:border-teal-300 transition" onClick={() => ref2.current?.showPicker?.()}>
                                        <i className="fa-solid fa-calendar text-teal-400 text-xs" />
                                        <input ref={ref2} type="date" className="w-full cursor-pointer border-0 p-0 text-sm text-gray-700 focus:ring-0" value={val}
                                            onChange={(e) => onChange({ [key]: e.target.value, month: '', year: '' })} />
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                    <div className="flex flex-col gap-1.5">
                        {presets.map(p => {
                            const isActive = (p.month !== undefined && month === (p.month ?? '')) || ((p as any).year !== undefined && year === ((p as any).year ?? '')) || (dateFrom === p.from && dateTo === p.to);
                            return (
                                <button key={p.label} type="button"
                                    onClick={() => { onChange({ date_from: p.from, date_to: p.to, month: p.month ?? '', year: (p as any).year ?? '' }); setOpen(false); }}
                                    className={`rounded-lg px-3 py-2 text-left text-sm font-medium transition ${isActive ? 'bg-teal-50 text-teal-700 ring-1 ring-teal-200' : 'text-gray-600 hover:bg-gray-50'}`}>
                                    {p.label}
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}

function SummaryCard({ icon, iconBg, label, value }: { icon: string; iconBg: string; label: string; value: string | number }) {
    return (
        <div className="flex items-center gap-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-100">
            <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${iconBg}`}>
                <i className={icon} />
            </div>
            <div className="min-w-0">
                <p className="text-[11px] text-gray-500 truncate">{label}</p>
                <p className="text-lg font-bold text-gray-800 leading-tight">{value}</p>
            </div>
        </div>
    );
}

export default function UserAttendancesIndex({ user, attendances, summary, hasSchedule, scheduleInfo, defaultBreakMinutes, salaryRoundMinutes, salaryRoundRule, calendarFrom, calendarTo, filters }: Props) {
    const canWrite = useAdminPermission('attendances');
    const [form, setForm] = useState({ date_from: filters.date_from || '', date_to: filters.date_to || '', month: filters.month || '', year: filters.year || '' });
    const [breakModal, setBreakModal] = useState<{ attendanceId: number; breaks: BreakRecord[] } | null>(null);

    const handleDeleteAttendance = (a: AttendanceItem) => {
        if (confirm(`${a.work_date}（打刻記録）を削除しますか？\n写真も含めて完全に削除されます。この操作は取り消せません。`)) {
            const params: Record<string, string> = { return_to: 'user_attendances' };
            if (form.month)     params.return_month = form.month;
            if (form.year)      params.return_year = form.year;
            if (form.date_from) params.return_date_from = form.date_from;
            if (form.date_to)   params.return_date_to = form.date_to;
            const qs = new URLSearchParams(params).toString();
            router.delete(`${route('admin.attendances.destroy', a.id)}?${qs}`);
        }
    };

    useEffect(() => {
        setForm({ date_from: filters.date_from || '', date_to: filters.date_to || '', month: filters.month || '', year: filters.year || '' });
    }, [filters.date_from, filters.date_to, filters.month, filters.year]);

    const handleFilterChange = (p: { date_from?: string; date_to?: string; month?: string; year?: string }) => {
        const next = { ...form, ...p };
        setForm(next);
        const params: Record<string, string> = {};
        if (next.date_from) params.date_from = next.date_from;
        if (next.date_to) params.date_to = next.date_to;
        if (next.month) params.month = next.month;
        if (next.year) params.year = next.year;
        router.get(route('admin.users.attendances', user.id), params, { preserveState: true });
    };

    const userAttendancesReturnParams = (extra: Record<string, string> = {}) =>
        new URLSearchParams({
            user_id: String(user.id),
            return_to: 'user_attendances',
            ...(form.month ? { return_month: form.month } : {}),
            ...(form.year ? { return_year: form.year } : {}),
            ...(form.date_from ? { return_date_from: form.date_from } : {}),
            ...(form.date_to ? { return_date_to: form.date_to } : {}),
            ...extra,
        }).toString();

    const userAttendancesReturnParamsEdit = () =>
        new URLSearchParams({
            return_to: 'user_attendances',
            ...(form.month ? { return_month: form.month } : {}),
            ...(form.year ? { return_year: form.year } : {}),
            ...(form.date_from ? { return_date_from: form.date_from } : {}),
            ...(form.date_to ? { return_date_to: form.date_to } : {}),
        }).toString();

    const handleExportCsv = () => {
        const params = new URLSearchParams();
        params.set('user_id', String(user.id));
        if (form.date_from) params.set('date_from', form.date_from);
        if (form.date_to) params.set('date_to', form.date_to);
        if (form.month) params.set('month', form.month);
        if (form.year) params.set('year', form.year);
        window.location.href = `${route('admin.attendances.export-csv')}?${params}`;
    };

    const formatTime = (datetime: string | null) => {
        if (!datetime) return '';
        return new Date(datetime).toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
    };

    const fmtHM = (m: number) => `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')}`;

    // 打刻データを日付キーでマップ化
    const attendanceMap = useMemo(() => {
        const map = new Map<string, AttendanceItem>();
        for (const a of attendances) {
            const d = new Date(a.work_date);
            const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
            map.set(key, a);
        }
        return map;
    }, [attendances]);

    // カレンダー全日付生成
    const calendarDays = useMemo(() => {
        if (!calendarFrom || !calendarTo) return null;
        const days: string[] = [];
        const cur = new Date(calendarFrom + 'T00:00:00');
        const end = new Date(calendarTo + 'T00:00:00');
        while (cur <= end) {
            days.push(`${cur.getFullYear()}-${String(cur.getMonth() + 1).padStart(2, '0')}-${String(cur.getDate()).padStart(2, '0')}`);
            cur.setDate(cur.getDate() + 1);
        }
        return days;
    }, [calendarFrom, calendarTo]);

    const getWeekday = (dateStr: string) => {
        const d = new Date(dateStr + 'T00:00:00');
        return WEEKDAYS[d.getDay()];
    };

    const getWeekdayColor = (dateStr: string) => {
        const d = new Date(dateStr + 'T00:00:00');
        return WEEKDAY_COLORS[d.getDay()] || 'text-gray-600';
    };

    const getWeekdayRowBg = (dateStr: string) => {
        const d = new Date(dateStr + 'T00:00:00');
        return WEEKDAY_ROW_BG[d.getDay()] || '';
    };

    const isMissingClockOut = (a: AttendanceItem | null) => {
        if (!a || !a.clock_in_at || a.clock_out_at) return false;
        const wd = new Date(a.work_date);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        wd.setHours(0, 0, 0, 0);
        return wd < today;
    };

    const calcNetMinutes = (a: AttendanceItem | null) => {
        if (!a || !a.clock_in_at || !a.clock_out_at) return null;
        const gross = Math.floor((new Date(a.clock_out_at).getTime() - new Date(a.clock_in_at).getTime()) / 60000);
        // サーバー計算済み休憩分を優先（規定休憩時間帯ロジック適用済み）
        const brk = a.computed_break_minutes ?? a.break_minutes ?? user.break_minutes ?? defaultBreakMinutes;
        return Math.max(0, gross - brk);
    };

    const roundMinutes = (minutes: number, unit: number, rule: string): number => {
        if (unit <= 0) return minutes;
        const q = minutes / unit;
        if (rule === 'ceil') return Math.ceil(q) * unit;
        if (rule === 'round') return Math.round(q) * unit;
        return Math.floor(q) * unit;
    };

    const calcRoundedMinutes = (a: AttendanceItem | null) => {
        const net = calcNetMinutes(a);
        if (net === null) return null;
        return roundMinutes(net, salaryRoundMinutes, salaryRoundRule);
    };

    const calcOvertime = (a: AttendanceItem | null) => {
        if (!scheduleInfo) return null;
        const net = calcNetMinutes(a);
        if (net === null) return null;
        return Math.max(0, net - scheduleInfo.work_hours_per_day);
    };

    const isLate = (a: AttendanceItem | null) => {
        if (!scheduleInfo || !a?.clock_in_at) return false;
        return formatTime(a.clock_in_at) > scheduleInfo.work_start_time;
    };

    const isEarlyLeave = (a: AttendanceItem | null) => {
        if (!scheduleInfo || !a?.clock_out_at) return false;
        return formatTime(a.clock_out_at) < scheduleInfo.work_end_time;
    };

    const formatDate = (dateStr: string) => {
        const d = new Date(dateStr + 'T00:00:00');
        return `${d.getMonth() + 1}/${d.getDate()}`;
    };

    const isToday = (dateStr: string) => {
        const today = new Date();
        const d = new Date(dateStr + 'T00:00:00');
        return d.getFullYear() === today.getFullYear() && d.getMonth() === today.getMonth() && d.getDate() === today.getDate();
    };

    // 表示する行リスト: カレンダーか打刻データのみか
    const displayDays = calendarDays || attendances.map(a => {
        const d = new Date(a.work_date);
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }).reverse();

    const colSpan = (hasSchedule ? 11 : 10) - (canWrite ? 0 : 1);

    return (
        <>
        <AdminLayout
            header={
                <div className="flex items-center gap-3">
                    <Link href={route('admin.users.index')} className="text-gray-500 hover:text-gray-700 transition">
                        <i className="fa-solid fa-arrow-left" />
                    </Link>
                    <h2 className="text-xl font-bold text-gray-800">
                        {user.name}
                        {user.customer_no && <span className="ml-2 text-sm font-normal text-gray-500">({user.customer_no})</span>}
                        <span className="ml-2 text-base font-normal text-gray-500">打刻一覧</span>
                    </h2>
                </div>
            }
        >
            <Head title={`${user.name} - 打刻一覧`} />

            <div className="p-4 lg:p-6 space-y-4">
                {/* Toolbar */}
                <div className="flex flex-wrap items-center gap-3 rounded-xl bg-white p-3 shadow-sm">
                    <DateDropdown dateFrom={form.date_from} dateTo={form.date_to} month={form.month} year={form.year} onChange={handleFilterChange} />
                    <button type="button" onClick={handleExportCsv} className="inline-flex items-center gap-1.5 rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white hover:bg-green-700 transition">
                        <i className="fa-solid fa-file-csv text-xs" /> CSV
                    </button>
                    {canWrite && (
                        <Link href={`${route('admin.attendances.create')}?${userAttendancesReturnParams()}`}
                            className="ml-auto inline-flex items-center gap-1.5 rounded-lg border border-teal-600 px-3 py-2 text-sm font-semibold text-teal-600 hover:bg-teal-50 transition">
                            <i className="fa-solid fa-plus" /> 打刻登録
                        </Link>
                    )}
                </div>

                {/* 時間集計 */}
                <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div className="flex items-center gap-2 border-b border-gray-100 px-4 py-2">
                        <i className="fa-solid fa-clock text-teal-500 text-sm" />
                        <h3 className="text-sm font-bold text-gray-700">時間集計</h3>
                    </div>
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 text-[11px] font-semibold text-gray-500">
                            <tr>
                                <th className="px-3 py-1.5 text-center whitespace-nowrap">労働合計</th>
                                <th className="px-3 py-1.5 text-center whitespace-nowrap">丸め後</th>
                                <th className="px-3 py-1.5 text-center whitespace-nowrap">休憩合計</th>
                                {hasSchedule && (
                                    <>
                                        <th className="px-3 py-1.5 text-center whitespace-nowrap">残業</th>
                                        <th className="px-3 py-1.5 text-center whitespace-nowrap">遅刻時間</th>
                                        <th className="px-3 py-1.5 text-center whitespace-nowrap">早退時間</th>
                                    </>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            <tr className="font-bold">
                                <td className="px-3 py-2 text-center font-mono text-gray-800">{summary.total_work}</td>
                                <td className="px-3 py-2 text-center font-mono text-gray-800">{summary.rounded_work}</td>
                                <td className="px-3 py-2 text-center font-mono text-gray-600">{summary.total_break}</td>
                                {hasSchedule && (
                                    <>
                                        <td className="px-3 py-2 text-center font-mono text-red-600">{summary.overtime}</td>
                                        <td className="px-3 py-2 text-center font-mono text-yellow-600">{summary.late_time}</td>
                                        <td className="px-3 py-2 text-center font-mono text-orange-600">{summary.early_leave_time}</td>
                                    </>
                                )}
                            </tr>
                        </tbody>
                    </table>
                </div>

                {/* 日数集計 */}
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    <SummaryCard icon="fa-solid fa-briefcase text-teal-600" iconBg="bg-teal-50" label="出勤日数" value={`${summary.work_days}日`} />
                    <SummaryCard icon="fa-solid fa-chart-line text-blue-600" iconBg="bg-blue-50" label="平均/日" value={summary.avg_per_day} />
                    {hasSchedule && (
                        <>
                            <SummaryCard icon="fa-solid fa-clock text-yellow-600" iconBg="bg-yellow-50" label="遅刻" value={`${summary.late_count}回`} />
                            <SummaryCard icon="fa-solid fa-right-from-bracket text-orange-600" iconBg="bg-orange-50" label="早退" value={`${summary.early_leave_count}回`} />
                        </>
                    )}
                    <SummaryCard
                        icon={`fa-solid fa-triangle-exclamation ${summary.missing_clock_out > 0 ? 'text-red-600' : 'text-gray-400'}`}
                        iconBg={summary.missing_clock_out > 0 ? 'bg-red-50' : 'bg-gray-50'}
                        label="退勤忘れ"
                        value={`${summary.missing_clock_out}件`}
                    />
                </div>

                {/* 日別データ */}
                <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div className="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5">
                        <i className="fa-solid fa-list text-teal-500 text-sm" />
                        <h3 className="text-sm font-bold text-gray-700">日別データ</h3>
                        {scheduleInfo && (
                            <span className="ml-auto text-[10px] text-gray-400">
                                所定 {scheduleInfo.work_start_time}〜{scheduleInfo.work_end_time}
                            </span>
                        )}
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-[11px] font-semibold text-gray-500">
                                <tr>
                                    <th className="px-3 py-2 text-left whitespace-nowrap">日付</th>
                                    <th className="px-2 py-2 text-center whitespace-nowrap">打刻店舗</th>
                                    <th className="px-2 py-2 text-center whitespace-nowrap">出勤</th>
                                    <th className="px-2 py-2 text-center whitespace-nowrap">退勤</th>
                                    <th className="px-2 py-2 text-center whitespace-nowrap">休憩</th>
                                    <th className="px-2 py-2 text-center whitespace-nowrap">実労働</th>
                                    <th className="px-2 py-2 text-center whitespace-nowrap">丸め後</th>
                                    {hasSchedule && <th className="px-2 py-2 text-center whitespace-nowrap">残業</th>}
                                    <th className="px-2 py-2 text-center whitespace-nowrap">写真</th>
                                    <th className="px-2 py-2 text-center whitespace-nowrap">備考</th>
                                    {canWrite && <th className="px-2 py-2 text-right whitespace-nowrap">操作</th>}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {displayDays.length === 0 && (
                                    <tr>
                                        <td colSpan={colSpan} className="px-4 py-8 text-center text-gray-400">データがありません</td>
                                    </tr>
                                )}
                                {displayDays.map((dateStr) => {
                                    const a = attendanceMap.get(dateStr) ?? null;
                                    const missing = isMissingClockOut(a);
                                    const net = calcNetMinutes(a);
                                    const rounded = calcRoundedMinutes(a);
                                    const ot = calcOvertime(a);
                                    const late = isLate(a);
                                    const early = isEarlyLeave(a);
                                    const weekdayBg = getWeekdayRowBg(dateStr);
                                    const todayHighlight = isToday(dateStr) ? 'bg-teal-50/40' : '';
                                    const rowBg = missing ? 'bg-red-50/60' : (todayHighlight || weekdayBg);
                                    const hasRecord = a !== null;

                                    return (
                                        <tr key={dateStr} className={`transition hover:bg-gray-50/50 ${rowBg}`}>
                                            {/* 日付 */}
                                            <td className="px-3 py-1.5 whitespace-nowrap">
                                                <span className={`font-medium ${isToday(dateStr) ? 'text-teal-700' : 'text-gray-800'}`}>
                                                    {formatDate(dateStr)}
                                                </span>
                                                <span className={`ml-1 text-xs ${getWeekdayColor(dateStr)}`}>
                                                    ({getWeekday(dateStr)})
                                                </span>
                                            </td>
                                            {/* 打刻店舗 */}
                                            <td className="px-2 py-1.5 text-center whitespace-nowrap">
                                                {a?.department?.name ? (
                                                    <span className="text-xs text-gray-500">{a.department.name}</span>
                                                ) : (
                                                    <span className="text-gray-300">—</span>
                                                )}
                                            </td>
                                            {/* 出勤 */}
                                            <td className="px-2 py-1.5 text-center whitespace-nowrap">
                                                {a?.clock_in_at ? (
                                                    <span className={`font-mono text-xs ${late ? 'text-yellow-600 font-bold' : 'text-green-600'}`}>
                                                        {formatTime(a.clock_in_at)}
                                                    </span>
                                                ) : <span className="text-gray-300">—</span>}
                                            </td>
                                            {/* 退勤 */}
                                            <td className="px-2 py-1.5 text-center whitespace-nowrap">
                                                {a?.clock_out_at ? (
                                                    <span className={`font-mono text-xs ${early ? 'text-orange-600 font-bold' : 'text-blue-600'}`}>
                                                        {formatTime(a.clock_out_at)}
                                                    </span>
                                                ) : <span className="text-gray-300">—</span>}
                                            </td>
                                            {/* 休憩 */}
                                            <td className="px-2 py-1.5 text-center text-xs text-gray-600">
                                                {hasRecord && a?.clock_in_at ? (
                                                    (() => {
                                                        const breakRecords = a.attendance_breaks ?? [];
                                                        const hasBreakRecords = breakRecords.length > 0;

                                                        // 退勤済み: サーバー計算値を使用、退勤前: 完了済み休憩の合計を表示
                                                        let brk: number;
                                                        if (a.clock_out_at) {
                                                            brk = a.computed_break_minutes ?? a.break_minutes ?? user.break_minutes ?? defaultBreakMinutes;
                                                        } else {
                                                            brk = breakRecords
                                                                .filter(b => b.ended_at !== null)
                                                                .reduce((acc, b) => acc + Math.floor((new Date(b.ended_at!).getTime() - new Date(b.started_at).getTime()) / 60000), 0);
                                                        }

                                                        if (!hasBreakRecords && !a.clock_out_at) {
                                                            return <span className="text-gray-300">—</span>;
                                                        }

                                                        return hasBreakRecords ? (
                                                            <button
                                                                onClick={() => setBreakModal({ attendanceId: a!.id, breaks: a!.attendance_breaks! })}
                                                                className="inline-flex items-center gap-1 rounded-full bg-orange-100 px-2 py-0.5 text-[11px] font-semibold text-orange-600 hover:bg-orange-200 transition"
                                                                title="休憩詳細を見る"
                                                            >
                                                                <i className="fa-solid fa-mug-hot text-[9px]" />
                                                                {brk}分
                                                            </button>
                                                        ) : (
                                                            <span>{brk}</span>
                                                        );
                                                    })()
                                                ) : <span className="text-gray-300">—</span>}
                                            </td>
                                            {/* 実労働 */}
                                            <td className="px-2 py-1.5 text-center font-mono text-xs text-gray-800 font-medium">
                                                {net !== null ? fmtHM(net) : <span className="text-gray-300">—</span>}
                                            </td>
                                            {/* 丸め後 */}
                                            <td className="px-2 py-1.5 text-center font-mono text-xs text-teal-700 font-medium">
                                                {rounded !== null ? fmtHM(rounded) : <span className="text-gray-300">—</span>}
                                            </td>
                                            {/* 残業 */}
                                            {hasSchedule && (
                                                <td className="px-2 py-1.5 text-center font-mono text-xs">
                                                    {ot !== null && ot > 0
                                                        ? <span className="font-bold text-red-600">{fmtHM(ot)}</span>
                                                        : <span className="text-gray-300">—</span>}
                                                </td>
                                            )}
                                            {/* 写真 */}
                                            <td className="px-2 py-1.5 text-center whitespace-nowrap">
                                                {hasRecord ? (
                                                    <div className="inline-flex gap-1">
                                                        {a?.clock_in_photo_path ? (
                                                            <a href={route('admin.attendances.photo', { attendance: a!.id, type: 'in' })} target="_blank"
                                                                className="inline-flex h-6 w-6 items-center justify-center rounded bg-blue-50 text-blue-500 hover:bg-blue-100 transition" title="出勤写真">
                                                                <i className="fa-solid fa-camera text-[10px]" />
                                                            </a>
                                                        ) : <span className="inline-flex h-6 w-6 items-center justify-center rounded bg-gray-50 text-gray-300"><i className="fa-solid fa-camera text-[10px]" /></span>}
                                                        {a?.clock_out_photo_path ? (
                                                            <a href={route('admin.attendances.photo', { attendance: a!.id, type: 'out' })} target="_blank"
                                                                className="inline-flex h-6 w-6 items-center justify-center rounded bg-red-50 text-red-500 hover:bg-red-100 transition" title="退勤写真">
                                                                <i className="fa-solid fa-camera text-[10px]" />
                                                            </a>
                                                        ) : <span className="inline-flex h-6 w-6 items-center justify-center rounded bg-gray-50 text-gray-300"><i className="fa-solid fa-camera text-[10px]" /></span>}
                                                    </div>
                                                ) : <span className="text-gray-300">—</span>}
                                            </td>
                                            {/* 備考 */}
                                            <td className="px-2 py-1.5 text-center whitespace-nowrap">
                                                <div className="flex flex-wrap items-center justify-center gap-1">
                                                    {missing && <span className="inline-flex items-center gap-0.5 rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-600"><i className="fa-solid fa-triangle-exclamation text-[8px]" /> 退勤忘れ</span>}
                                                    {late && !missing && <span className="inline-block rounded bg-yellow-100 px-1.5 py-0.5 text-[10px] font-bold text-yellow-700">遅刻</span>}
                                                    {early && !missing && <span className="inline-block rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-bold text-orange-700">早退</span>}
                                                    {!missing && !late && !early && <span className="text-gray-300">—</span>}
                                                </div>
                                            </td>
                                            {/* 操作 */}
                                            {canWrite && (
                                                <td className="px-2 py-1.5 text-right whitespace-nowrap">
                                                    <div className="flex items-center justify-end gap-1">
                                                        {hasRecord ? (
                                                            <Link href={`${route('admin.attendances.edit', a!.id)}?${userAttendancesReturnParamsEdit()}`}
                                                                className="inline-flex items-center gap-1 rounded bg-teal-50 px-2 py-1 text-[11px] font-semibold text-teal-600 hover:bg-teal-100 transition">
                                                                <i className="fa-solid fa-pen text-[9px]" /> 修正
                                                            </Link>
                                                        ) : (
                                                            <Link
                                                                href={`${route('admin.attendances.create')}?${userAttendancesReturnParams({ date: dateStr })}`}
                                                                className="inline-flex items-center gap-1 rounded bg-gray-50 px-2 py-1 text-[11px] font-semibold text-gray-400 hover:bg-teal-50 hover:text-teal-600 transition"
                                                            >
                                                                <i className="fa-solid fa-plus text-[9px]" /> 登録
                                                            </Link>
                                                        )}
                                                        {hasRecord && (
                                                            <button
                                                                onClick={() => handleDeleteAttendance(a!)}
                                                                className="inline-flex items-center gap-1 rounded border border-red-200 px-2 py-1 text-[11px] font-semibold text-red-600 hover:bg-red-50 transition"
                                                            >
                                                                <i className="fa-solid fa-trash-can text-[9px]" /> 削除
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            )}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AdminLayout>

        {/* 休憩詳細モーダル */}
        {breakModal && (
            <BreakDetailModal
                breaks={breakModal.breaks}
                attendanceId={breakModal.attendanceId}
                canWrite={canWrite}
                onClose={() => setBreakModal(null)}
            />
        )}
        </>
    );
}
