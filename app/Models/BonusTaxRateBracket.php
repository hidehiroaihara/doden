<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * 賞与に対する源泉徴収税額の算出率テーブル。適用期間つき。
 */
class BonusTaxRateBracket extends Model
{
    protected $fillable = [
        'tax_table',
        'min_prev_taxable',
        'max_prev_taxable',
        'rate',
        'dependent_shift',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'min_prev_taxable' => 'integer',
            'max_prev_taxable' => 'integer',
            'rate' => 'decimal:3',
            'dependent_shift' => 'integer',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    /**
     * 指定日・区分に適用されるブラケット群（min_prev_taxable 昇順）を返す。
     *
     * @return Collection<int, BonusTaxRateBracket>
     */
    public static function forDate(string $taxTable, string $date): Collection
    {
        $from = static::where('tax_table', $taxTable)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->max('effective_from');

        if (! $from) {
            return new Collection();
        }

        return static::where('tax_table', $taxTable)
            ->where('effective_from', $from)
            ->orderBy('min_prev_taxable')
            ->get();
    }
}
