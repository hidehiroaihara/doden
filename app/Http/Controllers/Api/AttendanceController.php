<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendChatworkNotification;
use App\Models\Attendance;
use App\Models\AttendanceBreak;
use App\Models\Setting;
use App\Models\User;
use App\Services\PhotoStorageService;
use App\Support\PunchBusinessDate;
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

        $attendance = Attendance::findActiveForPunch((int) $request->input('user_id'));

        return response()->json([
            'attendance' => $attendance,
        ]);
    }

    public function clockIn(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'photo' => [$this->photoRule(), 'string'],
        ]);

        $user = User::findOrFail($request->input('user_id'));

        // 未退勤のシフトが1件でもあれば新規出勤は不可（先に退勤が必要）。
        // 退勤済みであれば、同一営業日・別店舗でも次のシフトを出勤できる。
        if (Attendance::findOpenForUser($user->id)) {
            return response()->json([
                'message' => '未退勤の打刻があります。先に退勤してください',
            ], 409);
        }

        $businessDate = $this->resolveWorkDateForClockIn($user->id);

        // 打刻した店舗を勤怠へスナップショット保存する。
        // 店舗別画面からの打刻(department_id)を優先し、所属外の店舗は拒否する。
        // 店舗指定が無い場合は主所属(users.department_id)へフォールバック。
        $departmentId = $this->resolvePunchDepartmentId($user, $request->input('department_id'));

        $photoPath = $this->storePhoto($request->input('photo'), 'clock_in');

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'department_id' => $departmentId,
            'work_date' => $businessDate,
            'clock_in_at' => Carbon::now(),
            'clock_in_photo_path' => $photoPath,
            'clock_in_ip' => $request->ip(),
        ]);

        if ($user->chatwork_room_id) {
            SendChatworkNotification::dispatch($user, '出勤', $attendance, $photoPath ?? '');
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
            'department_id' => ['nullable', 'exists:departments,id'],
            'photo' => [$this->photoRule(), 'string'],
        ]);

        $user = User::findOrFail($request->input('user_id'));

        $attendance = Attendance::findOpenForUser($user->id);

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

        $photoPath = $this->storePhoto($request->input('photo'), 'clock_out');

        // 退勤した店舗をスナップショット保存する（出勤店舗と別店舗で退勤した場合に両方残す）。
        // 店舗指定が無い場合は出勤店舗をそのまま退勤店舗として記録する。
        $clockOutDepartmentId = $this->resolvePunchDepartmentId($user, $request->input('department_id'))
            ?? $attendance->department_id;

        $attendance->update([
            'clock_out_at' => Carbon::now(),
            'clock_out_photo_path' => $photoPath,
            'clock_out_ip' => $request->ip(),
            'clock_out_department_id' => $clockOutDepartmentId,
        ]);

        if ($user->chatwork_room_id) {
            SendChatworkNotification::dispatch($user, '退勤', $attendance->fresh(), $photoPath ?? '');
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
            'photo' => [$this->photoRule(), 'string'],
        ]);

        $user = User::findOrFail($request->input('user_id'));

        $attendance = Attendance::findOpenForUser($user->id);

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

        $photoPath = $this->storePhoto($request->input('photo'), 'break_start');

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
            'photo' => [$this->photoRule(), 'string'],
        ]);

        $user = User::findOrFail($request->input('user_id'));

        $attendance = Attendance::findOpenForUser($user->id);

        if (! $attendance) {
            return response()->json(['message' => '打刻レコードが見つかりません'], 409);
        }

        $openBreak = $attendance->attendanceBreaks()->whereNull('ended_at')->latest('started_at')->first();
        if (! $openBreak) {
            return response()->json(['message' => '休憩中の記録が見つかりません'], 409);
        }

        $photoPath = $this->storePhoto($request->input('photo'), 'break_end');

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

    /**
     * 出勤打刻の work_date（営業日）を決定する。
     *
     * 通常は PunchBusinessDate::date()（境界時刻前は前日扱い）を使う。
     * ただし早朝（境界時刻前）で、前営業日のシフトがすべて退勤済みの場合は、
     * 前日6-10時勤務→翌2時の新規出勤が同一 work_date に潰れないよう暦日を用いる。
     * これにより「前の日に勤務済み → 早朝に別シフトで出勤」が可能になる。
     */
    private function resolveWorkDateForClockIn(int $userId): string
    {
        $businessDate = PunchBusinessDate::date();
        $calendarDate = Carbon::now()->toDateString();

        // 境界時刻前（営業日=前日）かつ、その前営業日に未退勤シフトが無い＝
        // 継続中の夜勤ではなく新規の早朝出勤とみなせる場合は、暦日を採用する。
        if ($calendarDate !== $businessDate
            && ! Attendance::hasOpenShiftOnBusinessDate($userId, $businessDate)) {
            return $calendarDate;
        }

        return $businessDate;
    }

    /**
     * 打刻レコードへ保存する店舗ID（打刻時スナップショット）を決定する。
     *
     * 店舗別画面から渡された department_id を優先するが、その従業員の所属店舗
     * （users.department_id または department_user）でなければ主所属へフォールバックする。
     */
    private function resolvePunchDepartmentId(User $user, mixed $departmentId): ?int
    {
        if ($departmentId === null || $departmentId === '') {
            return $user->department_id;
        }

        $departmentId = (int) $departmentId;

        $belongs = $departmentId === (int) $user->department_id
            || $user->departments()->where('departments.id', $departmentId)->exists();

        return $belongs ? $departmentId : $user->department_id;
    }

    /**
     * 打刻時に顔写真を使用する設定か。
     * OFF(punch_use_photo != '1') の場合は写真なしで打刻できる。
     */
    private function usePhoto(): bool
    {
        return Setting::getValue('punch_use_photo', '0') === '1';
    }

    /**
     * photo フィールドのバリデーションルール。
     * 顔写真ONなら必須、OFFなら任意。
     */
    private function photoRule(): string
    {
        return $this->usePhoto() ? 'required' : 'nullable';
    }

    /**
     * 写真Base64を保存しパスを返す。顔写真OFFまたは未送信時は null。
     */
    private function storePhoto(mixed $photo, string $type): ?string
    {
        if (! $this->usePhoto() || $photo === null || $photo === '') {
            return null;
        }

        return $this->photoStorage->storeFromBase64($photo, $type);
    }
}
