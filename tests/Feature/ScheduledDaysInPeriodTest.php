<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\FiscalYearHoliday;
use App\Models\Setting;
use App\Services\AttendanceSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 所定労働日数の休日判定が年度設定を優先し、未作成時は勤怠設定へフォールバックすることを検証する。
 */
class ScheduledDaysInPeriodTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceSummaryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AttendanceSummaryService::class);
    }

    /** 勤怠設定フォールバック: 日曜=法定・土曜=所定 → 月〜金のみカウント */
    public function test_counts_weekdays_using_attendance_settings_when_no_fiscal_year(): void
    {
        Setting::setValue('legal_holiday_dows', 'sunday');
        Setting::setValue('prescribed_holiday_dows', 'saturday');

        // 2026-06-01(月) 〜 2026-06-07(日) → 平日5日
        $days = $this->service->scheduledDaysInPeriod('2026-06-01', '2026-06-07');

        $this->assertSame(5, $days);
    }

    /** 年度設定優先: 勤怠設定と異なる休日でも年度側を参照する */
    public function test_fiscal_year_overrides_attendance_settings(): void
    {
        Setting::setValue('legal_holiday_dows', 'sunday');
        Setting::setValue('prescribed_holiday_dows', '');

        $fy = FiscalYear::firstOrCreate(
            ['year' => 2026],
            ['work_hours_per_day_minutes' => 480],
        );
        $fy->holidays()->delete();
        foreach ([1, 2, 3, 4, 5] as $dow) {
            FiscalYearHoliday::create(['fiscal_year_id' => $fy->id, 'dow' => $dow, 'type' => 'weekday']);
        }
        FiscalYearHoliday::create(['fiscal_year_id' => $fy->id, 'dow' => 0, 'type' => 'legal']);
        FiscalYearHoliday::create(['fiscal_year_id' => $fy->id, 'dow' => 6, 'type' => 'prescribed']);

        // 勤怠設定では土曜も平日だが、年度設定では所定休日 → 5日
        $days = $this->service->scheduledDaysInPeriod('2026-06-01', '2026-06-07');
        $this->assertSame(5, $days);

        // 年度設定で水曜を所定休日に変更 → 4日
        FiscalYearHoliday::where('fiscal_year_id', $fy->id)->where('dow', 3)->update(['type' => 'prescribed']);
        $days = $this->service->scheduledDaysInPeriod('2026-06-01', '2026-06-07');
        $this->assertSame(4, $days);
    }
}
