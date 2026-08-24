<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Terminal;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TerminalController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Terminals/Index', [
            'terminals' => Terminal::orderBy('created_at', 'desc')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Terminals/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:100'],
            'terminal_id'   => ['required', 'string', 'max:100', 'unique:terminals,terminal_id', 'regex:/^[a-zA-Z0-9_\-]+$/'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'description'   => ['nullable', 'string', 'max:255'],
            'is_active'     => ['boolean'],
        ]);

        Terminal::create([
            'name'          => $validated['name'],
            'terminal_id'   => $validated['terminal_id'],
            'terminal_key'  => Terminal::generateKey(),
            'department_id' => $validated['department_id'] ?? null,
            'is_active'     => $validated['is_active'] ?? true,
            'description'   => $validated['description'] ?? null,
        ]);

        return redirect()->route('admin.terminals.index')->with('success', '端末を追加しました');
    }

    public function edit(Terminal $terminal)
    {
        return Inertia::render('Admin/Terminals/Edit', [
            'terminal' => $terminal,
        ]);
    }

    public function update(Request $request, Terminal $terminal)
    {
        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'description'   => ['nullable', 'string', 'max:255'],
            'is_active'     => ['boolean'],
        ]);

        $terminal->update($validated);

        return redirect()->route('admin.terminals.index')->with('success', '端末情報を更新しました');
    }

    public function reissueKey(Terminal $terminal)
    {
        $terminal->update(['terminal_key' => Terminal::generateKey()]);

        return redirect()->route('admin.terminals.edit', $terminal)->with('success', 'terminal_key を再発行しました');
    }

    public function destroy(Terminal $terminal)
    {
        $terminal->delete();

        return redirect()->route('admin.terminals.index')->with('success', '端末を削除しました');
    }
}
