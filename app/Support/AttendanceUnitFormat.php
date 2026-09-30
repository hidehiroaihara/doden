<?php

namespace App\Support;

/**
 * 勤怠値の表示整形。勤怠項目マスタの「単位」(unit_format) に従って
 * 小数桁と単位（時間／日／回）を決める。
 *
 * unit_format の対応:
 *   hour        0時間
 *   hour_1      0.0時間
 *   hour_decimal 0.00時間(10進)
 *   hour_min60  000時間00分(60進)
 *   day         0.0日
 *   day_decimal 0.00日
 *   count       0回
 */
class AttendanceUnitFormat
{
    /** 単位ごとの [小数桁, 単位ラベル]。hour_min60 は専用整形のため含めない。 */
    private const DECIMALS = [
        'hour' => [0, '時間'],
        'hour_1' => [1, '時間'],
        'hour_decimal' => [2, '時間'],
        'day' => [1, '日'],
        'day_decimal' => [2, '日'],
        'count' => [0, '回'],
    ];

    /**
     * 数値部と単位部に分けて返す。値が無い場合は number が null。
     *
     * @return array{number: string|null, unit: string}
     */
    public static function parts(?int $minutes, ?float $quantity, ?string $unitFormat): array
    {
        $format = $unitFormat ?: ($minutes !== null ? 'hour_decimal' : 'day');
        $isTimeBased = str_starts_with($format, 'hour');

        $value = $isTimeBased
            ? ($minutes !== null ? $minutes / 60 : $quantity)
            : ($quantity ?? ($minutes !== null ? $minutes / 60 : null));

        if ($value === null) {
            return ['number' => null, 'unit' => ''];
        }

        if ($format === 'hour_min60') {
            $total = (int) round($minutes !== null ? $minutes : $value * 60);

            return ['number' => intdiv($total, 60) . '時間' . sprintf('%02d', $total % 60) . '分', 'unit' => ''];
        }

        [$decimals, $unit] = self::DECIMALS[$format] ?? [2, '時間'];

        return ['number' => number_format($value, $decimals), 'unit' => $unit];
    }

    /** 単位付きの表示文字列（例: 136.00 時間 / 17.0 日）。値が無い場合は '—'。 */
    public static function format(?int $minutes, ?float $quantity, ?string $unitFormat): string
    {
        $parts = self::parts($minutes, $quantity, $unitFormat);
        if ($parts['number'] === null) {
            return '—';
        }

        return $parts['unit'] === '' ? $parts['number'] : $parts['number'] . ' ' . $parts['unit'];
    }
}
