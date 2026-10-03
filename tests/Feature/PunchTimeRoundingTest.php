<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Setting;
use App\Models\User;
use App\Services\AttendanceSummaryService;
use App\Support\PunchRounding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 給与計算の打刻時刻丸め（salary_round_mode=time）を検証する。
 * 出勤=30分グリッドへ切上げ / 退勤=切捨て、遅刻早退も丸め後で判定。
 */
class PunchTimeRoundingTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceSummaryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AttendanceSummaryService::class);

        Setting::setValue('punch_use_photo', '0');
        Setting::setValue('work_start_time', '08:00');
        Setting::setValue('work_end_time', '17:00');
        Setting::setValue('work_hours_per_day', '480');
        Setting::setValue('default_break_minutes', '60');
        Setting::setValue('break_start_time', '12:00');
        Setting::setValue('break_end_time', '13:00');
        Setting::setValue('salary_round_mode', 'time');
        Setting::setValue('salary_round_minutes', '30');
        Setting::setValue('legal_holiday_dows', 'sunday');
        Setting::setValue('prescribed_holiday_dows', 'saturday');
    }

    private function punch(User $user, string $date, string $in, string $out): void
    {
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => $date,
            'clock_in_at' => Carbon::parse("{$date} {$in}"),
            'clock_out_at' => Carbon::parse("{$date} {$out}"),
        ]);
    }

    private function summary(User $user): array
    {
        return $this->service->forMonth('2026-09', collect([$user]), true)['users'][0];
    }

    public function test_grid_rounding_ceils_in_and_floors_out(): void
    {
        $s = PunchRounding::settings();
        $fmt = fn (string $in, string $out) => array_map(
            fn (Carbon $c) => $c->format('H:i'),
            PunchRounding::interval(Carbon::parse("2026-09-07 {$in}"), Carbon::parse("2026-09-07 {$out}"), $s),
        );

        $this->assertSame(['08:30', '17:00'], $fmt('08:09:00', '17:15:00'));
        $this->assertSame(['08:00', '17:30'], $fmt('08:00:00', '17:45:00'));
        $this->assertSame(['08:30', '17:30'], $fmt('08:30:00', '17:30:00'));
        $this->assertSame(['08:00', '17:00'], $fmt('08:00:42', '17:29:59'));
    }

    /** 8:09〜17:15（休憩60分）→ 8:30〜17:00 の 7:30。遅刻30分として扱う。 */
    public function test_payroll_uses_rounded_times_for_work_and_late(): void
    {
        $user = User::factory()->create();
        $this->punch($user, '2026-09-07', '08:09:00', '17:15:00'); // 月曜

        $sum = $this->summary($user);

        $this->assertSame(450, $sum['total_work_minutes']);
        $this->assertSame(450, $sum['total_rounded_minutes']);
        $this->assertSame(1, $sum['late_count']);
        $this->assertSame(30, $sum['late_minutes_weekday']);
        $this->assertSame(0, $sum['early_leave_count']);
    }

    /** 早退も丸め後の退勤で判定する（16:50 → 16:30 で早退30分）。 */
    public function test_early_leave_uses_rounded_clock_out(): void
    {
        $user = User::factory()->create();
        $this->punch($user, '2026-09-07', '08:00:00', '16:50:00');

        $sum = $this->summary($user);

        $this->assertSame(1, $sum['early_leave_count']);
        $this->assertSame(30, $sum['early_leave_minutes_weekday']);
        $this->assertSame(0, $sum['late_count']);
    }

    /** 同日2回の出退勤はシフトごとに丸めてから1日に合算する。 */
    public function test_two_shifts_same_day_are_rounded_each_and_summed(): void
    {
        $user = User::factory()->create();
        Setting::setValue('break_start_time', null);
        Setting::setValue('break_end_time', null);
        Setting::setValue('default_break_minutes', '0');
        $this->punch($user, '2026-09-07', '08:10:00', '11:50:00'); // 8:30〜11:30 = 180
        $this->punch($user, '2026-09-07', '13:05:00', '17:20:00'); // 13:30〜17:00 = 210

        $sum = $this->summary($user);

        $this->assertSame(1, $sum['work_days']);
        $this->assertSame(390, $sum['total_work_minutes']);
    }

    /** 丸めで退勤が出勤以前になる短時間打刻は労働0分。 */
    public function test_short_punch_within_one_slot_is_zero(): void
    {
        $user = User::factory()->create();
        Setting::setValue('break_start_time', null);
        Setting::setValue('break_end_time', null);
        $this->punch($user, '2026-09-07', '08:05:00', '08:25:00');

        $sum = $this->summary($user);

        $this->assertSame(0, $sum['total_work_minutes']);
    }
}
