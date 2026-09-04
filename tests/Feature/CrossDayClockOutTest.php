<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Setting;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 日跨ぎ勤務（前日出勤→翌日退勤）の打刻を検証する。
 */
class CrossDayClockOutTest extends TestCase
{
    use RefreshDatabase;

    private function terminal(): Terminal
    {
        return Terminal::create([
            'name' => 'テスト端末',
            'terminal_id' => 'store1-testxx',
            'terminal_key' => Terminal::generateKey(),
            'is_active' => true,
        ]);
    }

    private function punchParams(User $user, Terminal $terminal, array $extra = []): array
    {
        return array_merge([
            'user_id' => $user->id,
            'terminal_id' => $terminal->terminal_id,
            'terminal_key' => $terminal->terminal_key,
        ], $extra);
    }

    public function test_today_endpoint_returns_yesterdays_open_shift_before_boundary(): void
    {
        Setting::setValue('punch_use_photo', '0');
        Setting::setValue('punch_day_boundary_hour', '5');
        $terminal = $this->terminal();
        $user = User::factory()->create();

        $yesterday = Carbon::today()->subDay();
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => $yesterday->toDateString(),
            'clock_in_at' => $yesterday->copy()->setTime(22, 0),
        ]);

        Carbon::setTestNow(Carbon::today()->setTime(1, 0));

        $response = $this->getJson('/api/attendance/today?'.http_build_query([
            'user_id' => $user->id,
            'terminal_id' => $terminal->terminal_id,
            'terminal_key' => $terminal->terminal_key,
        ]));

        $response->assertOk();
        $response->assertJsonPath('attendance.work_date', $yesterday->toDateString());
        $response->assertJsonPath('attendance.clock_out_at', null);

        Carbon::setTestNow();
    }

    public function test_clock_out_works_on_next_day_after_yesterday_clock_in(): void
    {
        Setting::setValue('punch_use_photo', '0');
        Setting::setValue('punch_day_boundary_hour', '5');
        $terminal = $this->terminal();
        $user = User::factory()->create();

        $yesterday = Carbon::today()->subDay();
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => $yesterday->toDateString(),
            'clock_in_at' => $yesterday->copy()->setTime(22, 0),
        ]);

        Carbon::setTestNow(Carbon::today()->setTime(1, 0));

        $response = $this->postJson('/api/attendance/clock-out', $this->punchParams($user, $terminal));

        $response->assertOk();

        $attendance = Attendance::where('user_id', $user->id)->first();
        $this->assertNotNull($attendance->clock_out_at);
        $this->assertSame($yesterday->toDateString(), $attendance->work_date->toDateString());

        Carbon::setTestNow();
    }

    public function test_clock_in_blocked_when_open_shift_on_same_business_day(): void
    {
        Setting::setValue('punch_use_photo', '0');
        Setting::setValue('punch_day_boundary_hour', '5');
        $terminal = $this->terminal();
        $user = User::factory()->create();

        $yesterday = Carbon::today()->subDay();
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => $yesterday->toDateString(),
            'clock_in_at' => $yesterday->copy()->setTime(22, 0),
        ]);

        Carbon::setTestNow(Carbon::today()->setTime(3, 0));

        $response = $this->postJson('/api/attendance/clock-in', $this->punchParams($user, $terminal));

        $response->assertStatus(409);
        $response->assertJsonPath('message', '未退勤の打刻があります。先に退勤してください');

        Carbon::setTestNow();
    }

    public function test_clock_in_allowed_after_boundary_with_stale_open_shift(): void
    {
        Setting::setValue('punch_use_photo', '0');
        Setting::setValue('punch_day_boundary_hour', '5');
        $terminal = $this->terminal();
        $user = User::factory()->create();

        $yesterday = Carbon::today()->subDay();
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => $yesterday->toDateString(),
            'clock_in_at' => $yesterday->copy()->setTime(22, 0),
        ]);

        Carbon::setTestNow(Carbon::today()->setTime(6, 0));

        $response = $this->postJson('/api/attendance/clock-in', $this->punchParams($user, $terminal));

        $response->assertOk();

        $this->assertSame(2, Attendance::where('user_id', $user->id)->count());
        $todayShift = Attendance::where('user_id', $user->id)
            ->where('work_date', Carbon::today()->toDateString())
            ->first();
        $this->assertNotNull($todayShift);
        $this->assertNotNull($todayShift->clock_in_at);

        Carbon::setTestNow();
    }
}
