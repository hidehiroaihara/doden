<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Setting;
use App\Models\User;
use App\Services\AttendanceSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 同一 work_date の複数シフトを1日として集計するロジックを検証する。
 */
class DailyShiftAggregationTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceSummaryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AttendanceSummaryService::class);

        Setting::setValue('punch_use_photo', '0');
        Setting::setValue('work_start_time', '09:00');
        Setting::setValue('work_end_time', '18:00');
        Setting::setValue('work_hours_per_day', '480');
        Setting::setValue('default_break_minutes', '0');
        Setting::setValue('break_start_time', null);
        Setting::setValue('break_end_time', null);
        Setting::setValue('salary_round_minutes', '15');
        Setting::setValue('salary_round_rule', 'floor');
        Setting::setValue('legal_holiday_dows', 'sunday');
        Setting::setValue('prescribed_holiday_dows', 'saturday');
    }

    /** 同日2シフト: 出勤日数は1日、労働時間は合算。 */
    public function test_same_day_two_shifts_count_as_one_work_day(): void
    {
        $user = User::factory()->create();
        $date = Carbon::parse('2026-09-07'); // 月曜

        Attendance::create([
            'user_id' => $user->id,
            'work_date' => $date->toDateString(),
            'clock_in_at' => $date->copy()->setTime(9, 0),
            'clock_out_at' => $date->copy()->setTime(12, 0),
        ]);
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => $date->toDateString(),
            'clock_in_at' => $date->copy()->setTime(18, 0),
            'clock_out_at' => $date->copy()->setTime(22, 0),
        ]);

        $result = $this->service->forMonth('2026-09', collect([$user]));
        $summary = $result['users'][0];

        $this->assertSame(1, $summary['work_days']);
        $this->assertSame(1, $summary['weekday_work_days']);
        // 3h + 4h = 7h = 420分
        $this->assertSame(420, $summary['weekday_work_minutes']);
        $this->assertSame(420, $summary['total_work_minutes']);
    }

    /** 同日2シフト合計12h: 法定外は日合計で4h（シフト単位0+0ではない）。 */
    public function test_statutory_overtime_on_daily_total_not_per_shift(): void
    {
        $user = User::factory()->create();
        $date = Carbon::parse('2026-09-08'); // 火曜

        Attendance::create([
            'user_id' => $user->id,
            'work_date' => $date->toDateString(),
            'clock_in_at' => $date->copy()->setTime(6, 0),
            'clock_out_at' => $date->copy()->setTime(12, 0),
        ]);
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => $date->toDateString(),
            'clock_in_at' => $date->copy()->setTime(13, 0),
            'clock_out_at' => $date->copy()->setTime(19, 0),
        ]);

        $result = $this->service->forMonth('2026-09', collect([$user]));
        $summary = $result['users'][0];

        // 6h + 6h = 12h → 法定外 4h
        $this->assertSame(720, $summary['weekday_work_minutes']);
        $this->assertSame(240, $summary['weekday_statutory_overtime_minutes']);
        $this->assertSame(240, $summary['statutory_overtime_minutes']);
    }

    /** 同日2シフト: 所定超過残業も日合計で判定（8h所定 + 12h実働 = 4h残業）。 */
    public function test_scheduled_overtime_on_daily_total(): void
    {
        $user = User::factory()->create();
        $date = Carbon::parse('2026-09-09'); // 水曜

        Attendance::create([
            'user_id' => $user->id,
            'work_date' => $date->toDateString(),
            'clock_in_at' => $date->copy()->setTime(6, 0),
            'clock_out_at' => $date->copy()->setTime(12, 0),
        ]);
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => $date->toDateString(),
            'clock_in_at' => $date->copy()->setTime(13, 0),
            'clock_out_at' => $date->copy()->setTime(19, 0),
        ]);

        $result = $this->service->forMonth('2026-09', collect([$user]));
        $summary = $result['users'][0];

        $this->assertSame(240, $summary['weekday_overtime_minutes']);
        $this->assertSame(240, $summary['overtime_minutes']);
    }

    /** 別日2シフトは2日としてカウント。 */
    public function test_different_work_dates_count_as_two_days(): void
    {
        $user = User::factory()->create();

        Attendance::create([
            'user_id' => $user->id,
            'work_date' => '2026-09-07',
            'clock_in_at' => Carbon::parse('2026-09-07 09:00:00'),
            'clock_out_at' => Carbon::parse('2026-09-07 17:00:00'),
        ]);
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => '2026-09-08',
            'clock_in_at' => Carbon::parse('2026-09-08 02:00:00'),
            'clock_out_at' => Carbon::parse('2026-09-08 06:00:00'),
        ]);

        $result = $this->service->forMonth('2026-09', collect([$user]));
        $summary = $result['users'][0];

        $this->assertSame(2, $summary['work_days']);
        $this->assertSame(2, $summary['weekday_work_days']);
    }
}
