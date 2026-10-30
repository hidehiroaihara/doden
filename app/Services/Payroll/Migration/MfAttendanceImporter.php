<?php

namespace App\Services\Payroll\Migration;

use App\Models\Attendance;
use App\Models\AttendanceBreak;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** MF日次勤怠CSVから打刻履歴を登録（移行用）。 */
class MfAttendanceImporter
{
    public const SOURCE_IP = 'mf-csv-migration';

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{
     *   to_import: array<int, array<string, mixed>>,
     *   errors: array<int, string>,
     *   warnings: array<int, string>,
     *   skipped: array<int, string>,
     *   stats: array{from:?string, to:?string}
     * }
     */
    public function prepare(array $rows, bool $skipUnknown = false): array
    {
        $resolver = new MfMigrationEmployeeResolver();
        $toImport = [];
        $errors = [];
        $warnings = [];
        $skipped = [];
        $dates = [];

        foreach ($rows as $row) {
            $where = sprintf('%d行目 %s %s %s', $row['line'], $row['employee_no'] ?: '-', $row['name'], $row['work_date'] ?? '-');

            foreach ($row['errors'] as $e) {
                if ($e === '出勤・退勤がありません') {
                    continue;
                }
                $errors[] = "{$where}: {$e}";
            }
            if ($row['errors'] !== [] && in_array('出勤・退勤がありません', $row['errors'], true)) {
                continue;
            }
            if ($row['errors'] !== []) {
                continue;
            }

            if ($row['employee_no'] === '' && str_contains($row['name'], '管理者')) {
                $skipped[] = "{$where}: 管理者行のためスキップ";

                continue;
            }

            [$employee, $message] = $resolver->resolve($row['employee_no'], $row['name']);
            if (! $employee) {
                if ($skipUnknown) {
                    $skipped[] = "{$where}: {$message}（--skip-unknown によりスキップ）";
                } else {
                    $errors[] = "{$where}: {$message}";
                }
                continue;
            }
            if ($message) {
                $warnings[] = "{$where}: {$message}";
            }

            $clockIn = $row['clock_in_at'];
            $clockOut = $row['clock_out_at'];
            $breakStart = $row['break_start_at'];
            $breakEnd = $row['break_end_at'];
            if ($clockIn && $clockOut && $clockOut < $clockIn) {
                $clockOut = Carbon::parse($clockOut)->addDay()->format('Y-m-d H:i:s');
            }
            if ($breakStart && $breakEnd && $breakEnd < $breakStart) {
                $breakEnd = Carbon::parse($breakEnd)->addDay()->format('Y-m-d H:i:s');
            }

            $dates[] = $row['work_date'];
            $toImport[] = [
                'user_id' => $employee->user_id,
                'department_id' => $employee->user?->department_id,
                'work_date' => $row['work_date'],
                'clock_in_at' => $clockIn,
                'clock_out_at' => $clockOut,
                'break_minutes' => $row['break_minutes'],
                'break_start_at' => $breakStart,
                'break_end_at' => $breakEnd,
                'source' => $where,
            ];
        }

        sort($dates);

        return [
            'to_import' => $toImport,
            'errors' => $errors,
            'warnings' => $warnings,
            'skipped' => $skipped,
            'stats' => [
                'from' => $dates[0] ?? null,
                'to' => $dates[array_key_last($dates)] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $prepared
     * @return array{created: int, replaced: int}
     */
    public function commit(array $prepared, bool $replaceExisting): array
    {
        if ($prepared['errors'] !== []) {
            throw new \RuntimeException('エラーがあるため登録できません。');
        }

        $counts = ['created' => 0, 'replaced' => 0];

        DB::transaction(function () use ($prepared, $replaceExisting, &$counts) {
            if ($replaceExisting && $prepared['stats']['from'] && $prepared['stats']['to']) {
                $userIds = collect($prepared['to_import'])->pluck('user_id')->unique()->all();
                $counts['replaced'] = Attendance::query()
                    ->whereIn('user_id', $userIds)
                    ->where('clock_in_ip', self::SOURCE_IP)
                    ->whereBetween('work_date', [$prepared['stats']['from'], $prepared['stats']['to']])
                    ->delete();
            }

            foreach ($prepared['to_import'] as $row) {
                $attendance = Attendance::create([
                    'user_id' => $row['user_id'],
                    'department_id' => $row['department_id'],
                    'work_date' => $row['work_date'],
                    'clock_in_at' => $row['clock_in_at'],
                    'clock_out_at' => $row['clock_out_at'],
                    'clock_in_ip' => self::SOURCE_IP,
                    'clock_out_ip' => self::SOURCE_IP,
                    'break_minutes' => $row['break_minutes'],
                ]);

                if ($row['break_start_at'] && $row['break_end_at']) {
                    AttendanceBreak::create([
                        'attendance_id' => $attendance->id,
                        'started_at' => $row['break_start_at'],
                        'ended_at' => $row['break_end_at'],
                    ]);
                }

                $counts['created']++;
            }
        });

        return $counts;
    }
}
