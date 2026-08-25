<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 事業所マスタ管理。
 * 保険料率・労働保険番号の帰属先、給与計算バッチの絞り込み単位となる（設計書08）。
 * 一覧表示は給与設定画面（PayrollSettingController::index）に含める。
 */
class BusinessLocationController extends Controller
{
    public function store(Request $request)
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $location = BusinessLocation::create($data);
            $this->ensureSingleMain($location);
            $this->applyIndustryRates($location);
        });

        return back()->with('success', '事業所を追加しました。');
    }

    public function update(Request $request, BusinessLocation $location)
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($location, $data) {
            $location->update($data);
            $this->ensureSingleMain($location);
            $this->applyIndustryRates($location);
        });

        return back()->with('success', '事業所を更新しました。');
    }

    /**
     * 選択された業種に対応する労災・雇用の料率(/1,000)を、最新の料率セットへ反映する。
     * 料率セットが未登録の場合は何もしない（保険タブで別途セットを作成する）。
     */
    private function applyIndustryRates(BusinessLocation $location): void
    {
        $location->syncLaborInsuranceRates();
    }

    public function destroy(BusinessLocation $location)
    {
        if ($location->insuranceRateSets()->exists()) {
            return back()->with('error', 'この事業所には保険料率が登録されているため削除できません。');
        }
        if ($location->employeePayrolls()->exists()) {
            return back()->with('error', 'この事業所に所属する従業員がいるため削除できません。');
        }

        $location->delete();

        return back()->with('success', '事業所を削除しました。');
    }

    /** is_main を1件のみに保つ（指定された事業所を本社にした場合、他をfalse化）。 */
    private function ensureSingleMain(BusinessLocation $location): void
    {
        if ($location->is_main) {
            BusinessLocation::whereKeyNot($location->id)->where('is_main', true)->update(['is_main' => false]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'is_main' => ['boolean'],
            'health_insurance_type' => ['required', 'string', 'in:kyokai,kumiai,kokuho'],
            'prefecture' => ['nullable', 'string', 'max:20'],
            'health_union_name' => ['nullable', 'string', 'max:255'],
            'health_office_symbol' => ['nullable', 'string', 'max:100'],
            'pension_jurisdiction' => ['nullable', 'string', 'max:100'],
            'pension_office_number' => ['nullable', 'string', 'max:100'],
            'pension_office_symbol' => ['nullable', 'string', 'max:100'],
            'pension_fund_name' => ['nullable', 'string', 'max:255'],
            'pension_fund_number' => ['nullable', 'string', 'max:100'],
            'pension_fund_office_number' => ['nullable', 'string', 'max:100'],
            'labor_insurance_number' => ['nullable', 'string', 'max:50'],
            'labor_insurance_pref_code' => ['nullable', 'string', 'max:2'],
            'labor_insurance_jurisdiction_code' => ['nullable', 'string', 'max:1'],
            'labor_insurance_office_code' => ['nullable', 'string', 'max:2'],
            'labor_insurance_serial_number' => ['nullable', 'string', 'max:6'],
            'labor_insurance_branch_code' => ['nullable', 'string', 'max:3'],
            'office_number' => ['nullable', 'string', 'max:50'],
            'accident_industry_code' => ['nullable', 'string', 'max:64'],
            'accident_merit_enabled' => ['boolean'],
            'accident_merit_rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'employment_industry_type' => ['nullable', 'string', 'in:general,agri_sake_forestry,construction'],
            'labor_bureau' => ['nullable', 'string', 'max:255'],
            'employment_bureau' => ['nullable', 'string', 'max:255'],
            'accident_business_desc' => ['nullable', 'string', 'max:255'],
            'employment_office_number' => ['nullable', 'string', 'max:50'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'address' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
        $data['is_main'] = $data['is_main'] ?? false;
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['accident_merit_enabled'] = $data['accident_merit_enabled'] ?? false;
        if (! $data['accident_merit_enabled']) {
            $data['accident_merit_rate'] = null;
        }

        // 分割値が入力されていれば労働保険番号（連結値）を自動合成する。
        $composed = $this->composeLaborInsuranceNumber($data);
        if ($composed !== null) {
            $data['labor_insurance_number'] = $composed;
        }

        return $data;
    }

    /**
     * 府県/所掌/管轄/基幹/枝番 から労働保険番号の連結値を合成する。全て空なら null。
     *
     * @param  array<string, mixed>  $data
     */
    private function composeLaborInsuranceNumber(array $data): ?string
    {
        $parts = [
            $data['labor_insurance_pref_code'] ?? null,
            $data['labor_insurance_jurisdiction_code'] ?? null,
            $data['labor_insurance_office_code'] ?? null,
            $data['labor_insurance_serial_number'] ?? null,
            $data['labor_insurance_branch_code'] ?? null,
        ];

        if (! collect($parts)->filter(fn ($v) => filled($v))->count()) {
            return null;
        }

        return sprintf(
            '%s%s%s%s-%s',
            $data['labor_insurance_pref_code'] ?? '',
            $data['labor_insurance_jurisdiction_code'] ?? '',
            $data['labor_insurance_office_code'] ?? '',
            $data['labor_insurance_serial_number'] ?? '',
            $data['labor_insurance_branch_code'] ?? '',
        );
    }
}
