<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 従業員の通勤手当ルート（複数登録可）。
 */
class EmployeeCommuteRoute extends Model
{
    protected $fillable = [
        'user_id',
        'sort_order',
        'transport_type',
        'from_place',
        'to_place',
        'one_way_distance_km',
        'condition',
        'payment_months',
        'attendance_item_code',
        'amount',
        'payment_method',
        'cap_amount',
        'non_taxable_limit',
        'uses_parking',
        'parking_condition',
        'parking_payment_months',
        'parking_attendance_item_code',
        'parking_amount',
        'parking_payment_method',
        'parking_cap_amount',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'payment_months' => 'array',
            'amount' => 'integer',
            'cap_amount' => 'integer',
            'non_taxable_limit' => 'integer',
            'one_way_distance_km' => 'decimal:1',
            'uses_parking' => 'boolean',
            'parking_payment_months' => 'array',
            'parking_amount' => 'integer',
            'parking_cap_amount' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
