import AdminLayout from '@/Layouts/AdminLayout';
import AttendanceEditForm, {
    AttendanceFormValues,
    buildAttendanceSubmitPayload,
    emptyAttendanceFormValues,
} from '@/Components/Admin/AttendanceEditForm';
import { Head, Link, router, usePage } from '@inertiajs/react';
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
    const [values, setValues] = useState<AttendanceFormValues>(() =>
        emptyAttendanceFormValues({
            user_id: presetUserId,
            work_date: presetDate || new Date().toISOString().slice(0, 10),
        }),
    );
    const [processing, setProcessing] = useState(false);

    const listBackHref = useMemo(() => {
        if (returnTo === 'user_attendances' && values.user_id) {
            return route('admin.users.attendances', {
                user: values.user_id,
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
    }, [returnTo, values.user_id, returnMonth, returnYear, returnDateFrom, returnDateTo]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setProcessing(true);
        router.post(
            route('admin.attendances.store'),
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
                            <AttendanceEditForm
                                mode="create"
                                values={values}
                                onChange={setValues}
                                errors={pageErrors}
                                users={users}
                                workDateInputRef={dateRef}
                            />

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
