<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendChatworkNotification;
use App\Models\Attendance;
use App\Models\AttendanceBreak;
use App\Models\User;
use App\Services\PhotoStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceController extends Controller
{
    public function __construct(
        private PhotoStorageService $photoStorage,
    ) {}

    public function today(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $attendance = Attendance::with('attendanceBreaks')
            ->where('user_id', $request->input('user_id'))
            ->where('work_date', Carbon::today()->toDateString())
            ->first();

        return response()->json([
            'attendance' => $attendance,
        ]);
    }

    public function clockIn(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'photo' => ['required', 'string'],
        ]);

        $user = User::findOrFail($request->input('user_id'));
        $today = Carbon::today()->toDateString();

        $existing = Attendance::where('user_id', $user->id)
            ->where('work_date', $today)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => '本日はすでに出勤打刻済みです',
            ], 409);
        }

        $photoPath = $this->photoStorage->storeFromBase64($request->input('photo'), 'clock_in');

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'department_id' => $user->department_id,
            'work_date' => $today,
            'clock_in_at' => Carbon::now(),
            'clock_in_photo_path' => $photoPath,
            'clock_in_ip' => $request->ip(),
        ]);

        if ($user->chatwork_room_id) {
            SendChatworkNotification::dispatch($user, '出勤', $attendance, $photoPath);
        }

        return response()->json([
            'message' => '出勤打刻が完了しました',
            'attendance' => $attendance->load('attendanceBreaks'),
        ]);
    }

    public function clockOut(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'photo' => ['required', 'string'],
        ]);

        $user = User::findOrFail($request->input('user_id'));
        $today = Carbon::today()->toDateString();

        $attendance = Attendance::where('user_id', $user->id)
            ->where('work_date', $today)
            ->first();

        if (! $attendance) {
            return response()->json([
                'message' => '出勤打刻が未登録のため、退勤できません',
            ], 409);
        }

        if ($attendance->clock_out_at) {
            return response()->json([
                'message' => '本日はすでに退勤打刻済みです',
            ], 409);
        }

        // 開いたままの休憩があれば退勤時刻で自動終了
        $openBreak = $attendance->attendanceBreaks()->whereNull('ended_at')->latest('started_at')->first();
        if ($openBreak) {
            $openBreak->update(['ended_at' => Carbon::now()]);
        }

        $photoPath = $this->photoStorage->storeFromBase64($request->input('photo'), 'clock_out');

        $attendance->update([
            'clock_out_at' => Carbon::now(),
            'clock_out_photo_path' => $photoPath,
            'clock_out_ip' => $request->ip(),
        ]);

        if ($user->chatwork_room_id) {
            SendChatworkNotification::dispatch($user, '退勤', $attendance->fresh(), $photoPath);
        }

        return response()->json([
            'message' => '退勤打刻が完了しました',
            'attendance' => $attendance->fresh()->load('attendanceBreaks'),
        ]);
    }

    public function breakStart(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'photo' => ['required', 'string'],
        ]);

        $user = User::findOrFail($request->input('user_id'));
        $today = Carbon::today()->toDateString();

        $attendance = Attendance::where('user_id', $user->id)
            ->where('work_date', $today)
            ->first();

        if (! $attendance || ! $attendance->clock_in_at) {
            return response()->json(['message' => '出勤打刻がないため休憩できません'], 409);
        }

        if ($attendance->clock_out_at) {
            return response()->json(['message' => 'すでに退勤済みのため休憩できません'], 409);
        }

        // 未終了の休憩が既にある場合は重複防止
        $openBreak = $attendance->attendanceBreaks()->whereNull('ended_at')->exists();
        if ($openBreak) {
            return response()->json(['message' => 'すでに休憩中です'], 409);
        }

        $photoPath = $this->photoStorage->storeFromBase64($request->input('photo'), 'break_start');

        $break = AttendanceBreak::create([
            'attendance_id'   => $attendance->id,
            'started_at'      => Carbon::now(),
            'start_photo_path' => $photoPath,
            'start_ip'        => $request->ip(),
        ]);

        return response()->json([
            'message'    => '休憩を開始しました',
            'attendance' => $attendance->fresh()->load('attendanceBreaks'),
            'break'      => $break,
        ]);
    }

    public function breakEnd(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'photo' => ['required', 'string'],
        ]);

        $user = User::findOrFail($request->input('user_id'));
        $today = Carbon::today()->toDateString();

        $attendance = Attendance::where('user_id', $user->id)
            ->where('work_date', $today)
            ->first();

        if (! $attendance) {
            return response()->json(['message' => '打刻レコードが見つかりません'], 409);
        }

        $openBreak = $attendance->attendanceBreaks()->whereNull('ended_at')->latest('started_at')->first();
        if (! $openBreak) {
            return response()->json(['message' => '休憩中の記録が見つかりません'], 409);
        }

        $photoPath = $this->photoStorage->storeFromBase64($request->input('photo'), 'break_end');

        $openBreak->update([
            'ended_at'       => Carbon::now(),
            'end_photo_path' => $photoPath,
            'end_ip'         => $request->ip(),
        ]);

        return response()->json([
            'message'    => '休憩から戻りました',
            'attendance' => $attendance->fresh()->load('attendanceBreaks'),
            'break'      => $openBreak->fresh(),
        ]);
    }
}
