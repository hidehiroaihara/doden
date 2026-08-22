<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    protected $fillable = [
        'business_location_id',
        'period_key',
        'pay_type',
        'closing_date',
        'payment_date',
        'publish_date',
        'status',
        'finalized_at',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'closing_date' => 'date:Y-m-d',
            'payment_date' => 'date:Y-m-d',
            'publish_date' => 'date:Y-m-d',
            'finalized_at' => 'datetime',
        ];
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }
}
