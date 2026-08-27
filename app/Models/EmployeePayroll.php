<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayroll extends Model
{
    protected $fillable = [
        'user_id',
        'business_location_id',
        'job_title_id',
        'closing_date_group_id',
        'employee_no',
        'employment_type',
        'pay_type',
        'position',
        'work_hours_per_day',
        'work_days_per_month',
        'work_days_monthly_avg',
        'work_hours_per_month',
        'work_hours_monthly_avg',
        'base_salary',
        'hourly_wage',
        'hourly_wage2',
        'daily_wage',
        'daily_wage2',
        'tax_table',
        'dependents_count',
        'is_widow',
        'is_single_parent',
        'disability_type',
        'is_working_student',
        'is_minor',
        'is_disaster',
        'is_foreigner',
        'residency_type',
        'report_municipality',
        'report_prefecture',
        'is_social_insurance_enrolled',
        'is_employment_insurance_enrolled',
        'is_care_insurance_target',
        'care_insurance_override',
        'is_short_time_worker',
        'is_miner',
        'standard_reward_grade_health',
        'standard_reward_health',
        'standard_reward_grade_pension',
        'standard_reward_pension',
        'health_qualified_at',
        'health_lost_at',
        'health_lost_reason',
        'health_insured_number',
        'pension_qualified_at',
        'pension_lost_at',
        'pension_lost_reason',
        'basic_pension_number',
        'accident_employee_type',
        'employment_qualified_at',
        'employment_lost_at',
        'employment_lost_reason',
        'employment_insured_number',
        'health_premium_mode',
        'health_premium_employee',
        'health_premium_employer',
        'nursing_premium_mode',
        'nursing_premium_employee',
        'nursing_premium_employer',
        'child_premium_mode',
        'child_premium_employee',
        'child_premium_employer',
        'pension_premium_mode',
        'pension_premium_employee',
        'pension_premium_employer',
        'commute_allowance_taxable',
        'commute_allowance_non_taxable',
        'resident_tax_monthly',
        'resident_tax_june',
        'bank_name',
        'bank_code',
        'branch_name',
        'branch_code',
        'account_type',
        'account_number',
        'account_holder_kana',
        'transfer_fixed_amount1',
        'transfer_fixed_amount2',
        'resident_tax_municipality',
        'resident_tax_prefecture',
        'resident_tax_recipient_number',
        'resident_tax_reference_number',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'integer',
            'hourly_wage' => 'integer',
            'hourly_wage2' => 'integer',
            'daily_wage' => 'integer',
            'daily_wage2' => 'integer',
            'dependents_count' => 'integer',
            'work_hours_per_day' => 'decimal:2',
            'work_days_per_month' => 'decimal:2',
            'work_days_monthly_avg' => 'decimal:2',
            'work_hours_per_month' => 'decimal:2',
            'work_hours_monthly_avg' => 'decimal:2',
            'is_widow' => 'boolean',
            'is_single_parent' => 'boolean',
            'is_working_student' => 'boolean',
            'is_minor' => 'boolean',
            'is_disaster' => 'boolean',
            'is_foreigner' => 'boolean',
            'is_social_insurance_enrolled' => 'boolean',
            'is_employment_insurance_enrolled' => 'boolean',
            'is_care_insurance_target' => 'boolean',
            'care_insurance_override' => 'boolean',
            'is_short_time_worker' => 'boolean',
            'is_miner' => 'boolean',
            'standard_reward_grade_health' => 'integer',
            'standard_reward_health' => 'integer',
            'standard_reward_grade_pension' => 'integer',
            'standard_reward_pension' => 'integer',
            'health_qualified_at' => 'date',
            'health_lost_at' => 'date',
            'pension_qualified_at' => 'date',
            'pension_lost_at' => 'date',
            'employment_qualified_at' => 'date',
            'employment_lost_at' => 'date',
            'health_premium_employee' => 'integer',
            'health_premium_employer' => 'integer',
            'nursing_premium_employee' => 'integer',
            'nursing_premium_employer' => 'integer',
            'child_premium_employee' => 'integer',
            'child_premium_employer' => 'integer',
            'pension_premium_employee' => 'integer',
            'pension_premium_employer' => 'integer',
            'commute_allowance_taxable' => 'integer',
            'commute_allowance_non_taxable' => 'integer',
            'resident_tax_monthly' => 'integer',
            'resident_tax_june' => 'integer',
            'transfer_fixed_amount1' => 'integer',
            'transfer_fixed_amount2' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function closingDateGroup(): BelongsTo
    {
        return $this->belongsTo(ClosingDateGroup::class);
    }
}
