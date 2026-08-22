<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StandardRewardGrade extends Model
{
    protected $fillable = [
        'insurance_type',
        'grade',
        'monthly_amount',
        'lower_bound',
        'upper_bound',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'monthly_amount' => 'integer',
            'lower_bound' => 'integer',
            'upper_bound' => 'integer',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    /**
     * 指定日・区分・報酬月額に該当する等級を返す。
     */
    public static function resolve(string $insuranceType, int $monthlyReward, string $date): ?self
    {
        return static::where('insurance_type', $insuranceType)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->where('lower_bound', '<=', $monthlyReward)
            ->where(function ($q) use ($monthlyReward) {
                $q->whereNull('upper_bound')->orWhere('upper_bound', '>', $monthlyReward);
            })
            ->orderByDesc('effective_from')
            ->first();
    }
}
