<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * 打刻レコードに誤って保存された手入力の休憩時間（break_minutes）をクリアする。
 *
 * NULL に戻すと、集計時は休憩ボタン記録 → 規定休憩時間帯との重なり計算の順で再判定される。
 */
class ClearAttendanceBreakMinutes extends Command
{
    protected $signature = 'attendance:clear-break-minutes
                            {--minutes=60 : クリア対象とする休憩分数}
                            {--include-users : users.break_minutes も同値なら NULL にする}
                            {--dry-run : 更新せず件数のみ表示}';

    protected $description = '打刻レコード（および任意で従業員）の手入力休憩時間をクリアする';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $dryRun = (bool) $this->option('dry-run');
        $includeUsers = (bool) $this->option('include-users');

        if ($minutes < 0) {
            $this->error('--minutes は 0 以上を指定してください。');

            return self::FAILURE;
        }

        $attendanceQuery = Attendance::query()->where('break_minutes', $minutes);
        $attendanceCount = (clone $attendanceQuery)->count();

        $userCount = 0;
        if ($includeUsers) {
            $userCount = User::query()->where('break_minutes', $minutes)->count();
        }

        if ($attendanceCount === 0 && $userCount === 0) {
            $this->info("break_minutes = {$minutes} のレコードは見つかりませんでした。");

            return self::SUCCESS;
        }

        $this->line("対象: 打刻 {$attendanceCount} 件".($includeUsers ? " / 従業員 {$userCount} 件" : ''));

        if ($dryRun) {
            $this->warn('--dry-run のため更新は行いません。');

            return self::SUCCESS;
        }

        if (! $dryRun && $this->input->isInteractive() && ! $this->confirm('上記の break_minutes を NULL にクリアします。よろしいですか？', true)) {
            $this->info('キャンセルしました。');

            return self::SUCCESS;
        }

        $clearedAttendances = Attendance::query()
            ->where('break_minutes', $minutes)
            ->update(['break_minutes' => null]);

        $clearedUsers = 0;
        if ($includeUsers) {
            $clearedUsers = User::query()
                ->where('break_minutes', $minutes)
                ->update(['break_minutes' => null]);
        }

        $this->info("クリア完了: 打刻 {$clearedAttendances} 件".($includeUsers ? " / 従業員 {$clearedUsers} 件" : ''));

        return self::SUCCESS;
    }
}
