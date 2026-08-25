<?php

namespace Database\Seeders\Support;

use App\Models\User;
use Carbon\Carbon;

/**
 * 中沢デモ用: 正社員・アルバイト・パートごとに異なる勤務時間帯を返す。
 * NakazawaAttendanceSeeder / LocalFinalizedPayrollSeeder で共用。
 */
class VariedAttendanceSlots
{
    /** @var array<string, array{days: list<int>, in: string, out: string, break: int}> */
    public const PART_TIME_SHIFTS = [
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
    public const PART_TIME_SHIFT_KEYS = [
        'lunch_mwf', 'lunch_tts', 'dinner_mwf', 'dinner_tts',
        'day_mttf', 'day_wfs', 'day_tts', 'weekend_ss',
    ];

    /**
     * @return array{in: string, out: string, break: int}|null
     */
    public static function resolve(User $user, string $employmentType, string $date): ?array
    {
        $carbon = Carbon::parse($date);
        $dow = $carbon->dayOfWeek;
        $customerNo = (string) ($user->customer_no ?? '');

        if ($employmentType === 'full_time') {
            return self::fullTimeSlot($customerNo, $dow, $carbon);
        }

        return self::partTimeSlot($user, $customerNo, $dow, $carbon);
    }

    /**
     * @return array{in: string, out: string, break: int}|null
     */
    private static function fullTimeSlot(string $customerNo, int $dow, Carbon $date): ?array
    {
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

        if ($dow === Carbon::SATURDAY && in_array($customerNo, ['4', '8', '10'], true)) {
            return ['in' => '10:00', 'out' => '18:00', 'break' => 60];
        }

        if ($dow === Carbon::SUNDAY && in_array($customerNo, ['8', '3'], true)) {
            return ['in' => '10:00', 'out' => '17:00', 'break' => 60];
        }

        return null;
    }

    /**
     * @return array{in: string, out: string, break: int}|null
     */
    private static function partTimeSlot(User $user, string $customerNo, int $dow, Carbon $date): ?array
    {
        $shiftKey = self::PART_TIME_SHIFT_KEYS[self::partTimeIndex($user) % count(self::PART_TIME_SHIFT_KEYS)];
        $shift = self::PART_TIME_SHIFTS[$shiftKey];

        if (in_array($dow, $shift['days'], true)) {
            return self::jitterSlot($user, $date, $shift);
        }

        $variant = (int) preg_replace('/\D/', '', $customerNo) ?: $user->id;

        if ($dow === Carbon::SATURDAY && $variant % 5 === 0 && ! in_array(Carbon::SATURDAY, $shift['days'], true)) {
            return self::jitterSlot($user, $date, ['in' => '10:00', 'out' => '18:00', 'break' => 60]);
        }

        if ($dow === Carbon::SUNDAY && $variant % 7 === 0) {
            return self::jitterSlot($user, $date, ['in' => '10:00', 'out' => '17:00', 'break' => 60]);
        }

        if ($dow === Carbon::WEDNESDAY && $variant % 6 === 1 && $date->weekOfMonth % 2 === 1) {
            return self::jitterSlot($user, $date, ['in' => '22:00', 'out' => '06:00', 'break' => 60]);
        }

        if ($dow === Carbon::FRIDAY && str_starts_with($shiftKey, 'dinner') && $variant % 4 === 0) {
            return self::jitterSlot($user, $date, ['in' => '17:00', 'out' => '01:00', 'break' => 0]);
        }

        return null;
    }

    /**
     * 日ごとに ±0〜20 分のゆらぎを付ける（従業員×日付で決定的）。
     *
     * @param  array{in: string, out: string, break: int}  $slot
     * @return array{in: string, out: string, break: int}
     */
    private static function jitterSlot(User $user, string $date, array $slot): array
    {
        $seed = crc32("{$user->id}:{$date}");
        $inJitter = ($seed % 21) - 10;
        $outJitter = (($seed >> 8) % 21) - 10;

        $in = self::shiftTime($slot['in'], $inJitter);
        $out = self::shiftTime($slot['out'], $outJitter);

        return ['in' => $in, 'out' => $out, 'break' => $slot['break']];
    }

    private static function shiftTime(string $time, int $minutes): string
    {
        return Carbon::createFromFormat('H:i', $time)->addMinutes($minutes)->format('H:i');
    }

    public static function partTimeIndex(User $user): int
    {
        $no = (int) preg_replace('/\D/', '', (string) $user->customer_no);

        return $no > 0 ? $no : $user->id;
    }

    public static function patternLabel(User $user, string $employmentType): string
    {
        if ($employmentType === 'full_time') {
            return '正社員';
        }

        if ($employmentType === 'part_time') {
            return 'パート固定';
        }

        $idx = self::partTimeIndex($user);

        return self::PART_TIME_SHIFT_KEYS[$idx % count(self::PART_TIME_SHIFT_KEYS)];
    }
}
