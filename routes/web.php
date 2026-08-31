<?php

use App\Http\Controllers\ProfileController;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// 打刻画面は「IP一致 OR 端末キー」のどちらかを満たせばアクセス可（PunchAccess）
Route::middleware(['punch.access'])->group(function () {
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

        // 店舗別画面: 所属店舗でメンバーを絞り込む。
        // 主所属(users.department_id) または 掛け持ち(department_user) のいずれかに一致すれば表示する。
        // これにより pivot 未登録の従業員も主所属店舗には従来どおり表示される。
        // 複数店舗を掛け持ちする従業員は、所属する各店舗の打刻画面に表示される。
        // 給与の所属事業所は全員本社でも、打刻・勤怠は店舗単位で管理する。
        if ($department) {
            $query->where(function ($q) use ($department) {
                $q->where('department_id', $department->id)
                    ->orWhereHas('departments', fn ($qq) => $qq->where('departments.id', $department->id));
            });
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

        // 当日打刻は「その店舗で実際に打刻された」レコードで絞り込む（打刻時の店舗スナップショット）。
        if ($department) {
            $attendanceQuery->where('department_id', $department->id);
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
            'usePhoto' => \App\Models\Setting::getValue('punch_use_photo', '0') === '1',
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
