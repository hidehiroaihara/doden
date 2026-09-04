<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClearAttendanceBreakMinutesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_clear_break_minutes(): void
    {
        $user = User::factory()->create();
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => Carbon::today()->toDateString(),
            'clock_in_at' => Carbon::today()->setTime(9, 0),
            'clock_out_at' => Carbon::today()->setTime(18, 0),
            'break_minutes' => 60,
        ]);

        $this->artisan('attendance:clear-break-minutes', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(60, Attendance::first()->break_minutes);
    }

    public function test_clears_matching_attendance_break_minutes(): void
    {
        $user = User::factory()->create();
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => Carbon::today()->toDateString(),
            'clock_in_at' => Carbon::today()->setTime(9, 0),
            'clock_out_at' => Carbon::today()->setTime(18, 0),
            'break_minutes' => 60,
        ]);
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => Carbon::today()->subDay()->toDateString(),
            'clock_in_at' => Carbon::today()->subDay()->setTime(9, 0),
            'clock_out_at' => Carbon::today()->subDay()->setTime(18, 0),
            'break_minutes' => 30,
        ]);

        $this->artisan('attendance:clear-break-minutes', ['--no-interaction' => true])
            ->assertSuccessful();

        $records = Attendance::orderBy('work_date')->get();
        $this->assertSame(30, $records[0]->break_minutes);
        $this->assertNull($records[1]->break_minutes);
    }

    public function test_include_users_clears_user_break_minutes(): void
    {
        $user = User::factory()->create(['break_minutes' => 60]);

        $this->artisan('attendance:clear-break-minutes', [
            '--include-users' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertNull($user->fresh()->break_minutes);
    }
}
