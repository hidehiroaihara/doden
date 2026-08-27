<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 従業員ごとの標準報酬月額 履歴（適用開始月つき）。
 */
class EmployeeStandardReward extends Model
{
    protected $fillable = [
        'user_id',
        'applied_from',
        'health_grade',
        'health_amount',
        'pension_grade',
        'pension_amount',
    ];

    protected function casts(): array
    {
        return [
            'applied_from' => 'date',
            'health_grade' => 'integer',
            'health_amount' => 'integer',
            'pension_grade' => 'integer',
            'pension_amount' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
