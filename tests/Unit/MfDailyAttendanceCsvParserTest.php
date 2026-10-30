<?php

namespace Tests\Unit;

use App\Services\Payroll\Migration\MfDailyAttendanceCsvParser;
use PHPUnit\Framework\TestCase;

class MfDailyAttendanceCsvParserTest extends TestCase
{
    public function test_parses_punch_row_with_raw_times(): void
    {
        $csv = <<<'CSV'
従業員番号,氏名,日付,出勤,退勤,休憩入り,休憩戻り,出勤（実打刻）,退勤（実打刻）,休憩入り（実打刻）,休憩戻り（実打刻）,勤怠区分,勤怠申請,勤務パターン,実質労働時間,総労働時間,所定,所定外,法定外,深夜所定,深夜所定外,深夜法定外,遅刻,早退,休憩,休暇みなし(所定),休暇みなし(所定外),休暇みなし(法定外),備考
1,和田 智希,2026/01/15,11:00,15:30,,,10:54,15:30,,,平日,,,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
CSV;
        $path = sys_get_temp_dir() . '/mf-attendance-test.csv';
        file_put_contents($path, $csv);

        $row = (new MfDailyAttendanceCsvParser())->parseFile($path)[0];

        $this->assertSame([], $row['errors']);
        $this->assertSame('1', $row['employee_no']);
        $this->assertSame('2026-01-15', $row['work_date']);
        $this->assertSame('2026-01-15 10:54:00', $row['clock_in_at']);
        $this->assertSame('2026-01-15 15:30:00', $row['clock_out_at']);
    }

}
