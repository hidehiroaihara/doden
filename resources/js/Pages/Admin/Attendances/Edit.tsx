import AdminLayout from '@/Layouts/AdminLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface EditLog {
    id: number;
    field_name: string;
    before_value: string | null;
    after_value: string | null;
    modified_at: string;
    reason: string | null;
    modifier: { name: string } | null;
}

interface BreakRow {
    id: number;
    started_at: string;
    ended_at: string | null;
}

interface AttendanceDetail {
    id: number;
    user_id: number;
    work_date: string;
    clock_in_at: string | null;
    clock_out_at: string | null;
    break_minutes: number | null;
    attendance_breaks?: BreakRow[];
    department?: { id: number; name: string } | null;
    user: { id: number; name: string };
    edit_logs: EditLog[];
}

interface Props {
    attendance: AttendanceDetail;
    returnTo?: string | null;
    returnMonth?: string | null;
    returnYear?: string | null;
    returnDateFrom?: string | null;
    returnDateTo?: string | null;
}

interface BreakFormRow {
    id?: number;
    started_at: string;
    ended_at: string;
}

function formatTimeOnly(datetime: string | null): string {
    if (!datetime) return '';
    const d = new Date(datetime);
    const pad = (n: number) => n.toString().padStart(2, '0');
    return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export default function AttendanceEdit({
    attendance,
    returnTo = null,
    returnMonth = null,
    returnYear = null,
    returnDateFrom = null,
    returnDateTo = null,
}: Props) {
    const pageErrors = usePage().props.errors as Record<string, string>;
    const workDate = (() => {
        const d = new Date(attendance.work_date);
        const pad = (n: number) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    })();

    const [clockInTime, setClockInTime] = useState(formatTimeOnly(attendance.clock_in_at));
    const [clockOutTime, setClockOutTime] = useState(formatTimeOnly(attendance.clock_out_at));
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);

    const initialBreaks: BreakFormRow[] = useMemo(() => {
        const records = attendance.attendance_breaks ?? [];
        if (records.length === 0) return [{ started_at: '', ended_at: '' }];
        return records.map(b => ({
            id: b.id,
            started_at: formatTimeOnly(b.started_at),
            ended_at: b.ended_at ? formatTimeOnly(b.ended_at) : '',
        }));
    }, [attendance.attendance_breaks]);

    const [breaks, setBreaks] = useState<BreakFormRow[]>(initialBreaks);

    const updateBreak = (index: number, field: 'started_at' | 'ended_at', value: string) => {
        setBreaks(prev => prev.map((row, i) => (i === index ? { ...row, [field]: value } : row)));
    };

    const addBreak = () => {
        setBreaks(prev => [...prev, { started_at: '', ended_at: '' }]);
    };

    const removeBreak = (index: number) => {
        setBreaks(prev => prev.filter((_, i) => i !== index));
    };

    const listBackHref = useMemo(() => {
        if (returnTo === 'user_attendances') {
            return route('admin.users.attendances', {
                user: attendance.user.id,
                ...(returnMonth ? { month: returnMonth } : {}),
                ...(returnYear ? { year: returnYear } : {}),
                ...(returnDateFrom ? { date_from: returnDateFrom } : {}),
                ...(returnDateTo ? { date_to: returnDateTo } : {}),
            });
        }
        return route('admin.attendances.index');
    }, [returnTo, attendance.user.id, returnMonth, returnYear, returnDateFrom, returnDateTo]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setProcessing(true);
        const payload = {
            clock_in_at: clockInTime ? `${workDate} ${clockInTime}:00` : '',
            clock_out_at: clockOutTime ? `${workDate} ${clockOutTime}:00` : '',
            breaks: breaks
                .filter(b => b.started_at)
                .map(b => ({
                    ...(b.id ? { id: b.id } : {}),
                    started_at: b.started_at,
                    ended_at: b.ended_at || null,
                })),
            reason,
            return_to: returnTo === 'user_attendances' ? 'user_attendances' : '',
            return_month: returnMonth ?? '',
            return_year: returnYear ?? '',
            return_date_from: returnDateFrom ?? '',
            return_date_to: returnDateTo ?? '',
        };
        router.put(route('admin.attendances.update', attendance.id), payload, {
            onFinish: () => setProcessing(false),
        });
    };

    const fieldLabel = (field: string) => {
        if (field === 'clock_in_at') return '出勤時刻';
        if (field === 'clock_out_at') return '退勤時刻';
        if (field === 'break_minutes') return '休憩時間';
        if (field === 'break') return '休憩';
        return field;
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-semibold text-gray-800">打刻修正</h2>}>
            <Head title="打刻修正" />

            <div className="py-8">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-6 rounded-xl bg-white p-6 shadow-sm">
                        <div className="mb-4 border-b border-gray-100 pb-4">
                            <p className="text-sm text-gray-500">ユーザー: <span className="font-semibold text-gray-900">{attendance.user.name}</span></p>
                            <p className="mt-1 text-sm text-gray-500">
                                勤務日:{' '}
                                <span className="font-bold text-teal-700 text-base">
                                    {new Date(workDate).toLocaleDateString('ja-JP', { year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'short' })}
                                </span>
                            </p>
                            <p className="mt-1 text-sm text-gray-500">
                                打刻店舗:{' '}
                                <span className="font-medium text-gray-800">
                                    {attendance.department?.name ?? '—'}
                                </span>
                                <span className="ml-2 text-xs text-gray-400">（打刻時に記録・変更不可）</span>
                            </p>
                        </div>

                        <form onSubmit={submit} className="space-y-6">
                            <div>
                                <InputLabel htmlFor="clock_in_at" value="出勤時刻" />
                                <input
                                    id="clock_in_at"
                                    type="time"
                                    className="mt-1 block w-48 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    value={clockInTime}
                                    onChange={(e) => setClockInTime(e.target.value)}
                                />
                                <InputError message={pageErrors.clock_in_at} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="clock_out_at" value="退勤時刻" />
                                <input
                                    id="clock_out_at"
                                    type="time"
                                    className="mt-1 block w-48 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    value={clockOutTime}
                                    onChange={(e) => setClockOutTime(e.target.value)}
                                />
                                <InputError message={pageErrors.clock_out_at} className="mt-2" />
                            </div>

                            {/* 休憩セット */}
                            <div>
                                <InputLabel value="休憩" />
                                <div className="mt-2 space-y-2">
                                    {breaks.map((row, i) => (
                                        <div key={row.id ?? `new-${i}`} className="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                                            <span className="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-bold text-orange-600">
                                                {i + 1}
                                            </span>
                                            <label className="flex items-center gap-1.5 text-xs font-semibold text-orange-500">
                                                入り
                                                <input
                                                    type="time"
                                                    className="rounded border-gray-300 text-sm focus:border-indigo-400 focus:ring-indigo-400"
                                                    value={row.started_at}
                                                    onChange={(e) => updateBreak(i, 'started_at', e.target.value)}
                                                />
                                            </label>
                                            <label className="flex items-center gap-1.5 text-xs font-semibold text-amber-500">
                                                戻り
                                                <input
                                                    type="time"
                                                    className="rounded border-gray-300 text-sm focus:border-indigo-400 focus:ring-indigo-400"
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
                                    className="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-dashed border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-600 hover:bg-indigo-50 transition"
                                >
                                    <i className="fa-solid fa-plus text-xs" />
                                    休憩を追加
                                </button>
                                {attendance.break_minutes != null && (
                                    <p className="mt-2 text-xs text-gray-400">
                                        ※ 旧形式で保存されていた手入力の休憩時間（{attendance.break_minutes}分）は、保存時に新しい休憩セットへ置き換えられます。
                                    </p>
                                )}
                            </div>

                            <div>
                                <InputLabel htmlFor="reason" value="修正理由（任意）" />
                                <textarea
                                    id="reason"
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    rows={3}
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                />
                                <InputError message={pageErrors.reason} className="mt-2" />
                            </div>

                            <div className="flex items-center justify-between">
                                <Link href={listBackHref} className="text-sm text-gray-600 hover:text-gray-800">戻る</Link>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    保存
                                </button>
                            </div>
                        </form>
                    </div>

                    {/* Edit History */}
                    {attendance.edit_logs.length > 0 && (
                        <div className="rounded-xl bg-white p-6 shadow-sm">
                            <h3 className="mb-4 text-lg font-semibold text-gray-800">修正履歴</h3>
                            <div className="space-y-3">
                                {attendance.edit_logs.map((log) => (
                                    <div key={log.id} className="rounded-lg border border-gray-100 bg-gray-50 p-3 text-sm">
                                        <div className="flex items-center justify-between">
                                            <span className="font-medium text-gray-700">{fieldLabel(log.field_name)}</span>
                                            <span className="text-xs text-gray-400">
                                                {new Date(log.modified_at).toLocaleString('ja-JP')}
                                                {log.modifier && ` by ${log.modifier.name}`}
                                            </span>
                                        </div>
                                        <p className="mt-1 text-gray-600">
                                            <span className="text-red-500 line-through">{log.before_value || '(なし)'}</span>
                                            {' → '}
                                            <span className="text-green-600">{log.after_value || '(なし)'}</span>
                                        </p>
                                        {log.reason && <p className="mt-1 text-xs text-gray-500">理由: {log.reason}</p>}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
