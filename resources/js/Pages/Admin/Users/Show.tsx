import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type {
    BusinessLocation, ClosingDateGroup, Department, EmployeeDependent, EmployeeLeave,
    EmploymentStatus, JobTitle, LeaveType, User, UserStatusHistory,
} from '@/types';

type LabelMap = Record<string, string>;

interface PayrollData {
    business_location_id: number | null;
    job_title_id: number | null;
    closing_date_group_id: number | null;
    employee_no: string | null;
    employment_type: string;
    pay_type: string;
    position: string | null;
    work_hours_per_day: number | string | null;
    work_days_per_month: number | string | null;
    work_days_monthly_avg: number | string | null;
    work_hours_per_month: number | string | null;
    work_hours_monthly_avg: number | string | null;
    base_salary: number;
    hourly_wage: number;
    hourly_wage2: number;
    daily_wage: number;
    daily_wage2: number;
    tax_table: string;
    dependents_count: number;
    is_widow: boolean;
    is_single_parent: boolean;
    disability_type: string;
    is_working_student: boolean;
    is_minor: boolean;
    is_disaster: boolean;
    is_foreigner: boolean;
    residency_type: string;
    is_social_insurance_enrolled: boolean;
    is_employment_insurance_enrolled: boolean;
    is_care_insurance_target: boolean;
    care_insurance_override: boolean | null;
    standard_reward_health: number | null;
    standard_reward_pension: number | null;
    commute_allowance_taxable: number;
    commute_allowance_non_taxable: number;
    resident_tax_monthly: number;
    resident_tax_june: number;
    bank_name: string | null;
    bank_code: string | null;
    branch_name: string | null;
    branch_code: string | null;
    account_type: string;
    account_number: string | null;
    account_holder_kana: string | null;
    resident_tax_municipality: string | null;
    resident_tax_recipient_number: string | null;
    report_municipality: string | null;
}

interface Options {
    departments: Department[];
    businessLocations: BusinessLocation[];
    jobTitles: JobTitle[];
    closingDateGroups: ClosingDateGroup[];
    leaveTypes: LeaveType[];
    employmentTypes: LabelMap;
    payTypes: LabelMap;
    taxTables: LabelMap;
    accountTypes: LabelMap;
    genders: LabelMap;
    disabilityTypes: LabelMap;
    dependentTypes: LabelMap;
    residencyTypes: LabelMap;
    transportTypes: LabelMap;
    commuteConditions: LabelMap;
    commutePaymentMethods: LabelMap;
}

interface PayItemOption {
    id: number;
    code: string;
    name: string;
    category: string | null;
    calc_method: string;
    sign: string;
    is_allowance_base: boolean;
    is_deduction_base: boolean;
    calc_method_label: string;
}

interface CommuteRoute {
    id?: number;
    sort_order?: number;
    transport_type: string;
    from_place: string | null;
    to_place: string | null;
    one_way_distance_km: number;
    condition: string;
    payment_months: number[];
    attendance_item_code: string | null;
    amount: number;
    payment_method: string;
    cap_amount: number | null;
    non_taxable_limit: number | null;
    uses_parking: boolean;
    parking_condition: string;
    parking_payment_months: number[];
    parking_attendance_item_code: string | null;
    parking_amount: number;
    parking_payment_method: string;
    parking_cap_amount: number | null;
}

// 通勤手段のグルーピング（MF準拠）
const PUBLIC_TRANSPORTS = ['train', 'bus'];        // 電車・バス: 距離/駐車場なし
const PARKING_TRANSPORTS = ['car', 'motorbike', 'bicycle']; // 交通用具: 距離＋駐車場
const DISTANCE_TRANSPORTS = ['car', 'motorbike', 'bicycle', 'walk']; // 交通用具＋徒歩: 片道距離

/** 通勤手段に応じた出発/到着ラベル */
function placeLabels(transport: string): { from: string; to: string } {
    if (transport === 'train') return { from: '乗車駅', to: '降車駅' };
    if (transport === 'bus') return { from: '乗車停留所', to: '降車停留所' };
    return { from: '開始地点', to: '終了地点' };
}

interface AttendanceItemOption {
    code: string;
    name: string;
}

interface Props {
    user: User;
    payroll: PayrollData;
    dependents: EmployeeDependent[];
    leaves: EmployeeLeave[];
    histories: UserStatusHistory[];
    payItems: PayItemOption[];
    payItemValues: Record<number, number>;
    commuteRoutes: CommuteRoute[];
    attendanceItems: AttendanceItemOption[];
    options: Options;
}

/**
 * 給与区分ごとに従業員へ直接入力する単価（時給1/2・日給1/2）。
 * これらは支給項目（基本給等）の計算式が参照する単価であり、支給項目そのものではない。
 */
const RATE_ROWS: Record<string, { label: string; col: keyof PayrollData }[]> = {
    hourly: [
        { label: '時給1', col: 'hourly_wage' },
        { label: '時給2', col: 'hourly_wage2' },
    ],
    daily: [
        { label: '日給1', col: 'daily_wage' },
        { label: '日給2', col: 'daily_wage2' },
    ],
};

/** 通勤手当ルート（定額分）から従来列（課税/非課税）を算出。バックエンドの updateCommute と同一ロジック。 */
function commuteColumns(routes: CommuteRoute[]): { taxable: number; nonTaxable: number } {
    let taxable = 0;
    let nonTaxable = 0;
    for (const r of routes) {
        if (r.condition !== 'fixed') continue;
        const amt = Number(r.amount || 0);
        const limit = r.non_taxable_limit;
        if (limit == null) {
            nonTaxable += amt;
        } else {
            const n = Math.min(amt, limit);
            nonTaxable += n;
            taxable += Math.max(0, amt - n);
        }
        // 駐車場代（定額分）は課税として合算
        if (r.uses_parking && r.parking_condition === 'fixed') {
            taxable += Number(r.parking_amount || 0);
        }
    }
    return { taxable, nonTaxable };
}

function emptyRoute(): CommuteRoute {
    return {
        transport_type: 'train',
        from_place: '',
        to_place: '',
        one_way_distance_km: 0,
        condition: 'fixed',
        payment_months: [],
        attendance_item_code: null,
        amount: 0,
        payment_method: 'cash',
        cap_amount: null,
        non_taxable_limit: null,
        uses_parking: false,
        parking_condition: 'fixed',
        parking_payment_months: [],
        parking_attendance_item_code: null,
        parking_amount: 0,
        parking_payment_method: 'cash',
        parking_cap_amount: null,
    };
}

const STATUS_CONFIG: Record<EmploymentStatus, { label: string; badge: string }> = {
    active: { label: '在籍中', badge: 'bg-green-100 text-green-700' },
    pre_join: { label: '入社前', badge: 'bg-blue-100 text-blue-700' },
    retired: { label: '退職', badge: 'bg-red-100 text-red-700' },
};

const inputClass = 'w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500';
const fieldLabel = 'mb-1 block text-xs font-medium text-gray-500';

function fmtDate(v?: string | null): string {
    if (!v) return '—';
    const [d] = v.split('T');
    return d || '—';
}

/** 表示専用の項目行 */
function Row({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-gray-50 py-2 last:border-0">
            <dt className="text-xs text-gray-500">{label}</dt>
            <dd className="text-right text-sm text-gray-800">{value === '' || value == null ? <span className="text-gray-300">未設定</span> : value}</dd>
        </div>
    );
}

/** セクションの外枠（見出し＋編集/保存/キャンセルボタン） */
function SectionShell({
    title, icon, canWrite, editing, onEdit, onCancel, onSave, processing, children,
}: {
    title: string; icon: string; canWrite: boolean; editing: boolean;
    onEdit: () => void; onCancel: () => void; onSave: () => void; processing: boolean; children: React.ReactNode;
}) {
    return (
        <div className="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
            <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3.5">
                <h3 className="flex items-center gap-2 text-sm font-bold text-gray-800">
                    <i className={`${icon} text-teal-600`} /> {title}
                </h3>
                {canWrite && (
                    editing ? (
                        <div className="flex items-center gap-2">
                            <button onClick={onCancel} className="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-500 transition hover:bg-gray-100">キャンセル</button>
                            <button onClick={onSave} disabled={processing} className="inline-flex items-center gap-1.5 rounded-lg bg-teal-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                                <i className="fa-solid fa-floppy-disk" /> 更新する
                            </button>
                        </div>
                    ) : (
                        <button onClick={onEdit} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-50">
                            <i className="fa-solid fa-pen" /> 編集
                        </button>
                    )
                )}
            </div>
            <div className="p-5">{children}</div>
        </div>
    );
}

/** セクション用の共通フック */
function useSection<T extends Record<string, unknown>>(userId: number, section: string, initial: T) {
    const [editing, setEditing] = useState(false);
    const [data, setData] = useState<T>(initial);
    const [processing, setProcessing] = useState(false);
    const set = <K extends keyof T>(k: K, v: T[K]) => setData((d) => ({ ...d, [k]: v }));
    const save = (payload?: Record<string, unknown>) => {
        setProcessing(true);
        router.put(route('admin.users.section', { user: userId, section }), (payload ?? data) as never, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
            onFinish: () => setProcessing(false),
        });
    };
    const cancel = () => { setData(initial); setEditing(false); };
    return { editing, setEditing, data, set, setData, processing, save, cancel };
}

const gridClass = 'grid grid-cols-1 gap-4 sm:grid-cols-2';

/* ============================ 基本情報 ============================ */
function BasicSection({ user, canWrite, genders }: { user: User; canWrite: boolean; genders: LabelMap }) {
    const s = useSection(user.id, 'basic', {
        last_name: user.last_name ?? '',
        first_name: user.first_name ?? '',
        last_name_kana: user.last_name_kana ?? '',
        first_name_kana: user.first_name_kana ?? '',
        gender: user.gender ?? '',
        birth_date: user.birth_date?.split('T')[0] ?? '',
        email: user.email ?? '',
        phone: user.phone ?? '',
        postal_code: user.postal_code ?? '',
        prefecture: user.prefecture ?? '',
        city: user.city ?? '',
        street: user.street ?? '',
        building: user.building ?? '',
        address_kana: user.address_kana ?? '',
        my_number: user.my_number ?? '',
        emergency_contact_name: user.emergency_contact_name ?? '',
        emergency_contact_phone: user.emergency_contact_phone ?? '',
    });
    return (
        <SectionShell title="基本情報" icon="fa-solid fa-user" canWrite={canWrite} editing={s.editing}
            onEdit={() => s.setEditing(true)} onCancel={s.cancel} onSave={() => s.save()} processing={s.processing}>
            {s.editing ? (
                <div className={gridClass}>
                    <div><label className={fieldLabel}>姓</label><input className={inputClass} value={s.data.last_name} onChange={(e) => s.set('last_name', e.target.value)} /></div>
                    <div><label className={fieldLabel}>名</label><input className={inputClass} value={s.data.first_name} onChange={(e) => s.set('first_name', e.target.value)} /></div>
                    <div><label className={fieldLabel}>姓（カナ）</label><input className={inputClass} value={s.data.last_name_kana} onChange={(e) => s.set('last_name_kana', e.target.value)} /></div>
                    <div><label className={fieldLabel}>名（カナ）</label><input className={inputClass} value={s.data.first_name_kana} onChange={(e) => s.set('first_name_kana', e.target.value)} /></div>
                    <div><label className={fieldLabel}>性別</label>
                        <select className={inputClass} value={s.data.gender} onChange={(e) => s.set('gender', e.target.value)}>
                            <option value="">未設定</option>
                            {Object.entries(genders).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                    </div>
                    <div><label className={fieldLabel}>生年月日</label><input type="date" className={inputClass} value={s.data.birth_date} onChange={(e) => s.set('birth_date', e.target.value)} /></div>
                    <div><label className={fieldLabel}>メール</label><input type="email" className={inputClass} value={s.data.email} onChange={(e) => s.set('email', e.target.value)} /></div>
                    <div><label className={fieldLabel}>電話番号</label><input className={inputClass} value={s.data.phone} onChange={(e) => s.set('phone', e.target.value)} /></div>
                    <div><label className={fieldLabel}>マイナンバー</label><input className={inputClass} value={s.data.my_number} onChange={(e) => s.set('my_number', e.target.value)} placeholder="12桁" /></div>
                    <div><label className={fieldLabel}>郵便番号</label><input className={inputClass} value={s.data.postal_code} onChange={(e) => s.set('postal_code', e.target.value)} placeholder="123-4567" /></div>
                    <div><label className={fieldLabel}>都道府県</label><input className={inputClass} value={s.data.prefecture} onChange={(e) => s.set('prefecture', e.target.value)} /></div>
                    <div><label className={fieldLabel}>市区町村</label><input className={inputClass} value={s.data.city} onChange={(e) => s.set('city', e.target.value)} /></div>
                    <div><label className={fieldLabel}>番地</label><input className={inputClass} value={s.data.street} onChange={(e) => s.set('street', e.target.value)} /></div>
                    <div><label className={fieldLabel}>建物名・部屋番号</label><input className={inputClass} value={s.data.building} onChange={(e) => s.set('building', e.target.value)} /></div>
                    <div className="sm:col-span-2"><label className={fieldLabel}>住所カナ</label><input className={inputClass} value={s.data.address_kana} onChange={(e) => s.set('address_kana', e.target.value)} /></div>
                    <div><label className={fieldLabel}>緊急連絡先（氏名）</label><input className={inputClass} value={s.data.emergency_contact_name} onChange={(e) => s.set('emergency_contact_name', e.target.value)} /></div>
                    <div><label className={fieldLabel}>緊急連絡先（電話）</label><input className={inputClass} value={s.data.emergency_contact_phone} onChange={(e) => s.set('emergency_contact_phone', e.target.value)} /></div>
                </div>
            ) : (
                <dl className="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                    <Row label="氏名" value={user.full_name} />
                    <Row label="フリガナ" value={[user.last_name_kana, user.first_name_kana].filter(Boolean).join(' ')} />
                    <Row label="性別" value={user.gender ? genders[user.gender] : ''} />
                    <Row label="生年月日" value={fmtDate(user.birth_date)} />
                    <Row label="メール" value={user.email} />
                    <Row label="電話番号" value={user.phone} />
                    <Row label="マイナンバー" value={user.my_number ? '登録済み' : ''} />
                    <Row label="郵便番号" value={user.postal_code} />
                    <Row label="住所" value={user.address} />
                    <Row label="住所カナ" value={user.address_kana} />
                    <Row label="緊急連絡先（氏名）" value={user.emergency_contact_name} />
                    <Row label="緊急連絡先（電話）" value={user.emergency_contact_phone} />
                </dl>
            )}
        </SectionShell>
    );
}

/* ============================ 在籍情報 ============================ */
function EmploymentSection({ user, canWrite }: { user: User; canWrite: boolean }) {
    const s = useSection(user.id, 'employment', {
        joined_at: user.joined_at?.split('T')[0] ?? '',
        is_active: user.is_active,
        customer_no: user.customer_no ?? '',
        retirement_date: user.retirement_date?.split('T')[0] ?? '',
        retirement_type: user.retirement_type ?? '',
        retirement_reason: user.retirement_reason ?? '',
    });
    return (
        <SectionShell title="在籍情報" icon="fa-solid fa-id-badge" canWrite={canWrite} editing={s.editing}
            onEdit={() => s.setEditing(true)} onCancel={s.cancel} onSave={() => s.save()} processing={s.processing}>
            {s.editing ? (
                <div className={gridClass}>
                    <div><label className={fieldLabel}>入社年月日</label><input type="date" className={inputClass} value={s.data.joined_at} onChange={(e) => s.set('joined_at', e.target.value)} /></div>
                    <div><label className={fieldLabel}>顧客No（連携用）</label><input className={inputClass} value={s.data.customer_no} onChange={(e) => s.set('customer_no', e.target.value)} /></div>
                    <div className="sm:col-span-2"><label className="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" className="rounded border-gray-300 text-teal-600 focus:ring-teal-500" checked={s.data.is_active} onChange={(e) => s.set('is_active', e.target.checked)} /> 在籍中（チェックを外すと退職扱い）</label></div>
                    <div><label className={fieldLabel}>退職年月日</label><input type="date" className={inputClass} value={s.data.retirement_date} onChange={(e) => s.set('retirement_date', e.target.value)} /></div>
                    <div><label className={fieldLabel}>退職事由</label><input className={inputClass} value={s.data.retirement_reason} onChange={(e) => s.set('retirement_reason', e.target.value)} /></div>
                </div>
            ) : (
                <dl className="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                    <Row label="入社年月日" value={fmtDate(user.joined_at)} />
                    <Row label="在籍状況" value={STATUS_CONFIG[(user.employment_status ?? 'active') as EmploymentStatus]?.label} />
                    <Row label="退職年月日" value={user.retirement_date ? fmtDate(user.retirement_date) : ''} />
                    <Row label="退職事由" value={user.retirement_reason} />
                    <Row label="顧客No（連携用）" value={user.customer_no} />
                </dl>
            )}
        </SectionShell>
    );
}

/* ============================ 休職・休業情報 ============================ */
function LeaveSection({ user, leaves, canWrite, leaveTypes }: { user: User; leaves: EmployeeLeave[]; canWrite: boolean; leaveTypes: LeaveType[] }) {
    const [editing, setEditing] = useState(false);
    const [rows, setRows] = useState<EmployeeLeave[]>(leaves);
    const [processing, setProcessing] = useState(false);
    const patch = (i: number, p: Partial<EmployeeLeave>) => setRows((r) => r.map((x, idx) => (idx === i ? { ...x, ...p } : x)));
    const add = () => setRows((r) => [...r, { leave_type_id: null, start_date: '', end_date: '', note: '' }]);
    const remove = (i: number) => setRows((r) => r.filter((_, idx) => idx !== i));
    const cancel = () => { setRows(leaves); setEditing(false); };
    const save = () => {
        setProcessing(true);
        router.put(route('admin.users.section', { user: user.id, section: 'leaves' }), { leaves: rows } as never, {
            preserveScroll: true, onSuccess: () => setEditing(false), onFinish: () => setProcessing(false),
        });
    };
    return (
        <SectionShell title="休職・休業情報" icon="fa-solid fa-bed-pulse" canWrite={canWrite} editing={editing}
            onEdit={() => setEditing(true)} onCancel={cancel} onSave={save} processing={processing}>
            {editing ? (
                <div className="space-y-3">
                    {rows.map((r, i) => (
                        <div key={i} className="grid grid-cols-1 gap-3 rounded-xl border border-gray-100 p-3 sm:grid-cols-4">
                            <div><label className={fieldLabel}>休職・休業名</label>
                                <select className={inputClass} value={String(r.leave_type_id ?? '')} onChange={(e) => patch(i, { leave_type_id: e.target.value === '' ? null : Number(e.target.value) })}>
                                    <option value="">未選択</option>
                                    {leaveTypes.map((lt) => <option key={lt.id} value={String(lt.id)}>{lt.name}</option>)}
                                </select>
                            </div>
                            <div><label className={fieldLabel}>開始日</label><input type="date" className={inputClass} value={r.start_date ?? ''} onChange={(e) => patch(i, { start_date: e.target.value })} /></div>
                            <div><label className={fieldLabel}>終了日</label><input type="date" className={inputClass} value={r.end_date ?? ''} onChange={(e) => patch(i, { end_date: e.target.value })} /></div>
                            <div className="flex items-end gap-2">
                                <div className="flex-1"><label className={fieldLabel}>メモ</label><input className={inputClass} value={r.note ?? ''} onChange={(e) => patch(i, { note: e.target.value })} /></div>
                                <button onClick={() => remove(i)} className="mb-0.5 rounded-lg px-2.5 py-2 text-red-500 transition hover:bg-red-50"><i className="fa-solid fa-trash-can" /></button>
                            </div>
                        </div>
                    ))}
                    {leaveTypes.length === 0 && <p className="text-xs text-amber-600">休職・休業種別が未登録です。基本設定＞全般 で追加してください。</p>}
                    <button onClick={add} className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-gray-300 px-4 py-2 text-sm text-gray-600 transition hover:bg-gray-50"><i className="fa-solid fa-plus" /> 追加</button>
                </div>
            ) : (
                leaves.length === 0 ? <p className="text-sm text-gray-400">登録されていません。</p> : (
                    <div className="space-y-2">
                        {leaves.map((l) => (
                            <div key={l.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm">
                                <span className="font-medium text-gray-700">{l.leave_type_name ?? '種別未設定'}</span>
                                <span className="text-gray-500">{fmtDate(l.start_date)} 〜 {l.end_date ? fmtDate(l.end_date) : '未定'}</span>
                                {l.note && <span className="text-xs text-gray-400">{l.note}</span>}
                            </div>
                        ))}
                    </div>
                )
            )}
        </SectionShell>
    );
}

/* ============================ 業務情報 ============================ */
const PAY_TYPE_RADIO: { value: string; label: string }[] = [
    { value: 'monthly', label: '月給制' },
    { value: 'hourly', label: '時給制' },
    { value: 'daily', label: '日給制' },
];

function WorkSection({ user, payroll, canWrite, options }: { user: User; payroll: PayrollData; canWrite: boolean; options: Options }) {
    const s = useSection(user.id, 'work', {
        employee_no: payroll.employee_no ?? '',
        employment_type: payroll.employment_type,
        pay_type: payroll.pay_type,
        closing_date_group_id: payroll.closing_date_group_id ?? '',
        business_location_id: payroll.business_location_id ?? '',
        department_id: user.department_id ?? '',
        job_title_id: payroll.job_title_id ?? '',
        position: payroll.position ?? '',
        work_hours_per_day: payroll.work_hours_per_day ?? '',
        work_days_monthly_avg: payroll.work_days_monthly_avg ?? '',
        work_hours_monthly_avg: payroll.work_hours_monthly_avg ?? '',
    });
    return (
        <SectionShell title="業務情報" icon="fa-solid fa-clipboard-list" canWrite={canWrite} editing={s.editing}
            onEdit={() => s.setEditing(true)} onCancel={s.cancel} onSave={() => s.save()} processing={s.processing}>
            {s.editing ? (
                <div className={gridClass}>
                    <div><label className={fieldLabel}>従業員番号</label><input className={inputClass} value={s.data.employee_no} onChange={(e) => s.set('employee_no', e.target.value)} /></div>
                    <div><label className={fieldLabel}>契約種別</label>
                        <select className={inputClass} value={s.data.employment_type} onChange={(e) => s.set('employment_type', e.target.value)}>
                            {Object.entries(options.employmentTypes).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                    </div>
                    <div className="sm:col-span-2">
                        <label className={fieldLabel}>給与区分</label>
                        <div className="flex flex-wrap gap-x-6 gap-y-2 pt-1">
                            {PAY_TYPE_RADIO.map((p) => (
                                <label key={p.value} className="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="radio" name="pay_type" className="border-gray-300 text-teal-600 focus:ring-teal-500" checked={s.data.pay_type === p.value} onChange={() => s.set('pay_type', p.value)} /> {p.label}
                                </label>
                            ))}
                        </div>
                    </div>
                    <div><label className={fieldLabel}>締め日グループ</label>
                        <select className={inputClass} value={String(s.data.closing_date_group_id)} onChange={(e) => s.set('closing_date_group_id', e.target.value)}>
                            <option value="">未設定</option>
                            {options.closingDateGroups.map((c) => <option key={c.id} value={String(c.id)}>{c.name}</option>)}
                        </select>
                    </div>
                    <div><label className={fieldLabel}>所属事業所</label>
                        <select className={inputClass} value={String(s.data.business_location_id)} onChange={(e) => s.set('business_location_id', e.target.value)}>
                            <option value="">未設定</option>
                            {options.businessLocations.map((l) => <option key={l.id} value={String(l.id)}>{l.name}</option>)}
                        </select>
                    </div>
                    <div><label className={fieldLabel}>部門</label>
                        <select className={inputClass} value={String(s.data.department_id)} onChange={(e) => s.set('department_id', e.target.value)}>
                            <option value="">未設定</option>
                            {options.departments.map((d) => <option key={d.id} value={String(d.id)}>{d.name}</option>)}
                        </select>
                    </div>
                    <div><label className={fieldLabel}>職種</label>
                        <select className={inputClass} value={String(s.data.job_title_id)} onChange={(e) => s.set('job_title_id', e.target.value)}>
                            <option value="">未設定</option>
                            {options.jobTitles.map((j) => <option key={j.id} value={String(j.id)}>{j.name}</option>)}
                        </select>
                    </div>
                    <div><label className={fieldLabel}>役職</label><input className={inputClass} value={s.data.position} onChange={(e) => s.set('position', e.target.value)} /></div>
                    <div><label className={fieldLabel}>1日の所定労働時間</label><input type="number" step="0.01" className={inputClass} value={s.data.work_hours_per_day} onChange={(e) => s.set('work_hours_per_day', e.target.value)} /></div>
                    <div><label className={fieldLabel}>所定労働日数（月平均）</label><input type="number" step="0.01" className={inputClass} value={s.data.work_days_monthly_avg} onChange={(e) => s.set('work_days_monthly_avg', e.target.value)} /></div>
                    <div><label className={fieldLabel}>所定労働時間（月平均）</label><input type="number" step="0.01" className={inputClass} value={s.data.work_hours_monthly_avg} onChange={(e) => s.set('work_hours_monthly_avg', e.target.value)} /></div>
                </div>
            ) : (
                <dl className="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                    <Row label="従業員番号" value={payroll.employee_no} />
                    <Row label="契約種別" value={options.employmentTypes[payroll.employment_type]} />
                    <Row label="給与区分" value={PAY_TYPE_RADIO.find((p) => p.value === payroll.pay_type)?.label} />
                    <Row label="締め日グループ" value={options.closingDateGroups.find((c) => c.id === payroll.closing_date_group_id)?.name} />
                    <Row label="所属事業所" value={options.businessLocations.find((l) => l.id === payroll.business_location_id)?.name} />
                    <Row label="部門" value={user.department?.name} />
                    <Row label="職種" value={options.jobTitles.find((j) => j.id === payroll.job_title_id)?.name} />
                    <Row label="役職" value={payroll.position} />
                    <Row label="1日の所定労働時間" value={payroll.work_hours_per_day ? `${payroll.work_hours_per_day} 時間` : ''} />
                    <Row label="所定労働日数（月平均）" value={payroll.work_days_monthly_avg ? `${payroll.work_days_monthly_avg} 日` : ''} />
                    <Row label="所定労働時間（月平均）" value={payroll.work_hours_monthly_avg ? `${payroll.work_hours_monthly_avg} 時間` : ''} />
                </dl>
            )}
        </SectionShell>
    );
}

/* ============================ 住民税 ============================ */
function ResidentTaxSection({ user, payroll, canWrite }: { user: User; payroll: PayrollData; canWrite: boolean }) {
    const s = useSection(user.id, 'resident_tax', {
        resident_tax_municipality: payroll.resident_tax_municipality ?? '',
        resident_tax_recipient_number: payroll.resident_tax_recipient_number ?? '',
        report_municipality: payroll.report_municipality ?? '',
    });
    return (
        <SectionShell title="住民税" icon="fa-solid fa-city" canWrite={canWrite} editing={s.editing}
            onEdit={() => s.setEditing(true)} onCancel={s.cancel} onSave={() => s.save()} processing={s.processing}>
            {s.editing ? (
                <div className={gridClass}>
                    <div><label className={fieldLabel}>給与支払報告書 提出先市区町村</label><input className={inputClass} value={s.data.report_municipality} onChange={(e) => s.set('report_municipality', e.target.value)} placeholder="例）新宿区" /></div>
                    <div><label className={fieldLabel}>納付先市区町村名</label><input className={inputClass} value={s.data.resident_tax_municipality} onChange={(e) => s.set('resident_tax_municipality', e.target.value)} placeholder="例）新宿区" /></div>
                    <div><label className={fieldLabel}>受給者番号</label><input className={inputClass} value={s.data.resident_tax_recipient_number} onChange={(e) => s.set('resident_tax_recipient_number', e.target.value)} /></div>
                </div>
            ) : (
                <dl className="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                    <Row label="給与支払報告書 提出先市区町村" value={payroll.report_municipality} />
                    <Row label="納付先市区町村名" value={payroll.resident_tax_municipality} />
                    <Row label="受給者番号" value={payroll.resident_tax_recipient_number} />
                </dl>
            )}
        </SectionShell>
    );
}

/* ============================ 所得税 ============================ */
function IncomeTaxSection({ user, payroll, canWrite, options }: { user: User; payroll: PayrollData; canWrite: boolean; options: Options }) {
    const s = useSection(user.id, 'income_tax', {
        tax_table: payroll.tax_table,
        dependents_count: payroll.dependents_count,
        is_widow: payroll.is_widow,
        is_single_parent: payroll.is_single_parent,
        disability_type: payroll.disability_type,
        is_working_student: payroll.is_working_student,
        is_minor: payroll.is_minor,
        is_disaster: payroll.is_disaster,
        is_foreigner: payroll.is_foreigner,
        residency_type: payroll.residency_type,
    });
    const check = (key: keyof typeof s.data, label: string) => (
        <label className="inline-flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" className="rounded border-gray-300 text-teal-600 focus:ring-teal-500" checked={s.data[key] as boolean} onChange={(e) => s.set(key, e.target.checked as never)} /> {label}
        </label>
    );
    return (
        <SectionShell title="所得税" icon="fa-solid fa-file-invoice-dollar" canWrite={canWrite} editing={s.editing}
            onEdit={() => s.setEditing(true)} onCancel={s.cancel} onSave={() => s.save()} processing={s.processing}>
            {s.editing ? (
                <div className="space-y-4">
                    <div className={gridClass}>
                        <div><label className={fieldLabel}>源泉徴収税額表</label>
                            <select className={inputClass} value={s.data.tax_table} onChange={(e) => s.set('tax_table', e.target.value)}>
                                {Object.entries(options.taxTables).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                            </select>
                        </div>
                        <div><label className={fieldLabel}>扶養親族等の数</label><input type="number" min="0" className={inputClass} value={s.data.dependents_count} onChange={(e) => s.set('dependents_count', Number(e.target.value))} /></div>
                        <div><label className={fieldLabel}>障害者区分</label>
                            <select className={inputClass} value={s.data.disability_type} onChange={(e) => s.set('disability_type', e.target.value)}>
                                {Object.entries(options.disabilityTypes).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                            </select>
                        </div>
                        <div><label className={fieldLabel}>居住区分</label>
                            <select className={inputClass} value={s.data.residency_type} onChange={(e) => s.set('residency_type', e.target.value)}>
                                {Object.entries(options.residencyTypes).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                            </select>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-x-6 gap-y-2">
                        {check('is_widow', '寡婦')}
                        {check('is_single_parent', 'ひとり親')}
                        {check('is_working_student', '勤労学生')}
                        {check('is_minor', '未成年者')}
                        {check('is_disaster', '災害者')}
                        {check('is_foreigner', '外国人')}
                    </div>
                </div>
            ) : (
                <dl className="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                    <Row label="源泉徴収税額表" value={options.taxTables[payroll.tax_table]} />
                    <Row label="扶養親族等の数" value={`${payroll.dependents_count} 人`} />
                    <Row label="障害者区分" value={options.disabilityTypes[payroll.disability_type]} />
                    <Row label="居住区分" value={options.residencyTypes[payroll.residency_type]} />
                    <Row label="寡婦 / ひとり親" value={[payroll.is_widow && '寡婦', payroll.is_single_parent && 'ひとり親'].filter(Boolean).join(' / ') || 'なし'} />
                    <Row label="その他区分" value={[payroll.is_working_student && '勤労学生', payroll.is_minor && '未成年', payroll.is_disaster && '災害者', payroll.is_foreigner && '外国人'].filter(Boolean).join(' / ') || 'なし'} />
                </dl>
            )}
        </SectionShell>
    );
}

/* ============================ 扶養情報 ============================ */
function emptyDependent(): EmployeeDependent {
    return {
        last_name: '', first_name: '', last_name_kana: '', first_name_kana: '', birth_date: '',
        relationship: '', my_number: '', lives_together: true, is_income_tax_dependent: false,
        dependent_type: 'general', is_same_livelihood_spouse: false, disability_type: 'none',
        is_health_insurance_dependent: false, annual_income: null,
    };
}
function DependentSection({ user, dependents, canWrite, options }: { user: User; dependents: EmployeeDependent[]; canWrite: boolean; options: Options }) {
    const [editing, setEditing] = useState(false);
    const [rows, setRows] = useState<EmployeeDependent[]>(dependents);
    const [processing, setProcessing] = useState(false);
    const patch = (i: number, p: Partial<EmployeeDependent>) => setRows((r) => r.map((x, idx) => (idx === i ? { ...x, ...p } : x)));
    const add = () => setRows((r) => [...r, emptyDependent()]);
    const remove = (i: number) => setRows((r) => r.filter((_, idx) => idx !== i));
    const cancel = () => { setRows(dependents); setEditing(false); };
    const save = () => {
        setProcessing(true);
        router.put(route('admin.users.section', { user: user.id, section: 'dependents' }), { dependents: rows } as never, {
            preserveScroll: true, onSuccess: () => setEditing(false), onFinish: () => setProcessing(false),
        });
    };
    return (
        <SectionShell title="扶養情報" icon="fa-solid fa-people-roof" canWrite={canWrite} editing={editing}
            onEdit={() => setEditing(true)} onCancel={cancel} onSave={save} processing={processing}>
            {editing ? (
                <div className="space-y-3">
                    {rows.map((d, i) => (
                        <div key={i} className="space-y-3 rounded-xl border border-gray-100 p-3">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                                <div><label className={fieldLabel}>姓</label><input className={inputClass} value={d.last_name ?? ''} onChange={(e) => patch(i, { last_name: e.target.value })} /></div>
                                <div><label className={fieldLabel}>名</label><input className={inputClass} value={d.first_name ?? ''} onChange={(e) => patch(i, { first_name: e.target.value })} /></div>
                                <div><label className={fieldLabel}>続柄</label><input className={inputClass} value={d.relationship ?? ''} onChange={(e) => patch(i, { relationship: e.target.value })} placeholder="配偶者・子 等" /></div>
                                <div><label className={fieldLabel}>生年月日</label><input type="date" className={inputClass} value={d.birth_date ?? ''} onChange={(e) => patch(i, { birth_date: e.target.value })} /></div>
                                <div><label className={fieldLabel}>扶養区分</label>
                                    <select className={inputClass} value={d.dependent_type} onChange={(e) => patch(i, { dependent_type: e.target.value })}>
                                        {Object.entries(options.dependentTypes).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                    </select>
                                </div>
                                <div><label className={fieldLabel}>障害者区分</label>
                                    <select className={inputClass} value={d.disability_type} onChange={(e) => patch(i, { disability_type: e.target.value })}>
                                        {Object.entries(options.disabilityTypes).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                    </select>
                                </div>
                                <div><label className={fieldLabel}>合計所得（円）</label><input type="number" min="0" className={inputClass} value={d.annual_income ?? ''} onChange={(e) => patch(i, { annual_income: e.target.value === '' ? null : Number(e.target.value) })} /></div>
                                <div><label className={fieldLabel}>マイナンバー</label><input className={inputClass} value={d.my_number ?? ''} onChange={(e) => patch(i, { my_number: e.target.value })} /></div>
                            </div>
                            <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                                <label className="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" className="rounded border-gray-300 text-teal-600 focus:ring-teal-500" checked={d.lives_together} onChange={(e) => patch(i, { lives_together: e.target.checked })} /> 同居</label>
                                <label className="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" className="rounded border-gray-300 text-teal-600 focus:ring-teal-500" checked={d.is_income_tax_dependent} onChange={(e) => patch(i, { is_income_tax_dependent: e.target.checked })} /> 源泉控除対象</label>
                                <label className="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" className="rounded border-gray-300 text-teal-600 focus:ring-teal-500" checked={d.is_same_livelihood_spouse} onChange={(e) => patch(i, { is_same_livelihood_spouse: e.target.checked })} /> 同一生計配偶者</label>
                                <label className="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" className="rounded border-gray-300 text-teal-600 focus:ring-teal-500" checked={d.is_health_insurance_dependent} onChange={(e) => patch(i, { is_health_insurance_dependent: e.target.checked })} /> 健保扶養</label>
                                <button onClick={() => remove(i)} className="ml-auto rounded-lg px-2.5 py-1.5 text-red-500 transition hover:bg-red-50"><i className="fa-solid fa-trash-can" /> 削除</button>
                            </div>
                        </div>
                    ))}
                    <button onClick={add} className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-gray-300 px-4 py-2 text-sm text-gray-600 transition hover:bg-gray-50"><i className="fa-solid fa-plus" /> 追加</button>
                </div>
            ) : (
                dependents.length === 0 ? <p className="text-sm text-gray-400">扶養家族は登録されていません。</p> : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead><tr className="text-left text-xs text-gray-500">
                                <th className="py-2 pr-4">氏名</th><th className="py-2 pr-4">続柄</th><th className="py-2 pr-4">生年月日</th><th className="py-2 pr-4">扶養区分</th><th className="py-2 pr-4">同居</th><th className="py-2">源泉控除</th>
                            </tr></thead>
                            <tbody className="divide-y divide-gray-50">
                                {dependents.map((d, i) => (
                                    <tr key={d.id ?? i}>
                                        <td className="py-2 pr-4 text-gray-800">{[d.last_name, d.first_name].filter(Boolean).join(' ') || '—'}</td>
                                        <td className="py-2 pr-4 text-gray-600">{d.relationship || '—'}</td>
                                        <td className="py-2 pr-4 text-gray-600">{fmtDate(d.birth_date)}</td>
                                        <td className="py-2 pr-4 text-gray-600">{options.dependentTypes[d.dependent_type] ?? d.dependent_type}</td>
                                        <td className="py-2 pr-4 text-gray-600">{d.lives_together ? '○' : '×'}</td>
                                        <td className="py-2 text-gray-600">{d.is_income_tax_dependent ? '○' : '×'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )
            )}
        </SectionShell>
    );
}

/* ============================ 給与情報タブ ============================ */
function SalaryTab({
    user, payroll, payItems, payItemValues, commuteRoutes, attendanceItems, options, canWritePayroll,
}: {
    user: User; payroll: PayrollData; payItems: PayItemOption[]; payItemValues: Record<number, number>;
    commuteRoutes: CommuteRoute[]; attendanceItems: AttendanceItemOption[]; options: Options; canWritePayroll: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const [data, setData] = useState<PayrollData>(payroll);
    const [itemValues, setItemValues] = useState<Record<number, number | ''>>(() => {
        const init: Record<number, number | ''> = {};
        payItems.forEach((p) => { init[p.id] = payItemValues[p.id] ?? 0; });
        return init;
    });
    const [routes, setRoutes] = useState<CommuteRoute[]>(commuteRoutes);
    const [processing, setProcessing] = useState(false);

    const set = <K extends keyof PayrollData>(k: K, v: PayrollData[K]) => setData((d) => ({ ...d, [k]: v }));
    const setItem = (id: number, v: number | '') => setItemValues((m) => ({ ...m, [id]: v }));
    const patchRoute = (i: number, p: Partial<CommuteRoute>) => setRoutes((r) => r.map((x, idx) => (idx === i ? { ...x, ...p } : x)));
    const addRoute = () => setRoutes((r) => [...r, emptyRoute()]);
    const removeRoute = (i: number) => setRoutes((r) => r.filter((_, idx) => idx !== i));

    const cancel = () => {
        setData(payroll);
        const init: Record<number, number | ''> = {};
        payItems.forEach((p) => { init[p.id] = payItemValues[p.id] ?? 0; });
        setItemValues(init);
        setRoutes(commuteRoutes);
        setEditing(false);
    };

    const save = () => {
        setProcessing(true);
        // 通勤手当（定額分）を従来列へ反映し、payroll の必須項目を満たす
        const cc = commuteColumns(routes);
        const baseItem = payItems.find((p) => p.code === 'base_salary');
        const payload: PayrollData = {
            ...data,
            base_salary: baseItem ? Number(itemValues[baseItem.id] || 0) : data.base_salary,
            commute_allowance_taxable: cc.taxable,
            commute_allowance_non_taxable: cc.nonTaxable,
        };
        router.put(route('admin.payroll.employees.update', user.id), payload as never, {
            preserveScroll: true,
            onSuccess: () => {
                // 従業員別の支給項目金額（employee 計算のみ）
                const items = payItems
                    .filter((p) => p.calc_method === 'employee')
                    .map((p) => ({ pay_item_master_id: p.id, amount: Number(itemValues[p.id] || 0) }));
                router.put(route('admin.users.section', { user: user.id, section: 'salary_items' }), { items } as never, {
                    preserveScroll: true,
                    onSuccess: () => {
                        router.put(route('admin.users.section', { user: user.id, section: 'commute' }), { routes } as never, {
                            preserveScroll: true,
                            onSuccess: () => setEditing(false),
                            onFinish: () => setProcessing(false),
                        });
                    },
                    onError: () => setProcessing(false),
                });
            },
            onError: () => setProcessing(false),
        });
    };

    const num = (k: keyof PayrollData, label: string, suffix = '円') => (
        <div>
            <label className={fieldLabel}>{label}</label>
            <div className="relative">
                <input type="number" min="0" disabled={!editing} className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={(data[k] as number | null) ?? ''} onChange={(e) => set(k, (e.target.value === '' ? null : Number(e.target.value)) as never)} />
                <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">{suffix}</span>
            </div>
        </div>
    );
    const txt = (k: keyof PayrollData, label: string, ph?: string) => (
        <div>
            <label className={fieldLabel}>{label}</label>
            <input disabled={!editing} placeholder={ph} className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={(data[k] as string | null) ?? ''} onChange={(e) => set(k, (e.target.value === '' ? null : e.target.value) as never)} />
        </div>
    );

    const payTypeLabel = options.payTypes[payroll.pay_type] ?? payroll.pay_type;

    return (
        <div className="space-y-4">
            {!canWritePayroll && (
                <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                    <i className="fa-solid fa-eye mr-1.5" /> 給与情報の閲覧のみ可能です（給与権限が必要）。
                </div>
            )}
            <div className="flex justify-end">
                {canWritePayroll && (editing ? (
                    <div className="flex items-center gap-2">
                        <button onClick={cancel} className="rounded-lg px-4 py-2 text-sm text-gray-500 transition hover:bg-gray-100">キャンセル</button>
                        <button onClick={save} disabled={processing} className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50"><i className="fa-solid fa-floppy-disk" /> 保存する</button>
                    </div>
                ) : (
                    <button onClick={() => setEditing(true)} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50"><i className="fa-solid fa-pen" /> 編集</button>
                ))}
            </div>

            {/* 支給項目（給与区分に応じて出し分け） */}
            <div className="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3.5">
                    <h3 className="flex items-center gap-2 text-sm font-bold text-gray-800"><i className="fa-solid fa-yen-sign text-teal-600" /> 支給項目</h3>
                    <span className="rounded-full bg-teal-50 px-2.5 py-0.5 text-xs font-medium text-teal-700">{payTypeLabel}</span>
                </div>
                <div className="p-5">
                    {(() => {
                        const rateRows = RATE_ROWS[payroll.pay_type] ?? [];
                        // 従業員ごとに金額を入力する支給項目のみ表示（通勤手当は専用セクションで扱う）。
                        // カスタム計算式（基本給など）や割増基礎等は給与計算画面で算出されるためここには出さない。
                        const employeeItems = payItems.filter((p) => p.calc_method === 'employee' && p.category !== 'commute');
                        if (rateRows.length === 0 && employeeItems.length === 0) {
                            return <p className="text-sm text-gray-400">この給与区分で従業員ごとに入力する支給項目はありません。</p>;
                        }
                        return (
                            <table className="min-w-full divide-y divide-gray-100 text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-gray-500">
                                        <th className="py-2 pr-4">項目</th>
                                        <th className="w-56 py-2 pr-4">金額</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50">
                                    {rateRows.map((r) => (
                                        <tr key={r.col}>
                                            <td className="py-2.5 pr-4">
                                                <span className="font-medium text-gray-800">{r.label}</span>
                                                <span className="ml-1.5 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500">単価</span>
                                            </td>
                                            <td className="py-2.5 pr-4">
                                                <div className="relative w-44">
                                                    <input type="number" min="0" disabled={!editing}
                                                        className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`}
                                                        value={(data[r.col] as number | null) ?? ''}
                                                        onChange={(e) => set(r.col, (e.target.value === '' ? 0 : Number(e.target.value)) as never)} />
                                                    <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">円</span>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {employeeItems.map((p) => (
                                        <tr key={p.id}>
                                            <td className="py-2.5 pr-4">
                                                <span className="font-medium text-gray-800">{p.name}</span>
                                                {p.sign === 'minus' && <span className="ml-1.5 rounded bg-red-50 px-1.5 py-0.5 text-[10px] text-red-500">控除</span>}
                                            </td>
                                            <td className="py-2.5 pr-4">
                                                <div className="relative w-44">
                                                    <input type="number" min="0" disabled={!editing}
                                                        className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`}
                                                        value={itemValues[p.id] ?? ''}
                                                        onChange={(e) => setItem(p.id, e.target.value === '' ? '' : Number(e.target.value))} />
                                                    <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">円</span>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        );
                    })()}
                </div>
            </div>

            {/* 通勤手当（複数ルート） */}
            <div className="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div className="border-b border-gray-100 px-5 py-3.5"><h3 className="flex items-center gap-2 text-sm font-bold text-gray-800"><i className="fa-solid fa-train-subway text-teal-600" /> 通勤手当</h3></div>
                <div className="space-y-3 p-5">
                    {routes.length === 0 && !editing && <p className="text-sm text-gray-400">通勤手当は登録されていません。</p>}
                    {routes.map((r, i) => {
                        const labels = placeLabels(r.transport_type);
                        const hasDistance = DISTANCE_TRANSPORTS.includes(r.transport_type);
                        const hasParking = PARKING_TRANSPORTS.includes(r.transport_type);
                        return (
                        <div key={r.id ?? `new-${i}`} className="space-y-3 rounded-xl border border-gray-100 p-4">
                            {/* 通勤経路 */}
                            <div className="rounded-md bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-600">通勤経路</div>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div><label className={fieldLabel}>通勤手段</label>
                                    <select disabled={!editing} className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={r.transport_type} onChange={(e) => patchRoute(i, { transport_type: e.target.value })}>
                                        {Object.entries(options.transportTypes).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                    </select>
                                </div>
                                <div><label className={fieldLabel}>{labels.from}</label><input disabled={!editing} className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={r.from_place ?? ''} onChange={(e) => patchRoute(i, { from_place: e.target.value })} /></div>
                                <div><label className={fieldLabel}>{labels.to}</label><input disabled={!editing} className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={r.to_place ?? ''} onChange={(e) => patchRoute(i, { to_place: e.target.value })} /></div>
                                {hasDistance && (
                                    <div><label className={fieldLabel}>片道の通勤距離</label>
                                        <div className="relative">
                                            <input type="number" min="0" step="0.1" disabled={!editing} className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={r.one_way_distance_km ?? 0} onChange={(e) => patchRoute(i, { one_way_distance_km: e.target.value === '' ? 0 : Number(e.target.value) })} />
                                            <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">km</span>
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* 通勤手当支給条件 */}
                            <div className="rounded-md bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-600">通勤手当支給条件</div>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div><label className={fieldLabel}>支給条件</label>
                                    <select disabled={!editing} className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={r.condition} onChange={(e) => patchRoute(i, { condition: e.target.value })}>
                                        {Object.entries(options.commuteConditions).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                    </select>
                                </div>
                                <div><label className={fieldLabel}>{r.condition === 'by_workdays' ? '日額' : '支給額（月額）'}</label>
                                    <div className="relative">
                                        <input type="number" min="0" disabled={!editing} className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={r.amount ?? 0} onChange={(e) => patchRoute(i, { amount: e.target.value === '' ? 0 : Number(e.target.value) })} />
                                        <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">円</span>
                                    </div>
                                </div>
                                <div><label className={fieldLabel}>支払手段</label>
                                    <select disabled={!editing} className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={r.payment_method} onChange={(e) => patchRoute(i, { payment_method: e.target.value })}>
                                        {Object.entries(options.commutePaymentMethods).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                    </select>
                                </div>
                                {r.condition === 'by_workdays' && (
                                    <div><label className={fieldLabel}>使用する勤怠項目</label>
                                        <select disabled={!editing} className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={r.attendance_item_code ?? ''} onChange={(e) => patchRoute(i, { attendance_item_code: e.target.value || null })}>
                                            <option value="">出勤日数（既定）</option>
                                            {attendanceItems.map((a) => <option key={a.code} value={a.code}>{a.name}</option>)}
                                        </select>
                                    </div>
                                )}
                                <div><label className={fieldLabel}>上限支給額</label>
                                    <div className="relative">
                                        <input type="number" min="0" disabled={!editing} placeholder="上限なし" className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={r.cap_amount ?? ''} onChange={(e) => patchRoute(i, { cap_amount: e.target.value === '' ? null : Number(e.target.value) })} />
                                        <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">円</span>
                                    </div>
                                </div>
                                <div><label className={fieldLabel}>非課税限度額</label>
                                    <div className="relative">
                                        <input type="number" min="0" disabled={!editing} placeholder="全額非課税" className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={r.non_taxable_limit ?? ''} onChange={(e) => patchRoute(i, { non_taxable_limit: e.target.value === '' ? null : Number(e.target.value) })} />
                                        <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">円</span>
                                    </div>
                                </div>
                            </div>
                            {r.condition === 'fixed' && (
                                <div>
                                    <label className={fieldLabel}>支給月（未選択=毎月）</label>
                                    <div className="flex flex-wrap gap-1.5">
                                        {Array.from({ length: 12 }, (_, m) => m + 1).map((m) => {
                                            const on = r.payment_months.includes(m);
                                            return (
                                                <button key={m} type="button" disabled={!editing}
                                                    onClick={() => patchRoute(i, { payment_months: on ? r.payment_months.filter((x) => x !== m) : [...r.payment_months, m].sort((a, b) => a - b) })}
                                                    className={`h-7 w-9 rounded-md border text-xs transition disabled:opacity-60 ${on ? 'border-teal-500 bg-teal-50 text-teal-700' : 'border-gray-200 text-gray-500 hover:bg-gray-50'}`}>
                                                    {m}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}

                            {/* 通勤用の駐車場等（自動車・バイク・自転車のみ） */}
                            {hasParking && (
                                <>
                                    <div className="rounded-md bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-600">通勤用の駐車場等</div>
                                    <label className="inline-flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" disabled={!editing} className="rounded border-gray-300 text-teal-600 focus:ring-teal-500" checked={r.uses_parking} onChange={(e) => patchRoute(i, { uses_parking: e.target.checked })} />
                                        利用している
                                    </label>
                                    {r.uses_parking && (
                                        <div className="space-y-3 rounded-xl border border-gray-100 bg-gray-50/40 p-3">
                                            <div className="text-xs font-medium text-gray-500">駐車場等料金支給条件</div>
                                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                                <div><label className={fieldLabel}>支給条件</label>
                                                    <select disabled={!editing} className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={r.parking_condition} onChange={(e) => patchRoute(i, { parking_condition: e.target.value })}>
                                                        {Object.entries(options.commuteConditions).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                                    </select>
                                                </div>
                                                <div><label className={fieldLabel}>{r.parking_condition === 'by_workdays' ? '日額' : '支給額（月額）'}</label>
                                                    <div className="relative">
                                                        <input type="number" min="0" disabled={!editing} className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={r.parking_amount ?? 0} onChange={(e) => patchRoute(i, { parking_amount: e.target.value === '' ? 0 : Number(e.target.value) })} />
                                                        <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">円</span>
                                                    </div>
                                                </div>
                                                <div><label className={fieldLabel}>支払手段</label>
                                                    <select disabled={!editing} className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={r.parking_payment_method} onChange={(e) => patchRoute(i, { parking_payment_method: e.target.value })}>
                                                        {Object.entries(options.commutePaymentMethods).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                                    </select>
                                                </div>
                                                {r.parking_condition === 'by_workdays' && (
                                                    <div><label className={fieldLabel}>使用する勤怠項目</label>
                                                        <select disabled={!editing} className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={r.parking_attendance_item_code ?? ''} onChange={(e) => patchRoute(i, { parking_attendance_item_code: e.target.value || null })}>
                                                            <option value="">出勤日数（既定）</option>
                                                            {attendanceItems.map((a) => <option key={a.code} value={a.code}>{a.name}</option>)}
                                                        </select>
                                                    </div>
                                                )}
                                                <div><label className={fieldLabel}>上限支給額</label>
                                                    <div className="relative">
                                                        <input type="number" min="0" disabled={!editing} placeholder="上限なし" className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={r.parking_cap_amount ?? ''} onChange={(e) => patchRoute(i, { parking_cap_amount: e.target.value === '' ? null : Number(e.target.value) })} />
                                                        <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">円</span>
                                                    </div>
                                                </div>
                                            </div>
                                            {r.parking_condition === 'fixed' && (
                                                <div>
                                                    <label className={fieldLabel}>支給月（未選択=毎月）</label>
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {Array.from({ length: 12 }, (_, m) => m + 1).map((m) => {
                                                            const on = r.parking_payment_months.includes(m);
                                                            return (
                                                                <button key={m} type="button" disabled={!editing}
                                                                    onClick={() => patchRoute(i, { parking_payment_months: on ? r.parking_payment_months.filter((x) => x !== m) : [...r.parking_payment_months, m].sort((a, b) => a - b) })}
                                                                    className={`h-7 w-9 rounded-md border text-xs transition disabled:opacity-60 ${on ? 'border-teal-500 bg-teal-50 text-teal-700' : 'border-gray-200 text-gray-500 hover:bg-gray-50'}`}>
                                                                    {m}
                                                                </button>
                                                            );
                                                        })}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </>
                            )}
                            {editing && (
                                <div className="flex justify-end">
                                    <button onClick={() => removeRoute(i)} className="rounded-lg px-2.5 py-1.5 text-red-500 transition hover:bg-red-50"><i className="fa-solid fa-trash-can" /> 削除</button>
                                </div>
                            )}
                        </div>
                        );
                    })}
                    {editing && (
                        <button onClick={addRoute} className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-gray-300 px-4 py-2 text-sm text-gray-600 transition hover:bg-gray-50"><i className="fa-solid fa-plus" /> 通勤ルートを追加</button>
                    )}
                </div>
            </div>

            {/* 社会保険 */}
            <div className="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div className="border-b border-gray-100 px-5 py-3.5"><h3 className="flex items-center gap-2 text-sm font-bold text-gray-800"><i className="fa-solid fa-shield-heart text-teal-600" /> 健康保険・厚生年金 / 労災・雇用保険</h3></div>
                <div className="space-y-4 p-5">
                    <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                        <label className="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" disabled={!editing} className="rounded border-gray-300 text-teal-600 focus:ring-teal-500" checked={data.is_social_insurance_enrolled} onChange={(e) => set('is_social_insurance_enrolled', e.target.checked)} /> 社会保険（健康・厚生年金）加入</label>
                        <label className="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" disabled={!editing} className="rounded border-gray-300 text-teal-600 focus:ring-teal-500" checked={data.is_employment_insurance_enrolled} onChange={(e) => set('is_employment_insurance_enrolled', e.target.checked)} /> 雇用保険加入</label>
                        <label className="inline-flex items-center gap-2 text-sm text-gray-700">
                            介護保険該当
                            <select
                                disabled={!editing}
                                className="rounded-lg border-gray-300 py-1 text-sm focus:border-teal-500 focus:ring-teal-500 disabled:bg-gray-50"
                                value={data.care_insurance_override === null || data.care_insurance_override === undefined ? 'auto' : (data.care_insurance_override ? 'on' : 'off')}
                                onChange={(e) => set('care_insurance_override', e.target.value === 'auto' ? null : e.target.value === 'on')}
                            >
                                <option value="auto">自動判定（生年月日／40〜64歳）</option>
                                <option value="on">対象にする</option>
                                <option value="off">対象外にする</option>
                            </select>
                        </label>
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {num('standard_reward_health', '標準報酬月額(健康保険)')}
                        {num('standard_reward_pension', '標準報酬月額(厚生年金)')}
                    </div>
                </div>
            </div>

            {/* 住民税納付額 */}
            <div className="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div className="border-b border-gray-100 px-5 py-3.5"><h3 className="flex items-center gap-2 text-sm font-bold text-gray-800"><i className="fa-solid fa-file-invoice text-teal-600" /> 住民税納付額</h3></div>
                <div className="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">
                    {num('resident_tax_monthly', '住民税(毎月)')}
                    {num('resident_tax_june', '住民税(6月)')}
                </div>
            </div>

            {/* 支払情報（振込先口座） */}
            <div className="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div className="border-b border-gray-100 px-5 py-3.5"><h3 className="flex items-center gap-2 text-sm font-bold text-gray-800"><i className="fa-solid fa-building-columns text-teal-600" /> 支払情報（振込先口座）</h3></div>
                <div className="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3">
                    {txt('bank_name', '金融機関名', '例）みずほ銀行')}
                    {txt('bank_code', '金融機関コード', '4桁')}
                    {txt('branch_name', '支店名', '例）新宿支店')}
                    {txt('branch_code', '支店コード', '3桁')}
                    <div><label className={fieldLabel}>預金種目</label>
                        <select disabled={!editing} className={`${inputClass} disabled:bg-gray-50 disabled:text-gray-500`} value={data.account_type} onChange={(e) => set('account_type', e.target.value)}>
                            {Object.entries(options.accountTypes).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                    </div>
                    {txt('account_number', '口座番号', '7桁')}
                    {txt('account_holder_kana', '口座名義人（半角カナ）', '例）ﾔﾏﾀﾞ ﾀﾛｳ')}
                </div>
            </div>
        </div>
    );
}

/* ============================ 従業員メモタブ ============================ */
function NoteTab({ user, canWrite }: { user: User; canWrite: boolean }) {
    const [editing, setEditing] = useState(false);
    const [note, setNote] = useState(user.employee_note ?? '');
    const [processing, setProcessing] = useState(false);
    const save = () => {
        setProcessing(true);
        router.put(route('admin.users.section', { user: user.id, section: 'note' }), { employee_note: note } as never, {
            preserveScroll: true, onSuccess: () => setEditing(false), onFinish: () => setProcessing(false),
        });
    };
    return (
        <SectionShell title="従業員メモ" icon="fa-solid fa-note-sticky" canWrite={canWrite} editing={editing}
            onEdit={() => setEditing(true)} onCancel={() => { setNote(user.employee_note ?? ''); setEditing(false); }} onSave={save} processing={processing}>
            {editing ? (
                <textarea className={inputClass} rows={8} value={note} onChange={(e) => setNote(e.target.value)} placeholder="社内向けメモ（従業員には表示されません）" />
            ) : (
                user.employee_note ? <p className="whitespace-pre-wrap text-sm text-gray-700">{user.employee_note}</p> : <p className="text-sm text-gray-400">メモはありません。</p>
            )}
        </SectionShell>
    );
}

/* ============================ ステータス履歴 ============================ */
function HistoryCard({ histories }: { histories: UserStatusHistory[] }) {
    if (histories.length === 0) return null;
    return (
        <div className="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
            <div className="border-b border-gray-100 px-5 py-3.5"><h3 className="flex items-center gap-2 text-sm font-bold text-gray-800"><i className="fa-solid fa-clock-rotate-left text-purple-600" /> ステータス変更履歴</h3></div>
            <div className="divide-y divide-gray-50">
                {histories.map((h) => (
                    <div key={h.id} className="flex flex-wrap items-center gap-2 px-5 py-2.5 text-sm">
                        <span className="text-xs text-gray-400">{h.changed_at}</span>
                        <span className="text-gray-600">{h.from_label}</span>
                        <i className="fa-solid fa-arrow-right text-[10px] text-gray-400" />
                        <span className="text-gray-800">{h.to_label}</span>
                        {h.changed_by && <span className="text-xs text-gray-300">by {h.changed_by}</span>}
                        {h.note && <span className="text-xs text-gray-400">{h.note}</span>}
                    </div>
                ))}
            </div>
        </div>
    );
}

type TabKey = 'general' | 'salary' | 'note';

export default function UserShow({ user, payroll, dependents, leaves, histories, payItems, payItemValues, commuteRoutes, attendanceItems, options }: Props) {
    const canWrite = useAdminPermission('users');
    const canWritePayroll = useAdminPermission('payroll');
    const status = (user.employment_status ?? (user.is_active ? 'active' : 'retired')) as EmploymentStatus;
    const statusCfg = STATUS_CONFIG[status];

    const initialTab = (() => {
        const hash = typeof window !== 'undefined' ? window.location.hash.replace('#', '') : '';
        return (['general', 'salary', 'note'].includes(hash) ? hash : 'general') as TabKey;
    })();
    const [tab, setTab] = useState<TabKey>(initialTab);
    const [menuOpen, setMenuOpen] = useState(false);

    const changeTab = (t: TabKey) => {
        setTab(t);
        if (typeof window !== 'undefined') window.history.replaceState(null, '', `#${t}`);
    };

    const handleDelete = () => {
        if (confirm(`${user.full_name} を削除しますか？この操作は取り消せません。`)) {
            router.delete(route('admin.users.destroy', user.id));
        }
    };

    const TABS: { key: TabKey; label: string }[] = [
        { key: 'general', label: '一般情報' },
        { key: 'salary', label: '給与情報' },
        { key: 'note', label: '従業員メモ' },
    ];

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">従業員情報</h2>}>
            <Head title={`従業員情報 - ${user.full_name}`} />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-5xl space-y-5">
                    {/* ヘッダー */}
                    <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-center gap-4">
                                <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-teal-100">
                                    <i className="fa-solid fa-user text-2xl text-teal-600" />
                                </div>
                                <div>
                                    <div className="flex items-center gap-2">
                                        <p className="text-xl font-bold text-gray-800">{user.full_name}</p>
                                        <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${statusCfg.badge}`}>{statusCfg.label}</span>
                                    </div>
                                    <p className="mt-0.5 text-sm text-gray-500">
                                        {payroll.employee_no ? `従業員番号: ${payroll.employee_no}` : '従業員番号未設定'}
                                        {[user.last_name_kana, user.first_name_kana].filter(Boolean).length > 0 && <span className="ml-2 text-gray-400">{[user.last_name_kana, user.first_name_kana].filter(Boolean).join(' ')}</span>}
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <Link href={route('admin.users.attendances', user.id)} className="inline-flex items-center gap-1.5 rounded-lg bg-gray-50 px-3 py-2 text-xs font-medium text-gray-600 transition hover:bg-gray-100">
                                    <i className="fa-solid fa-clock" /> 打刻一覧
                                </Link>
                                {canWrite && (
                                    <div className="relative">
                                        <button onClick={() => setMenuOpen((v) => !v)} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-2 text-xs font-medium text-gray-600 transition hover:bg-gray-50">
                                            <i className="fa-solid fa-ellipsis-vertical" /> メニュー
                                        </button>
                                        {menuOpen && (
                                            <div className="absolute right-0 z-10 mt-1 w-52 rounded-xl border border-gray-100 bg-white py-1 shadow-lg">
                                                <button onClick={handleDelete} className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-red-600 transition hover:bg-red-50">
                                                    <i className="fa-solid fa-trash-can" /> この従業員を削除する
                                                </button>
                            </div>
                        )}
                    </div>
                                )}
                            </div>
                        </div>

                        {/* タブ */}
                        <div className="mt-5 border-b border-gray-200">
                            <nav className="-mb-px flex gap-1">
                                {TABS.map((t) => (
                                    <button key={t.key} onClick={() => changeTab(t.key)}
                                        className={`whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold transition ${tab === t.key ? 'border-teal-600 text-teal-700' : 'border-transparent text-gray-500 hover:text-gray-700'}`}>
                                        {t.label}
                                    </button>
                                ))}
                            </nav>
                            </div>
                    </div>

                    {tab === 'general' && (
                        <div className="space-y-4">
                            <BasicSection user={user} canWrite={canWrite} genders={options.genders} />
                            <EmploymentSection user={user} canWrite={canWrite} />
                            <WorkSection user={user} payroll={payroll} canWrite={canWrite} options={options} />
                            <LeaveSection user={user} leaves={leaves} canWrite={canWrite} leaveTypes={options.leaveTypes} />
                            <ResidentTaxSection user={user} payroll={payroll} canWrite={canWrite} />
                            <IncomeTaxSection user={user} payroll={payroll} canWrite={canWrite} options={options} />
                            <DependentSection user={user} dependents={dependents} canWrite={canWrite} options={options} />
                            <HistoryCard histories={histories} />
                        </div>
                    )}

                    {tab === 'salary' && <SalaryTab user={user} payroll={payroll} payItems={payItems} payItemValues={payItemValues} commuteRoutes={commuteRoutes} attendanceItems={attendanceItems} options={options} canWritePayroll={canWritePayroll} />}

                    {tab === 'note' && <NoteTab user={user} canWrite={canWrite} />}

                    <div>
                        <Link href={route('admin.users.index')} className="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
                            <i className="fa-solid fa-arrow-left text-xs" /> 一覧に戻る
                        </Link>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
