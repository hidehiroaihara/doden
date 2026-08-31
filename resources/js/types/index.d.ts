export type PermissionLevel = 'none' | 'read' | 'write';

export interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: number;
    permissions: Record<string, PermissionLevel>;
}

export interface Department {
    id: number;
    name: string;
    sort_order: number;
}

export interface JobTitle {
    id: number;
    name: string;
    sort_order?: number;
}

export interface ClosingDateGroup {
    id: number;
    name: string;
}

export interface LeaveType {
    id: number;
    name: string;
    code?: string;
    leave_kind?: string;
    pay_calc_method?: string;
    is_active?: boolean;
    sort_order?: number;
}

export interface EmployeeDependent {
    id?: number;
    user_id?: number;
    last_name: string | null;
    first_name: string | null;
    last_name_kana: string | null;
    first_name_kana: string | null;
    birth_date: string | null;
    relationship: string | null;
    my_number: string | null;
    lives_together: boolean;
    is_income_tax_dependent: boolean;
    dependent_type: string;
    is_same_livelihood_spouse: boolean;
    disability_type: string;
    is_health_insurance_dependent: boolean;
    annual_income: number | null;
    sort_order?: number;
}

export interface EmployeeLeave {
    id?: number;
    leave_type_id: number | null;
    leave_type_name?: string | null;
    start_date: string | null;
    end_date: string | null;
    note: string | null;
}

export interface EmployeePayroll {
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
    flat_tax_reduction_total: number | null;
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

export interface BusinessLocation {
    id: number;
    name: string;
    code?: string | null;
    is_main?: boolean;
    health_insurance_type?: string;
    prefecture?: string | null;
    labor_insurance_number?: string | null;
    office_number?: string | null;
    postal_code?: string | null;
    address?: string | null;
    note?: string | null;
    sort_order?: number;
}

/** 雇用ステータス: active=在籍中 / pre_join=入社前 / retired=退職 */
export type EmploymentStatus = 'active' | 'pre_join' | 'retired';

export interface User {
    id: number;
    name: string;
    last_name?: string | null;
    first_name?: string | null;
    last_name_kana?: string | null;
    first_name_kana?: string | null;
    full_name?: string;
    gender?: string | null;
    email: string | null;
    email_verified_at?: string;
    is_active: boolean;
    joined_at?: string | null;
    /** サーバー側で算出した雇用ステータス */
    employment_status?: EmploymentStatus;
    chatwork_room_id?: string;
    role: number;
    department_id?: number | null;
    department?: Department | null;
    /** 打刻表示用の所属店舗ID一覧（主所属を含む多対多） */
    department_ids?: number[];
    departments?: Pick<Department, 'id' | 'name'>[];
    /** 給与情報(employee_payrolls)経由の所属事業所 */
    business_location_id?: number | null;
    business_location?: Pick<BusinessLocation, 'id' | 'name'> | null;
    customer_no?: string | null;
    resume_path?: string | null;
    identification_document_path?: string | null;
    phone?: string | null;
    postal_code?: string | null;
    prefecture?: string | null;
    city?: string | null;
    street?: string | null;
    building?: string | null;
    address_kana?: string | null;
    address?: string | null;
    my_number?: string | null;
    birth_date?: string | null;
    emergency_contact_name?: string | null;
    emergency_contact_phone?: string | null;
    break_minutes?: number | null;
    retirement_date?: string | null;
    retirement_type?: string | null;
    retirement_reason?: string | null;
    employee_note?: string | null;
    employee_payroll?: EmployeePayroll | null;
}

export interface UserStatusHistory {
    id: number;
    user_id: number;
    user_name?: string;
    from_status: EmploymentStatus | 'none';
    to_status: EmploymentStatus;
    from_label: string;
    to_label: string;
    changed_by: string | null;
    note: string | null;
    changed_at: string | null;
}

export interface AttendanceBreak {
    id: number;
    attendance_id: number;
    started_at: string;
    start_photo_path: string | null;
    start_ip: string | null;
    ended_at: string | null;
    end_photo_path: string | null;
    end_ip: string | null;
    created_at: string;
    updated_at: string;
}

export interface Attendance {
    id: number;
    user_id: number;
    work_date: string;
    clock_in_at: string | null;
    clock_in_photo_path: string | null;
    clock_in_ip: string | null;
    clock_out_at: string | null;
    clock_out_photo_path: string | null;
    clock_out_ip: string | null;
    attendance_breaks?: AttendanceBreak[];
    created_at: string;
    updated_at: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    /** 設定された月の締め日（1〜31）。未設定なら null（=月末締め）。 */
    monthClosingDay?: number | null;
};
