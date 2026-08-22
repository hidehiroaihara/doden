import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { BusinessLocation, Department, EmploymentStatus, JobTitle } from '@/types';

type LabelMap = Record<string, string>;

interface EmployeeRow {
    id: number;
    employee_no: string | null;
    full_name: string;
    name: string;
    employment_status: EmploymentStatus;
    department: { id: number; name: string } | null;
    business_location: { id: number; name: string } | null;
    job_title: { id: number; name: string } | null;
    employment_type: string | null;
    employment_type_label: string | null;
    pay_type: string | null;
    pay_type_label: string | null;
}

interface Props {
    users: EmployeeRow[];
    filters: {
        search: string;
        business_location_id: string | number;
        department_id: string | number;
        job_title_id: string | number;
        employment_type: string;
        status: string;
    };
    filterOptions: {
        departments: Department[];
        businessLocations: BusinessLocation[];
        jobTitles: JobTitle[];
        employmentTypes: LabelMap;
        statuses: LabelMap;
    };
}

const STATUS_CONFIG: Record<EmploymentStatus, { label: string; badge: string }> = {
    active: { label: '在籍中', badge: 'bg-green-100 text-green-700' },
    pre_join: { label: '入社前', badge: 'bg-blue-100 text-blue-700' },
    retired: { label: '退職', badge: 'bg-red-100 text-red-700' },
};

const selectClass = 'w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500';
const labelClass = 'mb-1 block text-xs font-medium text-gray-500';

export default function UsersIndex({ users, filters, filterOptions }: Props) {
    const canWrite = useAdminPermission('users');
    const { data, setData, get, processing } = useForm({
        search: filters.search ?? '',
        business_location_id: String(filters.business_location_id ?? ''),
        department_id: String(filters.department_id ?? ''),
        job_title_id: String(filters.job_title_id ?? ''),
        employment_type: filters.employment_type ?? '',
        status: filters.status ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        get(route('admin.users.index'), { preserveState: true, preserveScroll: true });
    };

    const clear = () => {
        router.get(route('admin.users.index'));
    };

    const hasFilters =
        data.search !== '' || data.business_location_id !== '' || data.department_id !== '' ||
        data.job_title_id !== '' || data.employment_type !== '' || data.status !== '';

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">従業員情報</h2>}>
            <Head title="従業員情報" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-7xl space-y-4">
                    {/* 検索パネル */}
                    <form onSubmit={submit} className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div className="lg:col-span-3">
                                <label className={labelClass}>従業員名・カナ・従業員番号</label>
                                <div className="relative">
                                    <i className="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400" />
                                    <input
                                        type="text"
                                        placeholder="氏名・フリガナ・従業員番号で検索"
                                        className={`${selectClass} pl-9`}
                                        value={data.search}
                                        onChange={(e) => setData('search', e.target.value)}
                                    />
                                </div>
                            </div>
                            <div>
                                <label className={labelClass}>所属事業所</label>
                                <select className={selectClass} value={data.business_location_id} onChange={(e) => setData('business_location_id', e.target.value)}>
                                    <option value="">すべて</option>
                                    {filterOptions.businessLocations.map((l) => (
                                        <option key={l.id} value={String(l.id)}>{l.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className={labelClass}>部門（店舗）</label>
                                <select className={selectClass} value={data.department_id} onChange={(e) => setData('department_id', e.target.value)}>
                                    <option value="">すべて</option>
                                    {filterOptions.departments.map((d) => (
                                        <option key={d.id} value={String(d.id)}>{d.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className={labelClass}>職種</label>
                                <select className={selectClass} value={data.job_title_id} onChange={(e) => setData('job_title_id', e.target.value)}>
                                    <option value="">すべて</option>
                                    {filterOptions.jobTitles.map((j) => (
                                        <option key={j.id} value={String(j.id)}>{j.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className={labelClass}>契約種別</label>
                                <select className={selectClass} value={data.employment_type} onChange={(e) => setData('employment_type', e.target.value)}>
                                    <option value="">すべて</option>
                                    {Object.entries(filterOptions.employmentTypes).map(([v, l]) => (
                                        <option key={v} value={v}>{l}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className={labelClass}>在籍状況</label>
                                <select className={selectClass} value={data.status} onChange={(e) => setData('status', e.target.value)}>
                                    <option value="">退職者を除く（初期表示）</option>
                                    {Object.entries(filterOptions.statuses).map(([v, l]) => (
                                        <option key={v} value={v}>{l}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                        <div className="mt-4 flex items-center justify-end gap-2">
                            {hasFilters && (
                                <button type="button" onClick={clear} className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 transition hover:bg-gray-50">
                                    クリア
                                </button>
                            )}
                            <button type="submit" disabled={processing} className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                                <i className="fa-solid fa-magnifying-glass" /> 検索
                            </button>
                        </div>
                    </form>

                    <div className="flex items-center justify-between">
                        <p className="text-sm text-gray-500">{users.length}件の従業員</p>
                        {canWrite && (
                            <Link href={route('admin.users.create')} className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700">
                                <i className="fa-solid fa-user-plus" /> 新規登録
                            </Link>
                        )}
                    </div>

                    {/* 一覧テーブル */}
                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">従業員番号</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">氏名</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">所属事業所</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">契約種別</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">給与区分</th>
                                        <th className="px-4 py-3 text-center text-xs font-semibold text-gray-500">在籍状況</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">勤怠</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {users.map((u) => {
                                        const cfg = STATUS_CONFIG[u.employment_status] ?? STATUS_CONFIG.active;
                                        return (
                                            <tr
                                                key={u.id}
                                                className="cursor-pointer transition hover:bg-teal-50/40"
                                                onClick={() => router.visit(route('admin.users.show', u.id))}
                                            >
                                                <td className="px-4 py-3 text-sm text-gray-500">{u.employee_no || <span className="text-gray-300">-</span>}</td>
                                                <td className="px-4 py-3">
                                                    <span className="text-sm font-medium text-gray-900">{u.full_name || u.name}</span>
                                                    {u.department?.name && <span className="ml-2 text-xs text-gray-400"><i className="fa-solid fa-store mr-1 text-[10px]" />{u.department.name}</span>}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-gray-600">{u.business_location?.name || <span className="text-gray-300">-</span>}</td>
                                                <td className="px-4 py-3 text-sm text-gray-600">{u.employment_type_label || <span className="text-gray-300">-</span>}</td>
                                                <td className="px-4 py-3 text-sm text-gray-600">{u.pay_type_label || <span className="text-gray-300">-</span>}</td>
                                                <td className="px-4 py-3 text-center">
                                                    <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${cfg.badge}`}>{cfg.label}</span>
                                                </td>
                                                <td className="px-4 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                                                    <Link
                                                        href={route('admin.users.attendances', u.id)}
                                                        className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-teal-700 transition hover:bg-teal-50"
                                                    >
                                                        <i className="fa-solid fa-clock" /> 打刻一覧
                                                    </Link>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {users.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="px-6 py-12 text-center text-sm text-gray-400">
                                                <i className="fa-solid fa-users-slash mb-2 text-2xl" />
                                                <p>該当する従業員がいません</p>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
