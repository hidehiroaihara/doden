<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\FiscalYear;
use App\Models\FiscalYearCustomHoliday;
use App\Models\FiscalYearHoliday;
use App\Models\Setting;
use App\Models\User;
use App\Services\AttendanceSummaryService;
use App\Services\HolidayCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 打刻一覧・月別打刻表で使う休日判定（HolidayCalendar）のテスト。
 * 給与計算側の AttendanceSummaryService::dayType() と判定が一致することも確認する。
 */
class HolidayCalendarTest extends TestCase
{
    use RefreshDatabase;

    private HolidayCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar = app(HolidayCalendar::class);

        Setting::setValue('work_start_time', '09:00');
        Setting::setValue('work_end_time', '18:00');
        Setting::setValue('work_hours_per_day', '480');
        Setting::setValue('default_break_minutes', '0');
        Setting::setValue('break_start_time', null);
        Setting::setValue('break_end_time', null);
        Setting::setValue('legal_holiday_dows', 'sunday');
        Setting::setValue('prescribed_holiday_dows', 'saturday');
    }

    /** 年度設定: 水曜=所定休日、祝日=所定休日、祝日名つき。 */
    private function fiscalYear2026(): FiscalYear
    {
        $fy = FiscalYear::firstOrCreate(['year' => 2026], ['work_hours_per_day_minutes' => 600]);
        FiscalYearHoliday::where('fiscal_year_id', $fy->id)->delete();
        FiscalYearCustomHoliday::where('fiscal_year_id', $fy->id)->delete();

        foreach ([0 => 'weekday', 1 => 'weekday', 2 => 'weekday', 3 => 'prescribed', 4 => 'weekday', 5 => 'weekday', 6 => 'weekday', 7 => 'prescribed'] as $dow => $type) {
            FiscalYearHoliday::create(['fiscal_year_id' => $fy->id, 'dow' => $dow, 'type' => $type]);
        }
        FiscalYearCustomHoliday::create([
            'fiscal_year_id' => $fy->id,
            'date' => '2026-09-21',
            'label' => '敬老の日',
            'source' => FiscalYearCustomHoliday::SOURCE_CABINET_OFFICE,
        ]);

        return $fy->fresh();
    }

    /** 年度設定が無い場合は基本設定の曜日指定（日=法定 / 土=所定）へフォールバックする。 */
    public function test_falls_back_to_setting_dows_without_fiscal_year(): void
    {
        FiscalYear::query()->delete();

        $this->assertSame('legal', $this->calendar->forDate('2026-09-06')['type']); // 日曜
        $this->assertSame('prescribed', $this->calendar->forDate('2026-09-05')['type']); // 土曜
        $this->assertSame('weekday', $this->calendar->forDate('2026-09-07')['type']); // 月曜
    }

    /** 年度設定があれば曜日→区分の設定が優先される（土日が平日になるケース）。 */
    public function test_fiscal_year_dow_map_takes_precedence(): void
    {
        $this->fiscalYear2026();

        $this->assertSame('weekday', $this->calendar->forDate('2026-09-06')['type']); // 日曜でも平日
        $this->assertSame('prescribed', $this->calendar->forDate('2026-09-09')['type']); // 水曜が所定休日
    }

    /** 祝日は区分と祝日名の両方を返す。 */
    public function test_custom_holiday_returns_label(): void
    {
        $this->fiscalYear2026();

        $info = $this->calendar->forDate('2026-09-21');
        $this->assertSame('prescribed', $info['type']);
        $this->assertSame('敬老の日', $info['label']);
    }

    /** 年度設定の「祝日」区分を法定休日にすると祝日も法定休日になる。 */
    public function test_holiday_type_follows_fiscal_year_setting(): void
    {
        $fy = $this->fiscalYear2026();
        FiscalYearHoliday::where('fiscal_year_id', $fy->id)
            ->where('dow', HolidayCalendar::HOLIDAY_DOW)
            ->update(['type' => 'legal']);

        $this->assertSame('legal', $this->calendar->forDate('2026-09-21')['type']);
    }

    /** forRange は期間内の全日付を返す。 */
    public function test_for_range_covers_every_day(): void
    {
        $this->fiscalYear2026();

        $range = $this->calendar->forRange('2026-09-20', '2026-09-23');

        $this->assertSame(['2026-09-20', '2026-09-21', '2026-09-22', '2026-09-23'], array_keys($range));
        $this->assertSame('敬老の日', $range['2026-09-21']['label']);
    }

    /** 画面表示の区分と給与計算の区分が一致する（祝日=法定休日に設定した場合）。 */
    public function test_matches_payroll_day_type_for_custom_holiday(): void
    {
        $fy = $this->fiscalYear2026();
        FiscalYearHoliday::where('fiscal_year_id', $fy->id)
            ->where('dow', HolidayCalendar::HOLIDAY_DOW)
            ->update(['type' => 'legal']);

        $user = User::factory()->create();
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => '2026-09-21',
            'clock_in_at' => Carbon::parse('2026-09-21 09:00:00'),
            'clock_out_at' => Carbon::parse('2026-09-21 13:00:00'),
        ]);

        $summary = app(AttendanceSummaryService::class)->forMonth('2026-09', collect([$user]))['users'][0];

        $this->assertSame('legal', $this->calendar->forDate('2026-09-21')['type']);
        // 給与計算側も法定休日として計上している
        $this->assertSame(1, $summary['legal_holiday_days']);
        $this->assertSame(240, $summary['legal_holiday_minutes']);
        $this->assertSame(0, $summary['prescribed_holiday_days']);
    }
}
