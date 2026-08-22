import AdminLayout from '@/Layouts/AdminLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import { Head, router, usePage, Link } from '@inertiajs/react';
import { FormEventHandler, useMemo, useRef, useState } from 'react';

interface Props {
    users: Array<{ id: number; name: string; break_minutes: number | null }>;
    defaultBreakMinutes: number;
    presetUserId?: string;
    presetDate?: string;
    returnTo?: string | null;
    returnMonth?: string | null;
    returnYear?: string | null;
    returnDateFrom?: string | null;
    returnDateTo?: string | null;
}

interface BreakFormRow {
    started_at: string;
    ended_at: string;
}

export default function AttendanceCreate({
    users,
    presetUserId = '',
    presetDate = '',
    returnTo = null,
    returnMonth = null,
    returnYear = null,
    returnDateFrom = null,
    returnDateTo = null,
}: Props) {
    const pageErrors = usePage().props.errors as Record<string, string>;
    const dateRef = useRef<HTMLInputElement>(null);
    const [userId, setUserId] = useState(presetUserId);
    const [workDate, setWorkDate] = useState(presetDate || new Date().toISOString().slice(0, 10));
    const [clockInTime, setClockInTime] = useState('');
    const [clockOutTime, setClockOutTime] = useState('');
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [breaks, setBreaks] = useState<BreakFormRow[]>([{ started_at: '', ended_at: '' }]);

    const updateBreak = (index: number, field: 'started_at' | 'ended_at', value: string) => {
        setBreaks(prev => prev.map((row, i) => (i === index ? { ...row, [field]: value } : row)));
    };

    const addBreak = () => setBreaks(prev => [...prev, { started_at: '', ended_at: '' }]);
    const removeBreak = (index: number) => setBreaks(prev => prev.filter((_, i) => i !== index));

    const listBackHref = useMemo(() => {
        if (returnTo === 'user_attendances' && userId) {
            return route('admin.users.attendances', {
                user: userId,
                ...(returnMonth ? { month: returnMonth } : {}),
                ...(returnYear ? { year: returnYear } : {}),
                ...(returnDateFrom ? { date_from: returnDateFrom } : {}),
                ...(returnDateTo ? { date_to: returnDateTo } : {}),
            });
        }
        return route('admin.attendances.index');
    }, [returnTo, userId, returnMonth, returnYear, returnDateFrom, returnDateTo]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setProcessing(true);
        const payload = {
            user_id: userId,
            work_date: workDate,
            clock_in_at: clockInTime ? `${workDate}T${clockInTime}` : '',
            clock_out_at: clockOutTime ? `${workDate}T${clockOutTime}` : '',
            breaks: breaks
                .filter(b => b.started_at)
                .map(b => ({ started_at: b.started_at, ended_at: b.ended_at || null })),
            reason,
            return_to: returnTo === 'user_attendances' ? 'user_attendances' : '',
            return_month: returnMonth ?? '',
            return_year: returnYear ?? '',
            return_date_from: returnDateFrom ?? '',
            return_date_to: returnDateTo ?? '',
        };
        router.post(route('admin.attendances.store'), payload, {
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">打刻登録</h2>}>
            <Head title="打刻登録" />

            <div className="p-6">
                <div className="mx-auto max-w-3xl">
                    <div className="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
                        <div className="mb-6 flex items-center gap-3 border-b border-gray-100 pb-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100">
                                <i className="fa-solid fa-plus text-amber-600" />
                            </div>
                            <div>
                                <h3 className="text-base font-bold text-gray-800">打刻を手動登録</h3>
                                <p className="text-xs text-gray-500">打刻忘れや修正が必要な場合に使用してください</p>
                            </div>
                        </div>

                        <form onSubmit={submit} className="space-y-5">
                            <div>
                                <InputLabel htmlFor="user_id" value="ユーザー" />
                                <select
                                    id="user_id"
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                    value={userId}
                                    onChange={(e) => setUserId(e.target.value)}
                                    required
                                >
                                    <option value="">選択してください</option>
                                    {users.map((u) => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </select>
                                <InputError message={pageErrors.user_id} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="work_date" value="勤務日" />
                                <div
                                    className="mt-1 flex cursor-pointer items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 shadow-sm hover:border-teal-400 transition"
                                    onClick={() => dateRef.current?.showPicker?.()}
                                >
                                    <i className="fa-solid fa-calendar-days text-teal-500" />
                                    <input
                                        ref={dateRef}
                                        id="work_date"
                                        type="date"
                                        className="w-full cursor-pointer border-0 p-0 text-sm text-gray-700 focus:ring-0"
                                        value={workDate}
                                        onChange={(e) => setWorkDate(e.target.value)}
                                        required
                                    />
                                </div>
                                <InputError message={pageErrors.work_date} className="mt-2" />
                            </div>

                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <InputLabel htmlFor="clock_in_time" value="出勤時刻" />
                                    <div className="mt-1 flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 shadow-sm hover:border-teal-400 transition">
                                        <i className="fa-solid fa-clock text-blue-500" />
                                        <input
                                            id="clock_in_time"
                                            type="time"
                                            className="w-full border-0 p-0 text-sm text-gray-700 focus:ring-0"
                                            value={clockInTime}
                                            onChange={(e) => setClockInTime(e.target.value)}
                                        />
                                    </div>
                                    <InputError message={pageErrors.clock_in_at} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="clock_out_time" value="退勤時刻" />
                                    <div className="mt-1 flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 shadow-sm hover:border-teal-400 transition">
                                        <i className="fa-solid fa-clock text-red-500" />
                                        <input
                                            id="clock_out_time"
                                            type="time"
                                            className="w-full border-0 p-0 text-sm text-gray-700 focus:ring-0"
                                            value={clockOutTime}
                                            onChange={(e) => setClockOutTime(e.target.value)}
                                        />
                                    </div>
                                    <InputError message={pageErrors.clock_out_at} className="mt-2" />
                                </div>
                            </div>

                            {/* 休憩セット */}
                            <div>
                                <InputLabel value="休憩" />
                                <div className="mt-2 space-y-2">
                                    {breaks.map((row, i) => (
                                        <div key={i} className="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                                            <span className="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-bold text-orange-600">
                                                {i + 1}
                                            </span>
                                            <label className="flex items-center gap-1.5 text-xs font-semibold text-orange-500">
                                                入り
                                                <input
                                                    type="time"
                                                    className="rounded border-gray-300 text-sm focus:border-teal-400 focus:ring-teal-400"
                                                    value={row.started_at}
                                                    onChange={(e) => updateBreak(i, 'started_at', e.target.value)}
                                                />
                                            </label>
                                            <label className="flex items-center gap-1.5 text-xs font-semibold text-amber-500">
                                                戻り
                                                <input
                                                    type="time"
                                                    className="rounded border-gray-300 text-sm focus:border-teal-400 focus:ring-teal-400"
                                                    value={row.ended_at}
                                                    onChange={(e) => updateBreak(i, 'ended_at', e.target.value)}
                                                />
                                            </label>
                                            <button
                                                type="button"
                                                onClick={() => removeBreak(i)}
                                                className="ml-auto rounded p-1.5 text-red-400 hover:bg-red-50 transition"
                                                title="この休憩を削除"
                                            >
                                                <i className="fa-solid fa-trash-can text-sm" />
                                            </button>
                                            {(pageErrors[`breaks.${i}.started_at`] || pageErrors[`breaks.${i}.ended_at`]) && (
                                                <p className="basis-full text-xs text-red-500">
                                                    {pageErrors[`breaks.${i}.started_at`] || pageErrors[`breaks.${i}.ended_at`]}
                                                </p>
                                            )}
                                        </div>
                                    ))}
                                </div>
                                <button
                                    type="button"
                                    onClick={addBreak}
                                    className="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-dashed border-teal-300 px-4 py-2 text-sm font-semibold text-teal-600 hover:bg-teal-50 transition"
                                >
                                    <i className="fa-solid fa-plus text-xs" />
                                    休憩を追加
                                </button>
                            </div>

                            <div>
                                <InputLabel htmlFor="reason" value="登録理由" />
                                <textarea
                                    id="reason"
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                    rows={3}
                                    placeholder="例: 打刻忘れのため管理者が代理登録"
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                />
                                <InputError message={pageErrors.reason} className="mt-2" />
                            </div>

                            <div className="flex items-center justify-between border-t border-gray-100 pt-5">
                                <Link
                                    href={listBackHref}
                                    className="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 hover:text-gray-700 transition"
                                >
                                    <i className="fa-solid fa-arrow-left text-xs" />
                                    一覧に戻る
                                </Link>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50 transition"
                                >
                                    <i className="fa-solid fa-check" />
                                    登録する
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
