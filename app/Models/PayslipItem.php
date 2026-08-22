<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayslipItem extends Model
{
    protected $fillable = [
        'payslip_id',
        'item_type',
        'source_master_id',
        'code',
        'name',
        'category',
        'amount',
        'minutes',
        'quantity',
        'is_manual_override',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'minutes' => 'integer',
            'quantity' => 'decimal:2',
            'is_manual_override' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
