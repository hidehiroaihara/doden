<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 従業員ごとの住民税納付額（年度・月別）。
 * fiscal_year は起点となる6月の西暦、month は暦月(1-12)。
 */
class EmployeeResidentTax extends Model
{
    protected $fillable = [
        'user_id',
        'fiscal_year',
        'month',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'month' => 'integer',
            'amount' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 暦月(period 'Y-m')が属する住民税年度（起点6月の西暦）を返す。
     * 例: 2026-06〜2026-12 → 2026 / 2027-01〜2027-05 → 2026。
     */
    public static function fiscalYearForMonth(int $year, int $month): int
    {
        return $month >= 6 ? $year : $year - 1;
    }
}
