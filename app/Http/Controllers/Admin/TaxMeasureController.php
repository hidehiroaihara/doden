<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TaxMeasure;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * 税制措置マスタ管理（定額減税など時限的な税制対応の適用期間・金額）。
 * 給与計算エンジンはここで有効化された制度を対象期間のバッチにのみ自動適用する。
 *
 * 参照: 資料/設計書 28_定額減税
 */
class TaxMeasureController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Payroll/Settings/TaxMeasures', [
            'measures' => TaxMeasure::orderByDesc('target_year')->orderBy('start_period')->get()
                ->map(fn (TaxMeasure $m) => [
                    'id' => $m->id,
                    'type' => $m->type,
                    'type_label' => $m->typeLabel(),
                    'name' => $m->name,
                    'target_year' => $m->target_year,
                    'start_period' => $m->start_period,
                    'end_period' => $m->end_period,
                    'per_person_amount' => $m->per_person_amount,
                    'is_active' => $m->is_active,
                    'note' => $m->note,
                ]),
            'options' => [
                'types' => collect(TaxMeasure::TYPE_LABELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        TaxMeasure::create($this->validated($request));

        return back()->with('success', '税制措置を追加しました。');
    }

    public function update(Request $request, TaxMeasure $taxMeasure)
    {
        $taxMeasure->update($this->validated($request));

        return back()->with('success', '税制措置を更新しました。');
    }

    public function destroy(TaxMeasure $taxMeasure)
    {
        $taxMeasure->delete();

        return back()->with('success', '税制措置を削除しました。');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:' . implode(',', array_keys(TaxMeasure::TYPE_LABELS))],
            'name' => ['required', 'string', 'max:120'],
            'target_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'start_period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'end_period' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'per_person_amount' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['is_active'] = $data['is_active'] ?? false;

        return $data;
    }
}
