<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * 月締め期間の単一ソース。
 *
 *  締め日 D の場合、月キー Y-M の期間は (前月 D+1) 〜 (Y-M D)。
 *  D が NULL もしくは その月に存在しない日は月末で丸める。
 *  D=NULL は従来どおり Y-M の月初〜月末。
 */
class MonthPeriod
{
    /** 設定された締め日（1〜31）。未設定なら null。 */
    public static function closingDay(): ?int
    {
        $v = Setting::getValue('month_closing_day');
        return $v ? (int) $v : null;
    }

    /**
     * monthKey 'Y-m' から ['from' => 'Y-m-d', 'to' => 'Y-m-d'] を返す。
     */
    public static function resolve(string $monthKey): array
    {
        $D = self::closingDay();
        $endMonth = Carbon::parse($monthKey . '-01');

        if (!$D) {
            return [
                'from' => $endMonth->copy()->startOfMonth()->toDateString(),
                'to'   => $endMonth->copy()->endOfMonth()->toDateString(),
            ];
        }

        $end = $endMonth->copy()->day(min($D, $endMonth->daysInMonth));
        $startMonth = $endMonth->copy()->subMonth();
        $start = $startMonth->copy()->day(min($D, $startMonth->daysInMonth))->addDay();

        return [
            'from' => $start->toDateString(),
            'to'   => $end->toDateString(),
        ];
    }

    /**
     * 今日が属する monthKey 'Y-m' を返す。
     * 締め日が未設定 / 31（月末扱い）の場合は単純に今月。
     * 締め日 D が当月の D を超えていれば翌月キー。
     */
    public static function currentKey(?Carbon $today = null): string
    {
        $today = $today ?: Carbon::today();
        $D = self::closingDay();

        if (!$D || $today->day <= $D) {
            return $today->format('Y-m');
        }
        return $today->copy()->addMonth()->format('Y-m');
    }

    /**
     * monthKey を delta 月だけずらした monthKey を返す。
     */
    public static function shift(string $monthKey, int $delta): string
    {
        return Carbon::parse($monthKey . '-01')->addMonths($delta)->format('Y-m');
    }

    /**
     * "2026年6月（5/21〜6/20）" のようなラベル文字列を生成。
     */
    public static function label(string $monthKey): string
    {
        $base = Carbon::parse($monthKey . '-01');
        $period = self::resolve($monthKey);
        $from = Carbon::parse($period['from']);
        $to   = Carbon::parse($period['to']);

        return sprintf(
            '%d年%d月（%d/%d〜%d/%d）',
            $base->year,
            $base->month,
            $from->month, $from->day,
            $to->month, $to->day,
        );
    }
}
