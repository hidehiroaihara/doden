<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 厚生年金基金の掛金料率（適用開始月単位・給与/賞与別・/1,000）。
 */
class PensionFundRate extends Model
{
    protected $fillable = [
        'pension_fund_id',
        'effective_from',
        'salary_employee_rate',
        'salary_employer_rate',
        'bonus_employee_rate',
        'bonus_employer_rate',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'salary_employee_rate' => 'decimal:5',
            'salary_employer_rate' => 'decimal:5',
            'bonus_employee_rate' => 'decimal:5',
            'bonus_employer_rate' => 'decimal:5',
        ];
    }

    public function pensionFund(): BelongsTo
    {
        return $this->belongsTo(PensionFund::class);
    }
}
