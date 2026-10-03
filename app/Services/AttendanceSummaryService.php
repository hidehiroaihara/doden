<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Setting;
use App\Models\User;
use App\Support\PunchRounding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 勤怠集計の単一ソース。
 *
 * これまで DashboardController の index / monthlySummary / exportMonthlyCsv に
 * 3重コピーされていた月次集計ロジックをここへ集約する。
 *
 * 給与計算(PayrollCalculator)からも同じ確定値を再利用できるよう、
 * 労働/休憩/丸め後だけでなく、割増計算の基礎となる区分別分数
 * (深夜=22:00-翌5:00、法定外=1日8時間超)も返す。
 * 深夜集計は日跨ぎ(夜勤)を跨いで正しく算出する。
 *
 * 参照: 資料/設計書 04_給与計算 / 11_基本設定_勤怠項目
 */
class AttendanceSummaryService
{
    /** 法定労働時間(1日) = 8時間 */
    private const STATUTORY_DAILY_MINUTES = 480;

    /**
     * 深夜時間の月合計キー。
     *
     * 丸めの基準は出勤時刻だが深夜帯の境界は 22:00 固定なので、打刻ごとに丸めた実労働から
     * 深夜分を切り出しても丸め単位の倍数にならない（例: 17:57〜23:27 → 深夜87分）。
     * そのため月合計を丸め単位へ揃える。設定 salary_round_night_total で無効化できる。
     *
     * @var list<string>
     */
    private const NIGHT_TOTAL_KEYS = [
        'night_minutes',
        'weekday_night_minutes',
        'prescribed_holiday_night_minutes',
        'legal_holiday_night_minutes',
        'night_overtime_weekday',
        'night_statutory_weekday',
        'night_overtime_prescribed_holiday',
        'night_statutory_prescribed_holiday',
        'night_overtime_legal_holiday',
        'night_statutory_legal_holiday',
        'break_night_weekday',
        'break_night_overtime_weekday',
        'break_night_statutory_weekday',
        'break_night_prescribed_holiday',
        'break_night_overtime_prescribed_holiday',
        'break_night_statutory_prescribed_holiday',
        'break_night_legal_holiday',
        'break_night_overtime_legal_holiday',
        'break_night_statutory_legal_holiday',
    ];

    /**
     * 指定 monthKey('Y-m') の期間について、対象ユーザーの勤怠サマリを返す。
     *
     * @param  Collection<int, User>|null  $users  未指定なら在籍中の全ユーザー
     * @param  bool  $useRoundedWork  true で打刻ごとの丸め後実労働を基準に集計する（給与計算用）。
     *                                打刻一覧の「丸め後」列と月合計が一致する。
     * @return array{hasSchedule: bool, users: array<int, array<string, mixed>>, company: array<string, mixed>}
     */
    public function forMonth(string $monthKey, ?Collection $users = null, bool $useRoundedWork = false): array
    {
        $period = MonthPeriod::resolve($monthKey);
        $monthStart = $period['from'];
        $monthEnd = $period['to'];

        $settings = $this->loadSettings($monthStart);
        $hasSchedule = $settings['hasSchedule'];

        $users ??= User::where('is_active', true)
            ->orderByEmployeeNo()
            ->get(['users.id', 'users.name', 'users.break_minutes']);

        $monthAttendances = Attendance::with('attendanceBreaks')
            ->whereBetween('work_date', [$monthStart, $monthEnd])
            ->get(['id', 'user_id', 'work_date', 'clock_in_at', 'clock_out_at', 'break_minutes']);

        $userSummaries = [];
        $company = $this->emptyTotals();

        foreach ($users as $user) {
            $summary = $this->summarizeUser(
                $user,
                $monthAttendances->where('user_id', $user->id),
                $settings,
                $useRoundedWork,
            );
            $userSummaries[] = $summary;

            foreach ($this->emptyTotals() as $key => $default) {
                // 数値の集計キーのみ合算（user_id/user_name/user_count は対象外）
                if (! is_int($default)) {
                    continue;
                }
                if ($key === 'user_count') {
                    continue;
                }
                $company[$key] += $summary[$key];
            }
        }

        $company['user_count'] = $users->count();

        return [
            'hasSchedule' => $hasSchedule,
            'users' => $userSummaries,
            'company' => $company,
        ];
    }

    /**
     * 1ユーザーの期間集計。返却キーは分数(生値)で統一し、
     * 表示用フォーマットは呼び出し側(または formatMinutesToHM)に委ねる。
     *
     * @param  Collection<int, Attendance>  $attendances
     * @param  array<string, mixed>  $settings
     * @param  bool  $useRoundedWork  打刻ごとの丸め後実労働を基準に集計するか
     * @return array<string, mixed>
     */
    private function summarizeUser(User $user, Collection $attendances, array $settings, bool $useRoundedWork = false): array
    {
        $totals = $this->emptyTotals();
        $totals['user_id'] = $user->id;
        $totals['user_name'] = $user->name;

        // 同一 work_date の複数シフトは1日として集計する（出勤日数・法定外・所定超過は日合計で判定）。
        /** @var array<string, list<Attendance>> $byWorkDate */
        $byWorkDate = [];
        foreach ($attendances as $att) {
            if ($att->clock_in_at && ! $att->clock_out_at && $att->work_date->lt(Carbon::today())) {
                $totals['missing_clock_out']++;
                continue;
            }
            if (! $att->clock_in_at || ! $att->clock_out_at) {
                continue;
            }
            $key = $att->work_date->format('Y-m-d');
            $byWorkDate[$key][] = $att;
        }

        $holidayThreshold = $settings['hasSchedule']
            ? (int) $settings['workHoursPerDay']
            : self::STATUTORY_DAILY_MINUTES;
        $timeMode = PunchRounding::isTimeMode($settings['rounding']);

        foreach ($byWorkDate as $dateStr => $dayAttendances) {
            usort($dayAttendances, fn ($a, $b) => $a->clock_in_at <=> $b->clock_in_at);

            $workDate = Carbon::parse($dateStr);
            $dayType = $this->dayType($workDate, $settings);

            $dayNet = 0;
            $dayBreak = 0;
            $dayNight = 0;
            $priorNet = 0;
            $firstClockIn = null;
            $lastClockOut = null;

            foreach ($dayAttendances as $att) {
                $clockIn = Carbon::parse($att->clock_in_at);
                $clockOut = Carbon::parse($att->clock_out_at);
                [$breakMin, $breaksList, $netMinutes] = $this->shiftWork($att, $clockIn, $clockOut, $dateStr, $user, $settings);

                // 遅刻・早退の判定に使う出退勤（time モードは丸め後、従来モードは打刻そのまま）
                [$judgeIn, $judgeOut] = PunchRounding::interval($clockIn, $clockOut, $settings['rounding']);

                if ($timeMode) {
                    [$roundedBreak, $roundedBreaks, $roundedNet] = $this->shiftWork($att, $judgeIn, $judgeOut, $dateStr, $user, $settings);
                    if ($useRoundedWork) {
                        $clockIn = $judgeIn->copy();
                        $clockOut = $judgeOut->copy();
                        $breakMin = $roundedBreak;
                        $breaksList = $roundedBreaks;
                        $netMinutes = $roundedNet;
                    }
                } else {
                    $roundedNet = PunchRounding::roundNetMinutes($netMinutes, $settings['rounding']);
                    // 丸めで増減した分を勤務終了側にずらした実効退勤時刻で集計する。
                    // これにより区分（所定内/所定外/法定外）と深夜が丸め後の実労働と整合する。
                    if ($useRoundedWork && $roundedNet !== $netMinutes) {
                        $clockOut = $this->shiftClockOutToNet($clockIn, $clockOut, $breaksList, $netMinutes, $roundedNet);
                        $netMinutes = $roundedNet;
                    }
                }
                $totals['total_rounded_minutes'] += $roundedNet;

                $nightMin = $this->nightMinutes($clockIn, $clockOut);

                $dayNet += $netMinutes;
                $dayBreak += $breakMin;
                $dayNight += $nightMin;

                $seg = $this->analyzeDay($clockIn, $clockOut, $breaksList, $holidayThreshold, $priorNet);

                if ($dayType === 'legal') {
                    $totals['legal_holiday_overtime_minutes'] += $seg['work_overtime'];
                    $totals['legal_holiday_statutory_over_minutes'] += $seg['work_statutory'];
                    $totals['legal_holiday_break_minutes'] += $breakMin;
                    $totals['night_overtime_legal_holiday'] += $seg['night_overtime'];
                    $totals['night_statutory_legal_holiday'] += $seg['night_statutory'];
                    $totals['break_overtime_legal_holiday'] += $seg['break_overtime'];
                    $totals['break_statutory_legal_holiday'] += $seg['break_statutory'];
                    $totals['break_night_legal_holiday'] += $seg['break_night_total'];
                    $totals['break_night_overtime_legal_holiday'] += $seg['break_night_overtime'];
                    $totals['break_night_statutory_legal_holiday'] += $seg['break_night_statutory'];
                } elseif ($dayType === 'prescribed') {
                    $totals['prescribed_holiday_statutory_over_minutes'] += $seg['work_statutory'];
                    $totals['prescribed_holiday_break_minutes'] += $breakMin;
                    $totals['night_overtime_prescribed_holiday'] += $seg['night_overtime'];
                    $totals['night_statutory_prescribed_holiday'] += $seg['night_statutory'];
                    $totals['break_overtime_prescribed_holiday'] += $seg['break_overtime'];
                    $totals['break_statutory_prescribed_holiday'] += $seg['break_statutory'];
                    $totals['break_night_prescribed_holiday'] += $seg['break_night_total'];
                    $totals['break_night_overtime_prescribed_holiday'] += $seg['break_night_overtime'];
                    $totals['break_night_statutory_prescribed_holiday'] += $seg['break_night_statutory'];
                } else {
                    $totals['weekday_break_minutes'] += $breakMin;
                    $totals['weekday_night_minutes'] += $nightMin;
                    $totals['night_overtime_weekday'] += $seg['night_overtime'];
                    $totals['night_statutory_weekday'] += $seg['night_statutory'];
                    $totals['break_overtime_weekday'] += $seg['break_overtime'];
                    $totals['break_statutory_weekday'] += $seg['break_statutory'];
                    $totals['break_night_weekday'] += $seg['break_night_total'];
                    $totals['break_night_overtime_weekday'] += $seg['break_night_overtime'];
                    $totals['break_night_statutory_weekday'] += $seg['break_night_statutory'];
                }

                $priorNet += $netMinutes;

                if ($firstClockIn === null || $judgeIn->lt($firstClockIn)) {
                    $firstClockIn = $judgeIn;
                }
                if ($lastClockOut === null || $judgeOut->gt($lastClockOut)) {
                    $lastClockOut = $judgeOut;
                }
            }

            // 日合計（出勤日数・法定外・所定超過はここで1回だけ加算）
            $totals['work_days']++;
            $totals['total_break_minutes'] += $dayBreak;
            $totals['total_work_minutes'] += $dayNet;
            $totals['night_minutes'] += $dayNight;
            $statutoryOver = max(0, $dayNet - self::STATUTORY_DAILY_MINUTES);
            $totals['statutory_overtime_minutes'] += $statutoryOver;

            $holidayWithin = min($dayNet, $holidayThreshold);
            $holidayOver = max(0, $dayNet - $holidayThreshold);

            if ($dayType === 'legal') {
                $totals['legal_holiday_days']++;
                $totals['legal_holiday_minutes'] += $dayNet;
                $totals['legal_holiday_within_minutes'] += $holidayWithin;
                $totals['legal_holiday_night_minutes'] += $dayNight;
            } elseif ($dayType === 'prescribed') {
                $totals['prescribed_holiday_days']++;
                $totals['prescribed_holiday_minutes'] += $dayNet;
                $totals['prescribed_holiday_within_minutes'] += $holidayWithin;
                $totals['prescribed_holiday_overtime_minutes'] += $holidayOver;
                $totals['prescribed_holiday_night_minutes'] += $dayNight;
            } else {
                $totals['weekday_work_days']++;
                $totals['weekday_work_minutes'] += $dayNet;
                $totals['weekday_statutory_overtime_minutes'] += $statutoryOver;

                if ($settings['hasSchedule'] && $firstClockIn && $lastClockOut) {
                    $scheduleStart = Carbon::parse("{$dateStr} {$settings['workStartTime']}");
                    $scheduleEnd = Carbon::parse("{$dateStr} {$settings['workEndTime']}");

                    if ($firstClockIn->gt($scheduleStart)) {
                        $totals['late_count']++;
                        $totals['late_minutes_weekday'] += (int) $scheduleStart->diffInMinutes($firstClockIn);
                    }
                    if ($lastClockOut->lt($scheduleEnd)) {
                        $totals['early_leave_count']++;
                        $totals['early_leave_minutes_weekday'] += (int) $lastClockOut->diffInMinutes($scheduleEnd);
                    }

                    $scheduledMinutes = (int) $settings['workHoursPerDay'];
                    if ($dayNet > $scheduledMinutes) {
                        $dayOvertime = $dayNet - $scheduledMinutes;
                        $totals['overtime_minutes'] += $dayOvertime;
                        $totals['weekday_overtime_minutes'] += $dayOvertime;
                    }
                }
            }
        }

        $totals['within_statutory_minutes'] = max(0, $totals['total_work_minutes'] - $totals['statutory_overtime_minutes']);
        $totals['weekday_within_statutory_minutes'] = max(0, $totals['weekday_work_minutes'] - $totals['weekday_statutory_overtime_minutes']);

        // time モードは出退勤がグリッド上にあるため、月合計の追加丸めは行わない（二重丸め防止）
        if ($useRoundedWork && ! $timeMode && $settings['salaryRoundNightTotal']) {
            $this->roundNightTotals($totals, $settings);
        }

        return $totals;
    }

    /**
     * 1シフトの休憩分・休憩区間・純労働分を [in, out] に対して求める。
     *
     * @param  array<string, mixed>  $settings
     * @return array{0: int, 1: list<array{0: Carbon, 1: Carbon}>, 2: int}
     */
    private function shiftWork(Attendance $att, Carbon $in, Carbon $out, string $dateStr, User $user, array $settings): array
    {
        $breakMin = BreakDeduction::resolveWithLimit(
            $att->break_minutes,
            $in,
            $out,
            $dateStr,
            $settings['breakStartTime'],
            $settings['breakEndTime'],
            $user->break_minutes ?? $settings['defaultBreakMinutes'],
            $att->attendanceBreaks,
        );
        $breaks = $this->breakIntervals($att, $in, $out, $breakMin, $settings);
        $net = max(0, (int) $in->diffInMinutes($out) - $breakMin);

        return [$breakMin, $breaks, $net];
    }

    /**
     * 深夜時間の月合計を丸め単位へ揃える（NIGHT_TOTAL_KEYS 参照）。
     *
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $settings
     */
    private function roundNightTotals(array &$totals, array $settings): void
    {
        foreach (self::NIGHT_TOTAL_KEYS as $key) {
            $totals[$key] = self::roundMinutes(
                (int) ($totals[$key] ?? 0),
                $settings['salaryRoundMinutes'],
                $settings['salaryRoundRule'],
            );
        }
    }

    /**
     * 勤務日の休日区分を判定する。
     *
     * 優先順位（MF準拠・年度一本化）:
     *   1) 年度(FiscalYear)の独自休日（特定日）→ 既定は所定休日扱い
     *   2) 年度の休日設定（曜日→区分）
     *   3) 基本設定＞勤怠の曜日指定（法定休日=日曜 / 所定休日=土曜）へフォールバック
     *
     * @param  array<string, mixed>  $settings
     * @return string 'legal'|'prescribed'|'weekday'
     */
    private function dayType(Carbon $date, array $settings): string
    {
        // 1) 独自休日（祝日・会社独自の休日など特定日）
        if (! empty($settings['customHolidayDates'][$date->format('Y-m-d')])) {
            $type = $settings['customHolidayDefaultType'] ?? 'prescribed';

            return in_array($type, ['legal', 'prescribed', 'weekday'], true) ? $type : 'prescribed';
        }

        // 2) 年度の休日設定（dow 0=日〜6=土 → 区分）
        if (! empty($settings['holidayTypeMap'])) {
            $type = $settings['holidayTypeMap'][(int) $date->dayOfWeek] ?? 'weekday';

            return in_array($type, ['legal', 'prescribed', 'weekday'], true) ? $type : 'weekday';
        }

        // 3) フォールバック: 基本設定＞勤怠の曜日指定
        $dow = strtolower($date->englishDayOfWeek); // sunday..saturday
        if (in_array($dow, $settings['legalHolidayDows'], true)) {
            return 'legal';
        }
        if (in_array($dow, $settings['prescribedHolidayDows'], true)) {
            return 'prescribed';
        }

        return 'weekday';
    }

    /**
     * 期間内の所定労働日数（平日数）を数える。
     * dayType() と同じ優先順位（年度設定 → 勤怠設定フォールバック）を日ごとに適用する。
     * 締め日で月をまたぐ期間では、日付ごとの暦年で年度設定を解決する。
     */
    public function scheduledDaysInPeriod(string $from, string $to): int
    {
        $days = 0;
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        /** @var array<string, array<string, mixed>> */
        $settingsCache = [];

        while ($cursor->lte($end)) {
            $yearKey = $cursor->format('Y');
            if (! isset($settingsCache[$yearKey])) {
                $settingsCache[$yearKey] = $this->loadSettings($cursor->format('Y-m-d'));
            }
            if ($this->dayType($cursor, $settingsCache[$yearKey]) === 'weekday') {
                $days++;
            }
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * 実勤務区間 [in, out] と 深夜帯(22:00〜翌05:00) の重なり分数。
     * 夜勤(日跨ぎ)に対応するため、勤務にかかる各日の深夜帯を走査して合算する。
     * 各日の深夜帯は互いに重ならないため二重計上は起きない。
     */
    private function nightMinutes(Carbon $in, Carbon $out): int
    {
        return $this->nightOverlap($in, $out);
    }

    /**
     * 任意区間 [in, out] と 深夜帯(22:00〜翌05:00) の重なり分数。
     */
    private function nightOverlap(Carbon $in, Carbon $out): int
    {
        $total = 0;
        $cursor = $in->copy()->startOfDay()->subDay();
        $limit = $out->copy()->startOfDay()->addDay();

        while ($cursor->lte($limit)) {
            $nightStart = $cursor->copy()->setTime(22, 0);
            $nightEnd = $cursor->copy()->addDay()->setTime(5, 0);

            $overlapStart = $in->gt($nightStart) ? $in : $nightStart;
            $overlapEnd = $out->lt($nightEnd) ? $out : $nightEnd;

            if ($overlapEnd->gt($overlapStart)) {
                $total += (int) $overlapStart->diffInMinutes($overlapEnd);
            }

            $cursor->addDay();
        }

        return $total;
    }

    /**
     * 当日の実休憩区間(clock時刻)を返す。
     * attendanceBreaks の完了打刻があればそれを、無ければ設定の休憩時間帯へ
     * 有効休憩分(breakMin)を配置して疑似区間を生成する。
     *
     * @param  array<string, mixed>  $settings
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function breakIntervals(Attendance $att, Carbon $in, Carbon $out, int $breakMin, array $settings): array
    {
        $completed = $att->attendanceBreaks
            ->filter(fn ($b) => $b->started_at && $b->ended_at);

        if ($completed->isNotEmpty()) {
            return $completed
                ->map(fn ($b) => [Carbon::parse($b->started_at), Carbon::parse($b->ended_at)])
                ->values()
                ->all();
        }

        if ($breakMin <= 0) {
            return [];
        }

        // 打刻区間が無い場合は設定の休憩時間帯に配置（勤務内へクランプ）。
        // 規定休憩時間帯が未設定なら勤務開始時刻を起点にする。
        $dateStr = $in->format('Y-m-d');
        $bs = $settings['breakStartTime']
            ? Carbon::parse("{$dateStr} {$settings['breakStartTime']}")
            : $in->copy();
        if ($bs->lt($in)) {
            $bs = $in->copy();
        }
        $be = $bs->copy()->addMinutes($breakMin);
        if ($be->gt($out)) {
            $be = $out->copy();
            $bs = $be->copy()->subMinutes($breakMin);
            if ($bs->lt($in)) {
                $bs = $in->copy();
            }
        }

        return $be->gt($bs) ? [[$bs, $be]] : [];
    }

    /**
     * 1日の勤務を「所定内/所定外(所定〜8h)/法定外(8h超)」の3バンドへ純労働分ベースで分割し、
     * 各バンドの労働分・深夜分、および休憩のバンド別・深夜別内訳を返す。
     *
     * @param  list<array{0: Carbon, 1: Carbon}>  $breaks
     * @param  int  $priorNet  同一日の先行シフトで既に累積した純労働分。
     *                         同日複数シフトでも「所定内→所定外(8h)→法定外」の閾値を日通算で判定するために用いる。
     * @return array<string, int>
     */
    private function analyzeDay(Carbon $in, Carbon $out, array $breaks, int $threshold, int $priorNet = 0): array
    {
        $work = $this->subtractIntervals($in, $out, $breaks);

        $bands = ['scheduled' => [], 'overtime' => [], 'statutory' => []];
        $acc = $priorNet; // 累積純労働分（先行シフト分を含む）
        foreach ($work as [$s, $e]) {
            $segLen = (int) $s->diffInMinutes($e);
            $offset = 0;
            while ($offset < $segLen) {
                if ($acc < $threshold) {
                    $band = 'scheduled';
                    $bandEndAcc = $threshold;
                } elseif ($acc < self::STATUTORY_DAILY_MINUTES) {
                    $band = 'overtime';
                    $bandEndAcc = self::STATUTORY_DAILY_MINUTES;
                } else {
                    $band = 'statutory';
                    $bandEndAcc = PHP_INT_MAX;
                }
                $take = min($segLen - $offset, $bandEndAcc - $acc);
                $partStart = $s->copy()->addMinutes($offset);
                $partEnd = $partStart->copy()->addMinutes($take);
                $bands[$band][] = [$partStart, $partEnd];
                $offset += $take;
                $acc += $take;
            }
        }

        $r = [
            'work_scheduled' => 0, 'work_overtime' => 0, 'work_statutory' => 0,
            'night_scheduled' => 0, 'night_overtime' => 0, 'night_statutory' => 0,
            'break_scheduled' => 0, 'break_overtime' => 0, 'break_statutory' => 0,
            'break_night_scheduled' => 0, 'break_night_overtime' => 0, 'break_night_statutory' => 0,
            'break_night_total' => 0,
        ];
        foreach ($bands as $name => $intervals) {
            foreach ($intervals as [$s, $e]) {
                $r["work_{$name}"] += (int) $s->diffInMinutes($e);
                $r["night_{$name}"] += $this->nightOverlap($s, $e);
            }
        }

        // 休憩は「開始前の累積純労働分」でバンド判定し、区間全体をそのバンドへ計上。
        foreach ($breaks as [$bs, $be]) {
            $bs2 = $bs->lt($in) ? $in->copy() : $bs->copy();
            $be2 = $be->gt($out) ? $out->copy() : $be->copy();
            if ($be2->lte($bs2)) {
                continue;
            }
            $netBefore = $priorNet + $this->netWorkBefore($work, $bs2);
            if ($netBefore < $threshold) {
                $band = 'scheduled';
            } elseif ($netBefore < self::STATUTORY_DAILY_MINUTES) {
                $band = 'overtime';
            } else {
                $band = 'statutory';
            }
            $night = $this->nightOverlap($bs2, $be2);
            $r["break_{$band}"] += (int) $bs2->diffInMinutes($be2);
            $r["break_night_{$band}"] += $night;
            $r['break_night_total'] += $night;
        }

        return $r;
    }

    /**
     * 純労働分が $targetNet になるよう退勤時刻をずらした時刻を返す。
     *
     * 切捨てで $targetNet < $net のときは実労働区間を先頭から辿って該当時点まで巻き戻す
     * （休憩帯を労働として数えないため）。切上げのときは退勤後へ伸ばす。
     *
     * @param  list<array{0: Carbon, 1: Carbon}>  $breaks
     */
    private function shiftClockOutToNet(Carbon $in, Carbon $out, array $breaks, int $net, int $targetNet): Carbon
    {
        if ($targetNet >= $net) {
            return $out->copy()->addMinutes($targetNet - $net);
        }
        if ($targetNet <= 0) {
            return $in->copy();
        }

        $remaining = $targetNet;
        foreach ($this->subtractIntervals($in, $out, $breaks) as [$s, $e]) {
            $length = (int) $s->diffInMinutes($e);
            if ($remaining <= $length) {
                return $s->copy()->addMinutes($remaining);
            }
            $remaining -= $length;
        }

        return $out->copy();
    }

    /**
     * [in, out] から休憩区間を差し引いた実労働区間の一覧を返す。
     *
     * @param  list<array{0: Carbon, 1: Carbon}>  $breaks
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function subtractIntervals(Carbon $in, Carbon $out, array $breaks): array
    {
        $cuts = [];
        foreach ($breaks as [$bs, $be]) {
            $s = $bs->lt($in) ? $in->copy() : $bs->copy();
            $e = $be->gt($out) ? $out->copy() : $be->copy();
            if ($e->gt($s)) {
                $cuts[] = [$s, $e];
            }
        }
        usort($cuts, fn ($a, $b) => $a[0] <=> $b[0]);

        $result = [];
        $cursor = $in->copy();
        foreach ($cuts as [$s, $e]) {
            if ($s->gt($cursor)) {
                $result[] = [$cursor->copy(), $s->copy()];
            }
            if ($e->gt($cursor)) {
                $cursor = $e->copy();
            }
        }
        if ($out->gt($cursor)) {
            $result[] = [$cursor->copy(), $out->copy()];
        }

        return $result;
    }

    /**
     * 実労働区間のうち時刻 t より前に位置する純労働分の合計。
     *
     * @param  list<array{0: Carbon, 1: Carbon}>  $work
     */
    private function netWorkBefore(array $work, Carbon $t): int
    {
        $sum = 0;
        foreach ($work as [$s, $e]) {
            if ($e->lte($t)) {
                $sum += (int) $s->diffInMinutes($e);
            } elseif ($s->lt($t)) {
                $sum += (int) $s->diffInMinutes($t);
            }
        }

        return $sum;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyTotals(): array
    {
        return [
            'user_id' => null,
            'user_name' => null,
            'work_days' => 0,
            'total_work_minutes' => 0,
            'total_rounded_minutes' => 0,
            'total_break_minutes' => 0,
            'late_count' => 0,
            'early_leave_count' => 0,
            'overtime_minutes' => 0,
            'missing_clock_out' => 0,
            'night_minutes' => 0,
            'statutory_overtime_minutes' => 0,
            'within_statutory_minutes' => 0,
            // 平日のみの区分別集計（給与計算の勤怠項目「〜（平日）」で参照）
            'weekday_work_days' => 0,
            'weekday_work_minutes' => 0,
            'weekday_within_statutory_minutes' => 0,
            'weekday_overtime_minutes' => 0,
            'weekday_statutory_overtime_minutes' => 0,
            'weekday_night_minutes' => 0,
            'weekday_break_minutes' => 0,
            // 所定休日（既定=土曜）
            'prescribed_holiday_days' => 0,
            'prescribed_holiday_minutes' => 0,
            'prescribed_holiday_within_minutes' => 0,
            'prescribed_holiday_overtime_minutes' => 0,
            'prescribed_holiday_night_minutes' => 0,
            // 法定休日（既定=日曜）
            'legal_holiday_days' => 0,
            'legal_holiday_minutes' => 0,
            'legal_holiday_within_minutes' => 0,
            'legal_holiday_night_minutes' => 0,
            // === MF準拠の拡充項目（打刻から算出） ===
            // 遅刻・早退時間（分）／休日回数
            'late_minutes_weekday' => 0,
            'early_leave_minutes_weekday' => 0,
            'late_minutes_prescribed_holiday' => 0,
            'early_leave_minutes_prescribed_holiday' => 0,
            'late_minutes_legal_holiday' => 0,
            'early_leave_minutes_legal_holiday' => 0,
            'late_count_prescribed_holiday' => 0,
            'early_leave_count_prescribed_holiday' => 0,
            'late_count_legal_holiday' => 0,
            'early_leave_count_legal_holiday' => 0,
            // 休日の所定外/法定外（8h超）
            'legal_holiday_overtime_minutes' => 0,
            'prescribed_holiday_statutory_over_minutes' => 0,
            'legal_holiday_statutory_over_minutes' => 0,
            // 深夜のバンド別（所定外/法定外）
            'night_overtime_weekday' => 0,
            'night_statutory_weekday' => 0,
            'night_overtime_prescribed_holiday' => 0,
            'night_statutory_prescribed_holiday' => 0,
            'night_overtime_legal_holiday' => 0,
            'night_statutory_legal_holiday' => 0,
            // 休憩（休日合計）
            'prescribed_holiday_break_minutes' => 0,
            'legal_holiday_break_minutes' => 0,
            // 休憩のバンド別
            'break_overtime_weekday' => 0,
            'break_statutory_weekday' => 0,
            'break_overtime_prescribed_holiday' => 0,
            'break_statutory_prescribed_holiday' => 0,
            'break_overtime_legal_holiday' => 0,
            'break_statutory_legal_holiday' => 0,
            // 深夜休憩
            'break_night_weekday' => 0,
            'break_night_overtime_weekday' => 0,
            'break_night_statutory_weekday' => 0,
            'break_night_prescribed_holiday' => 0,
            'break_night_overtime_prescribed_holiday' => 0,
            'break_night_statutory_prescribed_holiday' => 0,
            'break_night_legal_holiday' => 0,
            'break_night_overtime_legal_holiday' => 0,
            'break_night_statutory_legal_holiday' => 0,
            'user_count' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSettings(Carbon|string|null $periodStart = null): array
    {
        $workStartTime = Setting::getValue('work_start_time');
        $workEndTime = Setting::getValue('work_end_time');
        $workHoursPerDay = Setting::getValue('work_hours_per_day');

        // 年度(FiscalYear)を対象期間の暦年で解決し、あれば「1日の所定労働時間」と
        // 「休日設定（曜日→区分）・独自休日」を優先ソースとする（MF準拠の年度一本化）。
        $holidayTypeMap = [];
        $customHolidayDates = [];
        $periodDate = $periodStart ? Carbon::parse($periodStart)->format('Y-m-d') : null;
        $fiscalYear = $periodDate
            ? \App\Models\FiscalYear::forDate($periodDate)
            : null;
        if ($fiscalYear) {
            $workHoursPerDay = (string) $fiscalYear->workHoursPerDayMinutes();
            $holidayTypeMap = $fiscalYear->holidayTypeMap();
            $customHolidayDates = $fiscalYear->customHolidays
                ->mapWithKeys(fn ($c) => [
                    ($c->date instanceof \DateTimeInterface ? $c->date->format('Y-m-d') : (string) $c->date) => true,
                ])
                ->all();
        }

        return [
            'workStartTime' => $workStartTime,
            'workEndTime' => $workEndTime,
            'workHoursPerDay' => $workHoursPerDay,
            'defaultBreakMinutes' => (int) Setting::getValue('default_break_minutes', '60'),
            'breakStartTime' => Setting::getValue('break_start_time', '12:00'),
            'breakEndTime' => Setting::getValue('break_end_time', '13:00'),
            'salaryRoundMinutes' => (int) Setting::getValue('salary_round_minutes', 30),
            'salaryRoundRule' => Setting::getValue('salary_round_rule', 'floor'),
            'rounding' => PunchRounding::settings(),
            // 深夜時間の月合計も丸め単位へ揃えるか。'0' にすると丸め前（端数あり）へ戻る。
            'salaryRoundNightTotal' => Setting::getValue('salary_round_night_total', '1') !== '0',
            'hasSchedule' => (bool) ($workStartTime && $workEndTime && $workHoursPerDay),
            // 年度の休日設定（優先）。独自休日は年度設定の「祝日」区分に従う（未設定なら所定休日扱い）。
            'holidayTypeMap' => $holidayTypeMap,
            'customHolidayDates' => $customHolidayDates,
            'customHolidayDefaultType' => $holidayTypeMap[HolidayCalendar::HOLIDAY_DOW] ?? 'prescribed',
            // フォールバック用: 基本設定＞勤怠の曜日指定（既定: 法定=日曜 / 所定=土曜）
            'legalHolidayDows' => $this->splitDows(Setting::getValue('legal_holiday_dows', 'sunday')),
            'prescribedHolidayDows' => $this->splitDows(Setting::getValue('prescribed_holiday_dows', 'saturday')),
        ];
    }

    /**
     * "sunday,saturday" 形式の設定値を小文字曜日名の配列へ。
     *
     * @return array<int, string>
     */
    private function splitDows(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($d) => strtolower(trim($d)),
            explode(',', $value),
        )));
    }

    public static function formatMinutesToHM(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return sprintf('%d:%02d', $h, $m);
    }

    public static function roundMinutes(int $minutes, int $unit, string $rule): int
    {
        return PunchRounding::roundMinutes($minutes, $unit, $rule);
    }
}
