<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayItemMaster extends Model
{
    protected $fillable = [
        'pay_type',
        'code',
        'name',
        'category',
        'is_active',
        'calc_method',
        'divisor_base',
        'divisor_unit',
        'multiplier',
        'quantity_unit',
        'custom_formula',
        'is_income_tax_target',
        'is_labor_insurance_target',
        'is_social_insurance_target',
        'is_fixed_wage',
        'is_in_kind',
        'is_allowance_base',
        'is_deduction_base',
        'is_leave_target',
        'show_zero',
        'is_daily_proration_base',
        'sign',
        'rounding',
        'is_system',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'divisor_base' => 'decimal:2',
            'multiplier' => 'decimal:3',
            'custom_formula' => 'array',
            'is_income_tax_target' => 'boolean',
            'is_labor_insurance_target' => 'boolean',
            'is_social_insurance_target' => 'boolean',
            'is_fixed_wage' => 'boolean',
            'is_in_kind' => 'boolean',
            'is_allowance_base' => 'boolean',
            'is_deduction_base' => 'boolean',
            'is_leave_target' => 'boolean',
            'show_zero' => 'boolean',
            'is_daily_proration_base' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeForPayType($query, string $payType)
    {
        return $query->where('pay_type', $payType);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
