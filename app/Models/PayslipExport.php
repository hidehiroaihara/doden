<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayslipExport extends Model
{
    protected $fillable = [
        'business_location_id',
        'period_from',
        'period_to',
        'status',
        'total_count',
        'processed_count',
        'file_path',
        'file_name',
        'file_size',
        'error_message',
        'requested_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_count' => 'integer',
            'processed_count' => 'integer',
            'file_size' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class);
    }

    public function progressPercent(): int
    {
        if ($this->total_count <= 0) {
            return $this->status === 'completed' ? 100 : 0;
        }

        return (int) min(100, round($this->processed_count / $this->total_count * 100));
    }
}
