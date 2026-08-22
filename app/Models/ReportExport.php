<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    public const TYPE_LABELS = [
        'withholding_book' => '源泉徴収簿',
        'wage_ledger' => '賃金台帳',
        'roster' => '労働者名簿',
        'tax_slip' => '退職者の源泉徴収票',
    ];

    protected $fillable = [
        'report_type',
        'format',
        'year',
        'business_location_id',
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
            'year' => 'integer',
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

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->report_type] ?? $this->report_type;
    }

    public function progressPercent(): int
    {
        if ($this->total_count <= 0) {
            return $this->status === 'completed' ? 100 : 0;
        }

        return (int) min(100, round($this->processed_count / $this->total_count * 100));
    }
}
