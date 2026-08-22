<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 税制措置マスタ（定額減税など時限的な税制対応）。
 */
class TaxMeasure extends Model
{
    public const TYPE_FLAT_TAX = 'flat_tax_reduction';

    public const TYPE_LABELS = [
        'flat_tax_reduction' => '定額減税（所得税）',
    ];

    protected $fillable = [
        'type',
        'name',
        'target_year',
        'start_period',
        'end_period',
        'per_person_amount',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'target_year' => 'integer',
            'per_person_amount' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    /** 指定 period_key（Y-m）が適用期間内か。 */
    public function covers(string $periodKey): bool
    {
        if ($periodKey < $this->start_period) {
            return false;
        }

        return $this->end_period === null || $periodKey <= $this->end_period;
    }
}
