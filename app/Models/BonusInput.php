<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonusInput extends Model
{
    protected $fillable = [
        'payroll_run_id',
        'user_id',
        'gross_amount',
        'previous_month_taxable',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'integer',
            'previous_month_taxable' => 'integer',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
