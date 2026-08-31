import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import { monthPresetRanges, useMonthClosingDay } from '@/lib/monthPeriod';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

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
    attendance_breaks?: BreakRecord[];
    department?: { id: number; name: string } | null;
    user: { id: number; name: string };
}

interface PaginatedData<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
}

interface Props {
    attendances: PaginatedData<AttendanceItem>;
    users: Array<{ id: number; name: string }>;
    filters: { user_id?: string; date_from?: string; date_to?: string };
}

function toDateStr(d: Date): string {
    const pad = (n: number) => n.toString().padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

function formatFilterLabel(from: string, to: string): string {
    if (!from && !to) return '全期間';
    const fmt = (s: string) => {
        const d = new Date(s + 'T00:00:00');
        if (isNaN(d.getTime())) return s;
        return `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()}`;
    };
    if (from && to && from === to) return fmt(from);
    if (from && to) return `${fmt(from)} 〜 ${fmt(to)}`;
    if (from) return `${fmt(from)} 〜`;
    return `〜 ${fmt(to)}`;
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
        if (confirm(`休憩 ${index + 1} を削除しますか？写真も含めて完全に削除されます。この操作は取り消せません。`)) {
            router.delete(
                route('admin.attendances.breaks.destroy', { attendance: attendanceId, break: b.id }),
                { onSuccess: () => { router.reload(); onClose(); } }
            );
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
            <div className="w-full max-w-md rounded-2xl bg-white shadow-2xl" onClick={e => e.stopPropagation()}>
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

function DateDropdown({
    dateFrom,
    dateTo,
    onChange,
}: {
    dateFrom: string;
    dateTo: string;
    onChange: (from: string, to: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const fromRef = useRef<HTMLInputElement>(null);
    const toRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    const closingDay = useMonthClosingDay();
    const presets: { label: string; from: string; to: string }[] = (() => {
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(today.getDate() - 1);
        const past3Start = new Date(today.getFullYear(), today.getMonth() - 3, today.getDate());
        const { thisMonth, lastMonth } = monthPresetRanges(closingDay);
        return [
            { label: '今日', from: toDateStr(today), to: toDateStr(today) },
            { label: '昨日', from: toDateStr(yesterday), to: toDateStr(yesterday) },
            { label: '今月', from: thisMonth.from, to: thisMonth.to },
            { label: '先月', from: lastMonth.from, to: lastMonth.to },
            { label: '過去3ヶ月', from: toDateStr(past3Start), to: toDateStr(today) },
            { label: '全期間', from: '', to: '' },
        ];
    })();

    const applyPreset = (from: string, to: string) => { onChange(from, to); setOpen(false); };
    const openCalendar = (inputRef: React.RefObject<HTMLInputElement | null>) => inputRef.current?.showPicker?.();

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition ${
                    open ? 'bg-indigo-600 text-white' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100'
                }`}
            >
                <i className="fa-solid fa-calendar-days" />
                {formatFilterLabel(dateFrom, dateTo)}
                <i className={`fa-solid fa-chevron-down text-[10px] transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>
            {open && (
                <div className="absolute left-0 top-full z-30 mt-2 w-72 rounded-xl bg-white p-4 shadow-xl ring-1 ring-gray-200">
                    <div className="mb-3 grid grid-cols-2 gap-3">
                        <div>
                            <label className="mb-1 block text-[11px] font-semibold text-gray-500">開始日</label>
                            <div className="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm hover:border-indigo-300 transition" onClick={() => openCalendar(fromRef)}>
                                <i className="fa-solid fa-calendar text-indigo-400 text-xs" />
                                <input ref={fromRef} type="date" className="w-full cursor-pointer border-0 p-0 text-sm text-gray-700 focus:ring-0" value={dateFrom} onChange={(e) => onChange(e.target.value, dateTo)} />
                            </div>
                        </div>
                        <div>
                            <label className="mb-1 block text-[11px] font-semibold text-gray-500">終了日</label>
                            <div className="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm hover:border-indigo-300 transition" onClick={() => openCalendar(toRef)}>
                                <i className="fa-solid fa-calendar text-indigo-400 text-xs" />
                                <input ref={toRef} type="date" className="w-full cursor-pointer border-0 p-0 text-sm text-gray-700 focus:ring-0" value={dateTo} onChange={(e) => onChange(dateFrom, e.target.value)} />
                            </div>
                        </div>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        {presets.map((p) => {
                            const isActive = dateFrom === p.from && dateTo === p.to;
                            return (
                                <button key={p.label} type="button" onClick={() => applyPreset(p.from, p.to)}
                                    className={`rounded-lg px-3 py-2 text-sm font-medium transition ${isActive ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' : 'text-gray-600 hover:bg-gray-50'}`}>
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

export default function AttendancesIndex({ attendances, users, filters }: Props) {
    const [form, setForm] = useState({
        user_id: filters.user_id || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
    });
    const [breakModal, setBreakModal] = useState<{ attendanceId: number; breaks: BreakRecord[] } | null>(null);

    const handleDeleteAttendance = (a: AttendanceItem) => {
        if (confirm(`${formatDate(a.work_date)}（${a.user.name}）の打刻記録を削除しますか？\n写真も含めて完全に削除されます。この操作は取り消せません。`)) {
            router.delete(route('admin.attendances.destroy', a.id));
        }
    };

    const handleSearch = () => {
        router.get(route('admin.attendances.index'), form, { preserveState: true });
    };

    const handleExportCsv = () => {
        const params = new URLSearchParams();
        if (form.user_id) params.set('user_id', form.user_id);
        if (form.date_from) params.set('date_from', form.date_from);
        if (form.date_to) params.set('date_to', form.date_to);
        window.location.href = `${route('admin.attendances.export-csv')}?${params.toString()}`;
    };

    const formatDate = (dateStr: string) => {
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return `${d.getFullYear()}年${d.getMonth() + 1}月${d.getDate()}日`;
    };

    const userAttendancesHref = (userId: number, workDate: string) => {
        const d = new Date(workDate);
        const pad = (n: number) => String(n).padStart(2, '0');
        const month = `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
        return route('admin.users.attendances', { user: userId, month });
    };

    const formatTime = (datetime: string | null) => {
        if (!datetime) return '--:--';
        return new Date(datetime).toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
    };

    const isMissingClockOut = (a: AttendanceItem) => {
        if (!a.clock_in_at || a.clock_out_at) return false;
        const workDate = new Date(a.work_date);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        workDate.setHours(0, 0, 0, 0);
        return workDate < today;
    };

    const breakSummary = (a: AttendanceItem): { totalMin: number; hasRecords: boolean } => {
        const breakRecords = a.attendance_breaks ?? [];
        const completed = breakRecords.filter(b => b.ended_at !== null);
        if (completed.length > 0) {
            const totalMin = completed.reduce((acc, b) =>
                acc + Math.floor((new Date(b.ended_at!).getTime() - new Date(b.started_at).getTime()) / 60000), 0);
            return { totalMin, hasRecords: true };
        }
        if (a.break_minutes != null) {
            return { totalMin: a.break_minutes, hasRecords: false };
        }
        return { totalMin: 0, hasRecords: breakRecords.length > 0 };
    };

    const canWrite = useAdminPermission('attendances');

    return (
        <>
        <AdminLayout header={<h2 className="text-xl font-semibold text-gray-800">打刻一覧</h2>}>
            <Head title="打刻一覧" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    {/* Toolbar */}
                    <div className="mb-6 flex flex-wrap items-center gap-3 rounded-xl bg-white p-4 shadow-sm">
                        {canWrite && (
                            <Link href={route('admin.attendances.create')}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700 transition">
                                <i className="fa-solid fa-plus" /> 新規登録
                            </Link>
                        )}
                        <DateDropdown dateFrom={form.date_from} dateTo={form.date_to} onChange={(from, to) => setForm({ ...form, date_from: from, date_to: to })} />
                        <select className="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            value={form.user_id} onChange={(e) => setForm({ ...form, user_id: e.target.value })}>
                            <option value="">全ユーザー</option>
                            {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                        </select>
                        <button type="button" onClick={handleSearch}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                            <i className="fa-solid fa-magnifying-glass text-xs" /> 検索
                        </button>
                        <button type="button" onClick={handleExportCsv}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 transition">
                            <i className="fa-solid fa-file-csv text-xs" /> CSV出力
                        </button>
                    </div>

                    {/* Table */}
                    <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">日付</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">ユーザー</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">打刻店舗</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">出勤</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">退勤</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">休憩</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">写真確認</th>
                                        <th className="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500">操作</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200">
                                    {attendances.data.map((a) => {
                                        const { totalMin, hasRecords } = breakSummary(a);
                                        const breakRecords = a.attendance_breaks ?? [];
                                        const showBreak = hasRecords || a.break_minutes != null;

                                        return (
                                            <tr key={a.id}>
                                                <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">{formatDate(a.work_date)}</td>
                                                <td className="whitespace-nowrap px-6 py-4 text-sm">
                                                    <Link
                                                        href={userAttendancesHref(a.user.id, a.work_date)}
                                                        className="font-medium text-gray-900 hover:text-teal-600 transition"
                                                    >
                                                        {a.user.name}
                                                    </Link>
                                                </td>
                                                <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                    {a.department?.name ?? '—'}
                                                </td>
                                                <td className="whitespace-nowrap px-6 py-4 text-sm text-green-600 font-medium">{formatTime(a.clock_in_at)}</td>
                                                <td className="whitespace-nowrap px-6 py-4 text-sm font-medium">
                                                    <div className="flex items-center gap-2">
                                                        <span className={a.clock_out_at ? 'text-blue-600' : 'text-gray-400'}>
                                                            {formatTime(a.clock_out_at)}
                                                        </span>
                                                        {isMissingClockOut(a) && (
                                                            <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-bold text-amber-600">
                                                                <i className="fa-solid fa-triangle-exclamation" /> 退勤忘れ
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                {/* 休憩 */}
                                                <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-700">
                                                    {showBreak ? (
                                                        breakRecords.length > 0 ? (
                                                            <button
                                                                onClick={() => setBreakModal({ attendanceId: a.id, breaks: breakRecords })}
                                                                className="inline-flex items-center gap-1 rounded-full bg-orange-100 px-2.5 py-0.5 text-xs font-semibold text-orange-600 hover:bg-orange-200 transition"
                                                                title="休憩詳細を見る"
                                                            >
                                                                <i className="fa-solid fa-mug-hot text-[10px]" />
                                                                {totalMin}分
                                                            </button>
                                                        ) : (
                                                            <span>{a.break_minutes}分</span>
                                                        )
                                                    ) : (
                                                        <span className="text-gray-300">—</span>
                                                    )}
                                                </td>
                                                <td className="whitespace-nowrap px-6 py-4 text-sm">
                                                    <div className="flex gap-2">
                                                        {a.clock_in_photo_path ? (
                                                            <a href={route('admin.attendances.photo', { attendance: a.id, type: 'in' })} target="_blank"
                                                                className="inline-flex items-center gap-1.5 rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-600 hover:bg-blue-100 transition">
                                                                <i className="fa-solid fa-camera" /> 出勤
                                                            </a>
                                                        ) : (
                                                            <span className="inline-flex items-center gap-1.5 rounded-lg bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-400 cursor-not-allowed">
                                                                <i className="fa-solid fa-camera" /> 出勤
                                                            </span>
                                                        )}
                                                        {a.clock_out_photo_path ? (
                                                            <a href={route('admin.attendances.photo', { attendance: a.id, type: 'out' })} target="_blank"
                                                                className="inline-flex items-center gap-1.5 rounded-lg bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-100 transition">
                                                                <i className="fa-solid fa-camera" /> 退勤
                                                            </a>
                                                        ) : (
                                                            <span className="inline-flex items-center gap-1.5 rounded-lg bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-400 cursor-not-allowed">
                                                                <i className="fa-solid fa-camera" /> 退勤
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="whitespace-nowrap px-6 py-4 text-right text-sm">
                                                    {canWrite && (
                                                        <div className="flex items-center justify-end gap-1.5">
                                                            <Link href={route('admin.attendances.edit', a.id)}
                                                                className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-600 hover:bg-indigo-100 transition">
                                                                <i className="fa-solid fa-pen-to-square" /> 修正
                                                            </Link>
                                                            <button
                                                                onClick={() => handleDeleteAttendance(a)}
                                                                className="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition"
                                                            >
                                                                <i className="fa-solid fa-trash-can" /> 削除
                                                            </button>
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {attendances.data.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="px-6 py-8 text-center text-sm text-gray-500">
                                                データがありません
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Pagination */}
                    {attendances.last_page > 1 && (
                        <div className="mt-4 flex justify-center gap-1">
                            {attendances.links.map((link, i) => (
                                <Link key={i} href={link.url || '#'}
                                    className={`rounded-md px-3 py-1.5 text-sm ${
                                        link.active ? 'bg-indigo-600 text-white'
                                        : link.url ? 'bg-white text-gray-700 hover:bg-gray-50'
                                        : 'cursor-not-allowed bg-gray-100 text-gray-400'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
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
