<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 協会けんぽ 都道府県別 健康保険料率（＋全国一律の介護保険料率）。適用期間つき。
 * 料率はいずれも総額（労使合算）の千分率(/1,000)。
 */
class KyokaiKenpoRate extends Model
{
    protected $fillable = [
        'prefecture',
        'health_permille',
        'nursing_permille',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'health_permille' => 'decimal:3',
            'nursing_permille' => 'decimal:3',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    /**
     * 指定都道府県・日付に適用される料率を返す（無ければ null）。
     */
    public static function resolve(string $prefecture, string $date): ?self
    {
        return static::where('prefecture', $prefecture)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }
}
