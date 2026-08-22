<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * 源泉所得税（月額・電算機特例）のブラケット。適用期間つき。
 */
class IncomeTaxBracket extends Model
{
    protected $fillable = [
        'tax_table',
        'min_amount',
        'max_amount',
        'rate',
        'deduction',
        'dependent_deduction',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'rate' => 'decimal:4',
            'deduction' => 'integer',
            'dependent_deduction' => 'integer',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    /**
     * 指定日・区分に適用されるブラケット群（min_amount 昇順）を返す。
     *
     * @return Collection<int, IncomeTaxBracket>
     */
    public static function forDate(string $taxTable, string $date): Collection
    {
        // 対象日に有効な最新の適用開始日を1つに絞る
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
            ->orderBy('min_amount')
            ->get();
    }
}
