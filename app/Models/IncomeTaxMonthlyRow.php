<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 月額表の1行（社会保険料等控除後の給与等の階級 × 扶養親族等の数 → 税額）。
 *
 * 税額の持ち方は3通り。原表の書き方に合わせて使い分ける。
 *  1. tax_amount: 表に税額が載っている階級（大半がこれ）
 *  2. base_amount + excess_over + rate: 高額帯の「〇〇円の税額に、△円を超える金額の□％を加算」
 *  3. rate + deduction: 「給与の□％」のように率だけで決まる階級（乙欄の低額帯など）
 */
class IncomeTaxMonthlyRow extends Model
{
    protected $fillable = [
        'income_tax_monthly_table_id',
        'tax_table',
        'min_amount',
        'max_amount',
        'dependents',
        'tax_amount',
        'base_amount',
        'excess_over',
        'rate',
        'deduction',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'dependents' => 'integer',
            'tax_amount' => 'integer',
            'base_amount' => 'integer',
            'excess_over' => 'integer',
            'rate' => 'decimal:5',
            'deduction' => 'integer',
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(IncomeTaxMonthlyTable::class, 'income_tax_monthly_table_id');
    }

    /** この階級が指定金額（以上・未満）を含むか。 */
    public function covers(int $amount): bool
    {
        return $amount >= (int) $this->min_amount
            && ($this->max_amount === null || $amount < (int) $this->max_amount);
    }

    /** この行の税額。1円未満は原表の注記に従って切り捨てる。 */
    public function taxFor(int $amount): int
    {
        // 高額帯: 基準となる税額に、起点を超える金額の一定割合を加算する
        if ($this->base_amount !== null && $this->rate !== null) {
            $excess = max(0, $amount - (int) $this->excess_over);

            return max(0, (int) $this->base_amount + (int) floor($excess * (float) $this->rate));
        }

        // 率だけで決まる階級
        if ($this->rate !== null) {
            return max(0, (int) floor($amount * (float) $this->rate - (int) $this->deduction));
        }

        return max(0, (int) $this->tax_amount);
    }
}
