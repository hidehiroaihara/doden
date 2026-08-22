<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 従業員別の支給項目金額（calc_method='employee' の項目）。
 */
class EmployeePayItemValue extends Model
{
    protected $fillable = [
        'user_id',
        'pay_item_master_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payItemMaster(): BelongsTo
    {
        return $this->belongsTo(PayItemMaster::class);
    }
}
