<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * 労働保険（労災・雇用）の業種別料率プリセット（MFクラウド給与準拠）。
 *
 * 料率はすべて千分率（‰ = /1,000）で保持する。
 * 労災保険料率は事業主全額負担（従業員負担なし）。
 * 雇用保険料率は従業員負担・事業主負担の2本立てで、労働保険年度（4月〜翌3月）ごとに改定される。
 *
 * 雇用保険料率は年度別に保持し、対象日（＝料率セットの適用開始日）から労働保険年度を判定して
 * 当時の料率を返す。未収録の将来年度は「収録済みの最新年度」へフォールバックする。
 *
 * ※改定時は EMPLOYMENT_RATES_BY_YEAR に年度を追記する運用。
 */
class LaborInsuranceRates
{
    /**
     * 雇用保険：事業区分コード => ラベル。
     *
     * @var array<string, string>
     */
    private const EMPLOYMENT_LABELS = [
        'general' => '一般の事業',
        'agri_sake_forestry' => '農林水産・清酒製造の事業',
        'construction' => '建設の事業',
    ];

    /**
     * 雇用保険：労働保険年度(開始西暦) => 区分コード => [従業員負担(/1,000), 事業主負担(/1,000)]。
     *
     * 出典: 厚生労働省「雇用保険料率のご案内」（令和6〜8年度）。
     *
     * @var array<int, array<string, array{0: float, 1: float}>>
     */
    private const EMPLOYMENT_RATES_BY_YEAR = [
        // 令和6年度（2024/4〜2025/3）
        2024 => [
            'general' => [6.0, 9.5],
            'agri_sake_forestry' => [7.0, 10.5],
            'construction' => [7.0, 11.5],
        ],
        // 令和7年度（2025/4〜2026/3）
        2025 => [
            'general' => [5.5, 9.0],
            'agri_sake_forestry' => [6.5, 10.0],
            'construction' => [6.5, 11.0],
        ],
        // 令和8年度（2026/4〜2027/3）
        2026 => [
            'general' => [5.0, 8.5],
            'agri_sake_forestry' => [6.0, 9.5],
            'construction' => [6.0, 10.5],
        ],
    ];

    /**
     * 労災保険：業種コード => [ラベル, 事業主料率(/1,000)]。
     *
     * 参考: 令和6年度（2024/4改定、令和8年度まで据置）労災保険率表の代表業種。
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
     * 対象日の雇用保険：区分コード => [ラベル, 従業員料率(/1,000), 事業主料率(/1,000)]。
     *
     * @return array<string, array{label: string, employee: float, employer: float}>
     */
    public static function employmentIndustries(?string $date = null): array
    {
        $table = static::employmentTableForYear(static::laborYear($date));
        $result = [];
        foreach (static::EMPLOYMENT_LABELS as $code => $label) {
            $row = $table[$code] ?? [0.0, 0.0];
            $result[$code] = ['label' => $label, 'employee' => $row[0], 'employer' => $row[1]];
        }

        return $result;
    }

    /** 労災業種のラベルマップ（フロント options 用）。@return array<string, string> */
    public static function accidentIndustryLabels(): array
    {
        return array_map(fn ($v) => $v['label'], static::accidentIndustries());
    }

    /** 雇用区分のラベルマップ（フロント options 用）。@return array<string, string> */
    public static function employmentIndustryLabels(): array
    {
        return static::EMPLOYMENT_LABELS;
    }

    /** 指定した労災業種の事業主料率(/1,000)。未定義は0。 */
    public static function accidentEmployerRate(?string $code): float
    {
        return static::accidentIndustries()[$code]['employer'] ?? 0.0;
    }

    /**
     * 指定した雇用区分の従業員/事業主料率(/1,000)。対象日から労働保険年度を判定する。未定義は0。
     *
     * @return array{employee: float, employer: float}
     */
    public static function employmentRates(?string $code, ?string $date = null): array
    {
        $table = static::employmentTableForYear(static::laborYear($date));
        $row = $table[$code] ?? null;

        return [
            'employee' => $row[0] ?? 0.0,
            'employer' => $row[1] ?? 0.0,
        ];
    }

    /**
     * 対象日が属する労働保険年度（4月始まり）の開始西暦を返す。未指定は本日。
     */
    public static function laborYear(?string $date = null): int
    {
        $d = $date ? Carbon::parse($date) : Carbon::now();

        return $d->month >= 4 ? $d->year : $d->year - 1;
    }

    /**
     * 指定年度の雇用保険料率表を返す。未収録なら「その年度以下で最も新しい年度」、
     * それも無ければ最古年度へフォールバックする。
     *
     * @return array<string, array{0: float, 1: float}>
     */
    private static function employmentTableForYear(int $year): array
    {
        $years = array_keys(static::EMPLOYMENT_RATES_BY_YEAR);
        sort($years);

        $chosen = $years[0];
        foreach ($years as $y) {
            if ($y <= $year) {
                $chosen = $y;
            }
        }

        return static::EMPLOYMENT_RATES_BY_YEAR[$chosen];
    }
}
