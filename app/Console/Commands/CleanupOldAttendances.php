<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Services\PhotoStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CleanupOldAttendances extends Command
{
    protected $signature = 'attendance:cleanup {--months=12 : Months to retain}';
    protected $description = '1年以上前の打刻データと画像を削除する';

    public function handle(PhotoStorageService $photoStorage): int
    {
        $months = (int) $this->option('months');
        $cutoff = Carbon::now()->subMonths($months)->startOfDay();

        $this->info("Deleting attendance records older than {$cutoff->toDateString()}...");

        $query = Attendance::where('work_date', '<', $cutoff);
        $total = $query->count();

        if ($total === 0) {
            $this->info('No old records found.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} records to delete.");

        $deleted = 0;
        $query->chunkById(100, function ($attendances) use ($photoStorage, &$deleted) {
            foreach ($attendances as $attendance) {
                if ($attendance->clock_in_photo_path) {
                    $photoStorage->delete($attendance->clock_in_photo_path);
                }
                if ($attendance->clock_out_photo_path) {
                    $photoStorage->delete($attendance->clock_out_photo_path);
                }
                $attendance->delete();
                $deleted++;
            }
        });

        $this->info("Deleted {$deleted} attendance records and their photos.");

        return self::SUCCESS;
    }
}
