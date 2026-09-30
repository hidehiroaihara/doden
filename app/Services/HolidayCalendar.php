<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * 日付ごとの休日区分（法定休日／所定休日／平日）と祝日名を解決する。
 *
 * 判定の優先順位は AttendanceSummaryService::dayType() と同じ:
 *   1) 年度設定の独自休日（内閣府の祝日・会社独自の休日）→ 年度の「祝日」区分
 *   2) 年度設定の休日設定（曜日→区分）
 *   3) 基本設定＞勤怠の曜日指定（法定休日=日曜 / 所定休日=土曜）
 *
 * 打刻一覧・月別打刻表で「カレンダー上は空欄でも実は所定休日」を判別できるようにするため、
 * 画面表示と給与計算で同じ判定結果になることを保証する目的で切り出している。
 */
class HolidayCalendar
{
    /** holidayTypeMap における「祝日」のキー（0-6 は曜日）。 */
    public const HOLIDAY_DOW = 7;

    /** 独自休日の区分が年度設定にない場合の既定。 */
    private const DEFAULT_HOLIDAY_TYPE = 'prescribed';

    /** @var array<int, array<string, mixed>> 暦年ごとの解決済み設定 */
    private array $yearCache = [];

    /** @var array<string, string>|null */
    private ?array $fallbackDows = null;

    /**
     * 期間内の各日について休日情報を返す。
     *
     * @return array<string, array{type: string, label: string|null}> Y-m-d => 休日情報
     */
    public function forRange(string $from, string $to): array
    {
        $result = [];
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $result[$date] = $this->forDate($date);
            $cursor->addDay();
        }

        return $result;
    }

    /**
     * 指定した日付群について休日情報を返す（一覧のページング表示など期間が連続しない場合）。
     *
     * @param  iterable<string>  $dates
     * @return array<string, array{type: string, label: string|null}>
     */
    public function forDates(iterable $dates): array
    {
        $result = [];
        foreach ($dates as $date) {
            if ($date !== null && $date !== '' && ! isset($result[$date])) {
                $result[$date] = $this->forDate($date);
            }
        }

        return $result;
    }

    /** @return array{type: string, label: string|null} */
    public function forDate(string $date): array
    {
        $settings = $this->settingsForYear((int) substr($date, 0, 4));
        $dow = (int) Carbon::parse($date)->dayOfWeek;

        if (isset($settings['customHolidays'][$date])) {
            return [
                'type' => $settings['holidayTypeMap'][self::HOLIDAY_DOW] ?? self::DEFAULT_HOLIDAY_TYPE,
                'label' => $settings['customHolidays'][$date] ?: null,
            ];
        }

        if ($settings['holidayTypeMap'] !== []) {
            $type = $settings['holidayTypeMap'][$dow] ?? 'weekday';

            return [
                'type' => in_array($type, ['legal', 'prescribed', 'weekday'], true) ? $type : 'weekday',
                'label' => null,
            ];
        }

        return ['type' => $this->fallbackType($dow), 'label' => null];
    }

    /** @return array<string, mixed> */
    private function settingsForYear(int $year): array
    {
        if (isset($this->yearCache[$year])) {
            return $this->yearCache[$year];
        }

        $fiscalYear = FiscalYear::forDate(sprintf('%04d-01-01', $year));

        return $this->yearCache[$year] = [
            'holidayTypeMap' => $fiscalYear ? $fiscalYear->holidayTypeMap() : [],
            'customHolidays' => $fiscalYear
                ? $fiscalYear->customHolidays
                    ->mapWithKeys(fn ($c) => [
                        ($c->date instanceof \DateTimeInterface ? $c->date->format('Y-m-d') : (string) $c->date) => (string) $c->label,
                    ])
                    ->all()
                : [],
        ];
    }

    private function fallbackType(int $dow): string
    {
        $this->fallbackDows ??= $this->loadFallbackDows();

        return $this->fallbackDows[$dow] ?? 'weekday';
    }

    /** @return array<int, string> 曜日番号(0-6) => 区分 */
    private function loadFallbackDows(): array
    {
        $names = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $parse = fn (?string $value) => array_filter(array_map(
            fn ($d) => strtolower(trim($d)),
            explode(',', (string) $value),
        ));

        $map = [];
        foreach ($parse(Setting::getValue('prescribed_holiday_dows', 'saturday')) as $name) {
            $index = array_search($name, $names, true);
            if ($index !== false) {
                $map[$index] = 'prescribed';
            }
        }
        // 法定休日を後に適用し、両方に指定された曜日は法定休日として扱う。
        foreach ($parse(Setting::getValue('legal_holiday_dows', 'sunday')) as $name) {
            $index = array_search($name, $names, true);
            if ($index !== false) {
                $map[$index] = 'legal';
            }
        }

        return $map;
    }
}
