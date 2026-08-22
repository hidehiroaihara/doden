<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 年末調整（従業員・年単位）。
 */
class YearEndAdjustment extends Model
{
    public const STATUS_LABELS = [
        'draft' => '下書き',
        'confirmed' => '確定',
        'reflected' => '給与反映済',
    ];

    protected $fillable = [
        'user_id',
        'year',
        'gross_total',
        'social_insurance_withheld',
        'withheld_tax',
        'social_insurance_declared',
        'life_insurance_deduction',
        'earthquake_insurance_deduction',
        'spouse_deduction',
        'dependent_count',
        'housing_loan_credit',
        'other_deduction',
        'salary_income',
        'taxable_income',
        'calculated_tax',
        'yearly_tax',
        'adjustment_amount',
        'status',
        'reflected_run_id',
        'reflected_at',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'reflected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reflectedRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'reflected_run_id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
