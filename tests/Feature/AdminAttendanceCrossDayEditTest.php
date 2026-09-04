<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Attendance;
use App\Models\User;
use App\Services\AttendanceSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminAttendanceCrossDayEditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => '管理者',
            'email' => 'admin@example.com',
            'password' => 'password',
            'role' => 1,
        ]);
    }

    public function test_admin_can_save_next_day_clock_out(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $workDate = '2026-01-31';

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'work_date' => $workDate,
            'clock_in_at' => Carbon::parse("{$workDate} 22:00:00"),
        ]);

        $response = $this->actingAs($admin, 'admin')->put(route('admin.attendances.update', $attendance), [
            'clock_in_time' => '22:00',
            'clock_out_time' => '02:00',
            'clock_out_next_day' => true,
            'breaks' => [],
            'return_to' => 'monthly',
            'return_month' => '2026-01',
        ]);

        $response->assertRedirect(route('admin.attendances.monthly', ['month' => '2026-01']));

        $attendance->refresh();
        $this->assertSame('2026-02-01 02:00:00', $attendance->clock_out_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-31', $attendance->work_date->format('Y-m-d'));
    }

    public function test_form_data_returns_next_day_flags(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $workDate = '2026-01-31';

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'work_date' => $workDate,
            'clock_in_at' => Carbon::parse("{$workDate} 22:00:00"),
            'clock_out_at' => Carbon::parse('2026-02-01 02:00:00'),
        ]);

        $response = $this->actingAs($admin, 'admin')->getJson(route('admin.attendances.form-data', $attendance));

        $response->assertOk();
        $response->assertJsonPath('clock_in_time', '22:00');
        $response->assertJsonPath('clock_out_time', '02:00');
        $response->assertJsonPath('clock_out_next_day', true);
    }

    public function test_next_day_clock_out_counts_night_minutes_correctly(): void
    {
        $user = User::factory()->create();
        $workDate = '2026-01-29'; // 木曜（平日）

        Attendance::create([
            'user_id' => $user->id,
            'work_date' => $workDate,
            'clock_in_at' => Carbon::parse("{$workDate} 22:00:00"),
            'clock_out_at' => Carbon::parse('2026-01-30 02:00:00'),
        ]);

        $summary = app(AttendanceSummaryService::class)->forMonth('2026-01', collect([$user]));
        $row = $summary['users'][0];

        $this->assertSame(240, $row['weekday_night_minutes']);
    }
}
