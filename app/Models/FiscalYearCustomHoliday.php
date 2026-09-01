<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalYearCustomHoliday extends Model
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_CABINET_OFFICE = 'cabinet_office';

    protected $fillable = ['fiscal_year_id', 'date', 'label', 'source'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
