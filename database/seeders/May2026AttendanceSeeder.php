<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * 2026年5月分の打刻データ（休憩控除・一覧・CSV検証用）。
 *
 * 実行例:
 *   php artisan db:seed --class=May2026AttendanceSeeder
 *
 * 既存の同年月の attendances を削除してから再投入します（冪等）。
 */
class May2026AttendanceSeeder extends Seeder
{
    private const YEAR = 2026;

    private const MONTH = 5;

    /**
     * 平日ごとにローテーションするパターン（規定休憩 12:00〜13:00 を想定した検証ケース）
     *
     * break_minutes: null = 自動計算に任せる
     */
    private function scenarios(): array
    {
        return [
            // 通常終日（休憩帯と重なる → 上限まで控除されやすい）
            ['in' => '09:00', 'out' => '18:05', 'break_minutes' => null],
            // 早退（休憩開始より前に退勤 → 控除 0）
            ['in' => '09:00', 'out' => '11:45', 'break_minutes' => null],
            // 午後のみ（出勤が休憩終了後 → 控除 0）
            ['in' => '13:05', 'out' => '18:00', 'break_minutes' => null],
            // 休憩跨ぎ（12:30 出勤 → 重なりは 12:30〜13:00 の 30 分程度）
            ['in' => '12:30', 'out' => '18:00', 'break_minutes' => null],
            // 管理者指定休憩（自動計算より優先）
            ['in' => '09:00', 'out' => '18:00', 'break_minutes' => 30],
            // ちょうど休憩直前まで（退勤 12:00 → 重なりなし想定）
            ['in' => '09:00', 'out' => '12:00', 'break_minutes' => null],
            // 長時間勤務
            ['in' => '08:15', 'out' => '19:30', 'break_minutes' => null],
            // 欠勤（この日はレコードを作らない）
            null,
        ];
    }

    public function run(): void
    {
        $deleted = Attendance::query()
            ->whereYear('work_date', self::YEAR)
            ->whereMonth('work_date', self::MONTH)
            ->delete();

        $users = User::query()->where('is_active', true)->orderBy('id')->get();

        if ($users->isEmpty()) {
            $this->command->warn('アクティブユーザーがありません。UserSeeder 等でユーザーを作成してから再実行してください。');

            return;
        }

        $monthStart = Carbon::create(self::YEAR, self::MONTH, 1)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $scenarios = $this->scenarios();
        $scenarioCount = count($scenarios);

        $created = 0;
        $weekdayOrdinal = 0;

        for ($day = $monthStart->copy(); $day->lte($monthEnd); $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }

            foreach ($users as $userIndex => $user) {
                $key = ($weekdayOrdinal + $user->id + $userIndex) % $scenarioCount;
                $scenario = $scenarios[$key];

                if ($scenario === null) {
                    continue;
                }

                $dateStr = $day->format('Y-m-d');
                $clockIn = Carbon::parse("{$dateStr} {$scenario['in']}");
                $clockOut = Carbon::parse("{$dateStr} {$scenario['out']}");

                Attendance::create([
                    'user_id' => $user->id,
                    'work_date' => $dateStr,
                    'clock_in_at' => $clockIn,
                    'clock_out_at' => $clockOut,
                    'break_minutes' => $scenario['break_minutes'],
                ]);
                $created++;
            }

            $weekdayOrdinal++;
        }

        $this->command->info(sprintf(
            'May2026AttendanceSeeder: %d 件投入しました（削除済み %d 件・ユーザー %d 名・対象 %d年%d月）',
            $created,
            $deleted,
            $users->count(),
            self::YEAR,
            self::MONTH
        ));
    }
}
