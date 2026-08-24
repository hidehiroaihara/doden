<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
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

    /**
     * アルバイト・パートの固定シフト（曜日＝Carbon::MONDAY 等）。
     *
     * @var array<string, array{days: list<int>, in: string, out: string, break: int}>
     */
    private const PART_TIME_SHIFTS = [
        'lunch_mwf' => ['days' => [Carbon::MONDAY, Carbon::WEDNESDAY, Carbon::FRIDAY], 'in' => '10:00', 'out' => '15:00', 'break' => 0],
        'lunch_tts' => ['days' => [Carbon::TUESDAY, Carbon::THURSDAY, Carbon::SATURDAY], 'in' => '10:00', 'out' => '15:00', 'break' => 0],
        'dinner_mwf' => ['days' => [Carbon::MONDAY, Carbon::WEDNESDAY, Carbon::FRIDAY], 'in' => '17:00', 'out' => '22:00', 'break' => 0],
        'dinner_tts' => ['days' => [Carbon::TUESDAY, Carbon::THURSDAY], 'in' => '17:00', 'out' => '23:00', 'break' => 0],
        'day_mttf' => ['days' => [Carbon::MONDAY, Carbon::TUESDAY, Carbon::THURSDAY, Carbon::FRIDAY], 'in' => '10:00', 'out' => '18:00', 'break' => 60],
        'day_wfs' => ['days' => [Carbon::WEDNESDAY, Carbon::FRIDAY, Carbon::SATURDAY], 'in' => '11:00', 'out' => '19:00', 'break' => 60],
        'day_tts' => ['days' => [Carbon::TUESDAY, Carbon::THURSDAY, Carbon::SATURDAY], 'in' => '10:00', 'out' => '18:00', 'break' => 60],
        'weekend_ss' => ['days' => [Carbon::SATURDAY, Carbon::SUNDAY], 'in' => '10:00', 'out' => '18:00', 'break' => 60],
    ];

    /** @var list<string> */
    private const PART_TIME_SHIFT_KEYS = [
        'lunch_mwf', 'lunch_tts', 'dinner_mwf', 'dinner_tts',
        'day_mttf', 'day_wfs', 'day_tts', 'weekend_ss',
    ];

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
        if ($employmentType === 'full_time') {
            return '正社員';
        }

        if ($employmentType === 'part_time') {
            return 'パート固定';
        }

        $idx = $this->partTimeIndex($user);

        return self::PART_TIME_SHIFT_KEYS[$idx % count(self::PART_TIME_SHIFT_KEYS)];
    }

    /**
     * @return array{in: string, out: string, break: int}|null
     */
    private function resolveSlot(User $user, string $employmentType, string $customerNo, string $date): ?array
    {
        $carbon = Carbon::parse($date);
        $dow = $carbon->dayOfWeek;

        if ($employmentType === 'full_time') {
            return $this->fullTimeSlot($customerNo, $dow, $carbon);
        }

        return $this->partTimeSlot($user, $customerNo, $dow, $carbon);
    }

    /**
     * @return array{in: string, out: string, break: int}|null
     */
    private function fullTimeSlot(string $customerNo, int $dow, Carbon $date): ?array
    {
        // 平日（月〜金）: 基本 9:00-18:00
        if ($dow >= Carbon::MONDAY && $dow <= Carbon::FRIDAY) {
            return match ($customerNo) {
                '4' => ($dow === Carbon::TUESDAY || $dow === Carbon::THURSDAY)
                    ? ['in' => '09:00', 'out' => '21:00', 'break' => 60]
                    : ['in' => '09:00', 'out' => '18:00', 'break' => 60],
                '8' => ($dow === Carbon::MONDAY && $date->weekOfMonth % 2 === 1)
                    ? ['in' => '18:00', 'out' => '02:00', 'break' => 60]
                    : ['in' => '09:00', 'out' => '18:00', 'break' => 60],
                '5' => ($dow === Carbon::FRIDAY && $date->weekOfMonth % 2 === 0)
                    ? ['in' => '18:00', 'out' => '02:00', 'break' => 60]
                    : ['in' => '09:00', 'out' => '18:00', 'break' => 60],
                default => ['in' => '09:00', 'out' => '18:00', 'break' => 60],
            };
        }

        // 土曜（所定休日）
        if ($dow === Carbon::SATURDAY && in_array($customerNo, ['4', '8', '10'], true)) {
            return ['in' => '10:00', 'out' => '18:00', 'break' => 60];
        }

        // 日曜（法定休日）
        if ($dow === Carbon::SUNDAY && in_array($customerNo, ['8', '3'], true)) {
            return ['in' => '10:00', 'out' => '17:00', 'break' => 60];
        }

        return null;
    }

    /**
     * @return array{in: string, out: string, break: int}|null
     */
    private function partTimeSlot(User $user, string $customerNo, int $dow, Carbon $date): ?array
    {
        $shiftKey = self::PART_TIME_SHIFT_KEYS[$this->partTimeIndex($user) % count(self::PART_TIME_SHIFT_KEYS)];
        $shift = self::PART_TIME_SHIFTS[$shiftKey];

        if (in_array($dow, $shift['days'], true)) {
            return ['in' => $shift['in'], 'out' => $shift['out'], 'break' => $shift['break']];
        }

        // 追加パターン（深夜・休日出勤）: customer_no 末尾で分散
        $variant = (int) preg_replace('/\D/', '', $customerNo) ?: $user->id;

        // 隔週土曜（所定休日）— 基本シフトに土曜が無い人向け
        if ($dow === Carbon::SATURDAY && $variant % 5 === 0 && ! in_array(Carbon::SATURDAY, $shift['days'], true)) {
            return ['in' => '10:00', 'out' => '18:00', 'break' => 60];
        }

        // 隔週日曜（法定休日）
        if ($dow === Carbon::SUNDAY && $variant % 7 === 0) {
            return ['in' => '10:00', 'out' => '17:00', 'break' => 60];
        }

        // 水曜夜勤（隔週）
        if ($dow === Carbon::WEDNESDAY && $variant % 6 === 1 && $date->weekOfMonth % 2 === 1) {
            return ['in' => '22:00', 'out' => '06:00', 'break' => 60];
        }

        // 金曜深夜残業（ディナーシフト系）
        if ($dow === Carbon::FRIDAY && str_starts_with($shiftKey, 'dinner') && $variant % 4 === 0) {
            return ['in' => '17:00', 'out' => '01:00', 'break' => 0];
        }

        return null;
    }

    private function partTimeIndex(User $user): int
    {
        $no = (int) preg_replace('/\D/', '', (string) $user->customer_no);

        return $no > 0 ? $no : $user->id;
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
