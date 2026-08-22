<?php

namespace App\Support;

/**
 * 労働保険（労災・雇用）の業種別料率プリセット。
 *
 * 料率はすべて千分率（‰ = /1,000）で保持する。
 * 労災保険料率は事業主全額負担（従業員負担なし）。
 * 雇用保険料率は従業員負担・事業主負担の2本立て。
 *
 * ※改定時は本ファイルの値を更新するか、事業所ごとに手修正する運用。
 *   代表的な業種のみ収録し、該当がない場合は "other"（手入力）を選択する。
 */
class LaborInsuranceRates
{
    /**
     * 労災保険：業種コード => [ラベル, 事業主料率(/1,000)]。
     *
     * 参考: 令和6年度 労災保険率表の代表業種抜粋。
     *
     * @return array<string, array{label: string, employer: float}>
     */
    public static function accidentIndustries(): array
    {
        return [
            'other' => ['label' => 'その他（手入力）', 'employer' => 0.0],
            'wholesale_retail_food_lodging' => ['label' => '卸売業・小売業、飲食店又は宿泊業', 'employer' => 3.0],
            'finance_insurance_realestate' => ['label' => '金融業、保険業又は不動産業', 'employer' => 2.5],
            'communication_media_education' => ['label' => '通信業、放送業、新聞・出版業、教育・研究・調査事業', 'employer' => 2.5],
            'other_business_services' => ['label' => 'その他の各種事業', 'employer' => 3.0],
            'clean_maintenance' => ['label' => 'ビルメンテナンス業', 'employer' => 6.0],
            'transport' => ['label' => '貨物取扱事業・運送業', 'employer' => 8.5],
            'manufacturing_food' => ['label' => '食料品製造業', 'employer' => 5.5],
            'manufacturing_metal' => ['label' => '金属製品製造業又は金属加工業', 'employer' => 9.0],
            'manufacturing_machine' => ['label' => '電気機械器具製造業', 'employer' => 3.0],
            'construction_general' => ['label' => '建築事業（既設建築物設備工事業を除く）', 'employer' => 9.5],
            'construction_civil' => ['label' => '土木工事業', 'employer' => 9.0],
            'forestry' => ['label' => '林業', 'employer' => 52.0],
            'fishery' => ['label' => '漁業', 'employer' => 18.0],
            'mining' => ['label' => '鉱業（採石業等）', 'employer' => 26.0],
        ];
    }

    /**
     * 雇用保険：区分コード => [ラベル, 従業員料率(/1,000), 事業主料率(/1,000)]。
     *
     * 参考: 令和6年度 雇用保険料率。
     *
     * @return array<string, array{label: string, employee: float, employer: float}>
     */
    public static function employmentIndustries(): array
    {
        return [
            'general' => ['label' => '一般の事業', 'employee' => 6.0, 'employer' => 9.5],
            'agri_sake_forestry' => ['label' => '農林水産・清酒製造の事業', 'employee' => 7.0, 'employer' => 10.5],
            'construction' => ['label' => '建設の事業', 'employee' => 7.0, 'employer' => 11.5],
        ];
    }

    /** 労災業種のラベルマップ（フロント options 用）。@return array<string, string> */
    public static function accidentIndustryLabels(): array
    {
        return array_map(fn ($v) => $v['label'], static::accidentIndustries());
    }

    /** 雇用区分のラベルマップ（フロント options 用）。@return array<string, string> */
    public static function employmentIndustryLabels(): array
    {
        return array_map(fn ($v) => $v['label'], static::employmentIndustries());
    }

    /** 指定した労災業種の事業主料率(/1,000)。未定義は0。 */
    public static function accidentEmployerRate(?string $code): float
    {
        return static::accidentIndustries()[$code]['employer'] ?? 0.0;
    }

    /**
     * 指定した雇用区分の従業員/事業主料率(/1,000)。未定義は0。
     *
     * @return array{employee: float, employer: float}
     */
    public static function employmentRates(?string $code): array
    {
        $row = static::employmentIndustries()[$code] ?? null;

        return [
            'employee' => $row['employee'] ?? 0.0,
            'employer' => $row['employer'] ?? 0.0,
        ];
    }
}
