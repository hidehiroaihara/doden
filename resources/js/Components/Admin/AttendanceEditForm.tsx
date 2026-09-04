import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';

export interface BreakFormRow {
    id?: number;
    started_at: string;
    ended_at: string;
    start_next_day?: boolean;
    end_next_day?: boolean;
}

export interface AttendanceFormValues {
    user_id: string;
    work_date: string;
    clock_in_time: string;
    clock_out_time: string;
    clock_out_next_day: boolean;
    breaks: BreakFormRow[];
    reason: string;
}

export function emptyBreakRow(): BreakFormRow {
    return { started_at: '', ended_at: '', start_next_day: false, end_next_day: false };
}

export function emptyAttendanceFormValues(preset?: Partial<AttendanceFormValues>): AttendanceFormValues {
    return {
        user_id: '',
        work_date: new Date().toISOString().slice(0, 10),
        clock_in_time: '',
        clock_out_time: '',
        clock_out_next_day: false,
        breaks: [emptyBreakRow()],
        reason: '',
        ...preset,
    };
}

function formatTimeOnly(datetime: string | null | undefined): string {
    if (!datetime) return '';
    const d = new Date(datetime);
    const pad = (n: number) => n.toString().padStart(2, '0');
    return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function isNextCalendarDay(workDate: string, datetime: string | null | undefined): boolean {
    if (!datetime) return false;
    const d = new Date(datetime);
    const pad = (n: number) => String(n).padStart(2, '0');
    const dateStr = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    return dateStr > workDate;
}

/** 編集画面用: 打刻レコードからフォーム初期値を組み立てる */
export function attendanceFormValuesFromRecord(
    attendance: {
        user_id: number;
        work_date: string;
        clock_in_at: string | null;
        clock_out_at: string | null;
        attendance_breaks?: Array<{ id: number; started_at: string; ended_at: string | null }>;
    },
): AttendanceFormValues {
    const workDate = (() => {
        const d = new Date(attendance.work_date);
        const pad = (n: number) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    })();

    const breaks: BreakFormRow[] = (attendance.attendance_breaks ?? []).length
        ? (attendance.attendance_breaks ?? []).map((b) => ({
              id: b.id,
              started_at: formatTimeOnly(b.started_at),
              ended_at: b.ended_at ? formatTimeOnly(b.ended_at) : '',
              start_next_day: isNextCalendarDay(workDate, b.started_at),
              end_next_day: isNextCalendarDay(workDate, b.ended_at),
          }))
        : [emptyBreakRow()];

    return {
        user_id: String(attendance.user_id),
        work_date: workDate,
        clock_in_time: formatTimeOnly(attendance.clock_in_at),
        clock_out_time: formatTimeOnly(attendance.clock_out_at),
        clock_out_next_day: isNextCalendarDay(workDate, attendance.clock_out_at),
        breaks,
        reason: '',
    };
}

/** API form-data レスポンスからフォーム初期値を組み立てる */
export function attendanceFormValuesFromPayload(payload: {
    user_id: number;
    work_date: string;
    clock_in_time: string;
    clock_out_time: string;
    clock_out_next_day: boolean;
    breaks: BreakFormRow[];
}): AttendanceFormValues {
    return {
        user_id: String(payload.user_id),
        work_date: payload.work_date,
        clock_in_time: payload.clock_in_time ?? '',
        clock_out_time: payload.clock_out_time ?? '',
        clock_out_next_day: payload.clock_out_next_day ?? false,
        breaks: payload.breaks?.length ? payload.breaks : [emptyBreakRow()],
        reason: '',
    };
}

export interface AttendanceSubmitMeta {
    return_to?: string | null;
    return_month?: string | null;
    return_year?: string | null;
    return_date_from?: string | null;
    return_date_to?: string | null;
}

export function buildAttendanceSubmitPayload(
    values: AttendanceFormValues,
    meta: AttendanceSubmitMeta = {},
): Record<string, unknown> {
    return {
        user_id: values.user_id,
        work_date: values.work_date,
        clock_in_time: values.clock_in_time || null,
        clock_out_time: values.clock_out_time || null,
        clock_out_next_day: values.clock_out_next_day,
        breaks: values.breaks
            .filter((b) => b.started_at)
            .map((b) => ({
                ...(b.id ? { id: b.id } : {}),
                started_at: b.started_at,
                ended_at: b.ended_at || null,
                start_next_day: b.start_next_day ?? false,
                end_next_day: b.end_next_day ?? false,
            })),
        reason: values.reason,
        return_to: meta.return_to ?? '',
        return_month: meta.return_month ?? '',
        return_year: meta.return_year ?? '',
        return_date_from: meta.return_date_from ?? '',
        return_date_to: meta.return_date_to ?? '',
    };
}

interface UserOption {
    id: number;
    name: string;
}

interface Props {
    mode: 'create' | 'edit';
    values: AttendanceFormValues;
    onChange: (values: AttendanceFormValues) => void;
    errors: Record<string, string>;
    users?: UserOption[];
    userName?: string;
    departmentName?: string | null;
    showUserSelect?: boolean;
    showWorkDate?: boolean;
    reasonLabel?: string;
    workDateInputRef?: React.RefObject<HTMLInputElement | null>;
}

export default function AttendanceEditForm({
    mode,
    values,
    onChange,
    errors,
    users = [],
    userName,
    departmentName,
    showUserSelect = mode === 'create',
    showWorkDate = mode === 'create',
    reasonLabel = mode === 'create' ? '登録理由' : '修正理由（任意）',
    workDateInputRef,
}: Props) {
    const patch = (patchValues: Partial<AttendanceFormValues>) => onChange({ ...values, ...patchValues });

    const updateBreak = (index: number, field: keyof BreakFormRow, value: string | boolean) => {
        patch({
            breaks: values.breaks.map((row, i) => (i === index ? { ...row, [field]: value } : row)),
        });
    };

    const addBreak = () => patch({ breaks: [...values.breaks, emptyBreakRow()] });
    const removeBreak = (index: number) => patch({ breaks: values.breaks.filter((_, i) => i !== index) });

    const workDateLabel = (() => {
        const d = new Date(`${values.work_date}T00:00:00`);
        if (Number.isNaN(d.getTime())) return values.work_date;
        return d.toLocaleDateString('ja-JP', { year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'short' });
    })();

    return (
        <div className="space-y-5">
            {showUserSelect && (
                <div>
                    <InputLabel htmlFor="user_id" value="ユーザー" />
                    <select
                        id="user_id"
                        className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500"
                        value={values.user_id}
                        onChange={(e) => patch({ user_id: e.target.value })}
                        required
                    >
                        <option value="">選択してください</option>
                        {users.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.name}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.user_id} className="mt-2" />
                </div>
            )}

            {!showUserSelect && userName && (
                <p className="text-sm text-gray-500">
                    ユーザー: <span className="font-semibold text-gray-900">{userName}</span>
                </p>
            )}

            {showWorkDate ? (
                <div>
                    <InputLabel htmlFor="work_date" value="勤務日" />
                    <div
                        className="mt-1 flex cursor-pointer items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 shadow-sm hover:border-teal-400 transition"
                        onClick={() => workDateInputRef?.current?.showPicker?.()}
                    >
                        <i className="fa-solid fa-calendar-days text-teal-500" />
                        <input
                            ref={workDateInputRef}
                            id="work_date"
                            type="date"
                            className="w-full cursor-pointer border-0 p-0 text-sm text-gray-700 focus:ring-0"
                            value={values.work_date}
                            onChange={(e) => patch({ work_date: e.target.value })}
                            required
                        />
                    </div>
                    <InputError message={errors.work_date} className="mt-2" />
                </div>
            ) : (
                <p className="text-sm text-gray-500">
                    勤務日: <span className="font-bold text-teal-700">{workDateLabel}</span>
                </p>
            )}

            {mode === 'edit' && (
                <p className="text-sm text-gray-500">
                    打刻店舗:{' '}
                    <span className="font-medium text-gray-800">{departmentName ?? '—'}</span>
                    <span className="ml-2 text-xs text-gray-400">（打刻時に記録・変更不可）</span>
                </p>
            )}

            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <InputLabel htmlFor="clock_in_time" value="出勤時刻" />
                    <input
                        id="clock_in_time"
                        type="time"
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        value={values.clock_in_time}
                        onChange={(e) => patch({ clock_in_time: e.target.value })}
                    />
                    <InputError message={errors.clock_in_at ?? errors.clock_in_time} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="clock_out_time" value="退勤時刻" />
                    <div className="mt-1 flex flex-wrap items-center gap-3">
                        <input
                            id="clock_out_time"
                            type="time"
                            className="block w-40 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            value={values.clock_out_time}
                            onChange={(e) => patch({ clock_out_time: e.target.value })}
                        />
                        <label className="inline-flex items-center gap-2 text-sm text-gray-600">
                            <input
                                type="checkbox"
                                className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                checked={values.clock_out_next_day}
                                onChange={(e) => patch({ clock_out_next_day: e.target.checked })}
                            />
                            翌日
                        </label>
                    </div>
                    <InputError message={errors.clock_out_at ?? errors.clock_out_time} className="mt-2" />
                </div>
            </div>

            <div>
                <InputLabel value="休憩" />
                <div className="mt-2 space-y-2">
                    {values.breaks.map((row, i) => (
                        <div
                            key={row.id ?? `new-${i}`}
                            className="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2"
                        >
                            <span className="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-bold text-orange-600">
                                {i + 1}
                            </span>
                            <label className="flex flex-wrap items-center gap-1.5 text-xs font-semibold text-orange-500">
                                入り
                                <input
                                    type="time"
                                    className="rounded border-gray-300 text-sm focus:border-indigo-400 focus:ring-indigo-400"
                                    value={row.started_at}
                                    onChange={(e) => updateBreak(i, 'started_at', e.target.value)}
                                />
                                <label className="inline-flex items-center gap-1 font-normal text-gray-500">
                                    <input
                                        type="checkbox"
                                        className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        checked={row.start_next_day ?? false}
                                        onChange={(e) => updateBreak(i, 'start_next_day', e.target.checked)}
                                    />
                                    翌日
                                </label>
                            </label>
                            <label className="flex flex-wrap items-center gap-1.5 text-xs font-semibold text-amber-500">
                                戻り
                                <input
                                    type="time"
                                    className="rounded border-gray-300 text-sm focus:border-indigo-400 focus:ring-indigo-400"
                                    value={row.ended_at}
                                    onChange={(e) => updateBreak(i, 'ended_at', e.target.value)}
                                />
                                <label className="inline-flex items-center gap-1 font-normal text-gray-500">
                                    <input
                                        type="checkbox"
                                        className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        checked={row.end_next_day ?? false}
                                        onChange={(e) => updateBreak(i, 'end_next_day', e.target.checked)}
                                    />
                                    翌日
                                </label>
                            </label>
                            <button
                                type="button"
                                onClick={() => removeBreak(i)}
                                className="ml-auto rounded p-1.5 text-red-400 hover:bg-red-50 transition"
                                title="この休憩を削除"
                            >
                                <i className="fa-solid fa-trash-can text-sm" />
                            </button>
                            {(errors[`breaks.${i}.started_at`] || errors[`breaks.${i}.ended_at`]) && (
                                <p className="basis-full text-xs text-red-500">
                                    {errors[`breaks.${i}.started_at`] || errors[`breaks.${i}.ended_at`]}
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
            </div>

            <div>
                <InputLabel htmlFor="reason" value={reasonLabel} />
                <textarea
                    id="reason"
                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    rows={3}
                    value={values.reason}
                    onChange={(e) => patch({ reason: e.target.value })}
                    placeholder={mode === 'create' ? '例: 打刻忘れのため管理者が代理登録' : undefined}
                />
                <InputError message={errors.reason} className="mt-2" />
            </div>
        </div>
    );
}
