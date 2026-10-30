<?php

namespace App\Services\Payroll\Migration;

use Illuminate\Support\Carbon;

/**
 * MFクラウド給与「日次勤怠レポート」CSV（1行＝1日・1シフト）の解析。
 */
class MfDailyAttendanceCsvParser
{
    private const HEADER = [
        'employee_no', 'name', 'date', 'clock_in', 'clock_out', 'break_start', 'break_end',
        'raw_clock_in', 'raw_clock_out', 'raw_break_start', 'raw_break_end', 'day_type',
    ];

    /** @return array<int, array<string, mixed>> */
    public function parseFile(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \InvalidArgumentException("CSVを開けません: {$path}");
        }

        $rows = [];
        $lineNo = 0;
        while (($data = fgetcsv($handle)) !== false) {
            $lineNo++;
            if ($lineNo === 1) {
                continue;
            }
            if ($this->isEmptyRow($data)) {
                continue;
            }
            $rows[] = $this->parseRow($data, $lineNo);
        }
        fclose($handle);

        return $rows;
    }

    /** @param array<int, string|null> $data */
    private function parseRow(array $data, int $lineNo): array
    {
        $get = fn (int $i) => trim((string) ($data[$i] ?? ''));

        $date = $this->parseDate($get(2));
        $clockIn = $this->pickTime($get(7), $get(3));
        $clockOut = $this->pickTime($get(8), $get(4));
        $breakStart = $this->pickTime($get(9), $get(5));
        $breakEnd = $this->pickTime($get(10), $get(6));
        $breakHours = $get(24);

        $errors = [];
        if ($date === null) {
            $errors[] = '日付が読み取れません';
        }
        if ($clockIn === null && $clockOut === null) {
            $errors[] = '出勤・退勤がありません';
        }

        return [
            'line' => $lineNo,
            'employee_no' => $get(0),
            'name' => $get(1),
            'work_date' => $date,
            'clock_in_at' => $date && $clockIn ? $this->combine($date, $clockIn) : null,
            'clock_out_at' => $date && $clockOut ? $this->combine($date, $clockOut) : null,
            'break_start_at' => $date && $breakStart ? $this->combine($date, $breakStart) : null,
            'break_end_at' => $date && $breakEnd ? $this->combine($date, $breakEnd) : null,
            'break_minutes' => $this->breakMinutes($breakHours, $breakStart, $breakEnd),
            'day_type' => $get(11),
            'errors' => $errors,
        ];
    }

    /** @param array<int, string|null> $data */
    private function isEmptyRow(array $data): bool
    {
        return trim(implode('', $data)) === '';
    }

    private function parseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        return null;
    }

    private function pickTime(string $raw, string $rounded): ?string
    {
        $t = $raw !== '' ? $raw : $rounded;

        return preg_match('/^\d{1,2}:\d{2}$/', $t) ? $t : null;
    }

    private function combine(string $date, string $time): string
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return Carbon::parse($date)->setTime($h, $m, 0)->format('Y-m-d H:i:s');
    }

    private function breakMinutes(string $hours, ?string $breakStart, ?string $breakEnd): ?int
    {
        if ($breakStart && $breakEnd) {
            [$sh, $sm] = array_map('intval', explode(':', $breakStart));
            [$eh, $em] = array_map('intval', explode(':', $breakEnd));
            $start = $sh * 60 + $sm;
            $end = $eh * 60 + $em;

            return max(0, $end - $start);
        }
        if ($hours !== '' && is_numeric($hours)) {
            return (int) round((float) $hours * 60);
        }

        return null;
    }
}
