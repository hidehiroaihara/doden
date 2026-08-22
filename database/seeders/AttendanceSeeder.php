<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\AttendanceEditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AttendanceSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        AttendanceEditLog::truncate();
        Attendance::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $users = User::all();
        $today = Carbon::today();

        // 先月1日 〜 今日まで生成
        $start = $today->copy()->subMonth()->startOfMonth();
        $end = $today->copy();

        foreach ($users as $user) {
            $cur = $start->copy();

            while ($cur->lte($end)) {
                // 土日はスキップ（一部ユーザーは土曜も出勤）
                $isSaturday = $cur->isSaturday();
                $isSunday = $cur->isSunday();

                if ($isSunday) {
                    $cur->addDay();
                    continue;
                }

                // ユーザーIDが偶数は土曜もスキップ
                if ($isSaturday && $user->id % 2 === 0) {
                    $cur->addDay();
                    continue;
                }

                // 10%の確率で欠勤（レコードなし）
                if (rand(1, 10) === 1) {
                    $cur->addDay();
                    continue;
                }

                $dateStr = $cur->format('Y-m-d');

                // 出勤時刻: 通常 8:45〜9:15 の間
                $pattern = rand(1, 10);

                if ($pattern <= 6) {
                    // 通常出勤: 8:50〜9:05
                    $inHour = 9;
                    $inMin = rand(-10, 5);
                } elseif ($pattern <= 8) {
                    // 遅刻: 9:10〜9:45
                    $inHour = 9;
                    $inMin = rand(10, 45);
                } else {
                    // 大幅遅刻: 10:00〜10:30
                    $inHour = 10;
                    $inMin = rand(0, 30);
                }

                $clockIn = Carbon::parse("{$dateStr} " . sprintf('%02d:%02d', $inHour, abs($inMin)));
                if ($inMin < 0) {
                    $clockIn = Carbon::parse("{$dateStr} " . sprintf('%02d:%02d', 8, 60 + $inMin));
                }

                // 退勤時刻の決定
                $clockOut = null;
                $breakMinutes = null;

                $isPast = $cur->lt($today);
                $isToday = $cur->isToday();

                if ($isToday) {
                    // 今日: 出勤のみのユーザーを混在させる
                    if ($user->id % 3 === 0) {
                        // 退勤済み
                        $outHour = 18;
                        $outMin = rand(0, 30);
                        $clockOut = Carbon::parse("{$dateStr} " . sprintf('%02d:%02d', $outHour, $outMin));
                    }
                    // それ以外は退勤なし（まだ勤務中）
                } elseif ($isPast) {
                    $retirementPattern = rand(1, 10);

                    if ($retirementPattern <= 7) {
                        // 通常退勤: 17:50〜18:20
                        $outHour = 18;
                        $outMin = rand(-10, 20);
                        if ($outMin < 0) {
                            $clockOut = Carbon::parse("{$dateStr} " . sprintf('%02d:%02d', 17, 60 + $outMin));
                        } else {
                            $clockOut = Carbon::parse("{$dateStr} " . sprintf('%02d:%02d', $outHour, $outMin));
                        }
                    } elseif ($retirementPattern <= 8) {
                        // 残業: 19:00〜21:00
                        $outHour = rand(19, 21);
                        $outMin = rand(0, 59);
                        $clockOut = Carbon::parse("{$dateStr} " . sprintf('%02d:%02d', $outHour, $outMin));
                    } elseif ($retirementPattern === 9) {
                        // 早退: 14:00〜17:00
                        $outHour = rand(14, 16);
                        $outMin = rand(0, 59);
                        $clockOut = Carbon::parse("{$dateStr} " . sprintf('%02d:%02d', $outHour, $outMin));
                    } else {
                        // 退勤忘れ (retirementPattern === 10)
                        $clockOut = null;
                    }
                }

                // 休憩時間: 明示的に記録するのは一部のみ
                if ($clockOut && rand(1, 3) === 1) {
                    $breakMinutes = 60;
                }

                Attendance::create([
                    'user_id'     => $user->id,
                    'work_date'   => $dateStr,
                    'clock_in_at' => $clockIn,
                    'clock_out_at' => $clockOut,
                    'break_minutes' => $breakMinutes,
                ]);

                $cur->addDay();
            }
        }

        $count = Attendance::count();
        $this->command->info("✅ AttendanceSeeder 完了: {$count} 件生成しました");
    }
}
