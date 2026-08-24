<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessLocation extends Model
{
    protected $fillable = [
        'name',
        'code',
        'is_main',
        'health_insurance_type',
        'prefecture',
        'labor_insurance_number',
        'office_number',
        'accident_industry_code',
        'employment_industry_type',
        'labor_bureau',
        'accident_business_desc',
        'employment_office_number',
        'postal_code',
        'address',
        'note',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function insuranceRateSets(): HasMany
    {
        return $this->hasMany(InsuranceRateSet::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function employeePayrolls(): HasMany
    {
        return $this->hasMany(EmployeePayroll::class);
    }

    /**
     * 指定日に適用される料率セットを返す（無ければ null）。
     */
    public function rateSetForDate(string $date): ?InsuranceRateSet
    {
        return $this->insuranceRateSets()
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }
}
