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
                ->with(['businessLocation:id,name', 'terminals' => fn ($q) => $q->orderBy('name')])
                ->orderBy('sort_order')->orderBy('id')
                ->get()
                ->map(fn (Department $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'business_location_id' => $d->business_location_id,
                    'business_location_name' => $d->businessLocation?->name,
                    'sort_order' => $d->sort_order,
                    'users_count' => $d->users_count,
                    // 店舗に紐付く打刻端末（認証URL生成に使用）
                    'terminals' => $d->terminals->map(fn (Terminal $t) => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'terminal_id' => $t->terminal_id,
                        'terminal_key' => $t->terminal_key,
                        'is_active' => $t->is_active,
                    ])->all(),
                ]),
            // 店舗の所属先として選べる事業所一覧
            'businessLocations' => BusinessLocation::orderBy('sort_order')->orderBy('id')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * 店舗に紐付く打刻端末（認証URL）を発行する。
     * terminal_id / terminal_key は自動生成し、複雑URL直打ち用の認証情報として使う。
     */
    public function storeTerminal(Request $request, Department $department)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        Terminal::create([
            'name'          => $validated['name'] ?? ($department->name.' 認証URL'),
            'terminal_id'   => Terminal::generateTerminalId($department->id),
            'terminal_key'  => Terminal::generateKey(),
            'department_id' => $department->id,
            'is_active'     => true,
            'description'   => $department->name.' の打刻用（店舗管理から発行）',
        ]);

        return back()->with('success', '認証URL（端末）を発行しました。');
    }

    /** 端末キーを再発行（漏洩時などにURLを無効化してやり直す用途）。 */
    public function reissueTerminalKey(Terminal $terminal)
    {
        $terminal->update(['terminal_key' => Terminal::generateKey()]);

        return back()->with('success', '認証URLを再発行しました。以前のURLは無効になります。');
    }

    /** 端末（認証URL）を削除。 */
    public function destroyTerminal(Terminal $terminal)
    {
        $terminal->delete();

        return back()->with('success', '認証URLを削除しました。');
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
