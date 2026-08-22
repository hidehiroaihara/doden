<?php

use App\Http\Controllers\Api\AttendanceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['restrict.ip'])->group(function () {
    Route::get('attendance/today', [AttendanceController::class, 'today']);
    Route::post('attendance/clock-in', [AttendanceController::class, 'clockIn']);
    Route::post('attendance/clock-out', [AttendanceController::class, 'clockOut']);
    Route::post('attendance/break-start', [AttendanceController::class, 'breakStart']);
    Route::post('attendance/break-end', [AttendanceController::class, 'breakEnd']);
});
