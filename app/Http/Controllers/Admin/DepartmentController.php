<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\Department;
use App\Models\Terminal;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * 店舗（部門）マスタ管理。
 * ユーザーの所属先・勤怠の集計区分として利用される（users.department_id）。
 */
class DepartmentController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Departments/Index', [
            'departments' => Department::withCount('users')
                ->with('businessLocation:id,name')
                ->orderBy('sort_order')->orderBy('id')
                ->get()
                ->map(fn (Department $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'business_location_id' => $d->business_location_id,
                    'business_location_name' => $d->businessLocation?->name,
                    'sort_order' => $d->sort_order,
                    'users_count' => $d->users_count,
                ]),
            // 店舗の所属先として選べる事業所一覧
            'businessLocations' => BusinessLocation::orderBy('sort_order')->orderBy('id')
                ->get(['id', 'name']),
            // 打刻URLに認証情報を付与してワンクリックで開けるようにする（端末制限対策）
            'terminals' => Terminal::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'terminal_id', 'terminal_key']),
        ]);
    }

    public function store(Request $request)
    {
        Department::create($this->validated($request));

        return back()->with('success', '店舗を追加しました。');
    }

    public function update(Request $request, Department $department)
    {
        $department->update($this->validated($request));

        return back()->with('success', '店舗を更新しました。');
    }

    public function destroy(Department $department)
    {
        $department->delete();

        return back()->with('success', '店舗を削除しました。所属していたユーザーの店舗は未設定になります。');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_location_id' => ['nullable', 'integer', 'exists:business_locations,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['business_location_id'] = $data['business_location_id'] ?? null;

        return $data;
    }
}
