<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use App\Services\Payroll\Migration\MfAttendanceImporter;
use App\Services\Payroll\Migration\MfDailyAttendanceCsvParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MfAttendanceImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_attendance_with_source_marker(): void
    {
        $user = User::factory()->create(['name' => '和田 智希']);
        $user->employeePayroll()->create(['employee_no' => '1', 'pay_type' => 'hourly']);

        $csv = <<<'CSV'
従業員番号,氏名,日付,出勤,退勤,休憩入り,休憩戻り,出勤（実打刻）,退勤（実打刻）,休憩入り（実打刻）,休憩戻り（実打刻）,勤怠区分,勤怠申請,勤務パターン,実質労働時間,総労働時間,所定,所定外,法定外,深夜所定,深夜所定外,深夜法定外,遅刻,早退,休憩,休暇みなし(所定),休暇みなし(所定外),休暇みなし(法定外),備考
1,和田 智希,2026/01/15,11:00,15:30,,,10:54,15:30,,,平日,,,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
CSV;
        $path = sys_get_temp_dir() . '/mf-attendance-feature.csv';
        file_put_contents($path, $csv);

        $rows = (new MfDailyAttendanceCsvParser())->parseFile($path);
        $importer = new MfAttendanceImporter();
        $prepared = $importer->prepare($rows);
        $this->assertSame([], $prepared['errors']);
        $counts = $importer->commit($prepared, false);

        $this->assertSame(1, $counts['created']);
        $a = Attendance::first();
        $this->assertSame($user->id, $a->user_id);
        $this->assertSame('2026-01-15', $a->work_date->toDateString());
        $this->assertSame(MfAttendanceImporter::SOURCE_IP, $a->clock_in_ip);
    }

    public function test_night_shift_extends_clock_out_to_next_day(): void
    {
        $user = User::factory()->create(['name' => '新井 直也']);
        $user->employeePayroll()->create(['employee_no' => '2', 'pay_type' => 'hourly']);

        $csv = <<<'CSV'
従業員番号,氏名,日付,出勤,退勤,休憩入り,休憩戻り,出勤（実打刻）,退勤（実打刻）,休憩入り（実打刻）,休憩戻り（実打刻）,勤怠区分,勤怠申請,勤務パターン,実質労働時間,総労働時間,所定,所定外,法定外,深夜所定,深夜所定外,深夜法定外,遅刻,早退,休憩,休暇みなし(所定),休暇みなし(所定外),休暇みなし(法定外),備考
2,新井 直也,2026/07/24,22:00,6:00,,,22:05,6:10,,,平日,,,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
CSV;
        $path = sys_get_temp_dir() . '/mf-attendance-night-feature.csv';
        file_put_contents($path, $csv);

        $rows = (new MfDailyAttendanceCsvParser())->parseFile($path);
        $prepared = (new MfAttendanceImporter())->prepare($rows);
        $this->assertSame([], $prepared['errors']);
        $this->assertSame('2026-07-25 06:10:00', $prepared['to_import'][0]['clock_out_at']);
    }
}
