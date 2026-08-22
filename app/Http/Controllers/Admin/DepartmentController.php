<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
                ->orderBy('sort_order')->orderBy('id')
                ->get()
                ->map(fn (Department $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'sort_order' => $d->sort_order,
                    'users_count' => $d->users_count,
                ]),
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
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }
}
