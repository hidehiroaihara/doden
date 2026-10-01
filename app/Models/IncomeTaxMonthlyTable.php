<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 給与所得の源泉徴収税額表（月額表）の年分ヘッダ。
 *
 * 毎年 国税庁が公表する表を1年分=1レコードとして登録し、行は income_tax_monthly_rows に持つ。
 * 過去分は編集せず、新しい年分を追加する運用（確定済み給与の再計算でも当時の表が引ける）。
 */
class IncomeTaxMonthlyTable extends Model
{
    /** 甲欄で表に持つ扶養親族等の数の上限（これを超える分は dependent_over7_deduction で控除）。 */
    public const MAX_TABLE_DEPENDENTS = 7;

    protected $fillable = [
        'name',
        'target_year',
        'effective_from',
        'effective_to',
        'dependent_over7_deduction',
        'source_note',
    ];

    protected function casts(): array
    {
        return [
            'target_year' => 'integer',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'dependent_over7_deduction' => 'integer',
        ];
    }

    /** 階級の小さい順。taxFor() が最初に該当した階級を採用するため順序を固定する。 */
    public function rows(): HasMany
    {
        return $this->hasMany(IncomeTaxMonthlyRow::class)->orderBy('min_amount');
    }

    /** 指定日に適用される月額表を返す（無ければ null）。 */
    public static function forDate(string $date): ?self
    {
        return static::where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * 社会保険料等控除後の給与等の金額から税額を引く。該当行が無ければ null。
     *
     * 甲欄は扶養親族等の数の列を引き、7人を超える分は1人あたり控除額を差し引く。
     * 最上位の階級は税額ではなく率＋控除額で持つことがあるため、その場合は計算する。
     */
    public function taxFor(int $afterSocialInsurance, int $dependents = 0, string $taxTable = 'kou'): ?int
    {
        $amount = max(0, $afterSocialInsurance);
        $column = $taxTable === 'otsu' ? 0 : min(max(0, $dependents), self::MAX_TABLE_DEPENDENTS);

        $row = $this->rows
            ->where('tax_table', $taxTable)
            ->where('dependents', $column)
            ->first(fn (IncomeTaxMonthlyRow $r) => $r->covers($amount));

        if (! $row) {
            return null;
        }

        $tax = $row->taxFor($amount);

        // 扶養親族等が表の上限（7人）を超える場合は、超過1人につき所定額を控除する。
        $over = $taxTable === 'otsu' ? 0 : max(0, $dependents - self::MAX_TABLE_DEPENDENTS);
        $tax -= $over * (int) $this->dependent_over7_deduction;

        return max(0, $tax);
    }
}
