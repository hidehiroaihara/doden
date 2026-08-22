<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceBreak;
use App\Models\AttendanceEditLog;
use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use App\Services\BreakDeduction;
use App\Services\MonthPeriod;
use App\Services\PhotoStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    public function userAttendances(Request $request, User $user)
    {
        [$dateFrom, $dateTo, $month, $year] = $this->resolveDateFilters($request, true);

        $query = Attendance::with(['user', 'attendanceBreaks'])->where('user_id', $user->id)->orderBy('work_date');
        if ($dateFrom) $query->where('work_date', '>=', $dateFrom);
        if ($dateTo) $query->where('work_date', '<=', $dateTo);

        $attendances = $query->get();

        $workStartTime = Setting::getValue('work_start_time');
        $workEndTime = Setting::getValue('work_end_time');
        $workHoursPerDay = Setting::getValue('work_hours_per_day');
        $defaultBreakMinutes = (int) Setting::getValue('default_break_minutes', 60);
        $breakStartTime = Setting::getValue('break_start_time', '12:00');
        $breakEndTime = Setting::getValue('break_end_time', '13:00');
        $salaryRoundMinutes = (int) Setting::getValue('salary_round_minutes', 15);
        $salaryRoundRule = Setting::getValue('salary_round_rule', 'floor');
        $hasSchedule = (bool) ($workStartTime && $workEndTime && $workHoursPerDay);

        $userBreakDefault = $user->break_minutes ?? $defaultBreakMinutes;

        // 各打刻に computed_break_minutes を付与（休憩ボタン記録 > 手入力 > 時間帯計算）
        $attendances = $attendances->map(function ($att) use (
            $breakStartTime, $breakEndTime, $userBreakDefault
        ) {
            if ($att->clock_in_at && $att->clock_out_at) {
                $att->computed_break_minutes = BreakDeduction::resolveWithLimit(
                    $att->break_minutes,
                    $att->clock_in_at,
                    $att->clock_out_at,
                    $att->work_date->format('Y-m-d'),
                    $breakStartTime,
                    $breakEndTime,
                    $userBreakDefault,
                    $att->attendanceBreaks ?? new Collection(),
                );
            } else {
                $att->computed_break_minutes = null;
            }
            return $att;
        });

        $allAttendances = $attendances;

        $totalWorkMinutes = 0;
        $totalRoundedMinutes = 0;
        $totalBreakMinutes = 0;
        $workDays = 0;
        $lateCount = 0;
        $earlyLeaveCount = 0;
        $totalOvertimeMinutes = 0;
        $totalScheduledMinutes = 0;
        $totalLateMinutes = 0;
        $totalEarlyMinutes = 0;
        $missingClockOut = 0;

        foreach ($allAttendances as $att) {
            if ($att->clock_in_at && !$att->clock_out_at && $att->work_date->lt(Carbon::today())) {
                $missingClockOut++;
                continue;
            }
            if (!$att->clock_in_at || !$att->clock_out_at) continue;

            $workDays++;
            $breakMin = $att->computed_break_minutes ?? 0;
            $totalBreakMinutes += $breakMin;
            $clockIn = Carbon::parse($att->clock_in_at);
            $clockOut = Carbon::parse($att->clock_out_at);
            $grossMin = $clockIn->diffInMinutes($clockOut);
            $netMin = max(0, $grossMin - $breakMin);
            $totalWorkMinutes += $netMin;
            $totalRoundedMinutes += $this->roundMinutes($netMin, $salaryRoundMinutes, $salaryRoundRule);

            if ($hasSchedule) {
                $dateStr = $att->work_date->format('Y-m-d');
                $schedStart = Carbon::parse("{$dateStr} {$workStartTime}");
                $schedEnd = Carbon::parse("{$dateStr} {$workEndTime}");
                $scheduledMin = (int) $workHoursPerDay;
                $totalScheduledMinutes += $scheduledMin;

                if ($clockIn->gt($schedStart)) {
                    $lateCount++;
                    $totalLateMinutes += $schedStart->diffInMinutes($clockIn);
                }
                if ($clockOut->lt($schedEnd)) {
                    $earlyLeaveCount++;
                    $totalEarlyMinutes += $clockOut->diffInMinutes($schedEnd);
                }
                if ($netMin > $scheduledMin) {
                    $totalOvertimeMinutes += $netMin - $scheduledMin;
                }
            }
        }

        $fmtHM = fn(int $m) => sprintf('%d:%02d', intdiv($m, 60), $m % 60);

        $summary = [
            'work_days' => $workDays,
            'total_work' => $fmtHM($totalWorkMinutes),
            'rounded_work' => $fmtHM($totalRoundedMinutes),
            'avg_per_day' => $workDays > 0 ? $fmtHM(intdiv($totalWorkMinutes, $workDays)) : '0:00',
            'total_break' => $fmtHM($totalBreakMinutes),
            'missing_clock_out' => $missingClockOut,
        ];

        if ($hasSchedule) {
            $summary['scheduled_total'] = $fmtHM($totalScheduledMinutes);
            $summary['overtime'] = $fmtHM($totalOvertimeMinutes);
            $summary['late_count'] = $lateCount;
            $summary['late_time'] = $fmtHM($totalLateMinutes);
            $summary['early_leave_count'] = $earlyLeaveCount;
            $summary['early_leave_time'] = $fmtHM($totalEarlyMinutes);
        }

        return Inertia::render('Admin/Users/Attendances/Index', [
            'user' => $user->only(['id', 'name', 'customer_no', 'break_minutes']),
            'attendances' => $attendances,
            'summary' => $summary,
            'hasSchedule' => $hasSchedule,
            'scheduleInfo' => $hasSchedule ? [
                'work_start_time' => $workStartTime,
                'work_end_time' => $workEndTime,
                'work_hours_per_day' => (int) $workHoursPerDay,
            ] : null,
            'defaultBreakMinutes' => $defaultBreakMinutes,
            'salaryRoundMinutes' => $salaryRoundMinutes,
            'salaryRoundRule' => $salaryRoundRule,
            'calendarFrom' => $dateFrom ?? null,
            'calendarTo' => $dateTo ?? null,
            'filters' => [
                'date_from' => $dateFrom ?? '',
                'date_to' => $dateTo ?? '',
                'month' => $month ?? '',
                'year' => $year ?? '',
            ],
        ]);
    }

    /**
     * 月別打刻表：指定月の締め期間について、全従業員×日別の出退勤を一覧表示する。
     */
    public function monthlySheet(Request $request)
    {
        $monthParam = $request->input('month');
        $monthKey = ($monthParam && preg_match('/^\d{4}-\d{2}$/', $monthParam))
            ? $monthParam
            : MonthPeriod::currentKey();

        $period = MonthPeriod::resolve($monthKey);
        $from = $period['from'];
        $to = $period['to'];

        $search = trim((string) $request->input('search', ''));
        $departmentId = $request->input('department_id', '');

        $userQuery = User::with('department')->where('is_active', true);

        if ($search !== '') {
            $userQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name_kana', 'like', "%{$search}%")
                    ->orWhere('first_name_kana', 'like', "%{$search}%")
                    ->orWhere('customer_no', 'like', "%{$search}%");
            });
        }

        if ($departmentId !== '') {
            $userQuery->where('department_id', $departmentId);
        }

        $users = $userQuery->orderBy('name')->get(['id', 'name', 'department_id']);

        $attendances = Attendance::whereBetween('work_date', [$from, $to])
            ->whereIn('user_id', $users->pluck('id'))
            ->get(['id', 'user_id', 'work_date', 'clock_in_at', 'clock_out_at']);

        $dow = ['日', '月', '火', '水', '木', '金', '土'];
        $days = [];
        $cursor = Carbon::parse($from);
        $end = Carbon::parse($to);
        while ($cursor->lte($end)) {
            $days[] = [
                'date' => $cursor->toDateString(),
                'day' => $cursor->day,
                'month' => $cursor->month,
                'dow' => $dow[$cursor->dayOfWeek],
                'is_weekend' => in_array($cursor->dayOfWeek, [0, 6], true),
            ];
            $cursor->addDay();
        }

        $fmtTime = fn ($dt) => $dt ? Carbon::parse($dt)->format('H:i') : null;

        $byUser = [];
        foreach ($attendances as $a) {
            $date = $a->work_date instanceof \DateTimeInterface
                ? $a->work_date->format('Y-m-d')
                : (string) $a->work_date;
            $byUser[$a->user_id][$date] = [
                'in' => $fmtTime($a->clock_in_at),
                'out' => $fmtTime($a->clock_out_at),
                'attendance_id' => $a->id,
                'missing_out' => (bool) ($a->clock_in_at && ! $a->clock_out_at && Carbon::parse($date)->lt(Carbon::today())),
            ];
        }

        $rows = $users->map(function (User $u) use ($byUser, $days) {
            $cells = [];
            $workDays = 0;
            foreach ($days as $day) {
                $rec = $byUser[$u->id][$day['date']] ?? null;
                if ($rec && $rec['in']) {
                    $workDays++;
                }
                $cells[$day['date']] = $rec;
            }

            return [
                'user_id' => $u->id,
                'user_name' => $u->name,
                'department' => $u->department?->name,
                'cells' => $cells,
                'work_days' => $workDays,
            ];
        })->values();

        return Inertia::render('Admin/Attendances/MonthlySheet', [
            'rows' => $rows,
            'days' => $days,
            'monthKey' => $monthKey,
            'monthLabel' => MonthPeriod::label($monthKey),
            'prevMonth' => MonthPeriod::shift($monthKey, -1),
            'nextMonth' => MonthPeriod::shift($monthKey, 1),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'filters' => [
                'search' => $search,
                'department_id' => $departmentId !== '' ? (string) $departmentId : '',
            ],
        ]);
    }

    public function index(Request $request)
    {
        $today = Carbon::today()->toDateString();
        $query = Attendance::with(['user', 'attendanceBreaks']);

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        $dateFrom = $request->input('date_from', $request->has('date_from') ? '' : $today);
        $dateTo = $request->input('date_to', $request->has('date_to') ? '' : $today);

        if ($dateFrom) {
            $query->where('work_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('work_date', '<=', $dateTo);
        }

        $attendances = $query->orderByDesc('work_date')->orderBy('user_id')->paginate(30)->withQueryString();

        return Inertia::render('Admin/Attendances/Index', [
            'attendances' => $attendances,
            'users' => User::orderBy('name')->get(['id', 'name']),
            'filters' => [
                'user_id' => $request->input('user_id', ''),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Admin/Attendances/Create', [
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'break_minutes']),
            'defaultBreakMinutes' => (int) Setting::getValue('default_break_minutes', 60),
            'presetUserId' => $request->input('user_id', ''),
            'presetDate' => $request->input('date', ''),
            'returnTo' => $request->query('return_to') === 'user_attendances' ? 'user_attendances' : null,
            'returnMonth' => $request->query('return_month'),
            'returnYear' => $request->query('return_year'),
            'returnDateFrom' => $request->query('return_date_from'),
            'returnDateTo' => $request->query('return_date_to'),
        ]);
    }

    public function store(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'user_id' => ['required', 'exists:users,id'],
            'work_date' => ['required', 'date'],
            'clock_in_at' => ['nullable', 'date'],
            'clock_out_at' => ['nullable', 'date'],
            'breaks'              => ['nullable', 'array'],
            'breaks.*.started_at' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'breaks.*.ended_at'   => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'reason' => ['nullable', 'string', 'max:500'],
            'return_to' => ['nullable', 'string', 'in:user_attendances'],
            'return_month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'return_year' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'return_date_from' => ['nullable', 'date_format:Y-m-d'],
            'return_date_to' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'breaks.*.started_at.required' => '休憩の開始時刻を入力してください',
            'breaks.*.started_at.regex'    => '休憩の開始時刻の形式が正しくありません',
            'breaks.*.ended_at.regex'      => '休憩の終了時刻の形式が正しくありません',
        ]);
        $validator->after(function ($v) use ($request) {
            $this->validateBreakRanges($v, $request->input('breaks', []), $request->input('clock_in_at'), $request->input('clock_out_at'));
        });
        $validated = $validator->validate();

        $existing = Attendance::where('user_id', $validated['user_id'])
            ->where('work_date', $validated['work_date'])
            ->first();

        if ($existing) {
            return back()->withErrors(['work_date' => 'この日付の打刻は既に登録されています。編集画面から修正してください。']);
        }

        $attendance = Attendance::create([
            'user_id' => $validated['user_id'],
            'work_date' => $validated['work_date'],
            'clock_in_at' => $validated['clock_in_at'] ?: null,
            'clock_out_at' => $validated['clock_out_at'] ?: null,
            'break_minutes' => null,
        ]);

        $admin = Auth::guard('admin')->user();
        $now = Carbon::now();

        // 休憩セット保存 + 履歴
        $workDate = $validated['work_date'];
        foreach ($validated['breaks'] ?? [] as $row) {
            AttendanceBreak::create([
                'attendance_id' => $attendance->id,
                'started_at'    => "{$workDate} {$row['started_at']}:00",
                'ended_at'      => !empty($row['ended_at']) ? "{$workDate} {$row['ended_at']}:00" : null,
            ]);
            AttendanceEditLog::create([
                'attendance_id'       => $attendance->id,
                'field_name'          => 'break',
                'before_value'        => null,
                'after_value'         => $this->formatBreakRangeFromStrings($row['started_at'], $row['ended_at'] ?? null),
                'modified_by_user_id' => $admin->id,
                'modified_at'         => $now,
                'reason'              => $validated['reason'] ?? null,
            ]);
        }

        if ($validated['reason']) {
            foreach (['clock_in_at', 'clock_out_at'] as $field) {
                if (!empty($validated[$field])) {
                    AttendanceEditLog::create([
                        'attendance_id' => $attendance->id,
                        'field_name' => $field,
                        'before_value' => null,
                        'after_value' => $validated[$field],
                        'modified_by_user_id' => $admin->id,
                        'modified_at' => $now,
                        'reason' => $validated['reason'],
                    ]);
                }
            }
        }

        return $this->redirectAfterAttendanceSave(
            $request,
            ($validated['return_to'] ?? null) === 'user_attendances',
            (int) $validated['user_id'],
            '打刻を登録しました'
        );
    }

    public function edit(Request $request, Attendance $attendance)
    {
        $attendance->load(['user', 'editLogs.modifier', 'attendanceBreaks']);

        return Inertia::render('Admin/Attendances/Edit', [
            'attendance' => $attendance,
            'returnTo' => $request->query('return_to') === 'user_attendances' ? 'user_attendances' : null,
            'returnMonth' => $request->query('return_month'),
            'returnYear' => $request->query('return_year'),
            'returnDateFrom' => $request->query('return_date_from'),
            'returnDateTo' => $request->query('return_date_to'),
        ]);
    }

    public function update(Request $request, Attendance $attendance, PhotoStorageService $photos)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'clock_in_at'    => ['nullable', 'date_format:Y-m-d H:i:s'],
            'clock_out_at'   => ['nullable', 'date_format:Y-m-d H:i:s', 'after:clock_in_at'],
            'breaks'                   => ['nullable', 'array'],
            'breaks.*.id'              => ['nullable', 'integer'],
            'breaks.*.started_at'      => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'breaks.*.ended_at'        => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'reason'         => ['nullable', 'string', 'max:500'],
            'return_to'      => ['nullable', 'string', 'in:user_attendances'],
            'return_month'   => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'return_year'    => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'return_date_from' => ['nullable', 'date_format:Y-m-d'],
            'return_date_to'   => ['nullable', 'date_format:Y-m-d'],
        ], [
            'clock_in_at.date_format'  => '出勤時刻の形式が正しくありません',
            'clock_out_at.date_format' => '退勤時刻の形式が正しくありません',
            'clock_out_at.after'       => '退勤時刻は出勤時刻より後に設定してください',
            'breaks.*.started_at.required' => '休憩の開始時刻を入力してください',
            'breaks.*.started_at.regex'    => '休憩の開始時刻の形式が正しくありません',
            'breaks.*.ended_at.regex'      => '休憩の終了時刻の形式が正しくありません',
        ]);
        $validator->after(function ($v) use ($request) {
            $this->validateBreakRanges($v, $request->input('breaks', []), $request->input('clock_in_at'), $request->input('clock_out_at'));
        });
        $validated = $validator->validate();

        $admin = Auth::guard('admin')->user();
        $now = Carbon::now();

        foreach (['clock_in_at', 'clock_out_at'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== ($attendance->$field?->toIso8601String())) {
                AttendanceEditLog::create([
                    'attendance_id' => $attendance->id,
                    'field_name' => $field,
                    'before_value' => $attendance->$field?->format('Y-m-d H:i:s'),
                    'after_value' => $validated[$field],
                    'modified_by_user_id' => $admin->id,
                    'modified_at' => $now,
                    'reason' => $validated['reason'] ?? null,
                ]);
            }
        }

        $attendance->update([
            'clock_in_at'   => $validated['clock_in_at'],
            'clock_out_at'  => $validated['clock_out_at'],
            'break_minutes' => null,
        ]);

        // 休憩セット同期: 送信された breaks 配列で完全に置き換え
        $workDate = $attendance->work_date->format('Y-m-d');
        $incoming = collect($validated['breaks'] ?? []);
        $keepIds  = $incoming->pluck('id')->filter()->map(fn($v) => (int) $v)->all();

        $attendance->load('attendanceBreaks');
        $existingById = $attendance->attendanceBreaks->keyBy('id');

        // 配列に含まれない既存レコードは写真ごと削除 + 履歴
        foreach ($attendance->attendanceBreaks as $existing) {
            if (! in_array($existing->id, $keepIds, true)) {
                if ($existing->start_photo_path) $photos->delete($existing->start_photo_path);
                if ($existing->end_photo_path)   $photos->delete($existing->end_photo_path);
                AttendanceEditLog::create([
                    'attendance_id'       => $attendance->id,
                    'field_name'          => 'break',
                    'before_value'        => $this->formatBreakRange($existing->started_at, $existing->ended_at),
                    'after_value'         => null,
                    'modified_by_user_id' => $admin->id,
                    'modified_at'         => $now,
                    'reason'              => $validated['reason'] ?? null,
                ]);
                $existing->delete();
            }
        }

        // 既存は更新、id なしは新規作成（差分があるときだけ履歴）
        foreach ($incoming as $row) {
            $startStr = "{$workDate} {$row['started_at']}:00";
            $endStr   = !empty($row['ended_at']) ? "{$workDate} {$row['ended_at']}:00" : null;
            $payload  = ['started_at' => $startStr, 'ended_at' => $endStr];

            if (!empty($row['id']) && $existingById->has((int) $row['id'])) {
                $existing = $existingById->get((int) $row['id']);
                $beforeRange = $this->formatBreakRange($existing->started_at, $existing->ended_at);
                $afterRange  = $this->formatBreakRangeFromStrings($row['started_at'], $row['ended_at'] ?? null);

                if ($beforeRange !== $afterRange) {
                    AttendanceEditLog::create([
                        'attendance_id'       => $attendance->id,
                        'field_name'          => 'break',
                        'before_value'        => $beforeRange,
                        'after_value'         => $afterRange,
                        'modified_by_user_id' => $admin->id,
                        'modified_at'         => $now,
                        'reason'              => $validated['reason'] ?? null,
                    ]);
                }

                AttendanceBreak::where('id', $row['id'])
                    ->where('attendance_id', $attendance->id)
                    ->update($payload);
            } else {
                AttendanceBreak::create($payload + ['attendance_id' => $attendance->id]);
                AttendanceEditLog::create([
                    'attendance_id'       => $attendance->id,
                    'field_name'          => 'break',
                    'before_value'        => null,
                    'after_value'         => $this->formatBreakRangeFromStrings($row['started_at'], $row['ended_at'] ?? null),
                    'modified_by_user_id' => $admin->id,
                    'modified_at'         => $now,
                    'reason'              => $validated['reason'] ?? null,
                ]);
            }
        }

        return $this->redirectAfterAttendanceSave(
            $request,
            ($validated['return_to'] ?? null) === 'user_attendances',
            (int) $attendance->user_id,
            '打刻情報を修正しました'
        );
    }

    public function destroy(Request $request, Attendance $attendance, PhotoStorageService $photos)
    {
        $attendance->load('attendanceBreaks');

        foreach ($attendance->attendanceBreaks as $break) {
            if ($break->start_photo_path) $photos->delete($break->start_photo_path);
            if ($break->end_photo_path)   $photos->delete($break->end_photo_path);
        }

        if ($attendance->clock_in_photo_path)  $photos->delete($attendance->clock_in_photo_path);
        if ($attendance->clock_out_photo_path) $photos->delete($attendance->clock_out_photo_path);

        $userId = (int) $attendance->user_id;
        $attendance->delete();

        return $this->redirectAfterAttendanceSave(
            $request,
            $request->input('return_to') === 'user_attendances',
            $userId,
            '打刻記録を削除しました'
        );
    }

    public function breakUpdate(Request $request, Attendance $attendance, AttendanceBreak $break)
    {
        if ($break->attendance_id !== $attendance->id) {
            abort(404);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'started_at' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'ended_at'   => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
        ], [
            'started_at.required' => '開始時刻を入力してください',
            'started_at.regex'    => '開始時刻の形式が正しくありません',
            'ended_at.regex'      => '終了時刻の形式が正しくありません',
        ]);
        $clockIn  = $attendance->clock_in_at  ? $attendance->clock_in_at->format('H:i')  : null;
        $clockOut = $attendance->clock_out_at ? $attendance->clock_out_at->format('H:i') : null;
        $validator->after(function ($v) use ($request, $clockIn, $clockOut) {
            $start = $request->input('started_at');
            $end   = $request->input('ended_at');
            if ($start && $clockIn && $start < $clockIn) {
                $v->errors()->add('started_at', '休憩の開始時刻は出勤時刻以降にしてください');
            }
            if ($end && $clockOut && $end > $clockOut) {
                $v->errors()->add('ended_at', '休憩の終了時刻は退勤時刻以前にしてください');
            }
            if ($start && $end && $end < $start) {
                $v->errors()->add('ended_at', '休憩の終了時刻は開始時刻より後にしてください');
            }
        });
        $validated = $validator->validate();

        $beforeRange = $this->formatBreakRange($break->started_at, $break->ended_at);
        $afterRange  = $this->formatBreakRangeFromStrings($validated['started_at'], $validated['ended_at'] ?? null);

        $workDate = $attendance->work_date->format('Y-m-d');
        $break->update([
            'started_at' => "{$workDate} {$validated['started_at']}:00",
            'ended_at'   => $validated['ended_at'] ? "{$workDate} {$validated['ended_at']}:00" : null,
        ]);

        if ($beforeRange !== $afterRange) {
            AttendanceEditLog::create([
                'attendance_id'       => $attendance->id,
                'field_name'          => 'break',
                'before_value'        => $beforeRange,
                'after_value'         => $afterRange,
                'modified_by_user_id' => Auth::guard('admin')->id(),
                'modified_at'         => Carbon::now(),
                'reason'              => null,
            ]);
        }

        return back()->with('success', '休憩時刻を更新しました');
    }

    public function breakDestroy(Attendance $attendance, AttendanceBreak $break, PhotoStorageService $photos)
    {
        if ($break->attendance_id !== $attendance->id) {
            abort(404);
        }

        $beforeRange = $this->formatBreakRange($break->started_at, $break->ended_at);

        if ($break->start_photo_path) $photos->delete($break->start_photo_path);
        if ($break->end_photo_path)   $photos->delete($break->end_photo_path);

        $break->delete();

        AttendanceEditLog::create([
            'attendance_id'       => $attendance->id,
            'field_name'          => 'break',
            'before_value'        => $beforeRange,
            'after_value'         => null,
            'modified_by_user_id' => Auth::guard('admin')->id(),
            'modified_at'         => Carbon::now(),
            'reason'              => null,
        ]);

        return back()->with('success', '休憩記録を削除しました');
    }

    public function photo(Attendance $attendance, string $type)
    {
        $field = $type === 'in' ? 'clock_in_photo_path' : 'clock_out_photo_path';
        $path = $attendance->$field;

        if (!$path || !Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($path));
    }

    public function breakPhoto(Attendance $attendance, AttendanceBreak $break, string $type)
    {
        if ($break->attendance_id !== $attendance->id) {
            abort(404);
        }

        $path = $type === 'start' ? $break->start_photo_path : $break->end_photo_path;

        if (!$path || !Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($path));
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $userId = $request->input('user_id');
        $isUserSpecific = !empty($userId);

        $query = Attendance::with(['user.department', 'attendanceBreaks']);
        if ($isUserSpecific) {
            $query->where('user_id', $userId);
        }

        [$dateFrom, $dateTo] = $this->resolveDateFilters($request, false);
        if ($dateFrom) $query->where('work_date', '>=', $dateFrom);
        if ($dateTo) $query->where('work_date', '<=', $dateTo);

        $attendances = $query->orderBy('work_date')->orderBy('user_id')->get();

        $breakStartTime = Setting::getValue('break_start_time', '12:00');
        $breakEndTime = Setting::getValue('break_end_time', '13:00');
        $defaultBreakMinutes = (int) Setting::getValue('default_break_minutes', 60);
        $salaryRoundMinutes = (int) Setting::getValue('salary_round_minutes', 15);
        $salaryRoundRule = Setting::getValue('salary_round_rule', 'floor');
        $workStartTime = Setting::getValue('work_start_time');
        $workEndTime = Setting::getValue('work_end_time');
        $workHoursPerDay = Setting::getValue('work_hours_per_day');
        $hasSchedule = (bool) ($workStartTime && $workEndTime && $workHoursPerDay);

        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];

        // ユーザー個別CSVの場合: カレンダー全日を出力
        $calendarDays = [];
        if ($isUserSpecific && $dateFrom && $dateTo) {
            $cur = Carbon::parse($dateFrom);
            $end = Carbon::parse($dateTo);
            while ($cur->lte($end)) {
                $calendarDays[] = $cur->format('Y-m-d');
                $cur->addDay();
            }
        }

        // 打刻データを日付キーでマップ化
        $attendanceMap = $attendances->keyBy(fn($a) => $a->work_date->format('Y-m-d'));

        // ユーザー情報（個別時）
        $csvUser = $isUserSpecific ? User::with('department')->find($userId) : null;

        $fmtHM = fn(int $m) => sprintf('%d:%02d', intdiv($m, 60), $m % 60);

        // 最大休憩回数を算出（動的列数）
        $maxBreaks = $attendances->max(fn($a) => $a->attendanceBreaks->count()) ?? 0;

        $headers = ['ユーザー名', '顧客No', '勤務日', '曜日', '出勤時刻', '退勤時刻', '休憩時間(分)', '総拘束時間', '実労働時間', '丸め後労働時間'];
        if ($hasSchedule) {
            $headers[] = '遅刻';
            $headers[] = '早退';
            $headers[] = '残業時間';
        }
        for ($i = 1; $i <= $maxBreaks; $i++) {
            $headers[] = "休憩{$i}";
        }
        $headers[] = '備考';

        // ユーザー個別 CSV のみ: ヘッダーの前に合計行を出力するため事前集計
        $csvSummary = null;
        if ($isUserSpecific) {
            $csvSummary = $this->calcCsvSummary(
                $attendances,
                $breakStartTime, $breakEndTime,
                $csvUser?->break_minutes ?? $defaultBreakMinutes,
                $salaryRoundMinutes, $salaryRoundRule,
                $workStartTime, $workEndTime, $workHoursPerDay, $hasSchedule,
            );
        }

        return response()->streamDownload(function () use (
            $attendances, $attendanceMap, $calendarDays, $csvUser, $isUserSpecific,
            $breakStartTime, $breakEndTime, $defaultBreakMinutes,
            $salaryRoundMinutes, $salaryRoundRule,
            $workStartTime, $workEndTime, $workHoursPerDay, $hasSchedule,
            $weekdays, $headers, $fmtHM, $maxBreaks, $csvSummary
        ) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // ユーザー個別: ヘッダー行の前に合計ブロックを出力
            if ($csvSummary !== null) {
                $summaryLabels = ['出勤日数', '労働合計', '丸め後', '休憩合計', '平均/日', '退勤忘れ'];
                $summaryValues = [
                    $csvSummary['work_days'] . '日',
                    $csvSummary['total_work'],
                    $csvSummary['rounded_work'],
                    $csvSummary['total_break'],
                    $csvSummary['avg_per_day'],
                    $csvSummary['missing_clock_out'],
                ];
                if ($csvSummary['has_schedule']) {
                    $summaryLabels[] = '残業時間';
                    $summaryLabels[] = '遅刻回数';
                    $summaryLabels[] = '遅刻時間';
                    $summaryLabels[] = '早退回数';
                    $summaryLabels[] = '早退時間';
                    $summaryValues[] = $csvSummary['overtime'];
                    $summaryValues[] = $csvSummary['late_count'] . '回';
                    $summaryValues[] = $csvSummary['late_time'];
                    $summaryValues[] = $csvSummary['early_leave_count'] . '回';
                    $summaryValues[] = $csvSummary['early_leave_time'];
                }
                fputcsv($handle, array_merge(['合計'], array_fill(0, count($headers) - 1, '')));
                fputcsv($handle, $summaryLabels);
                fputcsv($handle, $summaryValues);
                fputcsv($handle, array_fill(0, count($headers), ''));  // 空行
            }

            fputcsv($handle, $headers);

            $buildRow = function (?Attendance $a, ?string $dateStr) use (
                $csvUser, $isUserSpecific,
                $breakStartTime, $breakEndTime, $defaultBreakMinutes,
                $salaryRoundMinutes, $salaryRoundRule,
                $workStartTime, $workEndTime, $workHoursPerDay, $hasSchedule,
                $weekdays, $fmtHM, $maxBreaks
            ): array {
                $dayCarbon = Carbon::parse($dateStr ?? $a?->work_date?->format('Y-m-d'));
                $userName = $isUserSpecific ? ($csvUser?->name ?? '') : ($a?->user?->name ?? '');
                $cusNo = $isUserSpecific ? ($csvUser?->customer_no ?? '') : ($a?->user?->customer_no ?? '');

                $breaks = $a?->attendanceBreaks ?? new Collection();
                $totalTimeStr = '';
                $workingTimeStr = '';
                $roundedTimeStr = '';
                $lateStr = '';
                $earlyStr = '';
                $overtimeStr = '';
                $note = '';
                $breakMin = 0;

                if ($a && $a->clock_in_at && $a->clock_out_at) {
                    $userBreakLimit = $isUserSpecific
                        ? ($csvUser?->break_minutes ?? $defaultBreakMinutes)
                        : ($a->user?->break_minutes ?? $defaultBreakMinutes);
                    $breakMin = BreakDeduction::resolveWithLimit(
                        $a->break_minutes,
                        $a->clock_in_at,
                        $a->clock_out_at,
                        $a->work_date->format('Y-m-d'),
                        $breakStartTime,
                        $breakEndTime,
                        $userBreakLimit,
                        $breaks,
                    );

                    $totalMinutes = $a->clock_in_at->diffInMinutes($a->clock_out_at);
                    $totalTimeStr = $fmtHM($totalMinutes);
                    $netMinutes = max(0, $totalMinutes - $breakMin);
                    $workingTimeStr = $fmtHM($netMinutes);
                    $roundedMinutes = (function () use ($netMinutes, $salaryRoundMinutes, $salaryRoundRule) {
                        if ($salaryRoundMinutes <= 0) return $netMinutes;
                        $q = $netMinutes / $salaryRoundMinutes;
                        return match ($salaryRoundRule) {
                            'ceil' => (int) ceil($q) * $salaryRoundMinutes,
                            'round' => (int) round($q) * $salaryRoundMinutes,
                            default => (int) floor($q) * $salaryRoundMinutes,
                        };
                    })();
                    $roundedTimeStr = $fmtHM($roundedMinutes);

                    if ($hasSchedule) {
                        $dateKey = $dayCarbon->format('Y-m-d');
                        $schedStart = Carbon::parse("{$dateKey} {$workStartTime}");
                        $schedEnd = Carbon::parse("{$dateKey} {$workEndTime}");
                        if ($a->clock_in_at->gt($schedStart)) {
                            $lateStr = $fmtHM($schedStart->diffInMinutes($a->clock_in_at));
                        }
                        if ($a->clock_out_at->lt($schedEnd)) {
                            $earlyStr = $fmtHM($a->clock_out_at->diffInMinutes($schedEnd));
                        }
                        $scheduledMin = (int) $workHoursPerDay;
                        if ($netMinutes > $scheduledMin) {
                            $overtimeStr = $fmtHM($netMinutes - $scheduledMin);
                        }
                    }
                } elseif ($a && $a->clock_in_at && !$a->clock_out_at && $dayCarbon->lt(Carbon::today())) {
                    $note = '退勤忘れ';
                }

                $row = [
                    $userName,
                    $cusNo,
                    $dayCarbon->format('Y-m-d'),
                    $weekdays[$dayCarbon->dayOfWeek] ?? '',
                    $a?->clock_in_at?->format('H:i') ?? '',
                    $a?->clock_out_at?->format('H:i') ?? '',
                    ($a && $a->clock_in_at && $a->clock_out_at) ? $breakMin : '',
                    $totalTimeStr,
                    $workingTimeStr,
                    $roundedTimeStr,
                ];

                if ($hasSchedule) {
                    $row[] = $lateStr;
                    $row[] = $earlyStr;
                    $row[] = $overtimeStr;
                }

                // 各休憩の所要時間（H:MM 形式）
                for ($i = 0; $i < $maxBreaks; $i++) {
                    $brk = $breaks->values()->get($i);
                    if ($brk && $brk->ended_at) {
                        $row[] = $fmtHM((int) $brk->started_at->diffInMinutes($brk->ended_at));
                    } else {
                        $row[] = '';
                    }
                }

                $row[] = $note;
                return $row;
            };

            if (!empty($calendarDays)) {
                // ユーザー個別: カレンダー全日を出力
                foreach ($calendarDays as $d) {
                    $a = $attendanceMap->get($d);
                    fputcsv($handle, $buildRow($a, $d));
                }
            } else {
                // 全社: 打刻レコードのみ
                foreach ($attendances as $a) {
                    fputcsv($handle, $buildRow($a, null));
                }
            }

            fclose($handle);
        }, 'attendances_' . now()->format('Ymd_His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * 休憩控除時間を算出する。
     * - attendance.break_minutes が明示的にセットされていればそれを使用
     * - 未設定の場合、規定休憩時間帯と勤務時間の重なりから判定
     *   → 退勤が休憩開始前なら控除0分
     */
    /**
     * 休憩配列の入り・戻り時刻が出勤・退勤の範囲内かを検証してエラー追加する。
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $v
     * @param  array  $breaks         ['started_at' => 'HH:MM', 'ended_at' => 'HH:MM'|null]
     * @param  string|null  $clockInRaw   出勤打刻（'Y-m-d H:i:s' or 'Y-m-d\TH:i' 等）
     * @param  string|null  $clockOutRaw  退勤打刻
     */
    private function validateBreakRanges($v, array $breaks, ?string $clockInRaw, ?string $clockOutRaw): void
    {
        $clockIn  = $clockInRaw  ? Carbon::parse($clockInRaw)->format('H:i')  : null;
        $clockOut = $clockOutRaw ? Carbon::parse($clockOutRaw)->format('H:i') : null;

        foreach ($breaks as $i => $row) {
            $start = $row['started_at'] ?? null;
            $end   = $row['ended_at']   ?? null;

            // HH:MM 形式以外はスキップ（別ルールでエラー検出済み）
            if ($start && ! preg_match('/^\d{2}:\d{2}$/', $start)) continue;
            if ($end   && ! preg_match('/^\d{2}:\d{2}$/', $end))   continue;

            if ($start && $clockIn && $start < $clockIn) {
                $v->errors()->add("breaks.{$i}.started_at", '休憩の開始時刻は出勤時刻以降にしてください');
            }
            if ($end && $clockOut && $end > $clockOut) {
                $v->errors()->add("breaks.{$i}.ended_at", '休憩の終了時刻は退勤時刻以前にしてください');
            }
            if ($start && $end && $end < $start) {
                $v->errors()->add("breaks.{$i}.ended_at", '休憩の終了時刻は開始時刻より後にしてください');
            }
        }
    }

    /**
     * 休憩レコードの「HH:MM 〜 HH:MM」表記を生成（Carbon インスタンス用）。
     */
    private function formatBreakRange($started, $ended): string
    {
        $s = $started ? Carbon::parse($started)->format('H:i') : '';
        $e = $ended   ? Carbon::parse($ended)->format('H:i')   : '未終了';
        return "{$s} 〜 {$e}";
    }

    /**
     * 休憩レコードの「HH:MM 〜 HH:MM」表記を生成（文字列入力用）。
     */
    private function formatBreakRangeFromStrings(string $start, ?string $end): string
    {
        return "{$start} 〜 " . ($end ?: '未終了');
    }

    private function resolveBreakMinutes(Attendance $attendance, string $breakStartTime, string $breakEndTime, int $defaultBreakMinutes): int
    {
        if ($attendance->break_minutes !== null) {
            return $attendance->break_minutes;
        }

        if (!$attendance->clock_in_at || !$attendance->clock_out_at) {
            return 0;
        }

        $userBreak = $attendance->user->break_minutes ?? $defaultBreakMinutes;

        $workDate = $attendance->work_date->format('Y-m-d');
        $breakStart = Carbon::parse("{$workDate} {$breakStartTime}");
        $breakEnd = Carbon::parse("{$workDate} {$breakEndTime}");

        $clockIn = $attendance->clock_in_at;
        $clockOut = $attendance->clock_out_at;

        if ($clockOut->lte($breakStart)) {
            return 0;
        }

        if ($clockIn->gte($breakEnd)) {
            return 0;
        }

        $overlapStart = $clockIn->gt($breakStart) ? $clockIn : $breakStart;
        $overlapEnd = $clockOut->lt($breakEnd) ? $clockOut : $breakEnd;
        $overlapMinutes = (int) $overlapStart->diffInMinutes($overlapEnd);

        return min($overlapMinutes, $userBreak);
    }

    /**
     * クエリ ?month, ?year, ?date_from, ?date_to から検索期間を解決する。
     * 月締め日設定（MonthPeriod）に合わせて期間を確定する。
     *
     * @param  bool  $defaultToCurrentMonth フィルタ未指定時に今月キーを補完するか
     * @return array{0:?string,1:?string,2:?string,3:?string} [dateFrom, dateTo, month, year]
     */
    private function resolveDateFilters(Request $request, bool $defaultToCurrentMonth): array
    {
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');
        $month    = $request->input('month');
        $year     = $request->input('year');

        if ($defaultToCurrentMonth && !$request->hasAny(['date_from', 'date_to', 'month', 'year'])) {
            $month = MonthPeriod::currentKey();
        }

        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $period = MonthPeriod::resolve($month);
            $dateFrom = $period['from'];
            $dateTo   = $period['to'];
        } elseif ($year && preg_match('/^\d{4}$/', $year)) {
            $dateFrom = $year . '-01-01';
            $dateTo   = $year . '-12-31';
        }

        return [$dateFrom, $dateTo, $month, $year];
    }

    /**
     * 打刻登録・修正後のリダイレクト先（ユーザー別打刻一覧から来た場合はその一覧へ戻す）
     */
    private function redirectAfterAttendanceSave(Request $request, bool $toUserAttendances, int $userId, string $message): \Illuminate\Http\RedirectResponse
    {
        if (!$toUserAttendances) {
            return redirect()->route('admin.attendances.index')->with('success', $message);
        }

        $routeParams = array_merge(
            ['user' => $userId],
            array_filter([
                'month' => $request->input('return_month'),
                'year' => $request->input('return_year'),
                'date_from' => $request->input('return_date_from'),
                'date_to' => $request->input('return_date_to'),
            ], fn ($v) => $v !== null && $v !== '')
        );

        return redirect()->route('admin.users.attendances', $routeParams)->with('success', $message);
    }

    /**
     * ユーザー個別 CSV 用に合計集計を事前算出する。
     * userAttendances() の summary 計算と同等のロジック。
     */
    private function calcCsvSummary(
        $attendances,
        string $breakStartTime,
        string $breakEndTime,
        int $userBreakDefault,
        int $salaryRoundMinutes,
        string $salaryRoundRule,
        ?string $workStartTime,
        ?string $workEndTime,
        ?string $workHoursPerDay,
        bool $hasSchedule,
    ): array {
        $totalWorkMinutes    = 0;
        $totalRoundedMinutes = 0;
        $totalBreakMinutes   = 0;
        $workDays            = 0;
        $lateCount           = 0;
        $earlyLeaveCount     = 0;
        $totalOvertimeMinutes = 0;
        $totalLateMinutes    = 0;
        $totalEarlyMinutes   = 0;
        $missingClockOut     = 0;

        foreach ($attendances as $att) {
            if ($att->clock_in_at && !$att->clock_out_at && $att->work_date->lt(Carbon::today())) {
                $missingClockOut++;
                continue;
            }
            if (!$att->clock_in_at || !$att->clock_out_at) {
                continue;
            }

            $workDays++;
            $clockIn  = Carbon::parse($att->clock_in_at);
            $clockOut = Carbon::parse($att->clock_out_at);
            $breakMin = BreakDeduction::resolveWithLimit(
                $att->break_minutes,
                $clockIn,
                $clockOut,
                $att->work_date->format('Y-m-d'),
                $breakStartTime,
                $breakEndTime,
                $userBreakDefault,
                $att->attendanceBreaks ?? new Collection(),
            );
            $totalBreakMinutes += $breakMin;
            $grossMin = $clockIn->diffInMinutes($clockOut);
            $netMin   = max(0, $grossMin - $breakMin);
            $totalWorkMinutes    += $netMin;
            $totalRoundedMinutes += $this->roundMinutes($netMin, $salaryRoundMinutes, $salaryRoundRule);

            if ($hasSchedule) {
                $dateStr   = $att->work_date->format('Y-m-d');
                $schedStart = Carbon::parse("{$dateStr} {$workStartTime}");
                $schedEnd   = Carbon::parse("{$dateStr} {$workEndTime}");
                $scheduledMin = (int) $workHoursPerDay;

                if ($clockIn->gt($schedStart)) {
                    $lateCount++;
                    $totalLateMinutes += $schedStart->diffInMinutes($clockIn);
                }
                if ($clockOut->lt($schedEnd)) {
                    $earlyLeaveCount++;
                    $totalEarlyMinutes += $clockOut->diffInMinutes($schedEnd);
                }
                if ($netMin > $scheduledMin) {
                    $totalOvertimeMinutes += $netMin - $scheduledMin;
                }
            }
        }

        $fmtHM = fn(int $m) => sprintf('%d:%02d', intdiv($m, 60), $m % 60);

        return array_merge([
            'work_days'        => $workDays,
            'total_work'       => $fmtHM($totalWorkMinutes),
            'rounded_work'     => $fmtHM($totalRoundedMinutes),
            'total_break'      => $fmtHM($totalBreakMinutes),
            'avg_per_day'      => $workDays > 0 ? $fmtHM(intdiv($totalWorkMinutes, $workDays)) : '0:00',
            'missing_clock_out' => $missingClockOut,
            'has_schedule'     => $hasSchedule,
        ], $hasSchedule ? [
            'overtime'          => $fmtHM($totalOvertimeMinutes),
            'late_count'        => $lateCount,
            'late_time'         => $fmtHM($totalLateMinutes),
            'early_leave_count' => $earlyLeaveCount,
            'early_leave_time'  => $fmtHM($totalEarlyMinutes),
        ] : []);
    }

    /**
     * 実労働分を丸め単位で丸める。
     * @param int $minutes 実労働時間（分）
     * @param int $unit 丸め単位（分）
     * @param string $rule floor=切り捨て, round=四捨五入, ceil=切り上げ
     */
    private function roundMinutes(int $minutes, int $unit, string $rule): int
    {
        if ($unit <= 0) {
            return $minutes;
        }

        $quotient = $minutes / $unit;

        return match ($rule) {
            'ceil' => (int) ceil($quotient) * $unit,
            'round' => (int) round($quotient) * $unit,
            default => (int) floor($quotient) * $unit,
        };
    }
}
