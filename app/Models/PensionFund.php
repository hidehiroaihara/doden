<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * 厚生年金基金（1事業所に複数）。掛金料率は適用開始月単位・給与/賞与別に保持する。
 */
class PensionFund extends Model
{
    protected $fillable = [
        'business_location_id',
        'name',
        'number',
        'office_number',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(PensionFundRate::class);
    }

    /**
     * 指定日に適用される掛金料率を返す（無ければ null）。
     */
    public function rateForDate(string $date): ?PensionFundRate
    {
        return $this->rates
            ->filter(fn (PensionFundRate $r) => $r->effective_from->toDateString() <= $date)
            ->sortByDesc('effective_from')
            ->first();
    }

    /**
     * 事業所の全基金について、指定日・給与/賞与の被保険者負担・事業主負担率（/1,000）を合算する。
     *
     * @param  Collection<int, PensionFund>  $funds
     * @return array{employee: float, employer: float}
     */
    public static function totalRates(Collection $funds, string $date, string $payKind): array
    {
        $employee = 0.0;
        $employer = 0.0;

        foreach ($funds as $fund) {
            $rate = $fund->rateForDate($date);
            if (! $rate) {
                continue;
            }
            if ($payKind === 'bonus') {
                $employee += (float) $rate->bonus_employee_rate;
                $employer += (float) $rate->bonus_employer_rate;
            } else {
                $employee += (float) $rate->salary_employee_rate;
                $employer += (float) $rate->salary_employer_rate;
            }
        }

        return ['employee' => $employee, 'employer' => $employer];
    }
}
