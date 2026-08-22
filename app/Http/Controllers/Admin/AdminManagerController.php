<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Support\AdminPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminManagerController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Managers/Index', [
            'managers' => Admin::orderBy('role')->orderBy('name')->get([
                'id', 'name', 'email', 'role', 'permissions', 'created_at',
            ]),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Managers/Create', [
            'sections' => AdminPermission::SECTIONS,
            'levels'   => AdminPermission::LEVELS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'email'    => ['required', 'email', 'max:255', 'unique:admins,email'],
            'password' => ['required', 'string', 'min:8'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', Rule::in(AdminPermission::LEVELS)],
        ]);

        $permissions = [];
        foreach (AdminPermission::SECTIONS as $section) {
            $permissions[$section] = $validated['permissions'][$section] ?? 'none';
        }

        Admin::create([
            'name'        => $validated['name'],
            'email'       => $validated['email'],
            'password'    => $validated['password'],
            'role'        => 2,
            'permissions' => $permissions,
        ]);

        return redirect()->route('admin.managers.index')->with('success', '管理ユーザーを追加しました');
    }

    public function edit(Admin $manager)
    {
        // 自分自身（role=1）の編集は別途プロフィール画面を想定
        if ($manager->isSuperAdmin()) {
            abort(403);
        }

        return Inertia::render('Admin/Managers/Edit', [
            'manager'  => $manager->only(['id', 'name', 'email', 'role', 'permissions']),
            'sections' => AdminPermission::SECTIONS,
            'levels'   => AdminPermission::LEVELS,
        ]);
    }

    public function update(Request $request, Admin $manager)
    {
        if ($manager->isSuperAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'email'       => ['required', 'email', 'max:255', Rule::unique('admins', 'email')->ignore($manager->id)],
            'password'    => ['nullable', 'string', 'min:8'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', Rule::in(AdminPermission::LEVELS)],
        ]);

        $permissions = [];
        foreach (AdminPermission::SECTIONS as $section) {
            $permissions[$section] = $validated['permissions'][$section] ?? 'none';
        }

        $updateData = [
            'name'        => $validated['name'],
            'email'       => $validated['email'],
            'permissions' => $permissions,
        ];

        if (! empty($validated['password'])) {
            $updateData['password'] = $validated['password'];
        }

        $manager->update($updateData);

        return redirect()->route('admin.managers.index')->with('success', '管理ユーザーを更新しました');
    }

    public function destroy(Admin $manager)
    {
        // 自分自身は削除不可
        if ($manager->id === Auth::guard('admin')->id()) {
            return back()->with('error', '自分自身は削除できません');
        }

        if ($manager->isSuperAdmin()) {
            abort(403);
        }

        $manager->delete();

        return redirect()->route('admin.managers.index')->with('success', '管理ユーザーを削除しました');
    }
}
