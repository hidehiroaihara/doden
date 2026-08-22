<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalYearHoliday extends Model
{
    protected $fillable = ['fiscal_year_id', 'dow', 'type'];

    protected function casts(): array
    {
        return ['dow' => 'integer'];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
