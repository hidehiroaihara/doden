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
 * 給与計算用の丸め後集計（forMonth の $useRoundedWork）を検証する。
 * 打刻一覧の「丸め後」列の月合計と実働時間が一致することを保証する。
 */
class RoundedWorkAggregationTest extends TestCase
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
        Setting::setValue('salary_round_mode', 'minutes');
        Setting::setValue('salary_round_minutes', '30');
        Setting::setValue('salary_round_rule', 'floor');
        Setting::setValue('legal_holiday_dows', 'sunday');
        Setting::setValue('prescribed_holiday_dows', 'saturday');
    }

    private function summary(User $user, bool $rounded): array
    {
        return $this->service->forMonth('2026-09', collect([$user]), $rounded)['users'][0];
    }

    /** 端数のある実労働は、丸め後モードで30分単位に切り捨てられる。 */
    public function test_rounded_mode_truncates_work_minutes(): void
    {
        $user = User::factory()->create();
        // 月曜 09:00-18:13 = 553分
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => '2026-09-07',
            'clock_in_at' => Carbon::parse('2026-09-07 09:00:00'),
            'clock_out_at' => Carbon::parse('2026-09-07 18:13:00'),
        ]);

        $raw = $this->summary($user, false);
        $rounded = $this->summary($user, true);

        $this->assertSame(553, $raw['total_work_minutes']);
        $this->assertSame(540, $rounded['total_work_minutes']);
        $this->assertSame(540, $rounded['weekday_work_minutes']);
    }

    /** 実働時間の合計は打刻一覧の「丸め後」月合計と一致する。 */
    public function test_rounded_total_matches_rounded_column(): void
    {
        $user = User::factory()->create();
        foreach ([['2026-09-07', '09:00:00', '17:47:00'], ['2026-09-08', '10:00:00', '19:05:00']] as [$date, $in, $out]) {
            Attendance::create([
                'user_id' => $user->id,
                'work_date' => $date,
                'clock_in_at' => Carbon::parse("{$date} {$in}"),
                'clock_out_at' => Carbon::parse("{$date} {$out}"),
            ]);
        }

        $rounded = $this->summary($user, true);

        $this->assertSame($rounded['total_rounded_minutes'], $rounded['total_work_minutes']);
        // 所定時間 + 法定外時間 = 総労働時間（平日）
        $this->assertSame(
            $rounded['weekday_work_minutes'],
            $rounded['weekday_within_statutory_minutes'] + $rounded['weekday_statutory_overtime_minutes'],
        );
    }

    /** 丸めで削られた分が深夜帯なら、深夜時間も減る。 */
    public function test_rounded_mode_shortens_night_minutes(): void
    {
        $user = User::factory()->create();
        // 月曜 20:00-23:20 = 200分 → 30分切捨で180分（実効退勤 23:00）
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => '2026-09-07',
            'clock_in_at' => Carbon::parse('2026-09-07 20:00:00'),
            'clock_out_at' => Carbon::parse('2026-09-07 23:20:00'),
        ]);

        $raw = $this->summary($user, false);
        $rounded = $this->summary($user, true);

        $this->assertSame(80, $raw['night_minutes']);
        $this->assertSame(60, $rounded['night_minutes']);
        $this->assertSame(180, $rounded['total_work_minutes']);
    }

    /** 休憩をまたぐ打刻でも、丸め分は労働時間側から差し引かれる。 */
    public function test_rounded_mode_skips_break_when_rewinding(): void
    {
        $user = User::factory()->create();
        // 09:00-18:20、休憩60分 → 実労働500分 → 30分切捨で480分
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => '2026-09-07',
            'clock_in_at' => Carbon::parse('2026-09-07 09:00:00'),
            'clock_out_at' => Carbon::parse('2026-09-07 18:20:00'),
            'break_minutes' => 60,
        ]);

        $rounded = $this->summary($user, true);

        $this->assertSame(480, $rounded['total_work_minutes']);
        $this->assertSame(60, $rounded['total_break_minutes']);
    }

    /**
     * 22時を大きく超える打刻を2日ぶん作る。
     * 深夜帯の境界は22:00固定なので、日ごとに丸めた実労働から切り出した深夜は端数になる。
     */
    private function createNightShifts(User $user): void
    {
        // 月 17:57-23:32 = 335分 → 丸め後330分（実効退勤23:27）→ 深夜87分
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => '2026-09-07',
            'clock_in_at' => Carbon::parse('2026-09-07 17:57:00'),
            'clock_out_at' => Carbon::parse('2026-09-07 23:32:00'),
        ]);
        // 火 17:56-23:34 = 338分 → 丸め後330分（実効退勤23:26）→ 深夜86分
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => '2026-09-08',
            'clock_in_at' => Carbon::parse('2026-09-08 17:56:00'),
            'clock_out_at' => Carbon::parse('2026-09-08 23:34:00'),
        ]);
    }

    /** 深夜の月合計も丸め単位に揃う（既定ON）。173分 → 150分。 */
    public function test_night_total_is_rounded_by_default(): void
    {
        $user = User::factory()->create();
        $this->createNightShifts($user);

        $rounded = $this->summary($user, true);

        $this->assertSame(150, $rounded['night_minutes']);
        $this->assertSame(150, $rounded['weekday_night_minutes']);
        $this->assertSame(660, $rounded['total_work_minutes']);
    }

    /** 設定OFFなら従来どおり端数のまま（173分）。 */
    public function test_night_total_keeps_remainder_when_setting_off(): void
    {
        Setting::setValue('salary_round_night_total', '0');
        $user = User::factory()->create();
        $this->createNightShifts($user);

        $rounded = $this->summary($user, true);

        $this->assertSame(173, $rounded['night_minutes']);
        $this->assertSame(173, $rounded['weekday_night_minutes']);
        // 実働時間の丸めは設定に関係なく効き続ける
        $this->assertSame(660, $rounded['total_work_minutes']);
    }

    /** 丸め後モードでない集計（ダッシュボード等）には影響しない。 */
    public function test_night_total_rounding_only_applies_to_rounded_mode(): void
    {
        $user = User::factory()->create();
        $this->createNightShifts($user);

        $raw = $this->summary($user, false);

        $this->assertSame(186, $raw['night_minutes']);
        $this->assertSame(673, $raw['total_work_minutes']);
    }

    /** 遅刻・早退は打刻時刻そのままで判定する（丸めの影響を受けない）。 */
    public function test_late_and_early_leave_use_raw_punch_times(): void
    {
        $user = User::factory()->create();
        // 所定 09:00-18:00。09:10 出勤 / 17:50 退勤（実労働520分 → 丸め後510分）
        Attendance::create([
            'user_id' => $user->id,
            'work_date' => '2026-09-07',
            'clock_in_at' => Carbon::parse('2026-09-07 09:10:00'),
            'clock_out_at' => Carbon::parse('2026-09-07 17:50:00'),
        ]);

        $raw = $this->summary($user, false);
        $rounded = $this->summary($user, true);

        $this->assertSame($raw['late_minutes_weekday'], $rounded['late_minutes_weekday']);
        $this->assertSame($raw['early_leave_minutes_weekday'], $rounded['early_leave_minutes_weekday']);
        $this->assertSame(10, $rounded['late_minutes_weekday']);
        $this->assertSame(10, $rounded['early_leave_minutes_weekday']);
    }
}
