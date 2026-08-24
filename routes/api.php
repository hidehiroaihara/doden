<?php

use App\Http\Controllers\Api\AttendanceController;
use Illuminate\Support\Facades\Route;

// 打刻APIも打刻画面と同じ判定（IP一致 OR 端末Cookie/キー）で許可する
Route::middleware(['punch.access'])->group(function () {
    Route::get('attendance/today', [AttendanceController::class, 'today']);
    Route::post('attendance/clock-in', [AttendanceController::class, 'clockIn']);
    Route::post('attendance/clock-out', [AttendanceController::class, 'clockOut']);
    Route::post('attendance/break-start', [AttendanceController::class, 'breakStart']);
    Route::post('attendance/break-end', [AttendanceController::class, 'breakEnd']);
});
