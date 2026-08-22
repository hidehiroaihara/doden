<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * 長時間勤務の検証用打刻（実働 10 / 11 / 12 時間）。
 *
 * 休憩は break_minutes=60 固定なので、
 *   実働 = (退勤−出勤) − 60分
 * になります。
 *
 *   10時間: 09:00〜20:00（滞在11h − 休憩1h）
 *   11時間: 09:00〜21:00（滞在12h − 休憩1h）
 *   12時間: 09:00〜22:00（滞在13h − 休憩1h）
 *
 * 実行例:
 *   php artisan db:seed --class=LongHoursAttendanceSeeder
 */
class LongHoursAttendanceSeeder extends Seeder
{
    /** 投入対象の年月（締め期間ではなくカレンダー月） */
    private const YEAR = 2026;

    private const MONTH = 8;

    /**
     * ユーザー名キーワード → 実働時間（時間）
     * 該当ユーザーがいない場合は先頭のアクティブユーザーから割り当てる。
     *
     * @var array<string, int>
     */
    private const TARGETS = [
        '山田' => 10,
        '佐藤' => 11,
        '鈴木' => 12,
    ];

    public function run(): void
    {
        $assignments = $this->resolveUsers();

        if ($assignments === []) {
            $this->command->warn('アクティブユーザーがいません。先にユーザーを作成してください。');

            return;
        }

        $weekdays = $this->weekdaysInMonth(self::YEAR, self::MONTH);
        $created = 0;

        foreach ($assignments as ['user' => $user, 'hours' => $hours]) {
            $outTime = match ($hours) {
                10 => '20:00',
                11 => '21:00',
                12 => '22:00',
                default => '20:00',
            };

            foreach ($weekdays as $date) {
                $in = Carbon::parse("{$date} 09:00");
                $out = Carbon::parse("{$date} {$outTime}");

                Attendance::updateOrCreate(
                    ['user_id' => $user->id, 'work_date' => $date],
                    [
                        'department_id' => $user->department_id,
                        'clock_in_at' => $in,
                        'clock_out_at' => $out,
                        'break_minutes' => 60,
                    ],
                );
                $created++;
            }

            $this->command->info(sprintf(
                '  %s (id=%d): 実働 %d 時間 × %d 平日（09:00〜%s / 休憩60分）',
                $user->name,
                $user->id,
                $hours,
                count($weekdays),
                $outTime,
            ));
        }

        $this->command->info(sprintf(
            'LongHoursAttendanceSeeder: %d 件 upsert（対象 %d年%d月・平日 %d 日）',
            $created,
            self::YEAR,
            self::MONTH,
            count($weekdays),
        ));
    }

    /**
     * @return list<array{user: User, hours: int}>
     */
    private function resolveUsers(): array
    {
        $active = User::query()->where('is_active', true)->orderBy('id')->get();
        if ($active->isEmpty()) {
            return [];
        }

        $usedIds = [];
        $result = [];

        foreach (self::TARGETS as $keyword => $hours) {
            $user = $active->first(
                fn (User $u) => ! in_array($u->id, $usedIds, true)
                    && str_contains($u->name, $keyword),
            );

            if (! $user) {
                $user = $active->first(fn (User $u) => ! in_array($u->id, $usedIds, true));
            }

            if (! $user) {
                break;
            }

            $usedIds[] = $user->id;
            $result[] = ['user' => $user, 'hours' => $hours];
        }

        return $result;
    }

    /** @return list<string> */
    private function weekdaysInMonth(int $year, int $month): array
    {
        $cursor = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $cursor->copy()->endOfMonth();
        $dates = [];

        while ($cursor->lte($end)) {
            if (! $cursor->isWeekend()) {
                $dates[] = $cursor->format('Y-m-d');
            }
            $cursor->addDay();
        }

        return $dates;
    }
}
