import AdminLayout from '@/Layouts/AdminLayout';
import AttendanceEditForm, {
    AttendanceFormValues,
    attendanceFormValuesFromRecord,
    buildAttendanceSubmitPayload,
} from '@/Components/Admin/AttendanceEditForm';
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

export default function AttendanceEdit({
    attendance,
    returnTo = null,
    returnMonth = null,
    returnYear = null,
    returnDateFrom = null,
    returnDateTo = null,
}: Props) {
    const pageErrors = usePage().props.errors as Record<string, string>;
    const [values, setValues] = useState<AttendanceFormValues>(() => attendanceFormValuesFromRecord(attendance));
    const [processing, setProcessing] = useState(false);

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
        if (returnTo === 'monthly') {
            return route('admin.attendances.monthly', {
                ...(returnMonth ? { month: returnMonth } : {}),
            });
        }
        return route('admin.attendances.index');
    }, [returnTo, attendance.user.id, returnMonth, returnYear, returnDateFrom, returnDateTo]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setProcessing(true);
        router.put(
            route('admin.attendances.update', attendance.id),
            buildAttendanceSubmitPayload(values, {
                return_to: returnTo,
                return_month: returnMonth,
                return_year: returnYear,
                return_date_from: returnDateFrom,
                return_date_to: returnDateTo,
            }),
            { onFinish: () => setProcessing(false) },
        );
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
                        <form onSubmit={submit} className="space-y-6">
                            <AttendanceEditForm
                                mode="edit"
                                values={values}
                                onChange={setValues}
                                errors={pageErrors}
                                userName={attendance.user.name}
                                departmentName={attendance.department?.name}
                            />

                            {attendance.break_minutes != null && (
                                <p className="text-xs text-gray-400">
                                    ※ 旧形式で保存されていた手入力の休憩時間（{attendance.break_minutes}分）は、保存時に新しい休憩セットへ置き換えられます。
                                </p>
                            )}

                            <div className="flex items-center justify-between">
                                <Link href={listBackHref} className="text-sm text-gray-600 hover:text-gray-800">
                                    戻る
                                </Link>
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
