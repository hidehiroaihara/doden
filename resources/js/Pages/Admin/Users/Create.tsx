import AdminLayout from '@/Layouts/AdminLayout';
import InputError from '@/Components/InputError';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import type { BusinessLocation, ClosingDateGroup, Department } from '@/types';

type LabelMap = Record<string, string>;

interface Props {
    options: {
        departments: Department[];
        businessLocations: BusinessLocation[];
        closingDateGroups: ClosingDateGroup[];
        employmentTypes: LabelMap;
        genders: LabelMap;
    };
}

const inputClass = 'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500';
const labelClass = 'block text-xs font-medium text-gray-600';

export default function UserCreate({ options }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        last_name: '',
        first_name: '',
        last_name_kana: '',
        first_name_kana: '',
        employee_no: '',
        gender: '',
        joined_at: '',
        employment_type: 'full_time',
        department_id: '',
        business_location_id: '',
        closing_date_group_id: '',
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.users.store'));
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">従業員の新規登録</h2>}>
            <Head title="従業員の新規登録" />

            <div className="p-6">
                <div className="mx-auto max-w-2xl">
                    <form onSubmit={submit} className="space-y-6">
                        <div className="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
                            <div className="mb-5 flex items-center gap-3 border-b border-gray-100 pb-4">
                                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-100">
                                    <i className="fa-solid fa-user text-teal-600" />
                                </div>
                                <div>
                                    <h3 className="text-base font-bold text-gray-800">基本情報</h3>
                                    <p className="text-xs text-gray-400">登録後、詳細画面でその他の情報を編集できます。</p>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label className={labelClass}>姓 <span className="text-red-500">*</span></label>
                                    <input className={inputClass} value={data.last_name} onChange={(e) => setData('last_name', e.target.value)} placeholder="山田" required />
                                    <InputError message={errors.last_name} className="mt-1" />
                                </div>
                                <div>
                                    <label className={labelClass}>名</label>
                                    <input className={inputClass} value={data.first_name} onChange={(e) => setData('first_name', e.target.value)} placeholder="太郎" />
                                    <InputError message={errors.first_name} className="mt-1" />
                                </div>
                                <div>
                                    <label className={labelClass}>姓（フリガナ）</label>
                                    <input className={inputClass} value={data.last_name_kana} onChange={(e) => setData('last_name_kana', e.target.value)} placeholder="ヤマダ" />
                                    <InputError message={errors.last_name_kana} className="mt-1" />
                                </div>
                                <div>
                                    <label className={labelClass}>名（フリガナ）</label>
                                    <input className={inputClass} value={data.first_name_kana} onChange={(e) => setData('first_name_kana', e.target.value)} placeholder="タロウ" />
                                    <InputError message={errors.first_name_kana} className="mt-1" />
                                </div>
                                <div>
                                    <label className={labelClass}>従業員番号</label>
                                    <input className={inputClass} value={data.employee_no} onChange={(e) => setData('employee_no', e.target.value)} placeholder="例: 0001" />
                                    <InputError message={errors.employee_no} className="mt-1" />
                                </div>
                                <div>
                                    <label className={labelClass}>性別</label>
                                    <select className={inputClass} value={data.gender} onChange={(e) => setData('gender', e.target.value)}>
                                        <option value="">未設定</option>
                                        {Object.entries(options.genders).map(([v, l]) => (
                                            <option key={v} value={v}>{l}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.gender} className="mt-1" />
                                </div>
                                <div>
                                    <label className={labelClass}>入社年月日</label>
                                    <input type="date" className={inputClass} value={data.joined_at} onChange={(e) => setData('joined_at', e.target.value)} />
                                    <p className="mt-1 text-xs text-gray-400">未来日は「入社前」として扱われます。</p>
                                    <InputError message={errors.joined_at} className="mt-1" />
                                </div>
                                <div>
                                    <label className={labelClass}>契約種別 <span className="text-red-500">*</span></label>
                                    <select className={inputClass} value={data.employment_type} onChange={(e) => setData('employment_type', e.target.value)}>
                                        {Object.entries(options.employmentTypes).map(([v, l]) => (
                                            <option key={v} value={v}>{l}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.employment_type} className="mt-1" />
                                </div>
                                <div>
                                    <label className={labelClass}>部門（店舗）</label>
                                    <select className={inputClass} value={data.department_id} onChange={(e) => setData('department_id', e.target.value)}>
                                        <option value="">未設定</option>
                                        {options.departments.map((d) => (
                                            <option key={d.id} value={String(d.id)}>{d.name}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.department_id} className="mt-1" />
                                </div>
                                <div>
                                    <label className={labelClass}>所属事業所</label>
                                    <select className={inputClass} value={data.business_location_id} onChange={(e) => setData('business_location_id', e.target.value)}>
                                        <option value="">未設定</option>
                                        {options.businessLocations.map((l) => (
                                            <option key={l.id} value={String(l.id)}>{l.name}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.business_location_id} className="mt-1" />
                                </div>
                                <div>
                                    <label className={labelClass}>締め日グループ</label>
                                    <select className={inputClass} value={data.closing_date_group_id} onChange={(e) => setData('closing_date_group_id', e.target.value)}>
                                        <option value="">未設定</option>
                                        {options.closingDateGroups.map((c) => (
                                            <option key={c.id} value={String(c.id)}>{c.name}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.closing_date_group_id} className="mt-1" />
                                </div>
                                <div className="sm:col-span-2">
                                    <label className={labelClass}>メールアドレス（任意）</label>
                                    <input type="email" className={inputClass} value={data.email} onChange={(e) => setData('email', e.target.value)} />
                                    <InputError message={errors.email} className="mt-1" />
                                </div>
                            </div>
                        </div>

                        <div className="flex items-center justify-between">
                            <Link href={route('admin.users.index')} className="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
                                <i className="fa-solid fa-arrow-left text-xs" /> 一覧に戻る
                            </Link>
                            <button type="submit" disabled={processing} className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                                <i className="fa-solid fa-user-plus" /> 登録する
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AdminLayout>
    );
}
