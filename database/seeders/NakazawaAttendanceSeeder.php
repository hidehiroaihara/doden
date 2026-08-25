<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Support\VariedAttendanceSlots;
use Illuminate\Database\Seeder;

/**
 * 本番初期データ（NakazawaInitialSeeder）投入後の打刻検証専用シーダー。
 *
 * - 在籍中の全従業員（38名想定）に当月打刻を投入
 * - 正社員: 平日所定＋所定外／土日（休日出勤）／深夜を混在
 * - アルバイト・パート: 曜日固定シフト（ランチ・ディナー・日勤）に準拠
 * - 従業員・事業所・給与データは触らない
 *
 * 実行例:
 *   php artisan db:seed --class=NakazawaAttendanceSeeder
 *
 * 前提:
 *   admin:create / PayrollMasterSeeder / LegalMasterSeeder / NakazawaInitialSeeder 済み
 */
class NakazawaAttendanceSeeder extends Seeder
{
    /** 当日を空欄にして出勤打刻テスト用（customer_no） */
    private const PUNCH_BLANK_TODAY = ['1', '3', '19', '22'];

    /** 当日は出勤のみ（退勤打刻テスト用） */
    private const PUNCH_IN_ONLY_TODAY = ['10'];

    public function run(): void
    {
        $today = Carbon::today();
        $year = $today->year;
        $month = $today->month;
        $until = $today->format('Y-m-d');

        $users = User::query()
            ->where('is_active', true)
            ->with('employeePayroll')
            ->orderBy('customer_no')
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->command->warn('在籍従業員が見つかりません。NakazawaInitialSeeder を先に実行してください。');

            return;
        }

        $userIds = $users->pluck('id');
        $deleted = Attendance::query()
            ->whereIn('user_id', $userIds)
            ->whereYear('work_date', $year)
            ->whereMonth('work_date', $month)
            ->delete();

        $dates = $this->datesInMonth($year, $month, $until);
        $created = 0;
        $patternCounts = [];

        foreach ($users as $user) {
            $customerNo = (string) ($user->customer_no ?? '');
            $ep = $user->employeePayroll;
            $employmentType = $ep?->employment_type ?? 'arbeit';
            $patternLabel = $this->patternLabel($user, $employmentType);
            $patternCounts[$patternLabel] = ($patternCounts[$patternLabel] ?? 0) + 1;
            $userCreated = 0;

            foreach ($dates as $date) {
                if ($this->isBeforeJoin($user, $date)) {
                    continue;
                }

                $isToday = $date === $until;

                if ($isToday && in_array($customerNo, self::PUNCH_BLANK_TODAY, true)) {
                    continue;
                }

                if ($isToday && in_array($customerNo, self::PUNCH_IN_ONLY_TODAY, true)) {
                    $userCreated += $this->upsertPunch($user, $date, '09:00', null);
                    continue;
                }

                $slot = $this->resolveSlot($user, $employmentType, $customerNo, $date);
                if ($slot === null) {
                    continue;
                }

                $userCreated += $this->upsertPunch($user, $date, $slot['in'], $slot['out'], $slot['break']);
            }

            $created += $userCreated;
            $this->command->info(sprintf(
                '  %s (customer_no=%s, id=%d): %s … %d 件',
                $user->name,
                $customerNo !== '' ? $customerNo : '—',
                $user->id,
                $patternLabel,
                $userCreated,
            ));
        }

        $this->command->info(sprintf(
            'NakazawaAttendanceSeeder: %d 件 upsert（削除 %d 件・対象 %d 名・%d年%d月・%s まで）',
            $created,
            $deleted,
            $users->count(),
            $year,
            $month,
            $until,
        ));
        $this->command->info('  パターン内訳: '.collect($patternCounts)->map(fn ($c, $p) => "{$p}×{$c}")->implode(', '));
        $this->command->info('  打刻端末: トップ画面から氏名を選んで打刻（パスワード不要）');
        $this->command->info('  出勤テスト: customer_no '.implode(',', self::PUNCH_BLANK_TODAY).' … 当日空欄');
        $this->command->info('  退勤テスト: customer_no '.implode(',', self::PUNCH_IN_ONLY_TODAY).' … 当日は出勤のみ');
    }

    private function patternLabel(User $user, string $employmentType): string
    {
        return VariedAttendanceSlots::patternLabel($user, $employmentType);
    }

    /**
     * @return array{in: string, out: string, break: int}|null
     */
    private function resolveSlot(User $user, string $employmentType, string $customerNo, string $date): ?array
    {
        return VariedAttendanceSlots::resolve($user, $employmentType, $date);
    }

    private function isBeforeJoin(User $user, string $date): bool
    {
        if (! $user->joined_at) {
            return false;
        }

        return $date < $user->joined_at->format('Y-m-d');
    }

    private function upsertPunch(User $user, string $date, string $inTime, ?string $outTime, int $breakMin = 60): int
    {
        $in = Carbon::parse("{$date} {$inTime}");
        $out = $outTime !== null ? Carbon::parse("{$date} {$outTime}") : null;
        if ($out && $out->lte($in)) {
            $out->addDay();
        }

        Attendance::updateOrCreate(
            ['user_id' => $user->id, 'work_date' => $date],
            [
                'department_id' => $user->department_id,
                'clock_in_at' => $in,
                'clock_out_at' => $out,
                'break_minutes' => $out ? $breakMin : null,
            ],
        );

        return 1;
    }

    /** @return list<string> */
    private function datesInMonth(int $year, int $month, string $until): array
    {
        $cursor = Carbon::create($year, $month, 1)->startOfMonth();
        $end = min(
            $cursor->copy()->endOfMonth(),
            Carbon::parse($until)->startOfDay(),
        );
        $dates = [];

        while ($cursor->lte($end)) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        return $dates;
    }
}
