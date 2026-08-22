<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsuranceRateSet extends Model
{
    protected $fillable = [
        'business_location_id',
        'name',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(InsuranceRate::class);
    }

    public function rate(string $kind): ?InsuranceRate
    {
        return $this->rates->firstWhere('kind', $kind);
    }
}
