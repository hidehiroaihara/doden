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
        'health_union_name',
        'health_office_symbol',
        'pension_jurisdiction',
        'pension_office_number',
        'pension_office_symbol',
        'pension_fund_name',
        'pension_fund_number',
        'pension_fund_office_number',
        'labor_insurance_number',
        'labor_insurance_pref_code',
        'labor_insurance_jurisdiction_code',
        'labor_insurance_office_code',
        'labor_insurance_serial_number',
        'labor_insurance_branch_code',
        'office_number',
        'accident_industry_code',
        'accident_merit_enabled',
        'accident_merit_rate',
        'employment_industry_type',
        'labor_bureau',
        'employment_bureau',
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
            'accident_merit_enabled' => 'boolean',
            'accident_merit_rate' => 'decimal:3',
        ];
    }

    /**
     * 労災保険の事業主料率(/1,000)を返す。メリット制が適用ありなら its 料率、
     * それ以外は業種プリセット。対象日は将来の年度別対応の余地のため受け取る。
     */
    public function accidentEmployerRate(?string $date = null): float
    {
        if ($this->accident_merit_enabled && $this->accident_merit_rate !== null) {
            return (float) $this->accident_merit_rate;
        }

        return \App\Support\LaborInsuranceRates::accidentEmployerRate($this->accident_industry_code ?: 'other');
    }

    /**
     * 分割値（府県/所掌/管轄/基幹/枝番）から労働保険番号の連結値を合成する。
     * いずれも未入力なら null。
     */
    public function composeLaborInsuranceNumber(): ?string
    {
        $parts = [
            $this->labor_insurance_pref_code,
            $this->labor_insurance_jurisdiction_code,
            $this->labor_insurance_office_code,
            $this->labor_insurance_serial_number,
            $this->labor_insurance_branch_code,
        ];

        if (! collect($parts)->filter(fn ($v) => filled($v))->count()) {
            return null;
        }

        return sprintf(
            '%s%s%s%s-%s',
            $this->labor_insurance_pref_code ?? '',
            $this->labor_insurance_jurisdiction_code ?? '',
            $this->labor_insurance_office_code ?? '',
            $this->labor_insurance_serial_number ?? '',
            $this->labor_insurance_branch_code ?? '',
        );
    }

    /**
     * 最新の料率セットへ、事業所の労災・雇用料率(/1,000)を業種設定から反映する。
     * 料率セットが未登録の場合は何もしない。
     */
    public function syncLaborInsuranceRates(): void
    {
        $set = $this->insuranceRateSets()->orderByDesc('effective_from')->first();
        if (! $set) {
            return;
        }

        $date = $set->effective_from?->toDateString() ?? now()->toDateString();

        if ($this->accident_industry_code || $this->accident_merit_enabled) {
            InsuranceRate::updateOrCreate(
                ['insurance_rate_set_id' => $set->id, 'kind' => 'accident'],
                [
                    'employee_rate' => 0,
                    'employer_rate' => $this->accidentEmployerRate($date),
                ],
            );
        }

        if ($this->employment_industry_type) {
            $rates = \App\Support\LaborInsuranceRates::employmentRates($this->employment_industry_type, $date);
            InsuranceRate::updateOrCreate(
                ['insurance_rate_set_id' => $set->id, 'kind' => 'employment'],
                [
                    'employee_rate' => $rates['employee'],
                    'employer_rate' => $rates['employer'],
                ],
            );
        }
    }

    public function insuranceRateSets(): HasMany
    {
        return $this->hasMany(InsuranceRateSet::class);
    }

    public function pensionFunds(): HasMany
    {
        return $this->hasMany(PensionFund::class)->orderBy('sort_order')->orderBy('id');
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
