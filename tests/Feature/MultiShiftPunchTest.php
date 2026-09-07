<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Setting;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 同一営業日に複数シフト（昼夜の別店舗勤務や早朝の新規出勤）を打刻できることを検証する。
 */
class MultiShiftPunchTest extends TestCase
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

    /** 同一日に別店舗で2シフト目を出勤できる（1シフト目は退勤済み）。 */
    public function test_second_shift_at_different_store_same_day_is_allowed(): void
    {
        Setting::setValue('punch_use_photo', '0');
        Setting::setValue('punch_day_boundary_hour', '5');
        $terminal = $this->terminal();

        $storeA = Department::create(['name' => '昼店舗']);
        $storeB = Department::create(['name' => '夜店舗']);
        $user = User::factory()->create(['department_id' => $storeA->id]);
        $user->departments()->attach([$storeA->id, $storeB->id]);

        // 昼: 店舗Aで出勤→退勤
        Carbon::setTestNow(Carbon::today()->setTime(9, 0));
        $this->postJson('/api/attendance/clock-in', $this->punchParams($user, $terminal, [
            'department_id' => $storeA->id,
        ]))->assertOk();

        Carbon::setTestNow(Carbon::today()->setTime(17, 0));
        $this->postJson('/api/attendance/clock-out', $this->punchParams($user, $terminal))->assertOk();

        // 夜: 店舗Bで再度出勤（同一営業日でも許可される）
        Carbon::setTestNow(Carbon::today()->setTime(18, 0));
        $this->postJson('/api/attendance/clock-in', $this->punchParams($user, $terminal, [
            'department_id' => $storeB->id,
        ]))->assertOk();

        $records = Attendance::where('user_id', $user->id)
            ->where('work_date', Carbon::today()->toDateString())
            ->orderBy('clock_in_at')
            ->get();

        $this->assertCount(2, $records);
        $this->assertSame($storeA->id, (int) $records[0]->department_id);
        $this->assertSame($storeB->id, (int) $records[1]->department_id);

        Carbon::setTestNow();
    }

    /** 未退勤のシフトがある間は2件目の出勤を拒否する。 */
    public function test_second_clock_in_blocked_while_open_shift_exists(): void
    {
        Setting::setValue('punch_use_photo', '0');
        Setting::setValue('punch_day_boundary_hour', '5');
        $terminal = $this->terminal();
        $user = User::factory()->create();

        Carbon::setTestNow(Carbon::today()->setTime(9, 0));
        $this->postJson('/api/attendance/clock-in', $this->punchParams($user, $terminal))->assertOk();

        // 退勤せずにもう一度出勤 → 409
        Carbon::setTestNow(Carbon::today()->setTime(12, 0));
        $this->postJson('/api/attendance/clock-in', $this->punchParams($user, $terminal))
            ->assertStatus(409)
            ->assertJsonPath('message', '未退勤の打刻があります。先に退勤してください');

        $this->assertSame(1, Attendance::where('user_id', $user->id)->count());

        Carbon::setTestNow();
    }

    /**
     * 前日に勤務済み（退勤済み）で、翌日の早朝（境界時刻前）に新規出勤できる。
     * このとき work_date は暦日（翌日）となり、前日の勤務と別レコードになる。
     */
    public function test_early_morning_new_shift_after_completed_previous_day(): void
    {
        Setting::setValue('punch_use_photo', '0');
        Setting::setValue('punch_day_boundary_hour', '5');
        $terminal = $this->terminal();
        $user = User::factory()->create();

        $yesterday = Carbon::today()->subDay();

        // 前日 6:00-10:00 勤務（退勤済み）
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => $yesterday->toDateString(),
            'clock_in_at' => $yesterday->copy()->setTime(6, 0),
            'clock_out_at' => $yesterday->copy()->setTime(10, 0),
        ]);

        // 翌日 2:00（境界5時前）に新規出勤 → 暦日（今日）で登録される
        Carbon::setTestNow(Carbon::today()->setTime(2, 0));
        $this->postJson('/api/attendance/clock-in', $this->punchParams($user, $terminal))->assertOk();

        $this->assertSame(2, Attendance::where('user_id', $user->id)->count());

        $newShift = Attendance::where('user_id', $user->id)
            ->where('work_date', Carbon::today()->toDateString())
            ->first();
        $this->assertNotNull($newShift);
        $this->assertNotNull($newShift->clock_in_at);
        $this->assertNull($newShift->clock_out_at);

        Carbon::setTestNow();
    }

    /**
     * 日跨ぎ夜勤（前日22時出勤、未退勤）の最中は、早朝2時にはまだ営業日=前日として
     * 未退勤ガードが働き、新規出勤は拒否される（継続中の夜勤を優先）。
     */
    public function test_early_morning_blocked_during_open_night_shift(): void
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

        Carbon::setTestNow(Carbon::today()->setTime(2, 0));
        $this->postJson('/api/attendance/clock-in', $this->punchParams($user, $terminal))
            ->assertStatus(409);

        $this->assertSame(1, Attendance::where('user_id', $user->id)->count());

        Carbon::setTestNow();
    }
}
