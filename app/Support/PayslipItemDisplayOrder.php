<?php

namespace App\Support;

use App\Models\AttendanceItemMaster;
use App\Models\DeductionItemMaster;
use App\Models\PayItemMaster;
use App\Models\Payslip;
use App\Models\PayslipItem;
use Illuminate\Support\Collection;

/**
 * 明細行の表示順を基本設定の sort_order に揃える。
 *
 * 支給は calc_method ごとの算出パス（employee → custom 等）で行が並ぶため、
 * 保存済み sort_order だけではマスタ順と一致しないことがある。
 */
class PayslipItemDisplayOrder
{
    /**
     * @param  Collection<int, PayslipItem>  $items
     * @return Collection<int, PayslipItem>
     */
    public static function sort(Payslip $payslip, Collection $items, string $type): Collection
    {
        $orderByCode = match ($type) {
            'earning' => self::earningOrderByCode($payslip),
            'deduction' => DeductionItemMaster::active()->orderBy('sort_order')->pluck('sort_order', 'code'),
            'attendance' => AttendanceItemMaster::active()->orderBy('sort_order')->pluck('sort_order', 'code'),
            default => collect(),
        };

        if ($orderByCode->isEmpty()) {
            return $items->sortBy('sort_order')->values();
        }

        return $items->sortBy(fn (PayslipItem $i) => [
            $orderByCode->get($i->code, 9000 + (int) $i->sort_order),
            (int) $i->sort_order,
            $i->id,
        ])->values();
    }

    /** @return Collection<string, int> code => sort_order */
    private static function earningOrderByCode(Payslip $payslip): Collection
    {
        $payType = $payslip->user?->employeePayroll?->pay_type;
        // with() の columns 指定で pay_type が落ちている場合に備え、未ロードなら取得する
        if (! $payType && $payslip->user_id) {
            $payType = $payslip->user?->employeePayroll()->value('pay_type');
        }
        if (! $payType) {
            return collect();
        }

        return PayItemMaster::active()
            ->forPayType($payType)
            ->orderBy('sort_order')
            ->pluck('sort_order', 'code');
    }
}
