<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomeTaxStatementOverride extends Model
{
    protected $fillable = [
        'year',
        'month',
        'form_type',
        'business_location_id',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'data' => 'array',
        ];
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class);
    }

    /**
     * 手入力データのデフォルト構造。
     *
     * @return array<string, mixed>
     */
    public static function defaultData(): array
    {
        $emptyRow = [
            'payment_date' => null,
            'employee_count' => 0,
            'payment_amount' => 0,
            'tax_amount' => 0,
        ];

        return [
            'daily_worker' => $emptyRow,
            'retirement' => $emptyRow,
            'professional_fee' => $emptyRow,
            'year_end_adjustment_shortage' => 0,
            'year_end_adjustment_overpayment' => 0,
            'late_payment_tax' => 0,
            'remarks' => '',
        ];
    }

    public static function findFor(int $year, int $month, string $formType, ?int $locationId = null): ?self
    {
        return static::query()
            ->where('year', $year)
            ->where('month', $month)
            ->where('form_type', $formType)
            ->when(
                $locationId,
                fn ($q) => $q->where('business_location_id', $locationId),
                fn ($q) => $q->whereNull('business_location_id'),
            )
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public static function mergedData(?self $record): array
    {
        return array_replace_recursive(static::defaultData(), $record?->data ?? []);
    }
}
