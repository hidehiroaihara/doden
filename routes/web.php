<?php

use App\Http\Controllers\ProfileController;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['restrict.ip', 'check.terminal'])->group(function () {
    /**
     * 打刻トップ（全店舗）と店舗別打刻画面で共通の描画処理。
     * $department が指定された場合はその店舗のユーザー・当日打刻のみを表示する。
     */
    $renderPunchScreen = function (?Department $department = null) {
        $useDepartment = config('features.department');

        // is_active=true かつ joined_at が今日以前（または null）のユーザーのみ表示
        $today = Carbon::today()->toDateString();
        $query = User::where('is_active', true)
            ->where(function ($q) use ($today) {
                $q->whereNull('joined_at')->orWhere('joined_at', '<=', $today);
            })
            ->orderBy('name');

        // 店舗別画面ではその店舗の所属ユーザーのみ
        if ($department) {
            $query->where('department_id', $department->id);
        }

        if ($useDepartment) {
            $query->with('department:id,name');
            $columns = ['id', 'name', 'department_id'];
            $departments = Department::orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        } else {
            $columns = ['id', 'name'];
            $departments = [];
        }

        $attendanceQuery = Attendance::with('user:id,name')
            ->where('work_date', $today)
            ->orderBy('clock_in_at');

        // 店舗別画面では当日打刻もその店舗の所属ユーザーに絞る
        if ($department) {
            $attendanceQuery->whereHas('user', fn ($q) => $q->where('department_id', $department->id));
        }

        return Inertia::render('Welcome', [
            'users' => $query->get($columns),
            'departments' => $departments,
            'currentDepartment' => $department?->only(['id', 'name']),
            'todayAttendances' => $attendanceQuery->get(['id', 'user_id', 'clock_in_at', 'clock_out_at']),
            'serverTime' => now()->toIso8601String(),
            'punchSuccess' => request()->boolean('punch_success'),
        ]);
    };

    // トップ（全店舗の打刻画面）は現在使用しない。店舗別 URL /store/{店舗} のみ運用。
    // 全店・本社などで全体表示が必要になったら、下記 abort(404) を
    //   fn () => $renderPunchScreen()
    // に戻すだけで復活できる（route('home') 参照箇所を壊さないよう name は維持）。
    Route::get('/', fn () => abort(404))->name('home');

    // 店舗ごとの専用打刻画面（店舗ごとに固定URLを端末へ割り当てる用途）
    Route::get('/store/{department}', fn (Department $department) => $renderPunchScreen($department))->name('home.store');

    Route::get('/punch/{user}', function (User $user) {
        $storeId = request('store');
        $store = $storeId ? Department::find($storeId) : null;

        return Inertia::render('Punch', [
            'user' => $user->only(['id', 'name']),
            'store' => $store?->only(['id', 'name']),
            'serverTime' => now()->toIso8601String(),
        ]);
    })->name('punch');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
