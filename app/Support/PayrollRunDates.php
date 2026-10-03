<?php

namespace App\Support;

use App\Models\ClosingDateGroup;
use App\Models\PayrollRun;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * 給与バッチの締め日・支給日と、明細・画面用の「○月分」表記。
 *
 * period_key は勤怠集計の締め月（例: 2026-09）。支給が翌月なら表示は 10月分（基本設定＞明細の表示月に従う）。
 */
final class PayrollRunDates
{
    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function resolve(PayrollRun $run): array
    {
        $closing = $run->closing_date ? $run->closing_date->copy()->startOfDay() : null;
        $payment = $run->payment_date ? $run->payment_date->copy()->startOfDay() : null;

        if (! $closing && preg_match('/^(\d{4})-(\d{2})$/', (string) $run->period_key, $m)) {
            $closing = Carbon::create((int) $m[1], (int) $m[2], 1)->endOfMonth()->startOfDay();
        }
        if (! $payment && $closing) {
            $payment = $closing->copy()->addMonth()->day(min(25, $closing->copy()->addMonth()->daysInMonth));
        }

        return [$closing, $payment];
    }

    /** 基本設定＞明細の「表示月」（支給月 / 締め月）。 */
    public static function displayMonth(PayrollRun $run): ?Carbon
    {
        [$closing, $payment] = self::resolve($run);
        $mode = Setting::getValue('payslip_display_month', 'payment');

        return ($mode === 'closing' ? $closing : $payment) ?? $payment ?? $closing;
    }

    /** 画面用「2026年10月分」など。 */
    public static function displayMonthLabel(PayrollRun $run): string
    {
        $date = self::displayMonth($run);
        if (! $date) {
            return (string) $run->period_key;
        }

        return sprintf('%d年%02d月分', $date->year, $date->month);
    }

    /** 勤怠・給与集計の締め月（period_key 由来）。例: 2026年9月締め分 */
    public static function closingPeriodLabel(PayrollRun $run): ?string
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', (string) $run->period_key, $m)) {
            return null;
        }

        return sprintf('%d年%d月締め分', (int) $m[1], (int) $m[2]);
    }

    /** 支給日＋締日のみ（締め月ラベルは別表示）。 */
    public static function paymentSelectorLabel(PayrollRun $run): string
    {
        [$closing, $payment] = self::resolve($run);

        if ($payment) {
            $label = sprintf(
                '%d年%02d月%02d日支給',
                $payment->year,
                $payment->month,
                $payment->day,
            );
        } else {
            $label = self::displayMonthLabel($run).' 支給';
        }

        if ($closing) {
            $label .= sprintf(
                '（締日 %d年%02d月%02d日）',
                $closing->year,
                $closing->month,
                $closing->day,
            );
        }

        return $label;
    }

    /** 給与計算画面の期間セレクタ（1行・一覧用）。 */
    public static function selectorLabel(PayrollRun $run): string
    {
        $closingPeriod = self::closingPeriodLabel($run);
        $paymentPart = self::paymentSelectorLabel($run);

        return $closingPeriod ? $closingPeriod.'　'.$paymentPart : $paymentPart;
    }

    /**
     * period_key と締め日グループから締め日・支給日・公開日を算出（年度設定の給与月度と同じ式）。
     *
     * @return array{closing_date: string, payment_date: string, publish_date: string}|null
     */
    public static function datesForPeriodKey(string $periodKey, ?ClosingDateGroup $group = null): ?array
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m)) {
            return null;
        }

        $group ??= ClosingDateGroup::query()->orderBy('sort_order')->orderBy('id')->first();
        if (! $group) {
            return null;
        }

        $base = Carbon::create((int) $m[1], (int) $m[2], 1);
        $closing = self::dayOfMonth($base, (int) $group->closing_day);
        $payBase = $base->copy()->addMonths((int) $group->payment_month_offset);
        $payment = self::dayOfMonth($payBase, (int) $group->payment_day);
        $publish = $payment->copy()->subDay();

        return [
            'closing_date' => $closing->toDateString(),
            'payment_date' => $payment->toDateString(),
            'publish_date' => $publish->toDateString(),
        ];
    }

    private static function dayOfMonth(Carbon $base, int $day): Carbon
    {
        $last = (int) $base->daysInMonth;

        return $base->copy()->day(min(max($day, 1), $last));
    }
}
