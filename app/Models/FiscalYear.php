<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 年度設定(se15)。年度ごとの休日設定・所定労働時間を保持し、
 * 月別日数表・年間/月平均の所定労働日数・時間を算出する。
 */
class FiscalYear extends Model
{
    protected $fillable = [
        'year',
        'name',
        'work_hours_per_day_minutes',
        'monthly_avg_work_days',
        'monthly_avg_work_hours',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'work_hours_per_day_minutes' => 'integer',
            'monthly_avg_work_days' => 'decimal:1',
            'monthly_avg_work_hours' => 'decimal:1',
        ];
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(FiscalYearHoliday::class);
    }

    public function customHolidays(): HasMany
    {
        return $this->hasMany(FiscalYearCustomHoliday::class);
    }

    /** 指定日(Y-m-d)が属する暦年の年度を返す（無ければ null）。 */
    public static function forDate(?string $date): ?self
    {
        if (! $date) {
            return null;
        }

        return static::with(['holidays', 'customHolidays'])
            ->where('year', (int) substr($date, 0, 4))
            ->first();
    }

    /** dow(0-6,7=祝日) => type の写像。 */
    public function holidayTypeMap(): array
    {
        $map = [];
        foreach ($this->holidays as $h) {
            $map[$h->dow] = $h->type;
        }

        return $map;
    }

    public function workHoursPerDayMinutes(): int
    {
        return (int) ($this->work_hours_per_day_minutes ?: 480);
    }

    /**
     * 月別日数表。各月の [所定労働日数, 休日数, 暦日数] を返す（合計行含む）。
     *
     * @return array{months: array<int, array{month:int, work_days:int, holidays:int, calendar_days:int}>, total: array{work_days:int, holidays:int, calendar_days:int}}
     */
    public function monthlyDayTable(): array
    {
        $types = $this->holidayTypeMap();
        $custom = $this->customHolidays->pluck('date')
            ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d)
            ->flip();

        $months = [];
        $totalWork = 0;
        $totalHoliday = 0;
        $totalCalendar = 0;

        for ($m = 1; $m <= 12; $m++) {
            $start = CarbonImmutable::create($this->year, $m, 1);
            $daysInMonth = (int) $start->daysInMonth;
            $work = 0;
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $date = $start->day($d);
                $dow = (int) $date->dayOfWeek; // 0=Sun..6=Sat
                $type = $types[$dow] ?? 'weekday';
                $isCustom = $custom->has($date->format('Y-m-d'));
                if ($type === 'weekday' && ! $isCustom) {
                    $work++;
                }
            }
            $holidays = $daysInMonth - $work;
            $months[] = ['month' => $m, 'work_days' => $work, 'holidays' => $holidays, 'calendar_days' => $daysInMonth];
            $totalWork += $work;
            $totalHoliday += $holidays;
            $totalCalendar += $daysInMonth;
        }

        return [
            'months' => $months,
            'total' => ['work_days' => $totalWork, 'holidays' => $totalHoliday, 'calendar_days' => $totalCalendar],
        ];
    }

    public function annualScheduledDays(): int
    {
        return $this->monthlyDayTable()['total']['work_days'];
    }

    /** 所定労働日数(月平均)。明示設定があれば優先、無ければ年間÷12。 */
    public function effectiveMonthlyAvgDays(): float
    {
        if ($this->monthly_avg_work_days !== null) {
            return (float) $this->monthly_avg_work_days;
        }

        return round($this->annualScheduledDays() / 12, 1);
    }

    /** 所定労働時間(月平均)。明示設定があれば優先、無ければ月平均日数×1日の所定労働時間。 */
    public function effectiveMonthlyAvgHours(): float
    {
        if ($this->monthly_avg_work_hours !== null) {
            return (float) $this->monthly_avg_work_hours;
        }

        return round($this->effectiveMonthlyAvgDays() * ($this->workHoursPerDayMinutes() / 60), 1);
    }
}
