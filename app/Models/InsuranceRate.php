<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsuranceRate extends Model
{
    protected $fillable = [
        'insurance_rate_set_id',
        'kind',
        'employee_rate',
        'employer_rate',
    ];

    protected function casts(): array
    {
        return [
            'employee_rate' => 'decimal:5',
            'employer_rate' => 'decimal:5',
        ];
    }

    public function rateSet(): BelongsTo
    {
        return $this->belongsTo(InsuranceRateSet::class, 'insurance_rate_set_id');
    }

    public function totalRate(): float
    {
        return (float) $this->employee_rate + (float) $this->employer_rate;
    }
}
