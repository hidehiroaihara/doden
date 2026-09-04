import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import {
    DndContext,
    KeyboardSensor,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
    type DragEndEvent,
} from '@dnd-kit/core';
import { restrictToParentElement, restrictToVerticalAxis } from '@dnd-kit/modifiers';
import {
    SortableContext,
    arrayMove,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

type LabelMap = Record<string, string>;

type FormulaToken =
    | { t: 'ref'; kind: 'basis' | 'pay' | 'attendance'; code: string; label: string }
    | { t: 'num'; value: number }
    | { t: 'op'; value: '+' | '-' | '*' | '/' }
    | { t: 'cmp'; value: '<=' | '>=' | '!=' | '<' | '>' | '=' }
    | { t: 'fn'; value: 'ROUND' | 'ROUNDUP' | 'ROUNDDOWN' | 'IF' }
    | { t: 'paren'; value: '(' | ')' }
    | { t: 'comma' };

interface PayItem {
    id: number;
    code: string;
    name: string;
    pay_type: string;
    category: string;
    calc_method: string;
    divisor_unit: string | null;
    quantity_unit: string | null;
    sign: string;
    is_active: boolean;
    multiplier: string | number | null;
    rounding: string;
    is_income_tax_target: boolean;
    is_labor_insurance_target: boolean;
    is_social_insurance_target: boolean;
    is_fixed_wage: boolean;
    is_in_kind: boolean;
    is_allowance_base: boolean;
    is_deduction_base: boolean;
    is_daily_proration_base: boolean;
    show_zero: boolean;
    is_system: boolean;
    sort_order: number;
    custom_formula: FormulaToken[] | null;
}

interface DeductionItem {
    id: number;
    code: string;
    name: string;
    category: string;
    calc_method: string;
    calc_description: string | null;
    is_active: boolean;
    show_zero: boolean;
    is_system: boolean;
    sort_order: number;
}

interface AttendanceItem {
    id: number;
    code: string;
    name: string;
    category: string;
    unit_format: string;
    is_active: boolean;
    show_zero: boolean;
    is_system: boolean;
}

interface RateRow {
    id: number;
    kind: string;
    employee_rate: string;
    employer_rate: string;
}

interface RateSet {
    id: number;
    name: string;
    effective_from: string;
    effective_to: string | null;
    rates: RateRow[];
}

interface PensionFundRateRow {
    id: number;
    effective_from: string;
    salary_employee_rate: string;
    salary_employer_rate: string;
    bonus_employee_rate: string;
    bonus_employer_rate: string;
}

interface PensionFundRow {
    id: number;
    name: string;
    number: string | null;
    office_number: string | null;
    sort_order: number;
    rates: PensionFundRateRow[];
}

interface Location {
    id: number;
    name: string;
    code: string | null;
    is_main: boolean;
    health_insurance_type: string;
    prefecture: string | null;
    health_union_name: string | null;
    health_office_symbol: string | null;
    pension_jurisdiction: string | null;
    pension_office_number: string | null;
    pension_office_symbol: string | null;
    pension_fund_name: string | null;
    pension_fund_number: string | null;
    pension_fund_office_number: string | null;
    labor_insurance_number: string | null;
    labor_insurance_pref_code: string | null;
    labor_insurance_jurisdiction_code: string | null;
    labor_insurance_office_code: string | null;
    labor_insurance_serial_number: string | null;
    labor_insurance_branch_code: string | null;
    office_number: string | null;
    accident_industry_code: string | null;
    accident_merit_enabled: boolean;
    accident_merit_rate: string | null;
    employment_industry_type: string | null;
    labor_bureau: string | null;
    employment_bureau: string | null;
    accident_business_desc: string | null;
    employment_office_number: string | null;
    postal_code: string | null;
    address: string | null;
    note: string | null;
    sort_order: number;
    insurance_rate_sets: RateSet[];
    pension_funds: PensionFundRow[];
}

interface Municipality {
    id: number;
    name: string;
    designation_number: string | null;
}

interface ClosingGroup {
    id: number;
    name: string;
    closing_day: number;
    payment_day: number;
    payment_month_offset: number;
    sort_order: number;
}

interface JobTitleRow {
    id: number;
    name: string;
    sort_order: number;
}

interface LeaveTypeRow {
    id: number;
    code: string;
    name: string;
    leave_kind: string;
    pay_calc_method: string;
    is_active: boolean;
    sort_order: number;
}

interface FiscalYearDetail {
    id: number;
    year: number;
    name: string | null;
    work_hours_per_day_minutes: number | null;
    monthly_avg_work_days: string | null;
    monthly_avg_work_hours: string | null;
    holidays: { dow: number; type: string }[];
    custom_holidays: { id: number; date: string; label: string | null }[];
    holiday_list: { date: string; label: string | null; source: string }[];
    holidays_imported_at: string | null;
}

interface MonthlyDayTable {
    months: { month: number; work_days: number; holidays: number; calendar_days: number }[];
    total: { work_days: number; holidays: number; calendar_days: number };
}

interface PayMonthGroup {
    group: string;
    months: { month: number; closing_date: string; payment_date: string; publish_date: string; work_days: number | null; status: string }[];
}

interface FiscalYearData {
    years: number[];
    selected: number | null;
    fiscalYear: FiscalYearDetail | null;
    monthlyDayTable: MonthlyDayTable | null;
    payMonths: PayMonthGroup[];
}

interface AttendanceSettings {
    default_break_minutes: string | null;
    break_start_time: string | null;
    break_end_time: string | null;
    salary_round_minutes: string | null;
    salary_round_rule: string | null;
    punch_use_photo: boolean;
    punch_day_boundary_hour: string;
    work_start_time: string | null;
    work_end_time: string | null;
    work_hours_per_day: string | null;
    month_closing_day: string | null;
    legal_holiday_dows: string[];
    prescribed_holiday_dows: string[];
}

interface Props {
    payItems: PayItem[];
    deductionItems: DeductionItem[];
    attendanceItems: AttendanceItem[];
    locations: Location[];
    municipalities: Municipality[];
    general: Record<string, string | null>;
    closingDateGroups: ClosingGroup[];
    jobTitles: JobTitleRow[];
    leaveTypes: LeaveTypeRow[];
    departments: { id: number; name: string }[];
    attendanceSettings: AttendanceSettings;
    fiscalYear: FiscalYearData;
    payslipSettings: Record<string, string | null>;
    options: {
        payCategories: LabelMap;
        calcMethods: LabelMap;
        attendanceCategories: LabelMap;
        unitFormats: LabelMap;
        insuranceKinds: LabelMap;
        roundings: LabelMap;
        healthInsuranceTypes: LabelMap;
        prefectures: string[];
        accidentIndustries: LabelMap;
        employmentIndustries: LabelMap;
        holidayTypes: LabelMap;
        payslipDisplayMonths: LabelMap;
        payslipNotifyOptions: LabelMap;
        leaveKinds: LabelMap;
        leavePayCalcMethods: LabelMap;
        incomeTaxMethods: LabelMap;
        docSubmitters: LabelMap;
        sortKeys: LabelMap;
        sortDirections: LabelMap;
        accountTypes: LabelMap;
        newPayCalcMethods: LabelMap;
        newDeductionCalcMethods: LabelMap;
        payBasisMethodsByType: Record<string, LabelMap>;
        basisLabels: LabelMap;
    };
}

type TabKey = 'general' | 'work_settings' | 'fiscal_year' | 'pay' | 'deduction' | 'attendance' | 'locations' | 'insurance' | 'labor' | 'resident_tax' | 'payslip';

const TABS: { key: TabKey; label: string }[] = [
    { key: 'general', label: '全般' },
    { key: 'resident_tax', label: '住民税' },
    { key: 'locations', label: '事業所' },
    { key: 'pay', label: '支給項目' },
    { key: 'deduction', label: '控除項目' },
    { key: 'attendance', label: '勤怠項目' },
    { key: 'insurance', label: '社会保険' },
    { key: 'labor', label: '労働保険' },
    { key: 'fiscal_year', label: '年度' },
    { key: 'payslip', label: '明細' },
    { key: 'work_settings', label: '勤怠設定' },
];

const HEALTH_KINDS = ['health', 'nursing', 'child_support'];
const PENSION_KINDS = ['pension', 'child_contribution'];
const LABOR_KINDS = ['accident', 'employment'];

const NEEDS_MULTIPLIER = ['allowance_base', 'prev_allowance_base', 'deduction_base', 'prev_deduction_base'];
// 「計算の基礎 ÷ 単位 × 割増率 × 勤怠項目」の複合入力を有効化する計算方法
const BASE_METHODS = ['allowance_base', 'prev_allowance_base', 'deduction_base', 'prev_deduction_base', 'hourly1', 'hourly2', 'daily1', 'daily2'];

type PayType = 'monthly' | 'hourly' | 'daily' | 'bonus';

function readPayTypeFromUrl(): PayType {
    if (typeof window === 'undefined') return 'monthly';
    const pt = new URLSearchParams(window.location.search).get('pay_type');
    return PAY_TYPES.some((t) => t.key === pt) ? (pt as PayType) : 'monthly';
}

function syncPayTypeToUrl(payType: PayType) {
    if (typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    url.searchParams.set('pay_type', payType);
    window.history.replaceState(null, '', `${url.pathname}${url.search}${url.hash}`);
}
const PAY_TYPES: { key: PayType; label: string }[] = [
    { key: 'monthly', label: '月給' },
    { key: 'hourly', label: '時給' },
    { key: 'daily', label: '日給' },
    { key: 'bonus', label: '賞与' },
];

function useResyncedState<T>(source: T): [T, React.Dispatch<React.SetStateAction<T>>] {
    const [state, setState] = useState<T>(source);
    const key = useMemo(() => JSON.stringify(source), [source]);
    useEffect(() => {
        setState(source);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);
    return [state, setState];
}

const cardClass = 'rounded-2xl bg-white shadow-sm ring-1 ring-gray-100';
const thClass = 'px-3 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap';
const tdClass = 'px-3 py-2 text-sm text-gray-700 whitespace-nowrap';
const checkboxClass = 'h-4 w-4 rounded border-gray-300 text-teal-600 focus:ring-teal-500';
const numberInputClass = 'w-24 rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500';
const selectClass = 'rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500';

// 支給項目タブ専用: MF準拠のコンパクト＋可読性バランス
const payThClass = 'px-2.5 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap';
const payTdClass = 'px-2.5 py-1.5 text-xs text-gray-700 align-middle';
const payCheckboxClass = 'h-4 w-4 shrink-0 rounded border-gray-300 text-teal-600 focus:ring-teal-500';
const payInputClass = 'rounded border border-gray-300 bg-white px-2 py-1.5 text-xs leading-5 focus:border-teal-500 focus:ring-1 focus:ring-teal-500 disabled:border-transparent disabled:bg-transparent disabled:shadow-none';
const paySelectClass = 'shrink-0 rounded border border-gray-300 bg-white py-1.5 pl-2 pr-7 text-xs leading-5 focus:border-teal-500 focus:ring-1 focus:ring-teal-500 disabled:bg-gray-50 disabled:text-gray-400';
const paySelectBasisClass = `${paySelectClass} min-w-[9.5rem]`;
const paySelectUnitClass = `${paySelectClass} min-w-[8.5rem]`;
const paySelectQtyClass = `${paySelectClass} min-w-[11rem]`;
const payMultiplierClass = 'w-16 shrink-0 rounded border border-gray-300 bg-white px-2 py-1.5 text-center text-xs leading-5 focus:border-teal-500 focus:ring-1 focus:ring-teal-500 disabled:bg-gray-50 disabled:text-gray-400';
const payOpClass = 'shrink-0 px-1 text-xs font-medium text-gray-500 select-none';
const payFormulaWrapClass = 'inline-flex max-w-full flex-nowrap items-center gap-1 overflow-x-auto rounded-md border border-gray-100 bg-gray-50/70 px-2 py-1.5';
const payDetailBtnClass = 'inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded border border-amber-300/80 bg-amber-50 px-2.5 py-1.5 text-xs font-medium text-amber-900 hover:bg-amber-100';
const payFormulaBtnClass = 'inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded border border-teal-500 bg-white px-2.5 py-1.5 text-xs font-medium text-teal-700 hover:bg-teal-50';
const payDeleteBtnClass = 'shrink-0 rounded px-1.5 py-1 text-red-500 hover:bg-red-50';
const payGripClass = 'cursor-grab touch-none px-1 text-gray-300 hover:text-gray-500 active:cursor-grabbing';

/** MF準拠: 無効(is_active=false)行はグレースケールで視覚的に区別する */
const LATE_EARLY_ATTENDANCE_CODES = new Set([
    'late_minutes_weekday',
    'late_minutes_prescribed_holiday',
    'late_minutes_legal_holiday',
    'early_leave_minutes_weekday',
    'early_leave_minutes_prescribed_holiday',
    'early_leave_minutes_legal_holiday',
    'late_count',
    'late_count_prescribed_holiday',
    'late_count_legal_holiday',
    'early_leave_count',
    'early_leave_count_prescribed_holiday',
    'early_leave_count_legal_holiday',
]);
function masterRowClass(isActive: boolean): string {
    return isActive
        ? 'bg-white hover:bg-gray-50/60'
        : 'bg-gray-100/80 text-gray-400 [&_input:not([type=checkbox])]:border-gray-200 [&_input:not([type=checkbox])]:bg-gray-50 [&_input:not([type=checkbox])]:text-gray-400 [&_select]:border-gray-200 [&_select]:bg-gray-50 [&_select]:text-gray-400';
}

function masterStickyCellBg(isActive: boolean): string {
    return isActive ? 'bg-white' : 'bg-gray-100/80';
}

function masterNameClass(isActive: boolean): string {
    return isActive ? 'font-medium text-gray-800' : 'font-medium text-gray-400';
}

function masterDescClass(isActive: boolean): string {
    return isActive ? 'whitespace-normal text-[11px] text-gray-500' : 'whitespace-normal text-[11px] text-gray-400';
}

function masterDetailBtnClass(isActive: boolean): string {
    return isActive
        ? payDetailBtnClass
        : 'inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-xs font-medium text-gray-400 cursor-not-allowed';
}

function masterGripClass(isActive: boolean): string {
    return isActive ? payGripClass : 'touch-none px-1 text-gray-200 cursor-not-allowed';
}

function masterFormulaWrapClass(isActive: boolean): string {
    return isActive
        ? payFormulaWrapClass
        : 'inline-flex max-w-full flex-nowrap items-center gap-1 overflow-x-auto rounded-md border border-gray-200 bg-gray-100/80 px-2 py-1.5';
}

function masterOpClass(isActive: boolean): string {
    return isActive ? payOpClass : 'shrink-0 px-1 text-xs font-medium text-gray-300 select-none';
}

/** MF準拠: 無効行は有効チェックボックス以外は編集不可 */
function masterEditable(canWrite: boolean, isActive: boolean): boolean {
    return canWrite && isActive;
}

function SortableTr({
    id,
    className,
    children,
}: {
    id: number;
    className?: string;
    children: (handle: Pick<ReturnType<typeof useSortable>, 'attributes' | 'listeners'>) => React.ReactNode;
}) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id });
    const style: React.CSSProperties = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.6 : 1,
        position: 'relative',
        zIndex: isDragging ? 20 : undefined,
    };
    return (
        <tr ref={setNodeRef} style={style} className={className}>
            {children({ attributes, listeners })}
        </tr>
    );
}

function SaveButton({ onClick, processing, compact = false }: { onClick: () => void; processing: boolean; compact?: boolean }) {
    return (
        <div className={`flex justify-end border-t border-gray-100 ${compact ? 'px-3 py-2' : 'px-4 py-3'}`}>
            <button
                type="button"
                onClick={onClick}
                disabled={processing}
                className={`inline-flex items-center gap-1.5 rounded bg-teal-600 font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50 ${
                    compact ? 'px-4 py-1.5 text-xs' : 'gap-2 rounded-lg px-6 py-2.5 text-sm'
                }`}
            >
                <i className="fa-solid fa-floppy-disk" />
                保存する
            </button>
        </div>
    );
}

interface WorkSettingsData {
    default_break_minutes: string;
    break_start_time: string;
    break_end_time: string;
    salary_round_minutes: string;
    salary_round_rule: string;
    punch_use_photo: boolean;
    punch_day_boundary_hour: string;
    work_start_time: string;
    work_end_time: string;
    work_hours_per_day: string;
    month_closing_day: string;
    legal_holiday_dows: string[];
    prescribed_holiday_dows: string[];
}

const DOW_OPTIONS: { value: string; label: string }[] = [
    { value: 'sunday', label: '日' },
    { value: 'monday', label: '月' },
    { value: 'tuesday', label: '火' },
    { value: 'wednesday', label: '水' },
    { value: 'thursday', label: '木' },
    { value: 'friday', label: '金' },
    { value: 'saturday', label: '土' },
];

function WorkSettingsTab({ form, partial, onSave, canWrite }: {
    form: {
        data: WorkSettingsData;
        setData: (key: keyof WorkSettingsData, value: string | string[] | boolean) => void;
        errors: Partial<Record<keyof WorkSettingsData, string>>;
        processing: boolean;
    };
    partial: boolean;
    onSave: () => void;
    canWrite: boolean;
}) {
    const { data, setData, errors } = form;
    const roundOptions = [1, 5, 10, 15, 30, 60];
    const toggleDow = (key: 'legal_holiday_dows' | 'prescribed_holiday_dows', dow: string) => {
        const cur = data[key] ?? [];
        setData(key, cur.includes(dow) ? cur.filter((d) => d !== dow) : [...cur, dow]);
    };
    const input = 'rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 disabled:bg-gray-50';
    const cardSection = 'rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100';
    const head = (icon: string, color: string, title: string, desc: string) => (
        <div className="mb-4 flex items-center gap-3 border-b border-gray-100 pb-3">
            <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${color}`}><i className={`fa-solid ${icon}`} /></span>
            <div>
                <h3 className="text-sm font-bold text-gray-800">{title}</h3>
                <p className="text-xs text-gray-500">{desc}</p>
            </div>
        </div>
    );

    return (
        <div className="space-y-5">
            {!canWrite && (
                <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                    <i className="fa-solid fa-eye mr-1.5" />閲覧のみのアクセスです。「勤怠設定」の権限がある管理者のみ変更できます。
                </div>
            )}

            {/* 休憩時間 */}
            <div className={cardSection}>
                {head('fa-mug-hot', 'bg-teal-100 text-teal-600', '休憩時間設定', '会社全体のデフォルト休憩時間。従業員個別の設定があればそちらが優先されます。')}
                <div className="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500">デフォルト休憩時間</label>
                        <div className="flex items-center gap-2">
                            <input type="number" min={0} max={480} disabled={!canWrite} className={`w-28 ${input}`}
                                value={data.default_break_minutes} onChange={(e) => setData('default_break_minutes', e.target.value)} />
                            <span className="text-sm text-gray-500">分</span>
                        </div>
                        <p className="mt-1 text-xs text-gray-400">管理画面から打刻登録時に設定される休憩時間。0で休憩なし。</p>
                        {errors.default_break_minutes && <p className="mt-1 text-xs text-red-600">{errors.default_break_minutes}</p>}
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500">規定休憩時間帯</label>
                        <div className="flex flex-wrap items-center gap-2">
                            <input type="time" disabled={!canWrite} className={`w-32 ${input}`}
                                value={data.break_start_time} onChange={(e) => setData('break_start_time', e.target.value)} />
                            <span className="text-sm text-gray-500">〜</span>
                            <input type="time" disabled={!canWrite} className={`w-32 ${input}`}
                                value={data.break_end_time} onChange={(e) => setData('break_end_time', e.target.value)} />
                        </div>
                        <p className="mt-1 text-xs text-gray-400">CSV出力時、この時間帯より前に退勤した場合は休憩を控除しません。</p>
                    </div>
                </div>
            </div>

            {/* 丸め */}
            <div className={cardSection}>
                {head('fa-calculator', 'bg-blue-100 text-blue-600', '給料計算設定', '勤務時間の丸め単位・ルールを設定します。')}
                <div className="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500">計算丸め単位</label>
                        <select disabled={!canWrite} className={`w-32 ${input}`}
                            value={data.salary_round_minutes} onChange={(e) => setData('salary_round_minutes', e.target.value)}>
                            {roundOptions.map((m) => <option key={m} value={m}>{m}分</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500">丸めルール</label>
                        <select disabled={!canWrite} className={`w-48 ${input}`}
                            value={data.salary_round_rule} onChange={(e) => setData('salary_round_rule', e.target.value)}>
                            <option value="floor">切り捨て</option>
                            <option value="round">四捨五入</option>
                            <option value="ceil">切り上げ</option>
                        </select>
                        <p className="mt-1 text-xs text-gray-400">例: 8:15を30分単位 → 切り捨て8:00 / 四捨五入・切り上げ8:30</p>
                    </div>
                </div>
            </div>

            {/* 打刻時の顔写真 */}
            <div className={cardSection}>
                {head('fa-camera', 'bg-indigo-100 text-indigo-600', '打刻時の顔写真', '打刻画面でカメラ・顔認識を使用し、顔写真を保存するかどうかを切り替えます。')}
                <label className="flex cursor-pointer items-start gap-3">
                    <input
                        type="checkbox"
                        disabled={!canWrite}
                        className="mt-0.5 h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-60"
                        checked={data.punch_use_photo}
                        onChange={(e) => setData('punch_use_photo', e.target.checked)}
                    />
                    <span>
                        <span className="block text-sm font-medium text-gray-800">打刻時に顔写真を使用する</span>
                        <span className="mt-0.5 block text-xs text-gray-400">
                            OFFにするとカメラ・顔認識を使わず、顔写真なしで打刻できます（出勤・退勤・休憩すべて）。ONにすると従来どおり顔写真の撮影が必須になります。
                        </span>
                    </span>
                </label>
            </div>

            {/* 打刻営業日の切替時刻 */}
            <div className={cardSection}>
                {head('fa-clock', 'bg-sky-100 text-sky-600', '打刻営業日の切替', '日跨ぎ勤務と翌朝の新規出勤を両立するため、深夜帯の打刻をどの「営業日」に属させるかを設定します。')}
                <div>
                    <label className="mb-1 block text-xs font-medium text-gray-500">営業日切替時刻</label>
                    <div className="flex items-center gap-2">
                        <select
                            disabled={!canWrite}
                            className={`w-32 ${input}`}
                            value={data.punch_day_boundary_hour}
                            onChange={(e) => setData('punch_day_boundary_hour', e.target.value)}
                        >
                            {[0, 1, 2, 3, 4, 5, 6].map((h) => (
                                <option key={h} value={String(h)}>
                                    {h}時
                                </option>
                            ))}
                        </select>
                        <span className="text-sm text-gray-500">未満は前日の営業日</span>
                    </div>
                    <p className="mt-1 text-xs text-gray-400">
                        例: 5時に設定すると、4:59までの打刻は前日扱い、5:00から新しい営業日になります。未退勤の古いシフトは退勤忘れとして残りますが、新しい営業日になれば新規出勤は可能です。
                    </p>
                    {errors.punch_day_boundary_hour && (
                        <p className="mt-1 text-xs text-red-600">{errors.punch_day_boundary_hour}</p>
                    )}
                </div>
            </div>

            {/* 所定時間 */}
            <div className={cardSection}>
                {head('fa-business-time', 'bg-amber-100 text-amber-600', '所定時間設定', '設定すると遅刻・早退・残業の算出が有効になります。設定する場合は3項目すべて入力してください。')}
                {partial && (
                    <div className="mb-4 rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-700">
                        <i className="fa-solid fa-triangle-exclamation mr-1.5" />出勤時刻・退勤時刻・所定労働時間のすべてを入力してください。
                    </div>
                )}
                <div className="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500">所定出勤・退勤時刻</label>
                        <div className="flex flex-wrap items-center gap-2">
                            <input type="time" disabled={!canWrite} className={`w-32 ${input}`}
                                value={data.work_start_time} onChange={(e) => setData('work_start_time', e.target.value)} />
                            <span className="text-sm text-gray-500">〜</span>
                            <input type="time" disabled={!canWrite} className={`w-32 ${input}`}
                                value={data.work_end_time} onChange={(e) => setData('work_end_time', e.target.value)} />
                        </div>
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500">1日の所定労働時間</label>
                        <div className="flex items-center gap-2">
                            <input type="number" min={1} max={1440} disabled={!canWrite} className={`w-28 ${input}`} placeholder="480"
                                value={data.work_hours_per_day} onChange={(e) => setData('work_hours_per_day', e.target.value)} />
                            <span className="text-sm text-gray-500">分</span>
                        </div>
                        <p className="mt-1 text-xs text-gray-400">例: 8時間 = 480分（休憩を含めない実労働）。従業員情報＞給与情報に個別値があれば給与計算ではそちらが優先されます。</p>
                    </div>
                </div>
                {canWrite && (data.work_start_time || data.work_end_time || data.work_hours_per_day) && (
                    <button type="button" onClick={() => { setData('work_start_time', ''); setData('work_end_time', ''); setData('work_hours_per_day', ''); }}
                        className="mt-3 inline-flex items-center gap-1.5 text-sm text-gray-500 transition hover:text-red-600">
                        <i className="fa-solid fa-xmark" />所定時間をクリア
                    </button>
                )}
            </div>

            {/* 締め日（勤怠集計） */}
            <div className={cardSection}>
                {head('fa-calendar-day', 'bg-purple-100 text-purple-600', '月の締め日設定（勤怠集計）', 'ダッシュボード・月次サマリ・月別CSVなど勤怠の月次集計の起点です。')}
                <div className="rounded-lg bg-purple-50/60 px-4 py-2.5 text-xs text-purple-700">
                    <i className="fa-solid fa-circle-info mr-1.5" />
                    ここは<strong>勤怠集計</strong>の締め日です。給与の締め日・支給日は「全般」タブの<strong>締め日グループ</strong>で従業員グループごとに設定します（別物です）。
                </div>
                <div className="mt-4">
                    <label className="mb-1 block text-xs font-medium text-gray-500">締め日</label>
                    <div className="flex items-center gap-2">
                        <input type="number" min={1} max={31} disabled={!canWrite} className={`w-28 ${input}`} placeholder="（空欄=月末）"
                            value={data.month_closing_day} onChange={(e) => setData('month_closing_day', e.target.value)} />
                        <span className="text-sm text-gray-500">日</span>
                        {canWrite && data.month_closing_day !== '' && (
                            <button type="button" onClick={() => setData('month_closing_day', '')}
                                className="ml-2 inline-flex items-center gap-1.5 text-sm text-gray-500 transition hover:text-red-600">
                                <i className="fa-solid fa-xmark" />クリア（月末締め）
                            </button>
                        )}
                    </div>
                    <p className="mt-1 text-xs text-gray-400">1〜31。例: 20を設定すると「6月」は5/21〜6/20として集計されます。</p>
                </div>
            </div>

            {/* 休日区分（年度設定未作成時のフォールバック） */}
            <div className={cardSection}>
                {head('fa-umbrella-beach', 'bg-rose-100 text-rose-600', '休日区分の設定', '年度設定が未作成の年に限り、休日判定のフォールバックとして使用します。')}
                <div className="space-y-2 rounded-lg bg-rose-50/60 px-4 py-3 text-xs text-rose-800">
                    <p>
                        <i className="fa-solid fa-circle-info mr-1.5" />
                        打刻の休日区分（平日 / 所定休日 / 法定休日）、休日労働時間の集計、<strong>当月の所定労働日数・時間</strong>の算出は、いずれも<strong>「年度設定」タブの休日設定を優先</strong>して参照します。
                    </p>
                    <p>
                        <strong>ここ（勤怠設定）が使われるのは、対象年の年度設定が未作成の場合のみ</strong>です。その年の休日判定フォールバックとして、この曜日指定が参照されます。
                    </p>
                    <p className="text-rose-600">
                        年度を新規作成すると、この設定は年度側へコピーされます。休日ルールを変更する場合は<strong>年度設定タブ</strong>を変更してください（勤怠設定と年度設定で内容が食い違わないようご注意ください）。
                    </p>
                </div>
                <div className="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div>
                        <label className="mb-1.5 block text-xs font-medium text-gray-500">法定休日</label>
                        <div className="flex flex-wrap gap-1.5">
                            {DOW_OPTIONS.map((o) => {
                                const active = (data.legal_holiday_dows ?? []).includes(o.value);
                                return (
                                    <button key={o.value} type="button" disabled={!canWrite}
                                        onClick={() => toggleDow('legal_holiday_dows', o.value)}
                                        className={`h-9 w-9 rounded-lg text-sm font-medium transition ${active ? 'bg-rose-600 text-white' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'} disabled:opacity-60`}>
                                        {o.label}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                    <div>
                        <label className="mb-1.5 block text-xs font-medium text-gray-500">所定休日</label>
                        <div className="flex flex-wrap gap-1.5">
                            {DOW_OPTIONS.map((o) => {
                                const active = (data.prescribed_holiday_dows ?? []).includes(o.value);
                                return (
                                    <button key={o.value} type="button" disabled={!canWrite}
                                        onClick={() => toggleDow('prescribed_holiday_dows', o.value)}
                                        className={`h-9 w-9 rounded-lg text-sm font-medium transition ${active ? 'bg-amber-500 text-white' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'} disabled:opacity-60`}>
                                        {o.label}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>
                <p className="mt-2 text-xs text-gray-400">同じ曜日を両方に指定した場合は法定休日が優先されます。</p>
            </div>

            {canWrite && (
                <div className="flex justify-end">
                    <button type="button" onClick={onSave} disabled={form.processing || partial}
                        className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                        <i className="fa-solid fa-floppy-disk" />勤怠設定を保存
                    </button>
                </div>
            )}
        </div>
    );
}

/* ============================ 年度設定タブ(se15) ============================ */
const FY_DOW_LABELS = ['日曜日', '月曜日', '火曜日', '水曜日', '木曜日', '金曜日', '土曜日', '祝日'];
const MONTH_LABELS = ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'];
const FY_WEEKDAY = ['日', '月', '火', '水', '木', '金', '土'];

/** 'YYYY-MM-DD' を 'YYYY-MM-DD（曜）' に整形。 */
const fmtHolidayDate = (d: string): string => {
    const dt = new Date(`${d}T00:00:00`);
    return Number.isNaN(dt.getTime()) ? d : `${d}（${FY_WEEKDAY[dt.getDay()]}）`;
};

/** ISO8601 を 'YYYY-MM-DD HH:mm' に整形。 */
const fmtImportedAt = (iso: string | null): string | null => {
    if (!iso) return null;
    const dt = new Date(iso);
    if (Number.isNaN(dt.getTime())) return null;
    const p = (n: number) => String(n).padStart(2, '0');
    return `${dt.getFullYear()}-${p(dt.getMonth() + 1)}-${p(dt.getDate())} ${p(dt.getHours())}:${p(dt.getMinutes())}`;
};

function FiscalYearTab({ data, options, canWrite }: {
    data: FiscalYearData;
    options: Props['options'];
    canWrite: boolean;
}) {
    const fy = data.fiscalYear;
    const [holidayTypes, setHolidayTypes] = useState<Record<number, string>>({});
    const [customHolidays, setCustomHolidays] = useState<{ date: string; label: string }[]>([]);
    const [name, setName] = useState('');
    const [hoursPerDay, setHoursPerDay] = useState('');
    const [avgDays, setAvgDays] = useState('');
    const [avgHours, setAvgHours] = useState('');
    const [processing, setProcessing] = useState(false);
    const [importing, setImporting] = useState(false);
    const [showHolidayList, setShowHolidayList] = useState(false);

    useEffect(() => {
        const map: Record<number, string> = {};
        for (let d = 0; d <= 7; d++) map[d] = 'weekday';
        (fy?.holidays ?? []).forEach((h) => { map[h.dow] = h.type; });
        setHolidayTypes(map);
        setCustomHolidays((fy?.custom_holidays ?? []).map((c) => ({ date: c.date, label: c.label ?? '' })));
        setName(fy?.name ?? '');
        setHoursPerDay(fy?.work_hours_per_day_minutes != null ? String(fy.work_hours_per_day_minutes / 60) : '');
        setAvgDays(fy?.monthly_avg_work_days ?? '');
        setAvgHours(fy?.monthly_avg_work_hours ?? '');
    }, [fy?.id]);

    const switchYear = (year: number) => router.get(route('admin.payroll.settings.index'), { fy: year, tab: 'fiscal_year' }, { preserveScroll: true });

    const createNext = () => {
        const next = data.years.length ? Math.max(...data.years) + 1 : new Date().getFullYear();
        if (!window.confirm(`${next}年度を作成しますか？（直近年度の設定を複製します）`)) return;
        const withHolidays = window.confirm(`${next}年の祝日を内閣府から自動で取り込みますか？\n\n・翌年分は例年2月頃に掲載されます（未掲載の年は取り込めません）\n・後から「祝日を取り込む」ボタンでも実行できます`);
        router.post(route('admin.payroll.settings.fiscal-years.store'), { year: next, import_holidays: withHolidays }, { preserveScroll: true });
    };

    const importHolidays = () => {
        if (!fy) return;
        if (!window.confirm(`${fy.year}年の祝日を内閣府CSVから取り込みますか？\n\n既存の取込分は最新データで置き換わります（手入力の独自休日は保持されます）。`)) return;
        setImporting(true);
        router.post(route('admin.payroll.settings.fiscal-years.import-holidays', fy.id), {}, { preserveScroll: true, onFinish: () => setImporting(false) });
    };

    const save = () => {
        if (!fy) return;
        setProcessing(true);
        router.put(route('admin.payroll.settings.fiscal-years.update', fy.id), {
            name,
            work_hours_per_day_minutes: hoursPerDay === '' ? null : Math.round(Number(hoursPerDay) * 60),
            monthly_avg_work_days: avgDays === '' ? null : Number(avgDays),
            monthly_avg_work_hours: avgHours === '' ? null : Number(avgHours),
            holidays: Object.entries(holidayTypes).map(([dow, type]) => ({ dow: Number(dow), type })),
            custom_holidays: customHolidays.filter((c) => c.date).map((c) => ({ date: c.date, label: c.label || null })),
        }, { preserveScroll: true, onFinish: () => setProcessing(false) });
    };

    const table = data.monthlyDayTable;

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <select value={data.selected ?? ''} onChange={(e) => switchYear(Number(e.target.value))}
                        className={`${selectClass} font-semibold`}>
                        {data.years.map((y) => <option key={y} value={y}>{y}年</option>)}
                        {data.years.length === 0 && <option value="">年度なし</option>}
                    </select>
                    {canWrite && (
                        <button type="button" onClick={createNext}
                            className="inline-flex items-center gap-1 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-700 hover:bg-teal-100">
                            <i className="fa-solid fa-plus" />翌年度を作成
                        </button>
                    )}
                </div>
            </div>

            {!fy ? (
                <div className={`${cardClass} px-4 py-8 text-center text-sm text-gray-400`}>
                    年度が未作成です。「翌年度を作成」から作成してください。
                </div>
            ) : (
                <>
                    {/* 休日設定 */}
                    <div className={`${cardClass} p-5`}>
                        <h3 className="mb-3 text-sm font-bold text-gray-800"><i className="fa-solid fa-calendar-week mr-2 text-teal-600" />休日設定</h3>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                            {FY_DOW_LABELS.map((label, dow) => (
                                <div key={dow}>
                                    <label className={`mb-1 block text-xs font-medium ${dow === 0 || dow === 7 ? 'text-red-500' : dow === 6 ? 'text-blue-500' : 'text-gray-500'}`}>{label}</label>
                                    <select value={holidayTypes[dow] ?? 'weekday'} disabled={!canWrite}
                                        onChange={(e) => setHolidayTypes((m) => ({ ...m, [dow]: e.target.value }))}
                                        className={`${selectClass} w-full`}>
                                        {Object.entries(options.holidayTypes).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                    </select>
                                </div>
                            ))}
                        </div>
                        <p className="mt-2 text-xs text-gray-400">設定した休日をもとに、年間・月別の所定労働日数が自動算出されます。打刻の休日区分・休日労働時間・当月所定労働日数/時間の算出も、この設定を優先して参照します。</p>
                    </div>

                    {/* 祝日（内閣府CSV取込） */}
                    <div className={`${cardClass} p-5`}>
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h3 className="text-sm font-bold text-gray-800"><i className="fa-solid fa-flag mr-2 text-teal-600" />祝日データ（内閣府）</h3>
                            <div className="flex items-center gap-2">
                                <button type="button" onClick={() => setShowHolidayList(true)}
                                    className="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                    <i className="fa-solid fa-list" />{fy.year}年の祝日（{(fy.holiday_list ?? []).length}件）
                                </button>
                                {canWrite && (
                                    <button type="button" onClick={importHolidays} disabled={importing}
                                        className="inline-flex items-center gap-1 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-700 hover:bg-teal-100 disabled:opacity-50">
                                        <i className={`fa-solid ${importing ? 'fa-spinner fa-spin' : 'fa-cloud-arrow-down'}`} />{fy.year}年の祝日を取り込む
                                    </button>
                                )}
                            </div>
                        </div>
                        <p className="text-xs text-gray-500">
                            {fy.holidays_imported_at
                                ? <>最終取込: <span className="font-semibold text-gray-700">{fmtImportedAt(fy.holidays_imported_at)}</span></>
                                : <span className="text-amber-600">まだ取り込まれていません。「{fy.year}年の祝日を取り込む」を実行してください。</span>}
                        </p>
                        <p className="mt-1 text-[11px] text-gray-400">
                            出典: 祝日データ <a href="https://www8.cao.go.jp/chosei/shukujitsu/gaiyou.html" target="_blank" rel="noopener noreferrer" className="text-teal-600 underline">内閣府</a>（CC BY）。翌年分は例年2月頃に確定・掲載されます。取り込んだ祝日は下の独自休日と合わせて休日として扱われます。
                        </p>
                    </div>

                    {/* 独自休日設定 */}
                    <div className={`${cardClass} p-5`}>
                        <div className="mb-3 flex items-center justify-between">
                            <h3 className="text-sm font-bold text-gray-800"><i className="fa-solid fa-calendar-xmark mr-2 text-teal-600" />独自休日設定</h3>
                            {canWrite && (
                                <button type="button" onClick={() => setCustomHolidays((a) => [...a, { date: '', label: '' }])}
                                    className="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                                    <i className="fa-solid fa-plus" />行を追加
                                </button>
                            )}
                        </div>
                        <p className="mb-3 text-[11px] text-gray-400">創立記念日など、会社独自の休日を手入力します（内閣府から取り込んだ祝日はここには表示されず、上の「祝日データ」で管理されます）。</p>
                        {customHolidays.length === 0 ? (
                            <p className="text-xs text-gray-400">独自休日情報がありません。</p>
                        ) : (
                            <div className="space-y-2">
                                {customHolidays.map((c, i) => (
                                    <div key={i} className="flex items-center gap-2">
                                        <input type="date" value={c.date} disabled={!canWrite}
                                            onChange={(e) => setCustomHolidays((a) => a.map((x, j) => j === i ? { ...x, date: e.target.value } : x))}
                                            className={`${selectClass}`} />
                                        <input type="text" value={c.label} placeholder="名称（任意）" disabled={!canWrite}
                                            onChange={(e) => setCustomHolidays((a) => a.map((x, j) => j === i ? { ...x, label: e.target.value } : x))}
                                            className={`${selectClass} flex-1`} />
                                        {canWrite && (
                                            <button type="button" onClick={() => setCustomHolidays((a) => a.filter((_, j) => j !== i))}
                                                className="rounded-lg px-2 py-1 text-red-500 hover:bg-red-50"><i className="fa-solid fa-trash-can" /></button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* 所定労働時間 */}
                    <div className={`${cardClass} p-5`}>
                        <h3 className="mb-3 text-sm font-bold text-gray-800"><i className="fa-solid fa-clock mr-2 text-teal-600" />所定労働時間</h3>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <label className="mb-1 block text-xs font-medium text-gray-500">1日の所定労働時間（時間）</label>
                                <input type="number" step="0.5" min="0" value={hoursPerDay} disabled={!canWrite}
                                    onChange={(e) => setHoursPerDay(e.target.value)} className={`${selectClass} w-full`} placeholder="例) 8" />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-medium text-gray-500">所定労働日数(月平均)</label>
                                <input type="number" step="0.1" min="0" value={avgDays} disabled={!canWrite}
                                    onChange={(e) => setAvgDays(e.target.value)} className={`${selectClass} w-full`} placeholder="自動算出" />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-medium text-gray-500">所定労働時間(月平均)</label>
                                <input type="number" step="0.1" min="0" value={avgHours} disabled={!canWrite}
                                    onChange={(e) => setAvgHours(e.target.value)} className={`${selectClass} w-full`} placeholder="自動算出" />
                            </div>
                        </div>
                        <p className="mt-2 text-xs text-gray-400">月平均を空欄にすると、休日設定から自動算出した値が給与計算に使用されます。</p>
                    </div>

                    {/* 月別日数表 */}
                    {table && (
                        <div className={cardClass}>
                            <div className="border-b border-gray-100 px-4 py-3"><h3 className="text-sm font-bold text-gray-800">月別日数表</h3></div>
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-100 text-sm">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className={thClass}></th>
                                            {MONTH_LABELS.map((m) => <th key={m} className={`${thClass} text-right`}>{m}</th>)}
                                            <th className={`${thClass} text-right`}>合計</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        <tr>
                                            <td className={`${tdClass} font-medium`}>所定労働日数</td>
                                            {table.months.map((m) => <td key={m.month} className={`${tdClass} text-right`}>{m.work_days}日</td>)}
                                            <td className={`${tdClass} text-right font-semibold`}>{table.total.work_days}日</td>
                                        </tr>
                                        <tr>
                                            <td className={`${tdClass} font-medium`}>休日数</td>
                                            {table.months.map((m) => <td key={m.month} className={`${tdClass} text-right`}>{m.holidays}日</td>)}
                                            <td className={`${tdClass} text-right font-semibold`}>{table.total.holidays}日</td>
                                        </tr>
                                        <tr>
                                            <td className={`${tdClass} font-medium`}>暦日数</td>
                                            {table.months.map((m) => <td key={m.month} className={`${tdClass} text-right`}>{m.calendar_days}日</td>)}
                                            <td className={`${tdClass} text-right font-semibold`}>{table.total.calendar_days}日</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* 給与月度 */}
                    {data.payMonths.length > 0 && (
                        <div className="space-y-4">
                            {data.payMonths.map((grp) => (
                                <div key={grp.group} className={cardClass}>
                                    <div className="border-b border-gray-100 px-4 py-3"><h3 className="text-sm font-bold text-gray-800">給与月度: {grp.group}</h3></div>
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                                            <thead className="bg-gray-50">
                                                <tr>
                                                    <th className={thClass}></th>
                                                    {grp.months.map((m) => <th key={m.month} className={`${thClass} text-center`}>{m.month}月</th>)}
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-100">
                                                <tr><td className={`${tdClass} font-medium`}>締め日</td>{grp.months.map((m) => <td key={m.month} className={`${tdClass} text-center`}>{m.closing_date.slice(5)}</td>)}</tr>
                                                <tr><td className={`${tdClass} font-medium`}>支給日</td>{grp.months.map((m) => <td key={m.month} className={`${tdClass} text-center text-teal-700`}>{m.payment_date.slice(5)}</td>)}</tr>
                                                <tr><td className={`${tdClass} font-medium`}>公開日</td>{grp.months.map((m) => <td key={m.month} className={`${tdClass} text-center`}>{m.publish_date.slice(5)}</td>)}</tr>
                                                <tr><td className={`${tdClass} font-medium`}>所定労働日数</td>{grp.months.map((m) => <td key={m.month} className={`${tdClass} text-center`}>{m.work_days ?? '—'}</td>)}</tr>
                                                <tr><td className={`${tdClass} font-medium`}>ステータス</td>{grp.months.map((m) => <td key={m.month} className={`${tdClass} text-center`}>{m.status === 'finalized' ? <span className="text-green-600">確定済</span> : <span className="text-gray-400">未確定</span>}</td>)}</tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {canWrite && (
                        <div className="flex justify-end">
                            <button type="button" onClick={save} disabled={processing}
                                className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                                <i className="fa-solid fa-floppy-disk" />年度設定を保存
                            </button>
                        </div>
                    )}

                    {/* 祝日一覧モーダル */}
                    {showHolidayList && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setShowHolidayList(false)}>
                            <div className="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                                    <h3 className="text-sm font-bold text-gray-800">{fy.year}年 祝日・休日一覧（{(fy.holiday_list ?? []).length}件）</h3>
                                    <button type="button" onClick={() => setShowHolidayList(false)} className="rounded-lg px-2 py-1 text-gray-400 hover:bg-gray-100"><i className="fa-solid fa-xmark" /></button>
                                </div>
                                <div className="flex-1 overflow-y-auto px-5 py-3">
                                    {(fy.holiday_list ?? []).length === 0 ? (
                                        <p className="py-8 text-center text-sm text-gray-400">祝日が登録されていません。<br />「{fy.year}年の祝日を取り込む」を実行してください。</p>
                                    ) : (
                                        <table className="min-w-full text-sm">
                                            <thead>
                                                <tr className="border-b border-gray-100 text-left text-xs text-gray-500">
                                                    <th className="py-2 pr-2 font-medium">日付</th>
                                                    <th className="py-2 pr-2 font-medium">名称</th>
                                                    <th className="py-2 font-medium">出典</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-50">
                                                {(fy.holiday_list ?? []).map((h, i) => (
                                                    <tr key={i} className={h.source === 'cabinet_office' ? '' : 'bg-amber-50/40'}>
                                                        <td className="py-2 pr-2 whitespace-nowrap text-gray-700">{fmtHolidayDate(h.date)}</td>
                                                        <td className="py-2 pr-2 text-gray-700">{h.label || '—'}</td>
                                                        <td className="py-2">
                                                            {h.source === 'cabinet_office'
                                                                ? <span className="rounded bg-teal-50 px-1.5 py-0.5 text-[11px] font-medium text-teal-700">内閣府</span>
                                                                : <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-700">手入力</span>}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                </div>
                                <div className="border-t border-gray-100 px-5 py-2.5 text-[11px] text-gray-400">
                                    {fy.holidays_imported_at ? `最終取込: ${fmtImportedAt(fy.holidays_imported_at)}` : '内閣府からの取込: 未実行'} / 出典: 内閣府（CC BY）
                                </div>
                            </div>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

/* ============================ 明細設定タブ(se17) ============================ */
function PayslipSettingsTab({ settings, options, canWrite }: {
    settings: Record<string, string | null>;
    options: Props['options'];
    canWrite: boolean;
}) {
    const boolInit = (k: string, d: boolean) => (settings[k] != null ? settings[k] === '1' : d);
    const common = useForm({
        payslip_display_month: settings.payslip_display_month ?? 'payment',
        payslip_show_target_period: boolInit('payslip_show_target_period', true),
        payslip_show_affiliation: boolInit('payslip_show_affiliation', true),
        payslip_show_department: boolInit('payslip_show_department', true),
        payslip_show_attendance: boolInit('payslip_show_attendance', true),
        payslip_show_ytd: boolInit('payslip_show_ytd', false),
        payslip_show_hourly: boolInit('payslip_show_hourly', true),
        payslip_show_standard_monthly: boolInit('payslip_show_standard_monthly', false),
        payslip_show_dependents: boolInit('payslip_show_dependents', false),
        payslip_show_tax_category: boolInit('payslip_show_tax_category', false),
    });
    const notify = useForm({
        payslip_notify: settings.payslip_notify ?? 'none',
        bonus_notify: settings.bonus_notify ?? 'none',
    });

    const toggleRows: { key: keyof typeof common.data; label: string }[] = [
        { key: 'payslip_show_target_period', label: '対象期間' },
        { key: 'payslip_show_affiliation', label: '所属' },
        { key: 'payslip_show_department', label: '部門' },
        { key: 'payslip_show_attendance', label: '勤怠' },
        { key: 'payslip_show_ytd', label: '本年累計' },
        { key: 'payslip_show_hourly', label: '時給' },
        { key: 'payslip_show_standard_monthly', label: '標準報酬月額' },
        { key: 'payslip_show_dependents', label: '扶養親族等数' },
        { key: 'payslip_show_tax_category', label: '税額表区分' },
    ];

    const radio = (checked: boolean, onChange: () => void, label: string) => (
        <label className="inline-flex items-center gap-1.5 text-sm text-gray-700">
            <input type="radio" checked={checked} disabled={!canWrite} onChange={onChange}
                className="text-teal-600 focus:ring-teal-500" />{label}
        </label>
    );

    return (
        <div className="space-y-6">
            <div className={`${cardClass} p-5`}>
                <h3 className="mb-1 text-sm font-bold text-gray-800">給与明細テンプレート（支給・控除・勤怠）</h3>
                <p className="text-xs text-gray-500">項目の名称や表示・非表示は「支給項目」「控除項目」「勤怠項目」タブで設定できます。</p>
            </div>

            <div className={`${cardClass} p-5`}>
                <h3 className="mb-4 text-sm font-bold text-gray-800">給与明細・賞与明細共通</h3>
                <div className="divide-y divide-gray-100">
                    <div className="flex items-center justify-between py-2.5">
                        <span className="text-sm font-medium text-gray-700">表示月</span>
                        <div className="flex gap-4">
                            {Object.entries(options.payslipDisplayMonths).map(([v, l]) =>
                                radio(common.data.payslip_display_month === v, () => common.setData('payslip_display_month', v), l))}
                        </div>
                    </div>
                    {toggleRows.map((r) => (
                        <div key={r.key} className="flex items-center justify-between py-2.5">
                            <span className="text-sm font-medium text-gray-700">{r.label}</span>
                            <div className="flex gap-4">
                                {radio(!!common.data[r.key], () => common.setData(r.key, true), '表示する')}
                                {radio(!common.data[r.key], () => common.setData(r.key, false), '表示しない')}
                            </div>
                        </div>
                    ))}
                </div>
                {canWrite && (
                    <div className="mt-4 flex justify-end">
                        <button type="button" onClick={() => common.put(route('admin.payroll.settings.payslip'), { preserveScroll: true })}
                            disabled={common.processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                            <i className="fa-solid fa-floppy-disk" />保存する
                        </button>
                    </div>
                )}
            </div>

            <div className={`${cardClass} p-5`}>
                <h3 className="mb-4 text-sm font-bold text-gray-800">通知</h3>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500">給与明細通知日</label>
                        <select value={notify.data.payslip_notify} disabled={!canWrite}
                            onChange={(e) => notify.setData('payslip_notify', e.target.value)} className={`${selectClass} w-full`}>
                            {Object.entries(options.payslipNotifyOptions).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500">賞与明細通知日</label>
                        <select value={notify.data.bonus_notify} disabled={!canWrite}
                            onChange={(e) => notify.setData('bonus_notify', e.target.value)} className={`${selectClass} w-full`}>
                            {Object.entries(options.payslipNotifyOptions).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                    </div>
                </div>
                <p className="mt-2 text-xs text-gray-400">明細通知は設定日（支給日もしくは公開日）に送信されます。</p>
                {canWrite && (
                    <div className="mt-4 flex justify-end">
                        <button type="button" onClick={() => notify.put(route('admin.payroll.settings.payslip-notify'), { preserveScroll: true })}
                            disabled={notify.processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                            <i className="fa-solid fa-floppy-disk" />保存する
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

type LocationFormShape = {
    name: string;
    code: string;
    is_main: boolean;
    health_insurance_type: string;
    prefecture: string;
    labor_insurance_number: string;
    labor_insurance_pref_code: string;
    labor_insurance_jurisdiction_code: string;
    labor_insurance_office_code: string;
    labor_insurance_serial_number: string;
    labor_insurance_branch_code: string;
    office_number: string;
    accident_industry_code: string;
    accident_merit_enabled: boolean;
    accident_merit_rate: string;
    employment_industry_type: string;
    labor_bureau: string;
    employment_bureau: string;
    accident_business_desc: string;
    employment_office_number: string;
    postal_code: string;
    address: string;
    note: string;
    sort_order: number;
};

function LocationForm({
    initial,
    healthInsuranceTypes,
    prefectures,
    accidentIndustries,
    employmentIndustries,
    onSubmit,
    submitLabel,
    processing,
    onCancel,
}: {
    initial: LocationFormShape;
    healthInsuranceTypes: LabelMap;
    prefectures: string[];
    accidentIndustries: LabelMap;
    employmentIndustries: LabelMap;
    onSubmit: (data: LocationFormShape) => void;
    submitLabel: string;
    processing: boolean;
    onCancel?: () => void;
}) {
    const [data, setData] = useState<LocationFormShape>(initial);
    const set = <K extends keyof LocationFormShape>(k: K, v: LocationFormShape[K]) => setData((d) => ({ ...d, [k]: v }));
    const fieldClass = 'w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500';
    const labelClass = 'mb-1 block text-xs font-medium text-gray-500';

    return (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-12">
            <div className="sm:col-span-6">
                <label className={labelClass}>事業所名</label>
                <input value={data.name} onChange={(e) => set('name', e.target.value)} placeholder="例: 本社" className={fieldClass} />
            </div>
            <div className="sm:col-span-3">
                <label className={labelClass}>事業所コード</label>
                <input value={data.code} onChange={(e) => set('code', e.target.value)} className={fieldClass} />
            </div>
            <div className="sm:col-span-3">
                <label className={labelClass}>表示順</label>
                <input type="number" min={0} value={data.sort_order} onChange={(e) => set('sort_order', Number(e.target.value))}
                    className={`${fieldClass} text-right`} />
            </div>
            <div className="sm:col-span-4">
                <label className={labelClass}>健康保険 管掌区分</label>
                <select value={data.health_insurance_type} onChange={(e) => set('health_insurance_type', e.target.value)} className={fieldClass}>
                    {Object.entries(healthInsuranceTypes).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
            </div>
            <div className="sm:col-span-4">
                <label className={labelClass}>都道府県（協会けんぽ料率）</label>
                <select value={data.prefecture} onChange={(e) => set('prefecture', e.target.value)} className={fieldClass}>
                    <option value="">未設定</option>
                    {prefectures.map((p) => <option key={p} value={p}>{p}</option>)}
                </select>
            </div>
            <div className="flex items-end sm:col-span-4">
                <label className="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" checked={data.is_main} onChange={(e) => set('is_main', e.target.checked)}
                        className="rounded border-gray-300 text-teal-600 focus:ring-teal-500" />
                    本社（主たる事業所）
                </label>
            </div>
            <div className="sm:col-span-6">
                <label className={labelClass}>事業所整理記号/番号</label>
                <input value={data.office_number} onChange={(e) => set('office_number', e.target.value)} className={fieldClass} />
            </div>
            <div className="sm:col-span-12 border-t border-gray-100 pt-2">
                <p className="text-xs font-semibold text-gray-600"><i className="fa-solid fa-shield-halved mr-1 text-teal-600" />労働保険（業種を選ぶと料率が自動セットされます）</p>
            </div>
            <div className="sm:col-span-12">
                <label className={labelClass}>労働保険番号（府県・所掌・管轄・基幹番号・枝番号）</label>
                <div className="flex flex-wrap items-center gap-1.5">
                    <input value={data.labor_insurance_pref_code} onChange={(e) => set('labor_insurance_pref_code', e.target.value.replace(/\D/g, '').slice(0, 2))}
                        inputMode="numeric" placeholder="府県" maxLength={2} className={`${fieldClass} w-14 text-center`} />
                    <span className="text-gray-300">-</span>
                    <input value={data.labor_insurance_jurisdiction_code} onChange={(e) => set('labor_insurance_jurisdiction_code', e.target.value.replace(/\D/g, '').slice(0, 1))}
                        inputMode="numeric" placeholder="所掌" maxLength={1} className={`${fieldClass} w-12 text-center`} />
                    <span className="text-gray-300">-</span>
                    <input value={data.labor_insurance_office_code} onChange={(e) => set('labor_insurance_office_code', e.target.value.replace(/\D/g, '').slice(0, 2))}
                        inputMode="numeric" placeholder="管轄" maxLength={2} className={`${fieldClass} w-14 text-center`} />
                    <span className="text-gray-300">-</span>
                    <input value={data.labor_insurance_serial_number} onChange={(e) => set('labor_insurance_serial_number', e.target.value.replace(/\D/g, '').slice(0, 6))}
                        inputMode="numeric" placeholder="基幹番号" maxLength={6} className={`${fieldClass} w-28 text-center`} />
                    <span className="text-gray-300">-</span>
                    <input value={data.labor_insurance_branch_code} onChange={(e) => set('labor_insurance_branch_code', e.target.value.replace(/\D/g, '').slice(0, 3))}
                        inputMode="numeric" placeholder="枝番号" maxLength={3} className={`${fieldClass} w-16 text-center`} />
                </div>
            </div>
            <div className="sm:col-span-6">
                <label className={labelClass}>労災保険料率用の業種</label>
                <select value={data.accident_industry_code} onChange={(e) => set('accident_industry_code', e.target.value)} className={fieldClass}>
                    <option value="">未設定</option>
                    {Object.entries(accidentIndustries).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
            </div>
            <div className="sm:col-span-6">
                <label className={labelClass}>雇用保険料率用の事業区分</label>
                <select value={data.employment_industry_type} onChange={(e) => set('employment_industry_type', e.target.value)} className={fieldClass}>
                    <option value="">未設定</option>
                    {Object.entries(employmentIndustries).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
            </div>
            <div className="sm:col-span-12">
                <div className="rounded-lg bg-gray-50 px-3 py-2.5">
                    <label className="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" checked={data.accident_merit_enabled}
                            onChange={(e) => set('accident_merit_enabled', e.target.checked)}
                            className="rounded border-gray-300 text-teal-600 focus:ring-teal-500" />
                        労災保険 メリット制（適用あり）
                    </label>
                    {data.accident_merit_enabled && (
                        <div className="mt-2 flex items-center gap-2">
                            <label className="text-xs text-gray-500">メリット制の労災保険料率</label>
                            <input value={data.accident_merit_rate}
                                onChange={(e) => set('accident_merit_rate', e.target.value.replace(/[^0-9.]/g, ''))}
                                inputMode="decimal" placeholder="例: 2.5" className={`${fieldClass} w-24 text-right`} />
                            <span className="text-xs text-gray-400">/1,000（事業主負担・業種料率を上書き）</span>
                        </div>
                    )}
                </div>
            </div>
            <div className="sm:col-span-6">
                <label className={labelClass}>労災 管轄（労働基準監督署）</label>
                <input value={data.labor_bureau} onChange={(e) => set('labor_bureau', e.target.value)} placeholder="例: 荒川 労働基準監督署" className={fieldClass} />
            </div>
            <div className="sm:col-span-6">
                <label className={labelClass}>事業の具体的内容（労災）</label>
                <input value={data.accident_business_desc} onChange={(e) => set('accident_business_desc', e.target.value)} placeholder="例: 飲食店" className={fieldClass} />
            </div>
            <div className="sm:col-span-6">
                <label className={labelClass}>雇用保険 管轄（ハローワーク）</label>
                <input value={data.employment_bureau} onChange={(e) => set('employment_bureau', e.target.value)} placeholder="例: 荒川 ハローワーク（公共職業安定所）" className={fieldClass} />
            </div>
            <div className="sm:col-span-6">
                <label className={labelClass}>雇用保険適用事業所番号</label>
                <input value={data.employment_office_number} onChange={(e) => set('employment_office_number', e.target.value)} className={fieldClass} />
            </div>
            <div className="sm:col-span-3">
                <label className={labelClass}>郵便番号</label>
                <input value={data.postal_code} onChange={(e) => set('postal_code', e.target.value)} placeholder="123-4567" className={fieldClass} />
            </div>
            <div className="sm:col-span-9">
                <label className={labelClass}>住所</label>
                <input value={data.address} onChange={(e) => set('address', e.target.value)} className={fieldClass} />
            </div>
            <div className="sm:col-span-12">
                <label className={labelClass}>備考</label>
                <textarea value={data.note} onChange={(e) => set('note', e.target.value)} rows={2} className={fieldClass} />
            </div>
            <div className="flex items-center gap-2 sm:col-span-12">
                <button onClick={() => onSubmit(data)} disabled={processing || data.name.trim() === ''}
                    className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                    <i className="fa-solid fa-floppy-disk" /> {submitLabel}
                </button>
                {onCancel && <button onClick={onCancel} className="text-xs text-gray-400 hover:text-gray-600">キャンセル</button>}
            </div>
        </div>
    );
}

/* ============================ 保険料率テーブル（社会保険/労働保険 共用） ============================ */
function RateEditorCard({ loc, kinds, options, canWrite, patchRate, deleteRow, showSetDelete }: {
    loc: Location;
    kinds: string[];
    options: Props['options'];
    canWrite: boolean;
    patchRate: (locId: number, rateId: number, patch: Partial<RateRow>) => void;
    deleteRow: (url: string, msg: string) => void;
    showSetDelete: boolean;
}) {
    const set = loc.insurance_rate_sets[0];
    const rows = (set?.rates ?? []).filter((r) => kinds.includes(r.kind));
    return (
        <div className={cardClass}>
            <div className="flex items-start justify-between border-b border-gray-100 px-4 py-3">
                <div>
                    <h3 className="text-sm font-bold text-gray-800">
                        {loc.name}
                        {loc.prefecture && <span className="ml-2 text-xs font-normal text-gray-400">{loc.prefecture}</span>}
                    </h3>
                    {set ? (
                        <p className="mt-0.5 text-xs text-gray-500">{set.name}（{set.effective_from}〜{set.effective_to ?? '現行'}）</p>
                    ) : (
                        <p className="mt-0.5 text-xs text-amber-600">料率セットが未登録です。</p>
                    )}
                </div>
                <div className="flex items-center gap-2">
                    {canWrite && set && kinds.includes('health') && (
                        <button
                            onClick={() => {
                                if (confirm(`「${loc.prefecture || '未設定'}」の協会けんぽ料率（健保・介護）と、厚生年金・拠出金の標準値をこの料率セットへ反映します。よろしいですか？`)) {
                                    router.post(route('admin.payroll.settings.insurance-sets.apply-kyokai', set.id), {}, { preserveScroll: true });
                                }
                            }}
                            className="rounded-lg border border-teal-200 bg-teal-50 px-2.5 py-1 text-xs font-medium text-teal-700 hover:bg-teal-100"
                            title="協会けんぽの都道府県別料率を自動セット">
                            <i className="fa-solid fa-wand-magic-sparkles mr-1" />都道府県料率を反映
                        </button>
                    )}
                    {canWrite && set && showSetDelete && (
                        <button onClick={() => deleteRow(route('admin.payroll.settings.insurance-sets.destroy', set.id), `料率セット「${set.name}」を削除しますか？`)}
                            className="rounded-lg px-2 py-1 text-red-500 hover:bg-red-50"><i className="fa-solid fa-trash-can" /></button>
                    )}
                </div>
            </div>
            {set && (
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-100">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className={thClass}>保険</th>
                                <th className={thClass}>従業員負担率(/1,000)</th>
                                <th className={thClass}>事業主負担率(/1,000)</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {rows.map((r) => (
                                <tr key={r.id}>
                                    <td className={`${tdClass} font-medium text-gray-800`}>{options.insuranceKinds[r.kind] ?? r.kind}</td>
                                    <td className={tdClass}>
                                        <input type="number" step="0.001" min="0" className={numberInputClass} disabled={!canWrite || r.kind === 'accident' || r.kind === 'child_contribution'}
                                            value={r.employee_rate} onChange={(e) => patchRate(loc.id, r.id, { employee_rate: e.target.value })} />
                                    </td>
                                    <td className={tdClass}>
                                        <input type="number" step="0.001" min="0" className={numberInputClass} disabled={!canWrite}
                                            value={r.employer_rate} onChange={(e) => patchRate(loc.id, r.id, { employer_rate: e.target.value })} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

/* ==================== 社会保険タブ（MFクラウド準拠） ==================== */

type RateSetKey = 'business_location_id' | 'name' | 'effective_from' | 'effective_to';
type RateSetForm = {
    data: Record<RateSetKey, string>;
    setData: (key: RateSetKey, value: string) => void;
    post: (url: string, opts?: Record<string, unknown>) => void;
    reset: (...fields: RateSetKey[]) => void;
    processing: boolean;
};

type SocialSection = 'health' | 'pension';

/** 千分率(/1,000)の表示整形。91.5 / 0.0 / 3.6 のように最低1桁の小数を表示。 */
function fmtPermille(v: string | number | null | undefined): string {
    const n = parseFloat(String(v ?? '0'));
    if (Number.isNaN(n)) return '0.0';
    return n.toLocaleString('ja-JP', { minimumFractionDigits: 1, maximumFractionDigits: 3 });
}

function SocialInsuranceTab({ locs, options, canWrite, newRateSet, deleteRow }: {
    locs: Location[];
    options: Props['options'];
    canWrite: boolean;
    newRateSet: RateSetForm;
    deleteRow: (url: string, msg: string) => void;
}) {
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [editSection, setEditSection] = useState<SocialSection | null>(null);

    const loc = locs.find((l) => l.id === selectedId) ?? locs[0] ?? null;
    const set = loc?.insurance_rate_sets[0];
    const rateOf = (kind: string) => set?.rates.find((r) => r.kind === kind);

    const sectionHeaderClass = 'flex items-center justify-between border-b border-gray-100 px-4 py-3';
    const editBtnClass = 'inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-50';

    return (
        <div className="space-y-6">
            <p className="text-xs text-gray-500">
                事業所の社会保険（健康保険・厚生年金保険・厚生年金基金）を設定します。保険料率は<span className="font-semibold">/1,000（千分率）</span>で管理し、従業員ごとに標準報酬月額から自動計算されます。労災・雇用の料率は「労働保険」タブで管理します。
            </p>

            {locs.length === 0 && (
                <div className={`${cardClass} px-4 py-8 text-center text-sm text-gray-400`}>事業所が登録されていません。先に「事業所」タブで登録してください。</div>
            )}

            {locs.length > 0 && (
                <>
                    {/* 事業所切替（MFの ▽ 相当） */}
                    <div className="flex flex-wrap items-center gap-3">
                        <label className="text-xs font-medium text-gray-500">事業所</label>
                        <select className={`${selectClass} min-w-[220px]`} value={String(loc?.id ?? '')}
                            onChange={(e) => setSelectedId(Number(e.target.value))}>
                            {locs.map((l) => (
                                <option key={l.id} value={String(l.id)}>{l.name}{l.is_main ? '（本社）' : ''}</option>
                            ))}
                        </select>
                        {set && (
                            <span className="text-xs text-gray-400">
                                料率セット: {set.name}（{set.effective_from}〜{set.effective_to ?? '現行'}）
                            </span>
                        )}
                    </div>

                    {/* 料率セット未登録時: 追加フォーム */}
                    {loc && !set && (
                        <div className={`${cardClass} p-5`}>
                            <h3 className="mb-1 text-sm font-bold text-amber-600"><i className="fa-solid fa-triangle-exclamation mr-2" />料率セットが未登録です</h3>
                            <p className="mb-3 text-xs text-gray-500">保険料率を入力するには、まず適用期間（料率セット）を登録してください。</p>
                            {canWrite && (
                                <div className="flex flex-wrap items-end gap-3">
                                    <div className="flex-1 min-w-[160px]">
                                        <label className="mb-1 block text-xs font-medium text-gray-500">名称</label>
                                        <input className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                                            value={newRateSet.data.name} onChange={(e) => newRateSet.setData('name', e.target.value)} placeholder="例）2026年度 本社" />
                                    </div>
                                    <div><label className="mb-1 block text-xs font-medium text-gray-500">適用開始</label>
                                        <input type="date" className="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                                            value={newRateSet.data.effective_from} onChange={(e) => newRateSet.setData('effective_from', e.target.value)} /></div>
                                    <div><label className="mb-1 block text-xs font-medium text-gray-500">適用終了(任意)</label>
                                        <input type="date" className="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                                            value={newRateSet.data.effective_to} onChange={(e) => newRateSet.setData('effective_to', e.target.value)} /></div>
                                    <button
                                        onClick={() => {
                                            newRateSet.setData('business_location_id', String(loc.id));
                                            newRateSet.post(route('admin.payroll.settings.insurance-sets.store'), {
                                                preserveScroll: true,
                                                onSuccess: () => newRateSet.reset('name', 'effective_from', 'effective_to'),
                                            });
                                        }}
                                        disabled={newRateSet.processing || newRateSet.data.name.trim() === '' || newRateSet.data.effective_from === ''}
                                        className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50">
                                        <i className="fa-solid fa-plus" /> 料率セットを追加
                                    </button>
                                </div>
                            )}
                        </div>
                    )}

                    {loc && set && (
                        <>
                            {/* 健康保険 */}
                            <div className={cardClass}>
                                <div className={sectionHeaderClass}>
                                    <h3 className="text-sm font-bold text-gray-800">健康保険</h3>
                                    <div className="flex items-center gap-2">
                                        {canWrite && loc.health_insurance_type === 'kyokai' && (
                                            <button
                                                onClick={() => {
                                                    if (confirm(`「${loc.prefecture || '未設定'}」の協会けんぽ料率（健保・介護）と、厚生年金・拠出金の標準値をこの料率セットへ反映します。よろしいですか？`)) {
                                                        router.post(route('admin.payroll.settings.insurance-sets.apply-kyokai', set.id), {}, { preserveScroll: true });
                                                    }
                                                }}
                                                className="rounded-lg border border-teal-200 bg-teal-50 px-2.5 py-1.5 text-xs font-medium text-teal-700 hover:bg-teal-100"
                                                title="協会けんぽの都道府県別料率を自動セット">
                                                <i className="fa-solid fa-wand-magic-sparkles mr-1" />都道府県料率を反映
                                            </button>
                                        )}
                                        {canWrite && (
                                            <button onClick={() => setEditSection('health')} className={editBtnClass}>
                                                <i className="fa-solid fa-pen" /> 編集
                                            </button>
                                        )}
                                    </div>
                                </div>
                                <div className="px-4 py-3">
                                    <dl className="mb-3 flex flex-wrap gap-x-8 gap-y-1 text-xs">
                                        <div><dt className="inline text-gray-400">管掌区分：</dt><dd className="inline font-medium text-gray-700">{options.healthInsuranceTypes[loc.health_insurance_type] ?? loc.health_insurance_type}</dd></div>
                                        {loc.health_insurance_type === 'kyokai' && (
                                            <div><dt className="inline text-gray-400">管轄：</dt><dd className="inline font-medium text-gray-700">{loc.prefecture ?? '未設定'}</dd></div>
                                        )}
                                        {loc.health_insurance_type === 'kumiai' && loc.health_union_name && (
                                            <div><dt className="inline text-gray-400">組合名：</dt><dd className="inline font-medium text-gray-700">{loc.health_union_name}</dd></div>
                                        )}
                                        {loc.health_office_symbol && (
                                            <div><dt className="inline text-gray-400">事業所整理記号：</dt><dd className="inline font-medium text-gray-700">{loc.health_office_symbol}</dd></div>
                                        )}
                                    </dl>
                                    {loc.health_insurance_type === 'kokuho' ? (
                                        <p className="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500">国民健康保険組合の保険料は、従業員情報で固定金額を設定します。</p>
                                    ) : (
                                        <RateDisplayTable
                                            header="保険料率"
                                            rows={HEALTH_KINDS.map((k) => ({
                                                label: options.insuranceKinds[k] ?? k,
                                                employee: rateOf(k)?.employee_rate,
                                                employer: rateOf(k)?.employer_rate,
                                            }))}
                                        />
                                    )}
                                </div>
                            </div>

                            {/* 厚生年金保険 */}
                            <div className={cardClass}>
                                <div className={sectionHeaderClass}>
                                    <h3 className="text-sm font-bold text-gray-800">厚生年金保険</h3>
                                    {canWrite && (
                                        <button onClick={() => setEditSection('pension')} className={editBtnClass}>
                                            <i className="fa-solid fa-pen" /> 編集
                                        </button>
                                    )}
                                </div>
                                <div className="px-4 py-3">
                                    {(loc.pension_jurisdiction || loc.pension_office_number || loc.pension_office_symbol) && (
                                        <dl className="mb-3 flex flex-wrap gap-x-8 gap-y-1 text-xs">
                                            {loc.pension_jurisdiction && <div><dt className="inline text-gray-400">管轄：</dt><dd className="inline font-medium text-gray-700">{loc.pension_jurisdiction}</dd></div>}
                                            {loc.pension_office_number && <div><dt className="inline text-gray-400">事業所番号：</dt><dd className="inline font-medium text-gray-700">{loc.pension_office_number}</dd></div>}
                                            {loc.pension_office_symbol && <div><dt className="inline text-gray-400">事業所整理番号：</dt><dd className="inline font-medium text-gray-700">{loc.pension_office_symbol}</dd></div>}
                                        </dl>
                                    )}
                                    <PensionRateTable
                                        pensionEmployee={rateOf('pension')?.employee_rate}
                                        pensionEmployer={rateOf('pension')?.employer_rate}
                                        contributionEmployer={rateOf('child_contribution')?.employer_rate}
                                    />
                                </div>
                            </div>

                            {/* 厚生年金基金（複数登録可・給与/賞与別料率） */}
                            <PensionFundSection loc={loc} canWrite={canWrite} deleteRow={deleteRow} />

                            {canWrite && (
                                <div className="flex justify-end">
                                    <button onClick={() => deleteRow(route('admin.payroll.settings.insurance-sets.destroy', set.id), `料率セット「${set.name}」を削除しますか？`)}
                                        className="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-red-500 hover:bg-red-50">
                                        <i className="fa-solid fa-trash-can" /> この料率セットを削除
                                    </button>
                                </div>
                            )}
                        </>
                    )}
                </>
            )}

            {loc && editSection && (
                <SocialInsuranceEditModal loc={loc} section={editSection} options={options} onClose={() => setEditSection(null)} />
            )}
        </div>
    );
}

/** MF準拠: 保険料率の表示テーブル（被保険者負担 / 事業主負担）。 */
function RateDisplayTable({ header, rows }: {
    header: string;
    rows: { label: string; employee: string | null | undefined; employer: string | null | undefined }[];
}) {
    return (
        <div className="overflow-x-auto rounded-lg border border-gray-100">
            <table className="min-w-full divide-y divide-gray-100">
                <thead className="bg-gray-50">
                    <tr>
                        <th className={thClass}>{header}</th>
                        <th className={thClass}>被保険者負担</th>
                        <th className={thClass}>事業主負担</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                    {rows.map((r) => (
                        <tr key={r.label}>
                            <td className={`${tdClass} font-medium text-gray-800`}>{r.label}</td>
                            <td className={tdClass}>{fmtPermille(r.employee)}</td>
                            <td className={tdClass}>{fmtPermille(r.employer)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** MF準拠: 厚生年金保険の料率テーブル（男子/女子/坑内夫 + 子ども・子育て拠出金）。 */
function PensionRateTable({ pensionEmployee, pensionEmployer, contributionEmployer }: {
    pensionEmployee: string | null | undefined;
    pensionEmployer: string | null | undefined;
    contributionEmployer: string | null | undefined;
}) {
    const pensionRows = [
        { label: '男子(第1種)' },
        { label: '女子(第2種)' },
        { label: '坑内夫(第3種)' },
    ];
    return (
        <div className="overflow-x-auto rounded-lg border border-gray-100">
            <table className="min-w-full divide-y divide-gray-100">
                <thead className="bg-gray-50">
                    <tr>
                        <th className={thClass}>/1,000</th>
                        <th className={thClass}>被保険者負担</th>
                        <th className={thClass}>事業主負担</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                    {pensionRows.map((r) => (
                        <tr key={r.label}>
                            <td className={`${tdClass} font-medium text-gray-800`}>{r.label}</td>
                            <td className={tdClass}>{fmtPermille(pensionEmployee)}</td>
                            <td className={tdClass}>{fmtPermille(pensionEmployer)}</td>
                        </tr>
                    ))}
                    <tr>
                        <td className={`${tdClass} font-medium text-gray-800`}>子ども・子育て拠出金</td>
                        <td className={tdClass}>{fmtPermille(0)}</td>
                        <td className={tdClass}>{fmtPermille(contributionEmployer)}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}

/** MF準拠: 社会保険セクションの編集モーダル。 */
function SocialInsuranceEditModal({ loc, section, options, onClose }: {
    loc: Location;
    section: SocialSection;
    options: Props['options'];
    onClose: () => void;
}) {
    const set = loc.insurance_rate_sets[0];
    const rateOf = (kind: string) => set?.rates.find((r) => r.kind === kind);
    const kinds = section === 'health' ? HEALTH_KINDS : PENSION_KINDS;

    const [healthType, setHealthType] = useState(loc.health_insurance_type);
    const [meta, setMeta] = useState({
        prefecture: loc.prefecture ?? '',
        health_union_name: loc.health_union_name ?? '',
        health_office_symbol: loc.health_office_symbol ?? '',
        pension_jurisdiction: loc.pension_jurisdiction ?? '',
        pension_office_number: loc.pension_office_number ?? '',
        pension_office_symbol: loc.pension_office_symbol ?? '',
        pension_fund_name: loc.pension_fund_name ?? '',
        pension_fund_number: loc.pension_fund_number ?? '',
        pension_fund_office_number: loc.pension_fund_office_number ?? '',
    });
    const [rates, setRates] = useState<Record<string, { employee_rate: string; employer_rate: string }>>(() => {
        const obj: Record<string, { employee_rate: string; employer_rate: string }> = {};
        kinds.forEach((k) => {
            const r = rateOf(k);
            obj[k] = { employee_rate: String(r?.employee_rate ?? '0'), employer_rate: String(r?.employer_rate ?? '0') };
        });
        return obj;
    });
    const [processing, setProcessing] = useState(false);

    const setMetaField = (k: keyof typeof meta, v: string) => setMeta((m) => ({ ...m, [k]: v }));
    const setRate = (kind: string, field: 'employee_rate' | 'employer_rate', v: string) =>
        setRates((prev) => ({ ...prev, [kind]: { ...prev[kind], [field]: v } }));

    const submit = () => {
        setProcessing(true);
        const payload: Record<string, unknown> = { section, rates };
        if (section === 'health') {
            payload.health_insurance_type = healthType;
            payload.prefecture = meta.prefecture;
            payload.health_union_name = meta.health_union_name;
            payload.health_office_symbol = meta.health_office_symbol;
        } else if (section === 'pension') {
            payload.pension_jurisdiction = meta.pension_jurisdiction;
            payload.pension_office_number = meta.pension_office_number;
            payload.pension_office_symbol = meta.pension_office_symbol;
        }
        router.put(route('admin.payroll.settings.locations.social-insurance', loc.id), payload as never, {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => setProcessing(false),
        });
    };

    const titleMap: Record<SocialSection, string> = { health: '健康保険', pension: '厚生年金保険' };
    const fieldClass = 'w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500';
    const labelClass = 'mb-1 block text-xs font-medium text-gray-500';
    const rateInputClass = 'w-28 rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
            <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                    <h3 className="text-sm font-bold text-gray-800">{titleMap[section]}の編集 <span className="ml-2 font-normal text-gray-400">{loc.name}</span></h3>
                    <button onClick={onClose} className="rounded px-1.5 py-0.5 text-gray-400 hover:bg-gray-100"><i className="fa-solid fa-xmark" /></button>
                </div>

                <div className="space-y-4 px-5 py-4">
                    {section === 'health' && (
                        <>
                            <div>
                                <label className={labelClass}>管掌区分</label>
                                <select value={healthType} onChange={(e) => setHealthType(e.target.value)} className={fieldClass}>
                                    {Object.entries(options.healthInsuranceTypes).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                </select>
                            </div>
                            {healthType === 'kyokai' && (
                                <div>
                                    <label className={labelClass}>管轄（都道府県）</label>
                                    <select value={meta.prefecture} onChange={(e) => setMetaField('prefecture', e.target.value)} className={fieldClass}>
                                        <option value="">未設定</option>
                                        {options.prefectures.map((p) => <option key={p} value={p}>{p}</option>)}
                                    </select>
                                    <p className="mt-1 text-[11px] text-gray-400">保存後、健康保険セクションの「都道府県料率を反映」で協会けんぽ料率を自動セットできます。</p>
                                </div>
                            )}
                            {healthType === 'kumiai' && (
                                <div>
                                    <label className={labelClass}>組合名</label>
                                    <input value={meta.health_union_name} onChange={(e) => setMetaField('health_union_name', e.target.value)} className={fieldClass} />
                                </div>
                            )}
                            <div>
                                <label className={labelClass}>事業所整理記号（任意）</label>
                                <input value={meta.health_office_symbol} onChange={(e) => setMetaField('health_office_symbol', e.target.value)} className={fieldClass} />
                            </div>
                            {healthType === 'kokuho' ? (
                                <p className="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500">国民健康保険組合を選択した場合、保険料は従業員情報で固定金額を設定します。</p>
                            ) : (
                                <div className="space-y-2 rounded-lg border border-gray-100 p-3">
                                    <p className="text-xs font-semibold text-gray-600">保険料率（/1,000）</p>
                                    {HEALTH_KINDS.map((k) => (
                                        <div key={k} className="flex items-center gap-3">
                                            <span className="w-40 text-xs text-gray-700">{options.insuranceKinds[k] ?? k}</span>
                                            <label className="text-[11px] text-gray-400">被保険者</label>
                                            <input type="number" step="0.001" min="0" className={rateInputClass}
                                                value={rates[k]?.employee_rate ?? '0'} onChange={(e) => setRate(k, 'employee_rate', e.target.value)} />
                                            <label className="text-[11px] text-gray-400">事業主</label>
                                            <input type="number" step="0.001" min="0" className={rateInputClass}
                                                value={rates[k]?.employer_rate ?? '0'} onChange={(e) => setRate(k, 'employer_rate', e.target.value)} />
                                        </div>
                                    ))}
                                </div>
                            )}
                        </>
                    )}

                    {section === 'pension' && (
                        <>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div>
                                    <label className={labelClass}>管轄</label>
                                    <input value={meta.pension_jurisdiction} onChange={(e) => setMetaField('pension_jurisdiction', e.target.value)} className={fieldClass} />
                                </div>
                                <div>
                                    <label className={labelClass}>事業所番号</label>
                                    <input value={meta.pension_office_number} onChange={(e) => setMetaField('pension_office_number', e.target.value)} className={fieldClass} />
                                </div>
                                <div>
                                    <label className={labelClass}>事業所整理番号</label>
                                    <input value={meta.pension_office_symbol} onChange={(e) => setMetaField('pension_office_symbol', e.target.value)} className={fieldClass} />
                                </div>
                            </div>
                            <div className="space-y-2 rounded-lg border border-gray-100 p-3">
                                <p className="text-xs font-semibold text-gray-600">保険料率（/1,000）</p>
                                <div className="flex items-center gap-3">
                                    <span className="w-40 text-xs text-gray-700">厚生年金保険</span>
                                    <label className="text-[11px] text-gray-400">被保険者</label>
                                    <input type="number" step="0.001" min="0" className={rateInputClass}
                                        value={rates.pension?.employee_rate ?? '0'} onChange={(e) => setRate('pension', 'employee_rate', e.target.value)} />
                                    <label className="text-[11px] text-gray-400">事業主</label>
                                    <input type="number" step="0.001" min="0" className={rateInputClass}
                                        value={rates.pension?.employer_rate ?? '0'} onChange={(e) => setRate('pension', 'employer_rate', e.target.value)} />
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className="w-40 text-xs text-gray-700">子ども・子育て拠出金</span>
                                    <label className="text-[11px] text-gray-400">被保険者</label>
                                    <input type="number" className={`${rateInputClass} bg-gray-50 text-gray-400`} value="0" disabled />
                                    <label className="text-[11px] text-gray-400">事業主</label>
                                    <input type="number" step="0.001" min="0" className={rateInputClass}
                                        value={rates.child_contribution?.employer_rate ?? '0'} onChange={(e) => setRate('child_contribution', 'employer_rate', e.target.value)} />
                                </div>
                                <p className="text-[11px] text-gray-400">厚生年金保険料率は男子(第1種)・女子(第2種)・坑内夫(第3種)で共通です。子ども・子育て拠出金は事業主全額負担です。</p>
                            </div>
                        </>
                    )}

                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 bg-gray-50/50 px-5 py-3">
                    <button onClick={onClose} className="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">キャンセル</button>
                    <button onClick={submit} disabled={processing}
                        className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-5 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50">
                        <i className="fa-solid fa-floppy-disk" /> 保存する
                    </button>
                </div>
            </div>
        </div>
    );
}

/* ==================== 労働保険タブ（MFクラウド準拠） ==================== */

type LaborSection = 'accident' | 'employment';

const LABOR_BUREAU_SUFFIX = '労働基準監督署';
const EMPLOYMENT_BUREAU_SUFFIX = 'ハローワーク（公共職業安定所）';

function stripBureauSuffix(value: string | null | undefined, suffix: string): string {
    if (!value) return '';
    const trimmed = value.trim();
    if (trimmed.endsWith(suffix)) {
        return trimmed.slice(0, -suffix.length).trim();
    }
    return trimmed;
}

function withBureauSuffix(prefix: string, suffix: string): string {
    const p = prefix.trim();
    if (!p) return '';
    return `${p} ${suffix}`;
}

function splitEmploymentOfficeNumber(num: string | null | undefined): [string, string, string] {
    const digits = (num ?? '').replace(/\D/g, '');
    if (!digits) return ['', '', ''];
    return [digits.slice(0, 4), digits.slice(4, 10), digits.slice(10)];
}

function composeEmploymentOfficeNumber(p1: string, p2: string, p3: string): string {
    return `${p1}${p2}${p3}`.replace(/\D/g, '');
}

const laborModalLabelClass = 'w-44 shrink-0 bg-gray-50 px-4 py-3 text-left text-xs font-medium text-gray-600 align-middle';
const laborModalCellClass = 'px-4 py-3 align-middle';
const laborModalInputClass = 'rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500';

function AccidentInsuranceEditModal({ loc, options, onClose }: {
    loc: Location;
    options: Props['options'];
    onClose: () => void;
}) {
    const [laborBureau, setLaborBureau] = useState(stripBureauSuffix(loc.labor_bureau, LABOR_BUREAU_SUFFIX));
    const [prefCode, setPrefCode] = useState(loc.labor_insurance_pref_code ?? '');
    const [jurisdictionCode, setJurisdictionCode] = useState(loc.labor_insurance_jurisdiction_code ?? '');
    const [officeCode, setOfficeCode] = useState(loc.labor_insurance_office_code ?? '');
    const [serialNumber, setSerialNumber] = useState(loc.labor_insurance_serial_number ?? '');
    const [branchCode, setBranchCode] = useState(loc.labor_insurance_branch_code ?? '');
    const [businessDesc, setBusinessDesc] = useState(loc.accident_business_desc ?? '');
    const [industryCode, setIndustryCode] = useState(loc.accident_industry_code ?? '');
    const [meritEnabled, setMeritEnabled] = useState(!!loc.accident_merit_enabled);
    const [meritRate, setMeritRate] = useState(loc.accident_merit_rate ?? '');
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        setProcessing(true);
        router.put(route('admin.payroll.settings.locations.labor-insurance', loc.id), {
            section: 'accident',
            labor_bureau: withBureauSuffix(laborBureau, LABOR_BUREAU_SUFFIX) || null,
            labor_insurance_pref_code: prefCode || null,
            labor_insurance_jurisdiction_code: jurisdictionCode || null,
            labor_insurance_office_code: officeCode || null,
            labor_insurance_serial_number: serialNumber.replace(/\D/g, '') || null,
            labor_insurance_branch_code: branchCode || null,
            accident_business_desc: businessDesc || null,
            accident_industry_code: industryCode || null,
            accident_merit_enabled: meritEnabled,
            accident_merit_rate: meritEnabled ? meritRate : null,
        } as never, {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => setProcessing(false),
        });
    };

    const numInput = (value: string, onChange: (v: string) => void, maxLen: number, width: string) => (
        <input value={value} onChange={(e) => onChange(e.target.value.replace(/\D/g, '').slice(0, maxLen))}
            inputMode="numeric" maxLength={maxLen} className={`${laborModalInputClass} ${width} text-center`} />
    );

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
            <div className="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-xl bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                    <h3 className="text-sm font-bold text-gray-800">労災保険</h3>
                    <button onClick={onClose} className="rounded px-1.5 py-0.5 text-gray-400 hover:bg-gray-100"><i className="fa-solid fa-xmark" /></button>
                </div>

                <table className="min-w-full text-sm">
                    <tbody className="divide-y divide-gray-100">
                        <tr>
                            <th className={laborModalLabelClass}>管轄</th>
                            <td className={laborModalCellClass}>
                                <div className="flex flex-wrap items-center gap-2">
                                    <input value={laborBureau} onChange={(e) => setLaborBureau(e.target.value)}
                                        placeholder="例: 荒川" className={`${laborModalInputClass} w-32`} />
                                    <span className="text-xs text-gray-500">{LABOR_BUREAU_SUFFIX}</span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th className={laborModalLabelClass}>労働保険番号</th>
                            <td className={laborModalCellClass}>
                                <div className="flex flex-wrap items-center gap-1">
                                    {numInput(prefCode, setPrefCode, 2, 'w-12')}
                                    <span className="text-gray-300">-</span>
                                    {numInput(jurisdictionCode, setJurisdictionCode, 1, 'w-10')}
                                    <span className="text-gray-300">-</span>
                                    {numInput(officeCode, setOfficeCode, 2, 'w-12')}
                                    <span className="text-gray-300">-</span>
                                    {numInput(serialNumber, setSerialNumber, 6, 'w-24')}
                                    <span className="text-gray-400">-</span>
                                    {numInput(branchCode, setBranchCode, 3, 'w-14')}
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th className={laborModalLabelClass}>具体的な業務又は作業の内容</th>
                            <td className={laborModalCellClass}>
                                <input value={businessDesc} onChange={(e) => setBusinessDesc(e.target.value)}
                                    placeholder="例: 飲食店" className={`${laborModalInputClass} w-full`} />
                            </td>
                        </tr>
                        <tr>
                            <th className={laborModalLabelClass}>労災保険料率用業種</th>
                            <td className={laborModalCellClass}>
                                <select value={industryCode} onChange={(e) => setIndustryCode(e.target.value)} className={`${laborModalInputClass} w-full`}>
                                    <option value="">未設定</option>
                                    {Object.entries(options.accidentIndustries).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th className={laborModalLabelClass}>
                                <span className="inline-flex items-center gap-1">
                                    メリット制 (/1,000)
                                    <i className="fa-regular fa-circle-question text-gray-300" title="メリット制適用時は業種料率の代わりにこちらの料率を使用します" />
                                </span>
                            </th>
                            <td className={laborModalCellClass}>
                                <div className="flex flex-wrap items-center gap-3">
                                    <label className="inline-flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" checked={meritEnabled} onChange={(e) => setMeritEnabled(e.target.checked)}
                                            className="rounded border-gray-300 text-teal-600 focus:ring-teal-500" />
                                        適用あり
                                    </label>
                                    <input value={meritRate} disabled={!meritEnabled}
                                        onChange={(e) => setMeritRate(e.target.value.replace(/[^0-9.]/g, ''))}
                                        inputMode="decimal" placeholder="0.000" className={`${laborModalInputClass} w-24 text-right disabled:bg-gray-50`} />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div className="flex justify-center gap-3 border-t border-gray-100 bg-gray-50/50 px-5 py-4">
                    <button onClick={submit} disabled={processing}
                        className="inline-flex min-w-[120px] items-center justify-center rounded-lg bg-amber-400 px-6 py-2 text-sm font-semibold text-gray-900 hover:bg-amber-500 disabled:opacity-50">
                        更新する
                    </button>
                    <button onClick={onClose}
                        className="inline-flex min-w-[120px] items-center justify-center rounded-lg border border-gray-300 bg-white px-6 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        キャンセル
                    </button>
                </div>
            </div>
        </div>
    );
}

function EmploymentInsuranceEditModal({ loc, options, onClose }: {
    loc: Location;
    options: Props['options'];
    onClose: () => void;
}) {
    const [empBureau, setEmpBureau] = useState(stripBureauSuffix(loc.employment_bureau, EMPLOYMENT_BUREAU_SUFFIX));
    const [officeParts, setOfficeParts] = useState(() => splitEmploymentOfficeNumber(loc.employment_office_number));
    const [industryType, setIndustryType] = useState(loc.employment_industry_type ?? '');
    const [processing, setProcessing] = useState(false);

    const setOfficePart = (idx: 0 | 1 | 2, v: string) => {
        const maxLens = [4, 6, 3] as const;
        setOfficeParts((prev) => {
            const next: [string, string, string] = [...prev] as [string, string, string];
            next[idx] = v.replace(/\D/g, '').slice(0, maxLens[idx]);
            return next;
        });
    };

    const submit = () => {
        setProcessing(true);
        router.put(route('admin.payroll.settings.locations.labor-insurance', loc.id), {
            section: 'employment',
            employment_bureau: withBureauSuffix(empBureau, EMPLOYMENT_BUREAU_SUFFIX) || null,
            employment_office_number: composeEmploymentOfficeNumber(...officeParts) || null,
            employment_industry_type: industryType || null,
        } as never, {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
            <div className="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-xl bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                    <h3 className="text-sm font-bold text-gray-800">雇用保険</h3>
                    <button onClick={onClose} className="rounded px-1.5 py-0.5 text-gray-400 hover:bg-gray-100"><i className="fa-solid fa-xmark" /></button>
                </div>

                <table className="min-w-full text-sm">
                    <tbody className="divide-y divide-gray-100">
                        <tr>
                            <th className={laborModalLabelClass}>管轄</th>
                            <td className={laborModalCellClass}>
                                <div className="flex flex-wrap items-center gap-2">
                                    <input value={empBureau} onChange={(e) => setEmpBureau(e.target.value)}
                                        placeholder="例: 荒川" className={`${laborModalInputClass} w-32`} />
                                    <span className="text-xs text-gray-500">{EMPLOYMENT_BUREAU_SUFFIX}</span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th className={laborModalLabelClass}>事業所番号</th>
                            <td className={laborModalCellClass}>
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <input value={officeParts[0]} onChange={(e) => setOfficePart(0, e.target.value)}
                                        inputMode="numeric" maxLength={4} className={`${laborModalInputClass} w-16 text-center`} />
                                    <input value={officeParts[1]} onChange={(e) => setOfficePart(1, e.target.value)}
                                        inputMode="numeric" maxLength={6} className={`${laborModalInputClass} w-24 text-center`} />
                                    <input value={officeParts[2]} onChange={(e) => setOfficePart(2, e.target.value)}
                                        inputMode="numeric" maxLength={3} className={`${laborModalInputClass} w-12 text-center`} />
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th className={laborModalLabelClass}>雇用保険料率用業種</th>
                            <td className={laborModalCellClass}>
                                <select value={industryType} onChange={(e) => setIndustryType(e.target.value)} className={`${laborModalInputClass} w-full`}>
                                    <option value="">未設定</option>
                                    {Object.entries(options.employmentIndustries).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div className="flex justify-center gap-3 border-t border-gray-100 bg-gray-50/50 px-5 py-4">
                    <button onClick={submit} disabled={processing}
                        className="inline-flex min-w-[120px] items-center justify-center rounded-lg bg-amber-400 px-6 py-2 text-sm font-semibold text-gray-900 hover:bg-amber-500 disabled:opacity-50">
                        更新する
                    </button>
                    <button onClick={onClose}
                        className="inline-flex min-w-[120px] items-center justify-center rounded-lg border border-gray-300 bg-white px-6 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        キャンセル
                    </button>
                </div>
            </div>
        </div>
    );
}

function LaborInsuranceTab({ locs, options, canWrite, patchRate, deleteRow, saveInsurance, processing }: {
    locs: Location[];
    options: Props['options'];
    canWrite: boolean;
    patchRate: (locId: number, rateId: number, patch: Partial<RateRow>) => void;
    deleteRow: (url: string, msg: string) => void;
    saveInsurance: () => void;
    processing: boolean;
}) {
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [editSection, setEditSection] = useState<LaborSection | null>(null);
    const [laborManualEdit, setLaborManualEdit] = useState(false);

    const loc = locs.find((l) => l.id === selectedId) ?? locs[0] ?? null;
    const set = loc?.insurance_rate_sets[0];
    const accident = set?.rates.find((r) => r.kind === 'accident');
    const employment = set?.rates.find((r) => r.kind === 'employment');
    const accidentEmployer = loc?.accident_merit_enabled && loc.accident_merit_rate
        ? loc.accident_merit_rate
        : accident?.employer_rate;
    const empEmployee = employment?.employee_rate;
    const empEmployer = employment?.employer_rate;
    const empTotal = employment
        ? parseFloat(employment.employee_rate || '0') + parseFloat(employment.employer_rate || '0')
        : null;
    const accidentLabel = loc?.accident_industry_code ? (options.accidentIndustries[loc.accident_industry_code] ?? loc.accident_industry_code) : '未設定';
    const employmentLabel = loc?.employment_industry_type ? (options.employmentIndustries[loc.employment_industry_type] ?? loc.employment_industry_type) : '未設定';
    const editBtnClass = 'inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-600 transition hover:bg-gray-50';

    return (
        <div className="space-y-6">
            <div className="flex items-start justify-between gap-3">
                <p className="text-xs text-gray-500">
                    労災保険・雇用保険の情報と料率を事業所ごとに管理します。料率は編集で業種（メリット制含む）を選ぶと自動セットされます（<span className="font-semibold">/1,000（千分率）</span>）。
                </p>
                {canWrite && (
                    <button type="button" onClick={() => setLaborManualEdit((v) => !v)}
                        className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-50">
                        <i className="fa-solid fa-sliders" /> {laborManualEdit ? '料率手修正を閉じる' : '料率を手修正'}
                    </button>
                )}
            </div>

            {locs.length === 0 && (
                <div className={`${cardClass} px-4 py-8 text-center text-sm text-gray-400`}>事業所が登録されていません。先に「事業所」タブで登録してください。</div>
            )}

            {locs.length > 0 && loc && (
                <>
                    <div className="flex flex-wrap items-center gap-3">
                        <select className={`${selectClass} min-w-[220px] text-base font-semibold text-gray-800`}
                            value={String(loc.id)} onChange={(e) => setSelectedId(Number(e.target.value))}>
                            {locs.map((l) => (
                                <option key={l.id} value={String(l.id)}>{l.name}{l.is_main ? '（本社）' : ''}</option>
                            ))}
                        </select>
                        {set && (
                            <span className="text-xs text-gray-400">
                                料率セット: {set.name}（{set.effective_from}〜{set.effective_to ?? '現行'}）
                            </span>
                        )}
                    </div>

                    <div className="space-y-4">
                        {/* 労災保険情報 */}
                        <div className={cardClass}>
                            <div className="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                                <h4 className="text-sm font-bold text-gray-700">労災保険情報</h4>
                                {canWrite && (
                                    <button onClick={() => setEditSection('accident')} className={editBtnClass}>
                                        <i className="fa-solid fa-pen" /> 編集
                                    </button>
                                )}
                            </div>
                            <table className="min-w-full table-fixed text-sm">
                                <tbody className="divide-y divide-gray-100">
                                    <tr>
                                        <th className="w-56 bg-gray-50 px-4 py-2.5 text-left font-medium text-gray-600">管轄</th>
                                        <td className="px-4 py-2.5 text-gray-800">{loc.labor_bureau || '—'}</td>
                                    </tr>
                                    <tr>
                                        <th className="bg-gray-50 px-4 py-2.5 text-left font-medium text-gray-600">労働保険番号</th>
                                        <td className="px-4 py-2.5 text-gray-800">{loc.labor_insurance_number || '—'}</td>
                                    </tr>
                                    <tr>
                                        <th className="bg-gray-50 px-4 py-2.5 text-left font-medium text-gray-600">具体的な業務又は作業の内容</th>
                                        <td className="px-4 py-2.5 text-gray-800">{loc.accident_business_desc || '—'}</td>
                                    </tr>
                                    <tr>
                                        <th className="bg-gray-50 px-4 py-2.5 text-left font-medium text-gray-600">労災保険業種</th>
                                        <td className="px-4 py-2.5 text-gray-800">{accidentLabel}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <div className="px-4 pt-3 text-xs text-gray-400">労災保険料率</div>
                            <table className="mt-1 min-w-full table-fixed text-sm">
                                <tbody className="divide-y divide-gray-100">
                                    <tr className="bg-slate-50">
                                        <th className="w-56 px-4 py-2 text-left font-semibold text-gray-600">/1,000</th>
                                        <td className="px-4 py-2 font-semibold text-gray-700">
                                            {accidentLabel}
                                            {loc.accident_merit_enabled && <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">メリット制</span>}
                                        </td>
                                    </tr>
                                    <tr>
                                        <th className="bg-gray-50 px-4 py-2.5 text-left font-medium text-gray-600">事業主</th>
                                        <td className="px-4 py-2.5 text-gray-800">{accidentEmployer != null ? fmtPermille(accidentEmployer) : '—'}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        {/* 雇用保険情報 */}
                        <div className={cardClass}>
                            <div className="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                                <h4 className="text-sm font-bold text-gray-700">雇用保険情報</h4>
                                {canWrite && (
                                    <button onClick={() => setEditSection('employment')} className={editBtnClass}>
                                        <i className="fa-solid fa-pen" /> 編集
                                    </button>
                                )}
                            </div>
                            <table className="min-w-full table-fixed text-sm">
                                <tbody className="divide-y divide-gray-100">
                                    <tr>
                                        <th className="w-56 bg-gray-50 px-4 py-2.5 text-left font-medium text-gray-600">管轄</th>
                                        <td className="px-4 py-2.5 text-gray-800">{loc.employment_bureau || '—'}</td>
                                    </tr>
                                    <tr>
                                        <th className="bg-gray-50 px-4 py-2.5 text-left font-medium text-gray-600">事業所番号</th>
                                        <td className="px-4 py-2.5 text-gray-800">{loc.employment_office_number || '—'}</td>
                                    </tr>
                                    <tr>
                                        <th className="bg-gray-50 px-4 py-2.5 text-left font-medium text-gray-600">雇用保険料率用業種</th>
                                        <td className="px-4 py-2.5 text-gray-800">{employmentLabel}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <div className="px-4 pt-3 text-xs text-gray-400">雇用保険料率</div>
                            <table className="mt-1 min-w-full table-fixed text-sm">
                                <tbody className="divide-y divide-gray-100">
                                    <tr className="bg-slate-50">
                                        <th className="w-56 px-4 py-2 text-left font-semibold text-gray-600">/1,000</th>
                                        <td className="px-4 py-2 font-semibold text-gray-700">{employmentLabel}</td>
                                    </tr>
                                    <tr>
                                        <th className="bg-gray-50 px-4 py-2.5 text-left font-medium text-gray-600">従業員</th>
                                        <td className="px-4 py-2.5 text-gray-800">{empEmployee != null ? fmtPermille(empEmployee) : '—'}</td>
                                    </tr>
                                    <tr>
                                        <th className="bg-gray-50 px-4 py-2.5 text-left font-medium text-gray-600">事業主</th>
                                        <td className="px-4 py-2.5 text-gray-800">{empEmployer != null ? fmtPermille(empEmployer) : '—'}</td>
                                    </tr>
                                    <tr>
                                        <th className="bg-gray-50 px-4 py-2.5 text-left font-medium text-gray-600">合計</th>
                                        <td className="px-4 py-2.5 font-semibold text-gray-900">{empTotal != null ? fmtPermille(empTotal) : '—'}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        {!set && (
                            <p className="px-1 text-xs text-amber-600">料率セットが未登録です。「社会保険」タブで料率セットを追加すると、業種に応じた労災・雇用料率が自動反映されます。</p>
                        )}

                        {laborManualEdit && set && (
                            <RateEditorCard loc={loc} kinds={LABOR_KINDS} options={options}
                                canWrite={canWrite} patchRate={patchRate} deleteRow={deleteRow} showSetDelete={false} />
                        )}
                    </div>
                </>
            )}

            {canWrite && laborManualEdit && locs.some((l) => l.insurance_rate_sets[0]) && (
                <div className="flex justify-end">
                    <button type="button" onClick={saveInsurance} disabled={processing}
                        className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                        <i className="fa-solid fa-floppy-disk" />
                        料率を保存する
                    </button>
                </div>
            )}

            {editSection === 'accident' && loc && (
                <AccidentInsuranceEditModal loc={loc} options={options} onClose={() => setEditSection(null)} />
            )}
            {editSection === 'employment' && loc && (
                <EmploymentInsuranceEditModal loc={loc} options={options} onClose={() => setEditSection(null)} />
            )}
        </div>
    );
}

/* ---------- 厚生年金基金（複数登録可・給与/賞与別料率） ---------- */

interface PensionRateDraft {
    effective_from: string; // 'YYYY-MM'
    salary_employee_rate: string;
    salary_employer_rate: string;
    bonus_employee_rate: string;
    bonus_employer_rate: string;
}

function PensionFundSection({ loc, canWrite, deleteRow }: {
    loc: Location;
    canWrite: boolean;
    deleteRow: (url: string, msg: string) => void;
}) {
    const [editing, setEditing] = useState<PensionFundRow | 'new' | null>(null);
    const funds = loc.pension_funds ?? [];

    const sectionHeaderClass = 'flex items-center justify-between border-b border-gray-100 px-4 py-3';
    const editBtnClass = 'inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-50';

    const monthLabel = (d: string) => (d ? d.slice(0, 7).replace('-', '/') : '');

    return (
        <div className={cardClass}>
            <div className={sectionHeaderClass}>
                <h3 className="text-sm font-bold text-gray-800">厚生年金基金</h3>
                {canWrite && (
                    <button onClick={() => setEditing('new')} className={editBtnClass}>
                        <i className="fa-solid fa-plus" /> 基金を追加
                    </button>
                )}
            </div>
            <div className="space-y-3 px-4 py-3">
                {funds.length === 0 ? (
                    <div className="rounded-lg bg-gray-50 px-4 py-4 text-xs text-gray-500">
                        <p>厚生年金基金情報がありません。</p>
                        <p>「基金を追加」から厚生年金基金を登録してください。</p>
                    </div>
                ) : (
                    funds.map((fund) => (
                        <div key={fund.id} className="rounded-xl border border-gray-100">
                            <div className="flex items-center justify-between border-b border-gray-100 px-3 py-2">
                                <div className="text-sm font-semibold text-gray-800">{fund.name}</div>
                                {canWrite && (
                                    <div className="flex items-center gap-2">
                                        <button onClick={() => setEditing(fund)} className={editBtnClass}>
                                            <i className="fa-solid fa-pen" /> 編集
                                        </button>
                                        <button
                                            onClick={() => deleteRow(route('admin.payroll.settings.pension-funds.destroy', fund.id), `厚生年金基金「${fund.name}」を削除しますか？`)}
                                            className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-500 hover:bg-red-50">
                                            <i className="fa-solid fa-trash-can" />
                                        </button>
                                    </div>
                                )}
                            </div>
                            <div className="px-3 py-3">
                                {(fund.number || fund.office_number) && (
                                    <dl className="mb-3 flex flex-wrap gap-x-8 gap-y-1 text-xs">
                                        {fund.number && <div><dt className="inline text-gray-400">基金番号：</dt><dd className="inline font-medium text-gray-700">{fund.number}</dd></div>}
                                        {fund.office_number && <div><dt className="inline text-gray-400">基金の事業所番号：</dt><dd className="inline font-medium text-gray-700">{fund.office_number}</dd></div>}
                                    </dl>
                                )}
                                {fund.rates.length === 0 ? (
                                    <p className="text-xs text-gray-400">掛金料率が未登録です。</p>
                                ) : (
                                    <div className="overflow-x-auto rounded-lg border border-gray-100">
                                        <table className="min-w-full divide-y divide-gray-100 text-center">
                                            <thead className="bg-gray-50">
                                                <tr>
                                                    <th className={thClass} rowSpan={2}>適用開始月</th>
                                                    <th className={thClass} colSpan={2}>給与（/1,000）</th>
                                                    <th className={thClass} colSpan={2}>賞与（/1,000）</th>
                                                </tr>
                                                <tr>
                                                    <th className={thClass}>被保険者負担</th>
                                                    <th className={thClass}>事業主負担</th>
                                                    <th className={thClass}>被保険者負担</th>
                                                    <th className={thClass}>事業主負担</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-100">
                                                {fund.rates.map((r) => (
                                                    <tr key={r.id}>
                                                        <td className={`${tdClass} font-medium text-gray-800`}>{monthLabel(r.effective_from)}</td>
                                                        <td className={tdClass}>{fmtPermille(r.salary_employee_rate)}</td>
                                                        <td className={tdClass}>{fmtPermille(r.salary_employer_rate)}</td>
                                                        <td className={tdClass}>{fmtPermille(r.bonus_employee_rate)}</td>
                                                        <td className={tdClass}>{fmtPermille(r.bonus_employer_rate)}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        </div>
                    ))
                )}
            </div>

            {editing && (
                <PensionFundEditModal
                    loc={loc}
                    fund={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}

function PensionFundEditModal({ loc, fund, onClose }: {
    loc: Location;
    fund: PensionFundRow | null;
    onClose: () => void;
}) {
    const currentMonth = new Date().toISOString().slice(0, 7);
    const [name, setName] = useState(fund?.name ?? '');
    const [number, setNumber] = useState(fund?.number ?? '');
    const [officeNumber, setOfficeNumber] = useState(fund?.office_number ?? '');
    const [rates, setRates] = useState<PensionRateDraft[]>(() =>
        fund && fund.rates.length > 0
            ? fund.rates.map((r) => ({
                effective_from: r.effective_from.slice(0, 7),
                salary_employee_rate: String(r.salary_employee_rate ?? '0'),
                salary_employer_rate: String(r.salary_employer_rate ?? '0'),
                bonus_employee_rate: String(r.bonus_employee_rate ?? '0'),
                bonus_employer_rate: String(r.bonus_employer_rate ?? '0'),
            }))
            : [{ effective_from: currentMonth, salary_employee_rate: '0.0', salary_employer_rate: '0.0', bonus_employee_rate: '0.0', bonus_employer_rate: '0.0' }],
    );
    const [processing, setProcessing] = useState(false);

    const setRateField = (idx: number, field: keyof PensionRateDraft, v: string) =>
        setRates((prev) => prev.map((r, i) => (i === idx ? { ...r, [field]: v } : r)));
    const addRate = () =>
        setRates((prev) => [...prev, { effective_from: currentMonth, salary_employee_rate: '0.0', salary_employer_rate: '0.0', bonus_employee_rate: '0.0', bonus_employer_rate: '0.0' }]);
    const removeRate = (idx: number) => setRates((prev) => (prev.length <= 1 ? prev : prev.filter((_, i) => i !== idx)));

    const submit = () => {
        setProcessing(true);
        const payload = {
            business_location_id: loc.id,
            name,
            number,
            office_number: officeNumber,
            rates: rates.map((r) => ({
                effective_from: `${r.effective_from}-01`,
                salary_employee_rate: r.salary_employee_rate === '' ? 0 : r.salary_employee_rate,
                salary_employer_rate: r.salary_employer_rate === '' ? 0 : r.salary_employer_rate,
                bonus_employee_rate: r.bonus_employee_rate === '' ? 0 : r.bonus_employee_rate,
                bonus_employer_rate: r.bonus_employer_rate === '' ? 0 : r.bonus_employer_rate,
            })),
        };
        const opts = { preserveScroll: true, onSuccess: onClose, onFinish: () => setProcessing(false) };
        if (fund) {
            router.put(route('admin.payroll.settings.pension-funds.update', fund.id), payload as never, opts);
        } else {
            router.post(route('admin.payroll.settings.pension-funds.store'), payload as never, opts);
        }
    };

    const fieldClass = 'w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500';
    const labelClass = 'mb-1 block text-xs font-medium text-gray-500';
    const rateInputClass = 'w-24 rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
            <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                    <h3 className="text-sm font-bold text-gray-800">厚生年金基金の{fund ? '編集' : '登録'} <span className="ml-2 font-normal text-gray-400">{loc.name}</span></h3>
                    <button onClick={onClose} className="rounded px-1.5 py-0.5 text-gray-400 hover:bg-gray-100"><i className="fa-solid fa-xmark" /></button>
                </div>

                <div className="space-y-4 px-5 py-4">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label className={labelClass}>基金名</label>
                            <input value={name} onChange={(e) => setName(e.target.value)} className={fieldClass} />
                        </div>
                        <div>
                            <label className={labelClass}>基金番号</label>
                            <input value={number} onChange={(e) => setNumber(e.target.value)} className={fieldClass} />
                        </div>
                        <div>
                            <label className={labelClass}>基金の事業所番号</label>
                            <input value={officeNumber} onChange={(e) => setOfficeNumber(e.target.value)} className={fieldClass} />
                        </div>
                    </div>

                    <div className="space-y-3">
                        <div className="flex items-center justify-between">
                            <p className="text-xs font-semibold text-gray-600">保険料率</p>
                            <button type="button" onClick={addRate} className="inline-flex items-center gap-1.5 text-xs font-medium text-teal-600 hover:text-teal-700">
                                <i className="fa-solid fa-plus" /> 保険料率を追加
                            </button>
                        </div>

                        {rates.map((r, idx) => (
                            <div key={idx} className="space-y-2 rounded-lg border border-gray-100 p-3">
                                <div className="flex items-center gap-3">
                                    <label className="text-xs text-gray-500">適用開始月</label>
                                    <input type="month" value={r.effective_from} onChange={(e) => setRateField(idx, 'effective_from', e.target.value)}
                                        className="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                                    {rates.length > 1 && (
                                        <button type="button" onClick={() => removeRate(idx)} className="ml-auto text-xs font-medium text-red-500 hover:text-red-600">
                                            <i className="fa-solid fa-trash-can mr-1" />削除
                                        </button>
                                    )}
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full text-center text-xs">
                                        <thead>
                                            <tr className="text-gray-400">
                                                <th className="py-1 font-medium">/1,000</th>
                                                <th className="py-1 font-medium">被保険者負担</th>
                                                <th className="py-1 font-medium">事業主負担</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td className="py-1 text-left font-medium text-gray-700">給与</td>
                                                <td className="py-1"><input type="number" step="0.001" min="0" className={rateInputClass}
                                                    value={r.salary_employee_rate} onChange={(e) => setRateField(idx, 'salary_employee_rate', e.target.value)} /></td>
                                                <td className="py-1"><input type="number" step="0.001" min="0" className={rateInputClass}
                                                    value={r.salary_employer_rate} onChange={(e) => setRateField(idx, 'salary_employer_rate', e.target.value)} /></td>
                                            </tr>
                                            <tr>
                                                <td className="py-1 text-left font-medium text-gray-700">賞与</td>
                                                <td className="py-1"><input type="number" step="0.001" min="0" className={rateInputClass}
                                                    value={r.bonus_employee_rate} onChange={(e) => setRateField(idx, 'bonus_employee_rate', e.target.value)} /></td>
                                                <td className="py-1"><input type="number" step="0.001" min="0" className={rateInputClass}
                                                    value={r.bonus_employer_rate} onChange={(e) => setRateField(idx, 'bonus_employer_rate', e.target.value)} /></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        ))}
                        <p className="text-[11px] text-gray-400">掛金料率を設定すると、厚生年金基金掛金が標準報酬月額から自動計算されます（控除項目マスタで有効化が必要です）。給与・賞与でそれぞれの料率が適用されます。</p>
                    </div>
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 bg-gray-50/50 px-5 py-3">
                    <button onClick={onClose} className="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">キャンセル</button>
                    <button onClick={submit} disabled={processing || name.trim() === ''}
                        className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-5 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50">
                        <i className="fa-solid fa-floppy-disk" /> 保存する
                    </button>
                </div>
            </div>
        </div>
    );
}

/* ============================ 全般タブ ============================ */
function GeneralTab({ general, closingDateGroups, jobTitles, leaveTypes, departments, options, canWrite }: {
    general: Record<string, string | null>;
    closingDateGroups: ClosingGroup[];
    jobTitles: JobTitleRow[];
    leaveTypes: LeaveTypeRow[];
    departments: { id: number; name: string }[];
    options: Props['options'];
    canWrite: boolean;
}) {
    const g = useForm({
        income_tax_calc_method: general.income_tax_calc_method ?? 'monthly_table',
        corporate_individual_number: general.corporate_individual_number ?? '',
        social_insurance_doc_submitter: general.social_insurance_doc_submitter ?? 'employer',
        tax_office_name: general.tax_office_name ?? '',
        tax_office_sign_number: general.tax_office_sign_number ?? '',
        tax_office_number: general.tax_office_number ?? '',
        employee_sort_key: general.employee_sort_key ?? 'join_date',
        employee_sort_direction: general.employee_sort_direction ?? 'asc',
        payment_account_bank_name: general.payment_account_bank_name ?? '',
        payment_account_branch_name: general.payment_account_branch_name ?? '',
        payment_account_type: general.payment_account_type ?? 'ordinary',
        payment_account_number: general.payment_account_number ?? '',
        payment_account_holder: general.payment_account_holder ?? '',
        payment_account_transfer_code: general.payment_account_transfer_code ?? '',
    });

    // 締め日グループ 新規入力
    const cg = useForm({ name: '', closing_day: 31, payment_day: 25, payment_month_offset: 1, sort_order: closingDateGroups.length });
    const jt = useForm({ name: '', sort_order: jobTitles.length });
    const lt = useForm({ name: '', leave_kind: 'other', pay_calc_method: 'all_zero', is_active: true, sort_order: leaveTypes.length });

    const field = 'w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500';
    const lbl = 'mb-1 block text-xs font-medium text-gray-500';
    const card = 'rounded-2xl bg-white shadow-sm ring-1 ring-gray-100';
    const del = (url: string, msg: string) => { if (window.confirm(msg)) router.delete(url, { preserveScroll: true }); };

    return (
        <div className="space-y-6">
            {/* 会社設定 */}
            <div className={`${card} p-5`}>
                <h3 className="mb-4 text-sm font-bold text-gray-800"><i className="fa-solid fa-sliders mr-2 text-teal-600" />会社の基本情報</h3>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label className={lbl}>源泉徴収税額の計算方法</label>
                        <select className={field} disabled={!canWrite} value={g.data.income_tax_calc_method} onChange={(e) => g.setData('income_tax_calc_method', e.target.value)}>
                            {Object.entries(options.incomeTaxMethods).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className={lbl}>個人番号又は法人番号</label>
                        <input className={field} disabled={!canWrite} value={g.data.corporate_individual_number} onChange={(e) => g.setData('corporate_individual_number', e.target.value)} />
                    </div>
                    <div>
                        <label className={lbl}>社会保険関係書類の提出元</label>
                        <select className={field} disabled={!canWrite} value={g.data.social_insurance_doc_submitter} onChange={(e) => g.setData('social_insurance_doc_submitter', e.target.value)}>
                            {Object.entries(options.docSubmitters).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className={lbl}>所轄税務署</label>
                        <input className={field} disabled={!canWrite} value={g.data.tax_office_name} onChange={(e) => g.setData('tax_office_name', e.target.value)} placeholder="例）新宿" />
                    </div>
                    <div>
                        <label className={lbl}>署番号</label>
                        <input className={field} disabled={!canWrite} value={g.data.tax_office_sign_number} onChange={(e) => g.setData('tax_office_sign_number', e.target.value)} />
                    </div>
                    <div>
                        <label className={lbl}>税務署番号</label>
                        <input className={field} disabled={!canWrite} value={g.data.tax_office_number} onChange={(e) => g.setData('tax_office_number', e.target.value)} />
                    </div>
                    <div>
                        <label className={lbl}>従業員の並び順に使用する情報</label>
                        <select className={field} disabled={!canWrite} value={g.data.employee_sort_key} onChange={(e) => g.setData('employee_sort_key', e.target.value)}>
                            {Object.entries(options.sortKeys).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className={lbl}>従業員の並び順</label>
                        <select className={field} disabled={!canWrite} value={g.data.employee_sort_direction} onChange={(e) => g.setData('employee_sort_direction', e.target.value)}>
                            {Object.entries(options.sortDirections).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                    </div>
                </div>

                <h4 className="mb-3 mt-6 text-xs font-bold uppercase tracking-wide text-gray-400">給与の支払口座</h4>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div><label className={lbl}>金融機関名</label><input className={field} disabled={!canWrite} value={g.data.payment_account_bank_name} onChange={(e) => g.setData('payment_account_bank_name', e.target.value)} /></div>
                    <div><label className={lbl}>支店名</label><input className={field} disabled={!canWrite} value={g.data.payment_account_branch_name} onChange={(e) => g.setData('payment_account_branch_name', e.target.value)} /></div>
                    <div>
                        <label className={lbl}>種別</label>
                        <select className={field} disabled={!canWrite} value={g.data.payment_account_type} onChange={(e) => g.setData('payment_account_type', e.target.value)}>
                            {Object.entries(options.accountTypes).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                    </div>
                    <div><label className={lbl}>口座番号</label><input className={field} disabled={!canWrite} value={g.data.payment_account_number} onChange={(e) => g.setData('payment_account_number', e.target.value)} /></div>
                    <div><label className={lbl}>口座名義</label><input className={field} disabled={!canWrite} value={g.data.payment_account_holder} onChange={(e) => g.setData('payment_account_holder', e.target.value)} /></div>
                    <div><label className={lbl}>振込依頼人コード</label><input className={field} disabled={!canWrite} value={g.data.payment_account_transfer_code} onChange={(e) => g.setData('payment_account_transfer_code', e.target.value)} /></div>
                </div>
                {canWrite && (
                    <div className="mt-4 flex justify-end">
                        <button onClick={() => g.put(route('admin.payroll.settings.general'), { preserveScroll: true })} disabled={g.processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                            <i className="fa-solid fa-floppy-disk" /> 保存する
                        </button>
                    </div>
                )}
            </div>

            {/* 締め日グループ */}
            <div className={card}>
                <div className="border-b border-gray-100 px-5 py-3.5"><h3 className="text-sm font-bold text-gray-800"><i className="fa-solid fa-calendar-day mr-2 text-teal-600" />締め日グループ</h3></div>
                <div className="divide-y divide-gray-50">
                    {closingDateGroups.map((c) => (
                        <div key={c.id} className="flex items-center justify-between px-5 py-3 text-sm">
                            <div><span className="font-medium text-gray-800">{c.name}</span><span className="ml-3 text-xs text-gray-500">{c.closing_day}日締め / {c.payment_month_offset === 0 ? '当月' : c.payment_month_offset === 1 ? '翌月' : '翌々月'}{c.payment_day}日払い</span></div>
                            {canWrite && <button onClick={() => del(route('admin.payroll.settings.closing-groups.destroy', c.id), `締め日グループ「${c.name}」を削除しますか？`)} className="rounded-lg px-2 py-1 text-red-500 hover:bg-red-50"><i className="fa-solid fa-trash-can" /></button>}
                        </div>
                    ))}
                    {closingDateGroups.length === 0 && <p className="px-5 py-4 text-sm text-gray-400">未登録です。</p>}
                </div>
                {canWrite && (
                    <div className="flex flex-wrap items-end gap-3 border-t border-gray-100 bg-gray-50/50 px-5 py-4">
                        <div><label className={lbl}>名称</label><input className={field} value={cg.data.name} onChange={(e) => cg.setData('name', e.target.value)} placeholder="例）月末締め・翌月25日払い" /></div>
                        <div className="w-24"><label className={lbl}>締め日</label><input type="number" min={1} max={31} className={field} value={cg.data.closing_day} onChange={(e) => cg.setData('closing_day', Number(e.target.value))} /></div>
                        <div className="w-24"><label className={lbl}>支給日</label><input type="number" min={1} max={31} className={field} value={cg.data.payment_day} onChange={(e) => cg.setData('payment_day', Number(e.target.value))} /></div>
                        <div className="w-28"><label className={lbl}>支給月</label>
                            <select className={field} value={cg.data.payment_month_offset} onChange={(e) => cg.setData('payment_month_offset', Number(e.target.value))}>
                                <option value={0}>当月</option><option value={1}>翌月</option><option value={2}>翌々月</option>
                            </select>
                        </div>
                        <button onClick={() => cg.post(route('admin.payroll.settings.closing-groups.store'), { preserveScroll: true, onSuccess: () => cg.reset('name') })} disabled={cg.processing || cg.data.name.trim() === ''}
                            className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50"><i className="fa-solid fa-plus" /> 追加</button>
                    </div>
                )}
            </div>

            {/* 職種 */}
            <div className={card}>
                <div className="border-b border-gray-100 px-5 py-3.5"><h3 className="text-sm font-bold text-gray-800"><i className="fa-solid fa-user-tag mr-2 text-teal-600" />職種</h3></div>
                <div className="divide-y divide-gray-50">
                    {jobTitles.map((j) => (
                        <div key={j.id} className="flex items-center justify-between px-5 py-3 text-sm">
                            <span className="font-medium text-gray-800">{j.name}</span>
                            {canWrite && <button onClick={() => del(route('admin.payroll.settings.job-titles.destroy', j.id), `職種「${j.name}」を削除しますか？`)} className="rounded-lg px-2 py-1 text-red-500 hover:bg-red-50"><i className="fa-solid fa-trash-can" /></button>}
                        </div>
                    ))}
                    {jobTitles.length === 0 && <p className="px-5 py-4 text-sm text-gray-400">未登録です。</p>}
                </div>
                {canWrite && (
                    <div className="flex items-end gap-3 border-t border-gray-100 bg-gray-50/50 px-5 py-4">
                        <div className="flex-1"><label className={lbl}>職種名</label><input className={field} value={jt.data.name} onChange={(e) => jt.setData('name', e.target.value)} placeholder="例）店長" /></div>
                        <button onClick={() => jt.post(route('admin.payroll.settings.job-titles.store'), { preserveScroll: true, onSuccess: () => jt.reset('name') })} disabled={jt.processing || jt.data.name.trim() === ''}
                            className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50"><i className="fa-solid fa-plus" /> 追加</button>
                    </div>
                )}
            </div>

            {/* 休職・休業種別 */}
            <div className={card}>
                <div className="border-b border-gray-100 px-5 py-3.5"><h3 className="text-sm font-bold text-gray-800"><i className="fa-solid fa-bed-pulse mr-2 text-teal-600" />休職・休業種別</h3></div>
                <div className="divide-y divide-gray-50">
                    {leaveTypes.map((l) => (
                        <div key={l.id} className="flex items-center justify-between px-5 py-3 text-sm">
                            <div><span className="font-medium text-gray-800">{l.name}</span><span className="ml-3 text-xs text-gray-500">{options.leaveKinds[l.leave_kind] ?? l.leave_kind} / {options.leavePayCalcMethods[l.pay_calc_method] ?? l.pay_calc_method}</span></div>
                            {canWrite && <button onClick={() => del(route('admin.payroll.settings.leave-types.destroy', l.id), `「${l.name}」を削除しますか？`)} className="rounded-lg px-2 py-1 text-red-500 hover:bg-red-50"><i className="fa-solid fa-trash-can" /></button>}
                        </div>
                    ))}
                    {leaveTypes.length === 0 && <p className="px-5 py-4 text-sm text-gray-400">未登録です。</p>}
                </div>
                {canWrite && (
                    <div className="flex flex-wrap items-end gap-3 border-t border-gray-100 bg-gray-50/50 px-5 py-4">
                        <div className="flex-1"><label className={lbl}>名称</label><input className={field} value={lt.data.name} onChange={(e) => lt.setData('name', e.target.value)} placeholder="例）育児休業" /></div>
                        <div><label className={lbl}>種別</label>
                            <select className={field} value={lt.data.leave_kind} onChange={(e) => lt.setData('leave_kind', e.target.value)}>
                                {Object.entries(options.leaveKinds).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                            </select>
                        </div>
                        <div><label className={lbl}>支給計算</label>
                            <select className={field} value={lt.data.pay_calc_method} onChange={(e) => lt.setData('pay_calc_method', e.target.value)}>
                                {Object.entries(options.leavePayCalcMethods).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                            </select>
                        </div>
                        <button onClick={() => lt.post(route('admin.payroll.settings.leave-types.store'), { preserveScroll: true, onSuccess: () => lt.reset('name') })} disabled={lt.processing || lt.data.name.trim() === ''}
                            className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50"><i className="fa-solid fa-plus" /> 追加</button>
                    </div>
                )}
            </div>

            {/* 部門（店舗） */}
            <div className={`${card} p-5`}>
                <div className="flex items-center justify-between">
                    <h3 className="text-sm font-bold text-gray-800"><i className="fa-solid fa-store mr-2 text-teal-600" />部門（店舗）</h3>
                    <Link href={route('admin.departments.index')} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-50">
                        <i className="fa-solid fa-arrow-up-right-from-square" /> 店舗管理で編集
                    </Link>
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                    {departments.map((d) => <span key={d.id} className="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-600">{d.name}</span>)}
                    {departments.length === 0 && <span className="text-sm text-gray-400">未登録です。</span>}
                </div>
            </div>
        </div>
    );
}

export default function PayrollSettingsIndex({ payItems, deductionItems, attendanceItems, locations, municipalities, general, closingDateGroups, jobTitles, leaveTypes, departments, attendanceSettings, fiscalYear, payslipSettings, options }: Props) {
    const canWrite = useAdminPermission('payroll');
    const canWriteWork = useAdminPermission('settings');
    const [tab, setTab] = useState<TabKey>(() => {
        if (typeof window === 'undefined') {
            return 'pay';
        }
        const params = new URLSearchParams(window.location.search);
        const fromQuery = params.get('tab');
        const fromHash = window.location.hash.replace('#', '');
        const fromFy = params.has('fy') ? 'fiscal_year' : '';
        const candidate = [fromQuery, fromHash, fromFy, 'pay'].find((k) => TABS.some((t) => t.key === k));
        return (candidate ?? 'pay') as TabKey;
    });
    const [processing, setProcessing] = useState(false);

    const [pay, setPay] = useResyncedState(payItems);
    const [payType, setPayType] = useState<PayType>(readPayTypeFromUrl);
    const [detailPayId, setDetailPayId] = useState<number | null>(null);
    const [formulaPayId, setFormulaPayId] = useState<number | null>(null);
    const [detailDeductionId, setDetailDeductionId] = useState<number | null>(null);
    const [deduction, setDeduction] = useResyncedState(deductionItems);
    const [attendance, setAttendance] = useResyncedState(attendanceItems);
    const formulaLabels = useMemo(() => {
        const map: Record<string, string> = {};
        BASIS_REFS.forEach((b) => { map[b.code] = b.label; });
        pay.forEach((p) => { map[p.code] = p.name; });
        attendance.forEach((a) => { map[a.code] = a.name; });
        return map;
    }, [pay, attendance]);
    const lateEarlyDeductionActive = useMemo(
        () => pay.some((p) => p.code === 'late_early_deduction' && p.is_active),
        [pay],
    );
    const [locs, setLocs] = useResyncedState(locations);
    const [munis, setMunis] = useResyncedState(municipalities);
    const [showAddLocation, setShowAddLocation] = useState(false);
    const [editingLocationId, setEditingLocationId] = useState<number | null>(null);
    const locationForm = useForm({});

    // 項目マスタの新規行フォーム
    const newPay = useForm<{ pay_type: string; name: string; calc_method: string; is_active: boolean; divisor_unit: string; multiplier: string | number; quantity_unit: string; is_income_tax_target: boolean; is_labor_insurance_target: boolean; is_social_insurance_target: boolean }>({ pay_type: 'monthly', name: '', calc_method: 'manual', is_active: true, divisor_unit: 'one', multiplier: 1, quantity_unit: 'one', is_income_tax_target: true, is_labor_insurance_target: true, is_social_insurance_target: true });

    const selectPayType = (key: PayType) => {
        setPayType(key);
        syncPayTypeToUrl(key);
        newPay.setData({ ...newPay.data, pay_type: key, calc_method: 'manual' });
    };

    useEffect(() => {
        if (tab === 'pay') setPayType(readPayTypeFromUrl());
    }, [payItems, tab]); // eslint-disable-line react-hooks/exhaustive-deps

    const newDeduction = useForm({ name: '', calc_method: 'manual' });
    const newAttendance = useForm({ name: '', category: 'actual_work', unit_format: 'hour_decimal' });
    const newRateSet = useForm({ business_location_id: '', name: '', effective_from: '', effective_to: '' });

    // 勤怠設定（旧「設定」ページ統合）
    const workForm = useForm({
        default_break_minutes: attendanceSettings.default_break_minutes ?? '60',
        break_start_time: attendanceSettings.break_start_time ?? '12:00',
        break_end_time: attendanceSettings.break_end_time ?? '13:00',
        salary_round_minutes: attendanceSettings.salary_round_minutes ?? '15',
        salary_round_rule: attendanceSettings.salary_round_rule ?? 'floor',
        punch_use_photo: attendanceSettings.punch_use_photo ?? false,
        punch_day_boundary_hour: attendanceSettings.punch_day_boundary_hour ?? '5',
        work_start_time: attendanceSettings.work_start_time ?? '',
        work_end_time: attendanceSettings.work_end_time ?? '',
        work_hours_per_day: attendanceSettings.work_hours_per_day ?? '',
        month_closing_day: attendanceSettings.month_closing_day ?? '',
        legal_holiday_dows: attendanceSettings.legal_holiday_dows ?? ['sunday'],
        prescribed_holiday_dows: attendanceSettings.prescribed_holiday_dows ?? ['saturday'],
    });
    const workPartial =
        [workForm.data.work_start_time, workForm.data.work_end_time, workForm.data.work_hours_per_day].some((v) => v !== '') &&
        [workForm.data.work_start_time, workForm.data.work_end_time, workForm.data.work_hours_per_day].some((v) => v === '');
    const saveWork = () => workForm.put(route('admin.settings.update'), { preserveScroll: true });

    const deleteRow = (url: string, msg: string) => { if (window.confirm(msg)) router.delete(url, { preserveScroll: true }); };

    const blankLocation: LocationFormShape = {
        name: '', code: '', is_main: false, health_insurance_type: 'kyokai', prefecture: '',
        labor_insurance_number: '',
        labor_insurance_pref_code: '', labor_insurance_jurisdiction_code: '', labor_insurance_office_code: '',
        labor_insurance_serial_number: '', labor_insurance_branch_code: '',
        office_number: '', accident_industry_code: '', accident_merit_enabled: false, accident_merit_rate: '',
        employment_industry_type: '',
        labor_bureau: '', employment_bureau: '', accident_business_desc: '', employment_office_number: '',
        postal_code: '', address: '', note: '',
        sort_order: locations.length,
    };

    const createLocation = (data: LocationFormShape) => {
        locationForm.transform(() => data as never);
        locationForm.post(route('admin.payroll.locations.store'), { preserveScroll: true, onSuccess: () => setShowAddLocation(false) });
    };
    const updateLocation = (id: number, data: LocationFormShape) => {
        locationForm.transform(() => data as never);
        locationForm.put(route('admin.payroll.locations.update', id), { preserveScroll: true, onSuccess: () => setEditingLocationId(null) });
    };
    const removeLocation = (loc: Location) => {
        if (!window.confirm(`事業所「${loc.name}」を削除しますか？（保険料率・所属従業員がある場合は削除できません）`)) return;
        router.delete(route('admin.payroll.locations.destroy', loc.id), { preserveScroll: true });
    };

    const changeTab = (key: TabKey) => {
        setTab(key);
        if (typeof window === 'undefined') return;
        const url = new URL(window.location.href);
        if (key === 'pay') {
            url.searchParams.set('pay_type', payType);
        } else {
            url.searchParams.delete('pay_type');
        }
        url.hash = key;
        window.history.replaceState(null, '', `${url.pathname}${url.search}${url.hash}`);
    };

    const submit = (url: string, payload: Record<string, unknown>) => {
        setProcessing(true);
        router.put(url, payload as never, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
            onSuccess: () => { if (tab === 'pay') syncPayTypeToUrl(payType); },
        });
    };

    const buildPayItems = (list: PayItem[]) =>
        list.map((p) => ({
            id: p.id,
            name: p.name,
            is_active: p.is_active,
            calc_method: p.calc_method,
            divisor_unit: p.divisor_unit ?? null,
            multiplier: p.multiplier === '' ? null : p.multiplier,
            quantity_unit: p.quantity_unit ?? null,
            sign: p.sign ?? 'plus',
            rounding: p.rounding,
            is_income_tax_target: p.is_income_tax_target,
            is_labor_insurance_target: p.is_labor_insurance_target,
            is_social_insurance_target: p.is_social_insurance_target,
            is_fixed_wage: p.is_fixed_wage,
            is_in_kind: p.is_in_kind,
            is_allowance_base: p.is_allowance_base,
            is_deduction_base: p.is_deduction_base,
            is_daily_proration_base: p.is_daily_proration_base,
            show_zero: p.show_zero,
            sort_order: p.sort_order,
            custom_formula: p.calc_method === 'custom' ? (p.custom_formula ?? []) : null,
        }));

    const savePay = () =>
        submit(route('admin.payroll.settings.pay-items'), { items: buildPayItems(pay) });

    // 支給項目のドラッグ＆ドロップ並べ替え（表示中の給与区分内で入れ替え、即保存）。
    const handlePayDragEnd = (event: DragEndEvent) => {
        const { active, over } = event;
        if (!over || active.id === over.id) return;

        const current = pay.filter((p) => p.pay_type === payType);
        const oldIndex = current.findIndex((p) => p.id === active.id);
        const newIndex = current.findIndex((p) => p.id === over.id);
        if (oldIndex < 0 || newIndex < 0) return;

        const reordered = arrayMove(current, oldIndex, newIndex).map((p, i) => ({ ...p, sort_order: i }));
        const iter = reordered[Symbol.iterator]();
        const next = pay.map((p) => (p.pay_type === payType ? iter.next().value ?? p : p));

        setPay(next);
        if (canWrite) submit(route('admin.payroll.settings.pay-items'), { items: buildPayItems(next) });
    };

    const paySensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    const buildDeductionItems = (list: DeductionItem[]) =>
        list.map((d) => ({
            id: d.id,
            is_active: d.is_active,
            show_zero: d.show_zero,
            sort_order: d.sort_order,
            // MF準拠: 初期項目は名称・計算方法を変更不可。ユーザー追加項目のみ送信
            ...(d.is_system ? {} : { name: d.name, calc_method: d.calc_method }),
        }));

    const saveDeduction = () =>
        submit(route('admin.payroll.settings.deduction-items'), { items: buildDeductionItems(deduction) });

    // 控除項目のドラッグ＆ドロップ並べ替え（給与区分で分割しないため全項目が対象、即保存）。
    const handleDeductionDragEnd = (event: DragEndEvent) => {
        const { active, over } = event;
        if (!over || active.id === over.id) return;

        const oldIndex = deduction.findIndex((d) => d.id === active.id);
        const newIndex = deduction.findIndex((d) => d.id === over.id);
        if (oldIndex < 0 || newIndex < 0) return;

        const next = arrayMove(deduction, oldIndex, newIndex).map((d, i) => ({ ...d, sort_order: i }));
        setDeduction(next);
        if (canWrite) submit(route('admin.payroll.settings.deduction-items'), { items: buildDeductionItems(next) });
    };

    const saveAttendance = () =>
        submit(route('admin.payroll.settings.attendance-items'), {
            items: attendance.map((a) => ({ id: a.id, is_active: a.is_active, unit_format: a.unit_format, show_zero: a.show_zero })),
        });

    const saveInsurance = () => {
        const rates = locs.flatMap((l) => (l.insurance_rate_sets[0]?.rates ?? []).map((r) => ({
            id: r.id,
            employee_rate: r.employee_rate,
            employer_rate: r.employer_rate,
        })));
        submit(route('admin.payroll.settings.insurance-rates'), { rates });
    };

    const saveMunicipalities = () =>
        submit(route('admin.payroll.settings.municipalities'), {
            items: munis.map((m) => ({ id: m.id, designation_number: m.designation_number })),
        });

    const patchPay = (id: number, patch: Partial<PayItem>) => {
        setPay((prev) => {
            const updated = prev.map((p) => (p.id === id ? { ...p, ...patch } : p));
            if (patch.is_active === false) {
                const item = updated.find((p) => p.id === id);
                if (item?.code === 'late_early_deduction') {
                    setAttendance((att) => att.map((a) =>
                        LATE_EARLY_ATTENDANCE_CODES.has(a.code) ? { ...a, is_active: false } : a,
                    ));
                }
            }
            return updated;
        });
    };
    const patchDeduction = (id: number, patch: Partial<DeductionItem>) =>
        setDeduction((prev) => prev.map((d) => (d.id === id ? { ...d, ...patch } : d)));
    const patchAttendance = (id: number, patch: Partial<AttendanceItem>) =>
        setAttendance((prev) => prev.map((a) => (a.id === id ? { ...a, ...patch } : a)));
    const patchMunicipality = (id: number, patch: Partial<Municipality>) =>
        setMunis((prev) => prev.map((m) => (m.id === id ? { ...m, ...patch } : m)));
    const patchRate = (locId: number, rateId: number, patch: Partial<RateRow>) =>
        setLocs((prev) => prev.map((l) => l.id !== locId ? l : {
            ...l,
            insurance_rate_sets: l.insurance_rate_sets.map((s, idx) => idx !== 0 ? s : {
                ...s,
                rates: s.rates.map((r) => (r.id === rateId ? { ...r, ...patch } : r)),
            }),
        }));

    const detailItem = detailPayId !== null ? pay.find((p) => p.id === detailPayId) ?? null : null;
    const formulaItem = formulaPayId !== null ? pay.find((p) => p.id === formulaPayId) ?? null : null;
    const detailDeductionItem = detailDeductionId !== null ? deduction.find((d) => d.id === detailDeductionId) ?? null : null;

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">基本設定</h2>}>
            <Head title="基本設定" />

            <div className="px-4 py-6 sm:p-6">
                {!canWrite && (
                    <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                        <i className="fa-solid fa-eye mr-1.5" />
                        閲覧のみのアクセスです。変更は保存できません。
                    </div>
                )}

                <div className="mb-4 flex justify-end">
                    <Link href={route('admin.payroll.tax-measures.index')}
                        className="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                        <i className="fa-solid fa-sliders" /> 税制措置マスタ（定額減税の適用期間）
                    </Link>
                </div>

                {/* タブ（下線ハイライト） */}
                <div className="mb-5 border-b border-gray-200">
                    <nav className="-mb-px flex gap-1 overflow-x-auto">
                        {TABS.map((t) => (
                            <button
                                key={t.key}
                                onClick={() => changeTab(t.key)}
                                className={`whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold transition ${
                                    tab === t.key
                                        ? 'border-teal-600 text-teal-700'
                                        : 'border-transparent text-gray-500 hover:text-gray-700'
                                }`}
                            >
                                {t.label}
                            </button>
                        ))}
                    </nav>
                </div>

                {tab === 'general' && (
                    <GeneralTab general={general} closingDateGroups={closingDateGroups} jobTitles={jobTitles}
                        leaveTypes={leaveTypes} departments={departments} options={options} canWrite={canWrite} />
                )}

                {tab === 'work_settings' && (
                    <WorkSettingsTab form={workForm} partial={workPartial} onSave={saveWork} canWrite={canWriteWork} />
                )}

                {tab === 'fiscal_year' && (
                    <FiscalYearTab data={fiscalYear} options={options} canWrite={canWriteWork} />
                )}

                {tab === 'payslip' && (
                    <PayslipSettingsTab settings={payslipSettings} options={options} canWrite={canWriteWork} />
                )}

                {tab === 'pay' && (
                    <div className={cardClass}>
                        {/* 給与区分サブタブ（MFクラウド準拠: 月給/時給/日給/賞与） */}
                        <div className="flex flex-wrap gap-0.5 border-b border-gray-100 px-3 pt-2">
                            {PAY_TYPES.map((t) => {
                                const count = pay.filter((p) => p.pay_type === t.key).length;
                                const activeCount = pay.filter((p) => p.pay_type === t.key && p.is_active).length;
                                return (
                                    <button key={t.key} type="button"
                                        onClick={() => selectPayType(t.key)}
                                        className={`inline-flex items-center gap-1 rounded-t px-3 py-1.5 text-xs font-semibold transition ${
                                            payType === t.key ? 'bg-teal-600 text-white' : 'text-gray-500 hover:bg-gray-100'
                                        }`}>
                                        {t.label}
                                        <span className={`rounded-full px-1 text-[10px] tabular-nums ${payType === t.key ? 'bg-white/25' : 'bg-gray-200 text-gray-600'}`}>
                                            {activeCount}/{count}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                        <div className="overflow-x-auto">
                            <DndContext sensors={paySensors} collisionDetection={closestCenter} modifiers={[restrictToVerticalAxis, restrictToParentElement]} onDragEnd={handlePayDragEnd}>
                            <table className="min-w-full divide-y divide-gray-100">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className={`${payThClass} w-10`}></th>
                                        <th className={`${payThClass} min-w-[7.5rem]`}>支給項目</th>
                                        <th className={payThClass}>計算方法</th>
                                        <th className={`${payThClass} min-w-[12rem]`}>時間数/日数</th>
                                        <th className={`${payThClass} whitespace-nowrap`}></th>
                                        <th className={`${payThClass} w-10`}></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    <SortableContext items={pay.filter((p) => p.pay_type === payType).map((p) => p.id)} strategy={verticalListSortingStrategy}>
                                    {pay.filter((p) => p.pay_type === payType).map((p) => {
                                        const isBase = BASE_METHODS.includes(p.calc_method);
                                        // 「従業員情報で設定」も単価とみなし ÷×勤怠 の式ビルダーを編集可能にする（MF準拠）
                                        const usesBuilder = isBase || p.calc_method === 'employee';
                                        const basisMap = options.payBasisMethodsByType[p.pay_type] ?? {};
                                        // 既存項目の値がプルダウンに無い場合（割増基礎など）は表示用に補完
                                        const basisEntries = p.calc_method in basisMap
                                            ? Object.entries(basisMap)
                                            : [[p.calc_method, options.basisLabels[p.calc_method] ?? p.calc_method] as [string, string], ...Object.entries(basisMap)];
                                        const rowBg = masterRowClass(p.is_active);
                                        return (
                                        <SortableTr key={p.id} id={p.id} className={rowBg}>
                                        {({ attributes, listeners }) => (<>
                                            <td className={`${payTdClass} sticky left-0 z-10 ${masterStickyCellBg(p.is_active)}`}>
                                                <div className="flex items-center gap-1">
                                                    {canWrite && (
                                                        <span
                                                            className={masterGripClass(p.is_active)}
                                                            title={p.is_active ? 'ドラッグで並べ替え' : undefined}
                                                            {...(p.is_active ? { ...attributes, ...listeners } : {})}
                                                        >
                                                            <i className="fa-solid fa-grip-vertical text-xs" />
                                                        </span>
                                                    )}
                                                    <input type="checkbox" className={payCheckboxClass} disabled={!canWrite}
                                                        checked={p.is_active} onChange={(e) => patchPay(p.id, { is_active: e.target.checked })} />
                                                </div>
                                            </td>
                                            <td className={`${payTdClass} sticky left-10 z-10 min-w-[7.5rem] shadow-[2px_0_4px_-2px_rgba(0,0,0,0.06)] ${masterStickyCellBg(p.is_active)}`}>
                                                <input className={`${payInputClass} w-full min-w-[7rem] ${masterNameClass(p.is_active)}`}
                                                    disabled={!masterEditable(canWrite, p.is_active)} value={p.name} onChange={(e) => patchPay(p.id, { name: e.target.value })} />
                                            </td>
                                            <td className={`${payTdClass} min-w-0`}>
                                                <select className={paySelectBasisClass} disabled={!masterEditable(canWrite, p.is_active)}
                                                    value={p.calc_method} onChange={(e) => {
                                                        const cm = e.target.value;
                                                        const patch: Partial<PayItem> = { calc_method: cm };
                                                        if ((BASE_METHODS.includes(cm) || cm === 'employee') && !p.divisor_unit) patch.divisor_unit = 'one';
                                                        patchPay(p.id, patch);
                                                    }}>
                                                    {basisEntries.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                                </select>
                                            </td>
                                            <td className={`${payTdClass} min-w-[12rem] align-top`}>
                                                {p.calc_method === 'custom' ? (
                                                    <div className="py-0.5">
                                                        <button type="button" onClick={() => setFormulaPayId(p.id)} disabled={!p.is_active}
                                                            className={`text-[13px] font-medium hover:underline disabled:cursor-not-allowed disabled:no-underline ${p.is_active ? 'text-teal-600 hover:text-teal-700' : 'text-gray-400'}`}>
                                                            計算式を設定
                                                        </button>
                                                        {p.custom_formula && p.custom_formula.length > 0 && formulaIsBroken(p.custom_formula) && (
                                                            <p className={`mt-1 text-[11px] ${p.is_active ? 'text-amber-600' : 'text-gray-400'}`}>計算式を再設定してください</p>
                                                        )}
                                                        {p.custom_formula && p.custom_formula.length > 0 && !formulaIsBroken(p.custom_formula) && (
                                                            <p className={`mt-1 whitespace-normal break-words text-[13px] leading-relaxed ${p.is_active ? 'text-gray-800' : 'text-gray-400'}`}>
                                                                {formulaPlainText(p.custom_formula, formulaLabels)}
                                                            </p>
                                                        )}
                                                    </div>
                                                ) : p.calc_method === 'manual' ? (
                                                    // 毎月手入力: 自動計算しない（給与計算画面で入力）ため式ビルダーは表示しない
                                                    <span className="text-[11px] text-gray-400">給与計算画面で入力</span>
                                                ) : p.category === 'commute' ? (
                                                    // MF準拠: 通勤手当は従業員情報で設定（計算式なし）
                                                    <span className="text-[11px] text-gray-400">従業員情報で設定</span>
                                                ) : (
                                                    <div className={masterFormulaWrapClass(p.is_active)}>
                                                        <span className={masterOpClass(p.is_active)}>÷</span>
                                                        <select className={paySelectUnitClass} disabled={!masterEditable(canWrite, p.is_active) || !usesBuilder}
                                                            value={p.divisor_unit ?? 'one'} onChange={(e) => patchPay(p.id, { divisor_unit: e.target.value })}>
                                                            <option value="one">1</option>
                                                            {attendance.filter((a) => a.category === 'fixed_work').map((a) => <option key={a.id} value={a.code}>{a.name}</option>)}
                                                        </select>
                                                        <span className={masterOpClass(p.is_active)}>×</span>
                                                        <input type="number" step="0.001" min="0" className={payMultiplierClass} disabled={!masterEditable(canWrite, p.is_active) || !usesBuilder}
                                                            value={p.multiplier ?? ''} onChange={(e) => patchPay(p.id, { multiplier: e.target.value })} placeholder="1.0" />
                                                        <span className={masterOpClass(p.is_active)}>×</span>
                                                        <select className={paySelectQtyClass} disabled={!masterEditable(canWrite, p.is_active) || !usesBuilder}
                                                            value={p.quantity_unit ?? 'one'} onChange={(e) => patchPay(p.id, { quantity_unit: e.target.value })}>
                                                            <option value="one">1</option>
                                                            {attendance.map((a) => <option key={a.id} value={a.code}>{a.name}</option>)}
                                                        </select>
                                                    </div>
                                                )}
                                            </td>
                                            <td className={`${payTdClass} whitespace-nowrap`}>
                                                <button type="button" onClick={() => setDetailPayId(p.id)} disabled={!p.is_active}
                                                    className={masterDetailBtnClass(p.is_active)}>
                                                    <i className="fa-solid fa-sliders text-[10px]" />詳細設定
                                                </button>
                                            </td>
                                            <td className={payTdClass}>
                                                {canWrite && !p.is_system && p.is_active && (
                                                    <button onClick={() => deleteRow(route('admin.payroll.settings.pay-items.destroy', p.id), `支給項目「${p.name}」を削除しますか？`)}
                                                        className={payDeleteBtnClass}><i className="fa-solid fa-trash-can text-xs" /></button>
                                                )}
                                            </td>
                                        </>)}
                                        </SortableTr>
                                        );
                                    })}
                                    </SortableContext>
                                    {pay.filter((p) => p.pay_type === payType).length === 0 && (
                                        <tr><td colSpan={6} className="px-3 py-8 text-center text-[11px] text-gray-400">この給与区分の支給項目はありません。</td></tr>
                                    )}
                                </tbody>
                            </table>
                            </DndContext>
                        </div>
                        {canWrite && (() => {
                            const newIsBase = BASE_METHODS.includes(newPay.data.calc_method) || newPay.data.calc_method === 'employee';
                            const newBasisMap = options.payBasisMethodsByType[payType] ?? {};
                            return (
                            <div className="border-t border-gray-100 bg-teal-50/40 px-2.5 py-2">
                                <p className="mb-1.5 px-0.5 text-[11px] font-medium text-gray-500">
                                    <i className="fa-solid fa-plus mr-1 text-teal-600" />
                                    {PAY_TYPES.find((t) => t.key === payType)?.label}に支給項目を追加（既存行と同じ形式で設定できます）
                                </p>
                                <div className="flex flex-nowrap items-center gap-2 overflow-x-auto">
                                    <input type="checkbox" className={payCheckboxClass} checked={newPay.data.is_active}
                                        onChange={(e) => newPay.setData('is_active', e.target.checked)} />
                                    <input className={`${payInputClass} w-32 shrink-0 font-medium text-gray-800`} value={newPay.data.name}
                                        onChange={(e) => newPay.setData('name', e.target.value)} placeholder="例）役職手当" />
                                    <div className={payFormulaWrapClass}>
                                        <select className={paySelectBasisClass} value={newPay.data.calc_method}
                                            onChange={(e) => {
                                                const cm = e.target.value;
                                                newPay.setData({ ...newPay.data, calc_method: cm, divisor_unit: (BASE_METHODS.includes(cm) || cm === 'employee') && !newPay.data.divisor_unit ? 'one' : newPay.data.divisor_unit });
                                            }}>
                                            {Object.entries(newBasisMap).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                        </select>
                                        {newIsBase && (
                                            <>
                                                <span className={payOpClass}>÷</span>
                                                <select className={paySelectUnitClass}
                                                    value={newPay.data.divisor_unit || 'one'} onChange={(e) => newPay.setData('divisor_unit', e.target.value)}>
                                                    <option value="one">1</option>
                                                    {attendance.filter((a) => a.category === 'fixed_work').map((a) => <option key={a.id} value={a.code}>{a.name}</option>)}
                                                </select>
                                                <span className={payOpClass}>×</span>
                                                <input type="number" step="0.001" min="0" className={payMultiplierClass}
                                                    value={newPay.data.multiplier ?? ''} onChange={(e) => newPay.setData('multiplier', e.target.value)} placeholder="1.0" />
                                                <span className={payOpClass}>×</span>
                                                <select className={paySelectQtyClass}
                                                    value={newPay.data.quantity_unit || 'one'} onChange={(e) => newPay.setData('quantity_unit', e.target.value)}>
                                                    <option value="one">1</option>
                                                    {attendance.map((a) => <option key={a.id} value={a.code}>{a.name}</option>)}
                                                </select>
                                            </>
                                        )}
                                    </div>
                                    {newPay.data.calc_method === 'custom' && (
                                        <span className="shrink-0 whitespace-nowrap px-1 text-[11px] text-gray-500">
                                            画面下部の「保存する」ボタンをクリックすると、計算式を設定できます。
                                        </span>
                                    )}
                                    <span className="flex-1" />
                                    <button type="button" disabled title="項目を追加して保存すると詳細設定できます"
                                        className={`${payDetailBtnClass} cursor-not-allowed opacity-50`}>
                                        <i className="fa-solid fa-sliders text-[10px]" />詳細設定
                                    </button>
                                    <button onClick={() => { newPay.setData('pay_type', payType); newPay.post(route('admin.payroll.settings.pay-items.store'), { preserveScroll: true, onSuccess: () => newPay.reset('name', 'calc_method', 'divisor_unit', 'multiplier', 'quantity_unit') }); }}
                                        disabled={newPay.processing || newPay.data.name.trim() === ''}
                                        className="inline-flex shrink-0 items-center gap-1 rounded bg-teal-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-teal-700 disabled:opacity-50"><i className="fa-solid fa-plus text-[11px]" /> 追加</button>
                                </div>
                            </div>
                            );
                        })()}
                        {canWrite && <SaveButton onClick={savePay} processing={processing} compact />}
                    </div>
                )}

                {tab === 'deduction' && (
                    <div className={cardClass}>
                        <p className="border-b border-gray-100 px-3 py-2 text-[11px] text-gray-400">
                            控除項目は全従業員（社員・アルバイト等）の給与・賞与に共通で適用されます。給与区分ごとの分割はMF同様行いません。
                        </p>
                        <div className="overflow-x-auto">
                            <DndContext sensors={paySensors} collisionDetection={closestCenter} modifiers={[restrictToVerticalAxis, restrictToParentElement]} onDragEnd={handleDeductionDragEnd}>
                            <table className="min-w-full divide-y divide-gray-100">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className={`${payThClass} w-10`}></th>
                                        <th className={`${payThClass} w-10`}></th>
                                        <th className={`${payThClass} min-w-[8rem]`}>控除項目</th>
                                        <th className={payThClass}>計算方法</th>
                                        <th className={`${payThClass} whitespace-nowrap`}></th>
                                        <th className={`${payThClass} w-10`}></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    <SortableContext items={deduction.map((d) => d.id)} strategy={verticalListSortingStrategy}>
                                    {deduction.map((d) => {
                                        const rowBg = masterRowClass(d.is_active);
                                        return (
                                        <SortableTr key={d.id} id={d.id} className={rowBg}>
                                        {({ attributes, listeners }) => (<>
                                            <td className={payTdClass}>
                                                {canWrite && (
                                                    <span
                                                        className={masterGripClass(d.is_active)}
                                                        title={d.is_active ? 'ドラッグで並べ替え' : undefined}
                                                        {...(d.is_active ? { ...attributes, ...listeners } : {})}
                                                    >
                                                        <i className="fa-solid fa-grip-vertical text-xs" />
                                                    </span>
                                                )}
                                            </td>
                                            <td className={payTdClass}>
                                                <input type="checkbox" className={payCheckboxClass} disabled={!canWrite}
                                                    checked={d.is_active} onChange={(e) => patchDeduction(d.id, { is_active: e.target.checked })} />
                                            </td>
                                            <td className={payTdClass}>
                                                {d.is_system ? (
                                                    <span className={masterNameClass(d.is_active)}>{d.name}</span>
                                                ) : (
                                                    <input className={`${payInputClass} w-full min-w-[7rem] ${masterNameClass(d.is_active)}`}
                                                        disabled={!masterEditable(canWrite, d.is_active)} value={d.name} onChange={(e) => patchDeduction(d.id, { name: e.target.value })} />
                                                )}
                                            </td>
                                            <td className={payTdClass}>
                                                {d.is_system ? (
                                                    <span className={masterDescClass(d.is_active)}>{d.calc_description}</span>
                                                ) : (
                                                    <select className={`${paySelectClass} min-w-[10rem]`} disabled={!masterEditable(canWrite, d.is_active)}
                                                        value={d.calc_method} onChange={(e) => patchDeduction(d.id, { calc_method: e.target.value })}>
                                                        {Object.entries(options.newDeductionCalcMethods).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                                    </select>
                                                )}
                                            </td>
                                            <td className={`${payTdClass} whitespace-nowrap`}>
                                                <button type="button" onClick={() => setDetailDeductionId(d.id)} disabled={!d.is_active} className={masterDetailBtnClass(d.is_active)}>
                                                    <i className="fa-solid fa-sliders text-[11px]" />詳細設定
                                                </button>
                                            </td>
                                            <td className={payTdClass}>
                                                {canWrite && !d.is_system && d.is_active && (
                                                    <button onClick={() => deleteRow(route('admin.payroll.settings.deduction-items.destroy', d.id), `控除項目「${d.name}」を削除しますか？`)}
                                                        className={payDeleteBtnClass}><i className="fa-solid fa-trash-can text-xs" /></button>
                                                )}
                                            </td>
                                        </>)}
                                        </SortableTr>
                                        );
                                    })}
                                    </SortableContext>
                                </tbody>
                            </table>
                            </DndContext>
                        </div>
                        {canWrite && (
                            <div className="border-t border-gray-100 bg-teal-50/40 px-2.5 py-2">
                                <p className="mb-1.5 px-0.5 text-[11px] font-medium text-gray-500">
                                    <i className="fa-solid fa-plus mr-1 text-teal-600" />
                                    控除項目を追加（既存行と同じ形式で設定できます）
                                </p>
                                <div className="flex flex-nowrap items-center gap-2 overflow-x-auto">
                                    <span className="w-6 shrink-0" />
                                    <input className={`${payInputClass} w-32 shrink-0 font-medium text-gray-800`} value={newDeduction.data.name}
                                        onChange={(e) => newDeduction.setData('name', e.target.value)} placeholder="例）財形貯蓄" />
                                    <select className={`${paySelectClass} min-w-[10rem]`} value={newDeduction.data.calc_method} onChange={(e) => newDeduction.setData('calc_method', e.target.value)}>
                                        {Object.entries(options.newDeductionCalcMethods).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                    </select>
                                    <button onClick={() => newDeduction.post(route('admin.payroll.settings.deduction-items.store'), { preserveScroll: true, onSuccess: () => newDeduction.reset('name', 'calc_method') })}
                                        disabled={newDeduction.processing || newDeduction.data.name.trim() === ''}
                                        className="inline-flex shrink-0 items-center gap-1 rounded bg-teal-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-teal-700 disabled:opacity-50"><i className="fa-solid fa-plus text-[11px]" /> 追加</button>
                                </div>
                            </div>
                        )}
                        {canWrite && <SaveButton onClick={saveDeduction} processing={processing} compact />}
                    </div>
                )}

                {tab === 'attendance' && (
                    <div className={cardClass}>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-100">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className={thClass}>有効</th>
                                        <th className={thClass}>カテゴリ</th>
                                        <th className={thClass}>勤怠項目</th>
                                        <th className={thClass}>単位</th>
                                        <th className={thClass}>0でも表示</th>
                                        <th className={thClass}></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {attendance.map((a) => {
                                        const lateEarlyLocked = LATE_EARLY_ATTENDANCE_CODES.has(a.code) && !lateEarlyDeductionActive;
                                        const rowActive = a.is_active && !lateEarlyLocked;

                                        return (
                                        <tr key={a.id} className={masterRowClass(rowActive)}>
                                            <td className={tdClass}>
                                                <input type="checkbox" className={checkboxClass} disabled={!canWrite || lateEarlyLocked}
                                                    checked={rowActive} onChange={(e) => patchAttendance(a.id, { is_active: e.target.checked })} />
                                            </td>
                                            <td className={`${tdClass} ${rowActive ? 'text-gray-700' : 'text-gray-400'}`}>{options.attendanceCategories[a.category] ?? a.category}</td>
                                            <td className={`${tdClass} ${masterNameClass(rowActive)}`}>{a.name}</td>
                                            <td className={tdClass}>
                                                <select className={`${selectClass} ${rowActive ? '' : 'border-gray-200 bg-gray-50 text-gray-400'}`} disabled={!masterEditable(canWrite, rowActive)}
                                                    value={a.unit_format} onChange={(e) => patchAttendance(a.id, { unit_format: e.target.value })}>
                                                    {Object.entries(options.unitFormats).map(([v, l]) => (
                                                        <option key={v} value={v}>{l}</option>
                                                    ))}
                                                </select>
                                            </td>
                                            <td className={tdClass}>
                                                <input type="checkbox" className={checkboxClass} disabled={!masterEditable(canWrite, rowActive)}
                                                    checked={a.show_zero} onChange={(e) => patchAttendance(a.id, { show_zero: e.target.checked })} />
                                            </td>
                                            <td className={tdClass}>
                                                {canWrite && !a.is_system && rowActive && (
                                                    <button onClick={() => deleteRow(route('admin.payroll.settings.attendance-items.destroy', a.id), `勤怠項目「${a.name}」を削除しますか？`)}
                                                        className="rounded-lg px-2 py-1 text-red-500 hover:bg-red-50"><i className="fa-solid fa-trash-can" /></button>
                                                )}
                                            </td>
                                        </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        {canWrite && (
                            <div className="flex flex-wrap items-end gap-3 border-t border-gray-100 bg-gray-50/50 px-4 py-4">
                                <div className="w-32">
                                    <label className="mb-1 block text-xs font-medium text-gray-500">カテゴリ</label>
                                    <select className={`${selectClass} w-full`} value={newAttendance.data.category} onChange={(e) => newAttendance.setData('category', e.target.value)}>
                                        {Object.entries(options.attendanceCategories).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                    </select>
                                </div>
                                <div className="flex-1 min-w-[160px]">
                                    <label className="mb-1 block text-xs font-medium text-gray-500">勤怠項目名</label>
                                    <input className="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" value={newAttendance.data.name} onChange={(e) => newAttendance.setData('name', e.target.value)} placeholder="例）特別休暇" />
                                </div>
                                <div className="w-44">
                                    <label className="mb-1 block text-xs font-medium text-gray-500">単位</label>
                                    <select className={`${selectClass} w-full`} value={newAttendance.data.unit_format} onChange={(e) => newAttendance.setData('unit_format', e.target.value)}>
                                        {Object.entries(options.unitFormats).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                    </select>
                                </div>
                                <button onClick={() => newAttendance.post(route('admin.payroll.settings.attendance-items.store'), { preserveScroll: true, onSuccess: () => newAttendance.reset('name') })}
                                    disabled={newAttendance.processing || newAttendance.data.name.trim() === ''}
                                    className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50"><i className="fa-solid fa-plus" /> 項目を追加</button>
                            </div>
                        )}
                        {canWrite && <SaveButton onClick={saveAttendance} processing={processing} />}
                    </div>
                )}

                {tab === 'locations' && (
                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <p className="text-xs text-gray-500">
                                事業所は保険料率・労働保険の帰属先、給与計算バッチの絞り込み単位です。従業員給与情報や給与計算の事業所選択に反映されます。
                            </p>
                            {canWrite && (
                                <button type="button" onClick={() => setShowAddLocation((v) => !v)}
                                    className="inline-flex shrink-0 items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700">
                                    <i className="fa-solid fa-plus" /> 事業所を追加
                                </button>
                            )}
                        </div>

                        {showAddLocation && canWrite && (
                            <div className={`${cardClass} p-5`}>
                                <h3 className="mb-4 text-sm font-bold text-gray-700">事業所を追加</h3>
                                <LocationForm initial={blankLocation} healthInsuranceTypes={options.healthInsuranceTypes}
                                    prefectures={options.prefectures} accidentIndustries={options.accidentIndustries}
                                    employmentIndustries={options.employmentIndustries} onSubmit={createLocation} submitLabel="追加"
                                    processing={locationForm.processing} onCancel={() => setShowAddLocation(false)} />
                            </div>
                        )}

                        {locs.length === 0 && (
                            <div className={`${cardClass} px-4 py-8 text-center text-sm text-gray-400`}>事業所が登録されていません。</div>
                        )}

                        {locs.map((loc) => (
                            <div key={loc.id} className={`${cardClass} p-5`}>
                                {editingLocationId === loc.id ? (
                                    <>
                                        <div className="mb-4 flex items-center justify-between">
                                            <h3 className="text-sm font-bold text-gray-700">事業所を編集</h3>
                                        </div>
                                        <LocationForm
                                            initial={{
                                                name: loc.name, code: loc.code ?? '', is_main: loc.is_main,
                                                health_insurance_type: loc.health_insurance_type, prefecture: loc.prefecture ?? '',
                                                labor_insurance_number: loc.labor_insurance_number ?? '',
                                                labor_insurance_pref_code: loc.labor_insurance_pref_code ?? '',
                                                labor_insurance_jurisdiction_code: loc.labor_insurance_jurisdiction_code ?? '',
                                                labor_insurance_office_code: loc.labor_insurance_office_code ?? '',
                                                labor_insurance_serial_number: loc.labor_insurance_serial_number ?? '',
                                                labor_insurance_branch_code: loc.labor_insurance_branch_code ?? '',
                                                office_number: loc.office_number ?? '',
                                                accident_industry_code: loc.accident_industry_code ?? '',
                                                accident_merit_enabled: !!loc.accident_merit_enabled,
                                                accident_merit_rate: loc.accident_merit_rate ?? '',
                                                employment_industry_type: loc.employment_industry_type ?? '',
                                                labor_bureau: loc.labor_bureau ?? '', employment_bureau: loc.employment_bureau ?? '',
                                                accident_business_desc: loc.accident_business_desc ?? '',
                                                employment_office_number: loc.employment_office_number ?? '',
                                                postal_code: loc.postal_code ?? '', address: loc.address ?? '', note: loc.note ?? '',
                                                sort_order: loc.sort_order,
                                            }}
                                            healthInsuranceTypes={options.healthInsuranceTypes} prefectures={options.prefectures}
                                            accidentIndustries={options.accidentIndustries} employmentIndustries={options.employmentIndustries}
                                            onSubmit={(data) => updateLocation(loc.id, data)} submitLabel="更新"
                                            processing={locationForm.processing} onCancel={() => setEditingLocationId(null)} />
                                    </>
                                ) : (
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-semibold text-gray-800">{loc.name}</span>
                                                {loc.is_main && <span className="rounded-full bg-teal-100 px-2 py-0.5 text-[10px] font-semibold text-teal-700">本社</span>}
                                                <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] text-gray-500">
                                                    {options.healthInsuranceTypes[loc.health_insurance_type] ?? loc.health_insurance_type}
                                                </span>
                                            </div>
                                            <div className="mt-1 text-xs text-gray-500">
                                                {loc.prefecture ?? '都道府県未設定'}
                                                {loc.code && <span className="ml-2">コード: {loc.code}</span>}
                                                {loc.labor_insurance_number && <span className="ml-2">労保: {loc.labor_insurance_number}</span>}
                                            </div>
                                            {loc.address && <div className="mt-0.5 text-xs text-gray-400">{loc.postal_code ? `〒${loc.postal_code} ` : ''}{loc.address}</div>}
                                        </div>
                                        {canWrite && (
                                            <div className="flex items-center gap-1">
                                                <button onClick={() => setEditingLocationId(loc.id)}
                                                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-600 transition hover:bg-gray-50">
                                                    <i className="fa-solid fa-pen" /> 編集
                                                </button>
                                                <button onClick={() => removeLocation(loc)}
                                                    className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-red-600 transition hover:bg-red-50">
                                                    <i className="fa-solid fa-trash-can" />
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}

                {tab === 'insurance' && (
                    <SocialInsuranceTab locs={locs} options={options} canWrite={canWrite} newRateSet={newRateSet} deleteRow={deleteRow} />
                )}

                {tab === 'labor' && (
                    <LaborInsuranceTab
                        locs={locs}
                        options={options}
                        canWrite={canWrite}
                        patchRate={patchRate}
                        deleteRow={deleteRow}
                        saveInsurance={saveInsurance}
                        processing={processing}
                    />
                )}

                {tab === 'resident_tax' && (
                    <div className={cardClass}>
                        <div className="border-b border-gray-100 px-4 py-3">
                            <p className="text-xs text-gray-500">
                                市区町村は従業員の「住民税 納付先」を保存すると自動で追加されます。各市区町村の指定番号（特別徴収義務者番号）を入力してください。
                            </p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-100">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className={thClass}>市区町村</th>
                                        <th className={thClass}>指定番号</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {munis.map((m) => (
                                        <tr key={m.id}>
                                            <td className={`${tdClass} font-medium text-gray-800`}>{m.name}</td>
                                            <td className={tdClass}>
                                                <input type="text" className="w-56 rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                                    disabled={!canWrite} value={m.designation_number ?? ''}
                                                    onChange={(e) => patchMunicipality(m.id, { designation_number: e.target.value })} />
                                            </td>
                                        </tr>
                                    ))}
                                    {munis.length === 0 && (
                                        <tr>
                                            <td colSpan={2} className="px-4 py-8 text-center text-sm text-gray-400">
                                                住民税の納付先市区町村を設定している従業員がいません。従業員の住民税設定より納付先市区町村を指定してください。
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {canWrite && munis.length > 0 && <SaveButton onClick={saveMunicipalities} processing={processing} />}
                    </div>
                )}
            </div>

            {detailItem && (
                <PayItemDetailModal
                    item={detailItem}
                    roundings={options.roundings}
                    canWrite={canWrite}
                    onPatch={(patch) => patchPay(detailItem.id, patch)}
                    onClose={() => setDetailPayId(null)}
                />
            )}
            {formulaItem && (
                <CustomFormulaModal
                    item={formulaItem}
                    payItems={pay.filter((p) => p.pay_type === formulaItem.pay_type && p.id !== formulaItem.id)}
                    attendanceItems={attendance}
                    canWrite={canWrite}
                    onSave={(tokens) => {
                        const next = pay.map((p) => (p.id === formulaItem.id ? { ...p, custom_formula: tokens } : p));
                        setPay(next);
                        setFormulaPayId(null);
                        syncPayTypeToUrl(payType);
                        if (canWrite) submit(route('admin.payroll.settings.pay-items'), { items: buildPayItems(next) });
                    }}
                    onClose={() => setFormulaPayId(null)}
                />
            )}
            {detailDeductionItem && (
                <DeductionDetailModal
                    item={detailDeductionItem}
                    canWrite={canWrite}
                    onPatch={(patch) => patchDeduction(detailDeductionItem.id, patch)}
                    onClose={() => setDetailDeductionId(null)}
                />
            )}
        </AdminLayout>
    );
}

/* ------------------------------------------------------------------ */
/* 控除項目 詳細設定モーダル（se12: 0円でも表示のみ）                     */
/* ------------------------------------------------------------------ */
function DeductionDetailModal({ item, canWrite, onPatch, onClose }: {
    item: DeductionItem;
    canWrite: boolean;
    onPatch: (patch: Partial<DeductionItem>) => void;
    onClose: () => void;
}) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
            <div className="w-full max-w-xs rounded-lg bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                    <h3 className="text-xs font-bold text-gray-800">詳細設定 <span className="ml-1.5 font-normal text-gray-500">{item.name}</span></h3>
                    <button onClick={onClose} className="rounded px-1.5 py-0.5 text-gray-400 hover:bg-gray-100"><i className="fa-solid fa-xmark text-xs" /></button>
                </div>
                <div className="px-4 py-3">
                    <label className="flex items-center justify-between gap-2 rounded px-1 py-1.5 hover:bg-gray-50">
                        <span className="text-xs text-gray-700">0円でも明細に表示</span>
                        <input type="checkbox" className="h-3.5 w-3.5 rounded border-gray-300 text-teal-600 focus:ring-teal-500"
                            disabled={!canWrite} checked={item.show_zero} onChange={(e) => onPatch({ show_zero: e.target.checked })} />
                    </label>
                </div>
                <div className="flex justify-end border-t border-gray-100 bg-gray-50/50 px-4 py-2">
                    <button onClick={onClose} className="rounded border border-amber-300/80 bg-amber-50 px-4 py-1 text-xs font-medium text-amber-900 hover:bg-amber-100">閉じる</button>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* 詳細設定モーダル（se11 ttl04）                                        */
/* ------------------------------------------------------------------ */
function PayItemDetailModal({ item, roundings, canWrite, onPatch, onClose }: {
    item: PayItem;
    roundings: LabelMap;
    canWrite: boolean;
    onPatch: (patch: Partial<PayItem>) => void;
    onClose: () => void;
}) {
    // MF準拠: 時給・日給の支給項目では「割増基礎」「控除基礎」は設定できないため非表示。
    const isHourlyOrDaily = item.pay_type === 'hourly' || item.pay_type === 'daily';
    // MF準拠: 通勤手当は「所得税の計算対象」を表示せず、端数処理は「切り上げ」固定。
    const isCommute = item.category === 'commute';
    // MF準拠: 賞与は所得税・労働保険・社会保険・現物・0円でも表示のみ（プラス/マイナス・端数処理・固定的賃金等は非表示）。
    const isBonus = item.pay_type === 'bonus';
    const toggles: { key: keyof PayItem; label: string; hint?: string }[] = isBonus
        ? [
            { key: 'is_income_tax_target', label: '所得税の計算対象' },
            { key: 'is_labor_insurance_target', label: '労働保険の計算対象' },
            { key: 'is_social_insurance_target', label: '社会保険の計算対象' },
            { key: 'is_in_kind', label: '現物支給' },
            { key: 'show_zero', label: '0円でも明細に表示' },
        ]
        : [
            ...(isCommute ? [] : [{ key: 'is_income_tax_target' as keyof PayItem, label: '所得税の計算対象' }]),
            { key: 'is_labor_insurance_target', label: '労働保険の計算対象' },
            { key: 'is_social_insurance_target', label: '社会保険の計算対象' },
            { key: 'is_fixed_wage', label: '固定的賃金', hint: '月額変更（随時改定）の判定に使用します' },
            { key: 'is_in_kind', label: '現物支給' },
            ...(isHourlyOrDaily ? [] : [
                { key: 'is_allowance_base' as keyof PayItem, label: '割増基礎に含める', hint: '割増賃金（残業手当など）の計算基礎に含めます' },
                { key: 'is_deduction_base' as keyof PayItem, label: '控除基礎に含める', hint: '欠勤・遅刻控除などの計算基礎に含めます' },
            ]),
            { key: 'is_daily_proration_base', label: '日割り計算の基礎' },
            { key: 'show_zero', label: '0円でも明細に表示' },
        ];

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
            <div className="max-h-[85vh] w-full max-w-md overflow-y-auto rounded-lg bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                    <h3 className="text-xs font-bold text-gray-800">詳細設定 <span className="ml-1.5 font-normal text-gray-500">{item.name}</span></h3>
                    <button onClick={onClose} className="rounded px-1.5 py-0.5 text-gray-400 hover:bg-gray-100"><i className="fa-solid fa-xmark text-xs" /></button>
                </div>
                <div className="space-y-0.5 px-4 py-3">
                    {toggles.map((t) => (
                        <label key={t.key} className="flex items-start justify-between gap-2 rounded px-1 py-1.5 hover:bg-gray-50">
                            <span className="text-xs text-gray-700">
                                {t.label}
                                {t.hint && <span className="mt-0.5 block text-[10px] text-gray-400">{t.hint}</span>}
                            </span>
                            <input type="checkbox" className="mt-0.5 h-3.5 w-3.5 rounded border-gray-300 text-teal-600 focus:ring-teal-500"
                                disabled={!canWrite} checked={Boolean(item[t.key])}
                                onChange={(e) => onPatch({ [t.key]: e.target.checked } as Partial<PayItem>)} />
                        </label>
                    ))}
                    {!isBonus && <div className="grid grid-cols-2 gap-3 border-t border-gray-100 pt-3">
                        <div>
                            <label className="mb-0.5 block text-[10px] font-medium text-gray-500">プラス／マイナス計算</label>
                            <select className="w-full rounded border border-gray-300 bg-white py-1.5 pl-2 pr-7 text-xs leading-5 focus:border-teal-500 focus:ring-1 focus:ring-teal-500"
                                disabled={!canWrite} value={item.sign ?? 'plus'} onChange={(e) => onPatch({ sign: e.target.value })}>
                                <option value="plus">プラス計算</option>
                                <option value="minus">マイナス計算</option>
                            </select>
                        </div>
                        <div>
                            <label className="mb-0.5 block text-[10px] font-medium text-gray-500">端数処理</label>
                            <select className="w-full rounded border border-gray-300 bg-white py-1.5 pl-2 pr-7 text-xs leading-5 focus:border-teal-500 focus:ring-1 focus:ring-teal-500 disabled:bg-gray-100 disabled:text-gray-400"
                                disabled={!canWrite || isCommute} value={isCommute ? 'ceil' : item.rounding} onChange={(e) => onPatch({ rounding: e.target.value })}>
                                {Object.entries(roundings).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                            </select>
                            {isCommute && <p className="mt-0.5 text-[10px] text-gray-400">通勤手当は切り上げ固定（MF準拠）</p>}
                        </div>
                    </div>}
                </div>
                <div className="flex justify-end border-t border-gray-100 bg-gray-50/50 px-4 py-2">
                    <button onClick={onClose} className="rounded border border-amber-300/80 bg-amber-50 px-4 py-1 text-xs font-medium text-amber-900 hover:bg-amber-100">閉じる</button>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* カスタム計算式エディタ（se13）                                        */
/* ------------------------------------------------------------------ */
const BASIS_REFS: { code: string; label: string }[] = [
    { code: 'employee', label: '従業員情報で設定' },
    { code: 'allowance_base', label: '割増基礎' },
    { code: 'prev_allowance_base', label: '前月の割増基礎' },
    { code: 'deduction_base', label: '控除基礎' },
    { code: 'prev_deduction_base', label: '前月の控除基礎' },
    { code: 'hourly1', label: '時給1' },
    { code: 'hourly2', label: '時給2' },
    { code: 'daily1', label: '日給1' },
    { code: 'daily2', label: '日給2' },
];

function tokenText(tk: FormulaToken, labels?: Record<string, string>): string {
    switch (tk.t) {
        case 'num': return tk.value != null ? String(tk.value) : '';
        case 'op': return tk.value === '*' ? '×' : tk.value === '/' ? '÷' : (tk.value ?? '');
        case 'cmp': return tk.value === '<=' ? '≤' : tk.value === '>=' ? '≥' : tk.value === '!=' ? '≠' : (tk.value ?? '');
        case 'fn': return tk.value ?? '';
        case 'paren': return tk.value ?? '';
        case 'comma': return ',';
        case 'ref': return (tk.code && labels?.[tk.code]) || tk.label || tk.code || '';
    }
}

function formulaIsBroken(tokens: FormulaToken[]): boolean {
    if (tokens.length === 0) return false;
    return tokens.some((tk) => {
        if (tk.t === 'ref') return !tk.code;
        if (tk.t === 'op' || tk.t === 'cmp' || tk.t === 'fn' || tk.t === 'paren') return !tk.value;
        if (tk.t === 'num') return tk.value == null;
        return false;
    });
}

function formulaPlainText(tokens: FormulaToken[], labels?: Record<string, string>): string {
    return tokens
        .map((tk) => {
            if (tk.t === 'op' && tk.value === '*') return '*';
            if (tk.t === 'op' && tk.value === '/') return '/';
            return tokenText(tk, labels);
        })
        .filter((t) => t !== '')
        .join(' ');
}

function FormulaPreview({ tokens, labels, className = '' }: { tokens: FormulaToken[]; labels?: Record<string, string>; className?: string }) {
    const parts = tokens
        .map((tk, i) => ({ key: i, text: tokenText(tk, labels), isRef: tk.t === 'ref' }))
        .filter((p) => p.text !== '');
    if (parts.length === 0) return null;
    return (
        <div className={`inline-flex min-w-0 flex-1 flex-nowrap items-center gap-1 overflow-x-auto ${className}`}>
            {parts.map((p) => (
                p.isRef
                    ? <span key={p.key} className="shrink-0 rounded-md bg-teal-100 px-2 py-0.5 text-[11px] font-medium text-teal-800">{p.text}</span>
                    : <span key={p.key} className="shrink-0 px-0.5 text-[13px] font-medium text-gray-700">{p.text}</span>
            ))}
        </div>
    );
}

function CustomFormulaModal({ item, payItems, attendanceItems, canWrite, onSave, onClose }: {
    item: PayItem;
    payItems: PayItem[];
    attendanceItems: AttendanceItem[];
    canWrite: boolean;
    onSave: (tokens: FormulaToken[]) => void;
    onClose: () => void;
}) {
    const refLabels = useMemo(() => {
        const map: Record<string, string> = {};
        BASIS_REFS.forEach((b) => { map[b.code] = b.label; });
        payItems.forEach((p) => { map[p.code] = p.name; });
        attendanceItems.forEach((a) => { map[a.code] = a.name; });
        return map;
    }, [payItems, attendanceItems]);

    const withLabels = (list: FormulaToken[]): FormulaToken[] =>
        list.map((tk) => (tk.t === 'ref' ? { ...tk, label: refLabels[tk.code] || tk.label || tk.code } : tk));

    const [tokens, setTokens] = useState<FormulaToken[]>(() => withLabels(item.custom_formula ?? []));
    const [buffer, setBuffer] = useState('');
    const [paySearch, setPaySearch] = useState('');
    const [attSearch, setAttSearch] = useState('');

    useEffect(() => {
        setTokens(withLabels(item.custom_formula ?? []));
        setBuffer('');
        setPaySearch('');
        setAttSearch('');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [item.id]);

    const broken = formulaIsBroken(tokens);

    const flush = (list: FormulaToken[]): FormulaToken[] => {
        if (buffer === '' || buffer === '.') return list;
        const next = [...list, { t: 'num', value: Number(buffer) } as FormulaToken];
        return next;
    };
    const pushToken = (tk: FormulaToken) => {
        setTokens((prev) => [...flush(prev), tk]);
        setBuffer('');
    };
    const pushDigit = (d: string) => setBuffer((b) => (d === '.' && b.includes('.') ? b : b + d));
    const del = () => {
        if (buffer !== '') { setBuffer((b) => b.slice(0, -1)); return; }
        setTokens((prev) => prev.slice(0, -1));
    };
    const clearAll = () => { setBuffer(''); setTokens([]); };
    const save = () => onSave(withLabels(flush(tokens)));

    const filteredPay = payItems.filter((p) => p.name.includes(paySearch));
    const filteredAtt = attendanceItems.filter((a) => a.name.includes(attSearch));

    const keyBtn = 'rounded-lg border border-gray-200 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-teal-50 active:bg-teal-100 disabled:opacity-40';
    const fnBtn = 'rounded-lg border border-teal-200 bg-teal-50 py-2.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100 disabled:opacity-40';

    const displayTokens: { text: string; isRef: boolean }[] = [
        ...tokens.map((tk) => ({ text: tokenText(tk, refLabels), isRef: tk.t === 'ref' })).filter((d) => d.text !== ''),
        ...(buffer !== '' ? [{ text: buffer, isRef: false }] : []),
    ];

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
            <div className="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                    <h3 className="text-sm font-bold text-gray-800">カスタム計算式 <span className="ml-2 font-normal text-gray-500">{item.name}</span></h3>
                    <button onClick={onClose} className="rounded-lg px-2 py-1 text-gray-400 hover:bg-gray-100"><i className="fa-solid fa-xmark" /></button>
                </div>

                {/* 数式表示 */}
                <div className="border-b border-gray-100 bg-gray-50 px-5 py-4">
                    {broken && (
                        <p className="mb-2 text-xs text-amber-600">
                            <i className="fa-solid fa-triangle-exclamation mr-1" />
                            保存された計算式が不完全です。C でクリアして作り直してください。
                        </p>
                    )}
                    <div className="flex min-h-12 flex-wrap items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2">
                        {displayTokens.length === 0 && <span className="text-sm text-gray-300">計算式を入力してください</span>}
                        {displayTokens.map((d, i) => (
                            d.isRef
                                ? <span key={i} className="rounded-md bg-teal-100 px-2 py-1 text-xs font-medium text-teal-800">{d.text}</span>
                                : <span key={i} className="px-0.5 text-sm font-medium text-gray-800">{d.text}</span>
                        ))}
                    </div>
                </div>

                <div className="grid flex-1 grid-cols-1 gap-4 overflow-y-auto p-5 md:grid-cols-2">
                    {/* 左: 参照リスト */}
                    <div className="space-y-4">
                        <div>
                            <p className="mb-2 text-xs font-bold uppercase tracking-wide text-gray-400">計算の基礎</p>
                            <div className="flex flex-wrap gap-1.5">
                                {BASIS_REFS.map((b) => (
                                    <button key={b.code} type="button" disabled={!canWrite}
                                        onClick={() => pushToken({ t: 'ref', kind: 'basis', code: b.code, label: b.label })}
                                        className="rounded-full border border-gray-200 px-3 py-1 text-xs text-gray-700 hover:bg-teal-50 disabled:opacity-40">{b.label}</button>
                                ))}
                            </div>
                        </div>
                        <div>
                            <p className="mb-2 text-xs font-bold uppercase tracking-wide text-gray-400">支給項目</p>
                            <input value={paySearch} onChange={(e) => setPaySearch(e.target.value)} placeholder="検索"
                                className="mb-2 w-full rounded-lg border-gray-200 text-xs focus:border-teal-500 focus:ring-teal-500" />
                            <div className="max-h-32 space-y-1 overflow-y-auto">
                                {filteredPay.map((p) => (
                                    <button key={p.id} type="button" disabled={!canWrite}
                                        onClick={() => pushToken({ t: 'ref', kind: 'pay', code: p.code, label: p.name })}
                                        className="block w-full rounded-md px-2 py-1 text-left text-xs text-gray-700 hover:bg-teal-50 disabled:opacity-40">{p.name}</button>
                                ))}
                                {filteredPay.length === 0 && <p className="px-2 py-1 text-xs text-gray-300">該当なし</p>}
                            </div>
                        </div>
                        <div>
                            <p className="mb-2 text-xs font-bold uppercase tracking-wide text-gray-400">勤怠項目</p>
                            <input value={attSearch} onChange={(e) => setAttSearch(e.target.value)} placeholder="検索"
                                className="mb-2 w-full rounded-lg border-gray-200 text-xs focus:border-teal-500 focus:ring-teal-500" />
                            <div className="max-h-32 space-y-1 overflow-y-auto">
                                {filteredAtt.map((a) => (
                                    <button key={a.id} type="button" disabled={!canWrite}
                                        onClick={() => pushToken({ t: 'ref', kind: 'attendance', code: a.code, label: a.name })}
                                        className="block w-full rounded-md px-2 py-1 text-left text-xs text-gray-700 hover:bg-teal-50 disabled:opacity-40">{a.name}</button>
                                ))}
                                {filteredAtt.length === 0 && <p className="px-2 py-1 text-xs text-gray-300">該当なし</p>}
                            </div>
                        </div>
                    </div>

                    {/* 右: 電卓キーパッド */}
                    <div className="grid grid-cols-5 gap-1.5 self-start">
                        <button type="button" disabled={!canWrite} onClick={() => pushDigit('7')} className={keyBtn}>7</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushDigit('8')} className={keyBtn}>8</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushDigit('9')} className={keyBtn}>9</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'op', value: '/' })} className={keyBtn}>÷</button>
                        <button type="button" disabled={!canWrite} onClick={del} className={`${keyBtn} text-red-500`}>Delete</button>

                        <button type="button" disabled={!canWrite} onClick={() => pushDigit('4')} className={keyBtn}>4</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushDigit('5')} className={keyBtn}>5</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushDigit('6')} className={keyBtn}>6</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'op', value: '*' })} className={keyBtn}>×</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'fn', value: 'ROUND' })} className={fnBtn}>ROUND</button>

                        <button type="button" disabled={!canWrite} onClick={() => pushDigit('1')} className={keyBtn}>1</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushDigit('2')} className={keyBtn}>2</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushDigit('3')} className={keyBtn}>3</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'op', value: '-' })} className={keyBtn}>−</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'fn', value: 'ROUNDUP' })} className={fnBtn}>ROUNDUP</button>

                        <button type="button" disabled={!canWrite} onClick={() => pushDigit('0')} className={keyBtn}>0</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushDigit('.')} className={keyBtn}>.</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'cmp', value: '=' })} className={keyBtn}>=</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'op', value: '+' })} className={keyBtn}>＋</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'fn', value: 'ROUNDDOWN' })} className={fnBtn}>ROUNDDOWN</button>

                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'cmp', value: '<=' })} className={keyBtn}>≤</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'cmp', value: '>=' })} className={keyBtn}>≥</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'cmp', value: '!=' })} className={keyBtn}>≠</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'comma' })} className={keyBtn}>,</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'fn', value: 'IF' })} className={fnBtn}>IF</button>

                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'cmp', value: '<' })} className={keyBtn}>&lt;</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'cmp', value: '>' })} className={keyBtn}>&gt;</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'paren', value: '(' })} className={keyBtn}>(</button>
                        <button type="button" disabled={!canWrite} onClick={() => pushToken({ t: 'paren', value: ')' })} className={keyBtn}>)</button>
                        <button type="button" disabled={!canWrite} onClick={clearAll} className={`${keyBtn} text-red-500`}>C</button>
                    </div>
                </div>

                <div className="flex justify-between gap-2 border-t border-gray-100 bg-gray-50/50 px-5 py-3">
                    <button onClick={onClose} className="rounded-lg border border-gray-300 px-5 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100">戻る</button>
                    {canWrite && (
                        <button onClick={save} className="rounded-lg bg-teal-600 px-6 py-2 text-sm font-semibold text-white hover:bg-teal-700">保存する</button>
                    )}
                </div>
            </div>
        </div>
    );
}
