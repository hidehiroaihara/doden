<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\InsuranceRate;
use App\Support\LaborInsuranceRates;
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
        $set = $location->insuranceRateSets()->orderByDesc('effective_from')->first();
        if (! $set) {
            return;
        }

        if ($location->accident_industry_code) {
            InsuranceRate::updateOrCreate(
                ['insurance_rate_set_id' => $set->id, 'kind' => 'accident'],
                [
                    'employee_rate' => 0,
                    'employer_rate' => LaborInsuranceRates::accidentEmployerRate($location->accident_industry_code),
                ],
            );
        }

        if ($location->employment_industry_type) {
            $rates = LaborInsuranceRates::employmentRates($location->employment_industry_type);
            InsuranceRate::updateOrCreate(
                ['insurance_rate_set_id' => $set->id, 'kind' => 'employment'],
                [
                    'employee_rate' => $rates['employee'],
                    'employer_rate' => $rates['employer'],
                ],
            );
        }
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
            'labor_insurance_number' => ['nullable', 'string', 'max:50'],
            'office_number' => ['nullable', 'string', 'max:50'],
            'accident_industry_code' => ['nullable', 'string', 'max:64'],
            'employment_industry_type' => ['nullable', 'string', 'in:general,agri_sake_forestry,construction'],
            'labor_bureau' => ['nullable', 'string', 'max:255'],
            'accident_business_desc' => ['nullable', 'string', 'max:255'],
            'employment_office_number' => ['nullable', 'string', 'max:50'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'address' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
        $data['is_main'] = $data['is_main'] ?? false;
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }
}
