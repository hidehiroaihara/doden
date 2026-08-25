<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payslip extends Model
{
    protected $fillable = [
        'payroll_run_id',
        'user_id',
        'total_earnings',
        'total_deductions',
        'net_pay',
        'allowance_base',
        'deduction_base',
        'scheduled_work_days',
        'scheduled_work_minutes',
        'insurance_rate_set_id',
        'applied_rates',
        'snapshot_standard_reward_health',
        'snapshot_standard_reward_pension',
        'snapshot_grade_health',
        'snapshot_grade_pension',
        'snapshot_tax_table',
        'snapshot_dependents_count',
        'income_tax_source',
        'remarks',
        'is_confirmed',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'total_earnings' => 'integer',
            'total_deductions' => 'integer',
            'net_pay' => 'integer',
            'allowance_base' => 'integer',
            'deduction_base' => 'integer',
            'scheduled_work_days' => 'decimal:2',
            'scheduled_work_minutes' => 'integer',
            'applied_rates' => 'array',
            'snapshot_standard_reward_health' => 'integer',
            'snapshot_standard_reward_pension' => 'integer',
            'snapshot_grade_health' => 'integer',
            'snapshot_grade_pension' => 'integer',
            'snapshot_dependents_count' => 'integer',
            'is_confirmed' => 'boolean',
            'calculated_at' => 'datetime',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayslipItem::class)->orderBy('sort_order');
    }

    public function earnings(): HasMany
    {
        return $this->items()->where('item_type', 'earning');
    }

    public function deductions(): HasMany
    {
        return $this->items()->where('item_type', 'deduction');
    }

    public function attendances(): HasMany
    {
        return $this->items()->where('item_type', 'attendance');
    }

    /**
     * 従業員番号（employee_payrolls.employee_no）の自然順で並べる。未設定は末尾。
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Payslip>  $query
     */
    public function scopeOrderByEmployeeNo($query)
    {
        if (empty($query->getQuery()->columns)) {
            $query->select('payslips.*');
        }

        return $query
            ->leftJoin('employee_payrolls as ep_sort', 'ep_sort.user_id', '=', 'payslips.user_id')
            ->orderByRaw("(ep_sort.employee_no IS NULL OR ep_sort.employee_no = '')")
            ->orderByRaw('LENGTH(ep_sort.employee_no)')
            ->orderBy('ep_sort.employee_no');
    }
}
