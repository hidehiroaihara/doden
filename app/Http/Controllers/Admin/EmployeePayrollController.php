<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\ClosingDateGroup;
use App\Models\JobTitle;
use App\Models\ResidentTaxMunicipality;
use App\Models\StandardRewardGrade;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * 従業員給与情報の編集。
 * 一般情報(氏名・部署)は既存のユーザー管理で編集するため、
 * ここでは給与計算に必要な属性(給与区分・基本給・所属事業所・社会保険・税)のみを扱う。
 * 参照: 資料/設計書 05_従業員情報
 */
class EmployeePayrollController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('search', ''));

        $users = User::with(['employeePayroll.businessLocation:id,name', 'department:id,name'])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('name', 'like', "%{$search}%")
                        ->orWhere('customer_no', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get()
            ->map(function (User $user) {
                $ep = $user->employeePayroll;

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'department' => $user->department?->name,
                    'employment_status' => $user->employment_status,
                    'has_payroll' => (bool) $ep,
                    'employee_no' => $ep?->employee_no,
                    'pay_type' => $ep?->pay_type,
                    'base_salary' => $ep?->base_salary,
                    'business_location' => $ep?->businessLocation?->name,
                    'is_social_insurance_enrolled' => (bool) ($ep?->is_social_insurance_enrolled),
                    'is_employment_insurance_enrolled' => (bool) ($ep?->is_employment_insurance_enrolled),
                ];
            });

        return Inertia::render('Admin/Payroll/Employees/Index', [
            'users' => $users,
            'filters' => ['search' => $search],
        ]);
    }

    public function edit(User $user)
    {
        $ep = $user->employeePayroll;

        return Inertia::render('Admin/Payroll/Employees/Edit', [
            'user' => ['id' => $user->id, 'name' => $user->name],
            'payroll' => $ep ? $ep->toArray() : $this->defaultPayroll(),
            'options' => [
                'businessLocations' => BusinessLocation::orderBy('sort_order')->get(['id', 'name']),
                'jobTitles' => JobTitle::orderBy('sort_order')->get(['id', 'name']),
                'closingDateGroups' => ClosingDateGroup::orderBy('sort_order')->get(['id', 'name']),
                'employmentTypes' => $this->employmentTypeLabels(),
                'payTypes' => ['monthly' => '月給', 'hourly' => '時給', 'daily' => '日給'],
                'taxTables' => ['kou' => '甲欄', 'otsu' => '乙欄'],
                'accountTypes' => ['ordinary' => '普通', 'checking' => '当座', 'savings' => '貯蓄'],
            ],
        ]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'business_location_id' => ['nullable', 'exists:business_locations,id'],
            'job_title_id' => ['nullable', 'exists:job_titles,id'],
            'closing_date_group_id' => ['nullable', 'exists:closing_date_groups,id'],
            'employee_no' => ['nullable', 'string', 'max:50'],
            'employment_type' => ['required', 'string', 'max:30'],
            'pay_type' => ['required', 'in:monthly,hourly,daily'],
            'base_salary' => ['required', 'integer', 'min:0'],
            'hourly_wage' => ['required', 'integer', 'min:0'],
            'hourly_wage2' => ['nullable', 'integer', 'min:0'],
            'daily_wage' => ['required', 'integer', 'min:0'],
            'daily_wage2' => ['nullable', 'integer', 'min:0'],
            'tax_table' => ['required', 'in:kou,otsu'],
            'dependents_count' => ['required', 'integer', 'min:0', 'max:20'],
            'is_social_insurance_enrolled' => ['boolean'],
            'is_employment_insurance_enrolled' => ['boolean'],
            'is_care_insurance_target' => ['boolean'],
            'care_insurance_override' => ['nullable', 'boolean'],
            'standard_reward_health' => ['nullable', 'integer', 'min:0'],
            'standard_reward_pension' => ['nullable', 'integer', 'min:0'],
            'commute_allowance_taxable' => ['required', 'integer', 'min:0'],
            'commute_allowance_non_taxable' => ['required', 'integer', 'min:0'],
            'resident_tax_monthly' => ['required', 'integer', 'min:0'],
            'resident_tax_june' => ['required', 'integer', 'min:0'],
            // 支払情報（振込先口座）
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_code' => ['nullable', 'string', 'max:4'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'branch_code' => ['nullable', 'string', 'max:3'],
            'account_type' => ['required', 'in:ordinary,checking,savings'],
            'account_number' => ['nullable', 'string', 'max:7'],
            'account_holder_kana' => ['nullable', 'string', 'max:255'],
            // 住民税納付先
            'resident_tax_municipality' => ['nullable', 'string', 'max:255'],
            'resident_tax_recipient_number' => ['nullable', 'string', 'max:255'],
        ]);

        // 標準報酬月額から等級を自動解決（適用日は当日基準）
        $today = now()->toDateString();
        $validated['standard_reward_grade_health'] = $this->resolveGrade('health', $validated['standard_reward_health'] ?? null, $today);
        $validated['standard_reward_grade_pension'] = $this->resolveGrade('pension', $validated['standard_reward_pension'] ?? null, $today);

        $user->employeePayroll()->updateOrCreate(['user_id' => $user->id], $validated);

        // 納付先市区町村を住民税マスタへ自動同期
        ResidentTaxMunicipality::sync($validated['resident_tax_municipality'] ?? null);

        return back()->with('success', "{$user->name} の給与情報を保存しました");
    }

    private function resolveGrade(string $type, ?int $reward, string $date): ?int
    {
        if (! $reward) {
            return null;
        }

        return StandardRewardGrade::resolve($type, $reward, $date)?->grade;
    }

    /** @return array<string, mixed> */
    private function defaultPayroll(): array
    {
        return [
            'business_location_id' => null,
            'job_title_id' => null,
            'closing_date_group_id' => null,
            'employee_no' => null,
            'employment_type' => 'full_time',
            'pay_type' => 'monthly',
            'base_salary' => 0,
            'hourly_wage' => 0,
            'daily_wage' => 0,
            'tax_table' => 'kou',
            'dependents_count' => 0,
            'is_social_insurance_enrolled' => false,
            'is_employment_insurance_enrolled' => false,
            'is_care_insurance_target' => false,
            'care_insurance_override' => null,
            'standard_reward_health' => null,
            'standard_reward_pension' => null,
            'commute_allowance_taxable' => 0,
            'commute_allowance_non_taxable' => 0,
            'resident_tax_monthly' => 0,
            'resident_tax_june' => 0,
            'bank_name' => null,
            'bank_code' => null,
            'branch_name' => null,
            'branch_code' => null,
            'account_type' => 'ordinary',
            'account_number' => null,
            'account_holder_kana' => null,
            'resident_tax_municipality' => null,
            'resident_tax_recipient_number' => null,
        ];
    }

    /** @return array<string, string> */
    private function employmentTypeLabels(): array
    {
        return [
            'executive' => '役員',
            'employee_executive' => '使用人兼務役員',
            'full_time' => '正社員',
            'contract' => '契約社員',
            'entrusted' => '嘱託社員',
            'part_time' => 'パート',
            'arbeit' => 'アルバイト',
            'dispatch' => '派遣社員',
            'other' => 'その他',
        ];
    }
}
