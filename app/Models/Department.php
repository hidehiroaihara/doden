<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'business_location_id',
        'sort_order',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** この店舗に紐付く打刻端末。 */
    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class);
    }
}
