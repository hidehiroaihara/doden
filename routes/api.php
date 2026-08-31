<?php

use App\Http\Controllers\Api\AttendanceController;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Route;

// 打刻APIも打刻画面と同じ判定（IP一致 OR 端末Cookie/キー）で許可する。
// api ルートグループは既定で EncryptCookies を含まないため、端末認証Cookie
// (punch_terminal) を復号できるよう明示的に前置する（無いと web で発行した
// 暗号化Cookieを照合できず 403 になる）。
Route::middleware([EncryptCookies::class, 'punch.access'])->group(function () {
    Route::get('attendance/today', [AttendanceController::class, 'today']);
    Route::post('attendance/clock-in', [AttendanceController::class, 'clockIn']);
    Route::post('attendance/clock-out', [AttendanceController::class, 'clockOut']);
    Route::post('attendance/break-start', [AttendanceController::class, 'breakStart']);
    Route::post('attendance/break-end', [AttendanceController::class, 'breakEnd']);
});
