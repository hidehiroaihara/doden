<?php

namespace App\Console\Commands;

use App\Models\FiscalYear;
use App\Services\JapaneseHolidayImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 内閣府CSVから日本の祝日を取り込む。
 *
 *   php artisan holidays:import              … 作成済みの全年度をそれぞれ取込
 *   php artisan holidays:import 2027         … 2027年分のみ取込
 *   php artisan holidays:import --years=current,next  … 当年+翌年を取込
 */
class ImportJapaneseHolidays extends Command
{
    protected $signature = 'holidays:import {year? : 取り込む年（例: 2027）} {--years= : current,next のカンマ区切り}';

    protected $description = '内閣府CSVから祝日を取り込み、年度の独自休日に登録する';

    public function handle(JapaneseHolidayImporter $importer): int
    {
        $years = $this->resolveYears();

        if (empty($years)) {
            $this->warn('取り込み対象の年度が見つかりません。先に年度を作成してください。');

            return self::SUCCESS;
        }

        $hadError = false;
        foreach ($years as $year) {
            if (! FiscalYear::where('year', $year)->exists()) {
                $this->warn("{$year}年度は未作成のためスキップしました。");

                continue;
            }

            try {
                $count = $importer->importYear($year);
                $this->info("{$year}年: 祝日を {$count} 件取り込みました。");
            } catch (\Throwable $e) {
                $hadError = true;
                $this->error("{$year}年: 取り込みに失敗しました - {$e->getMessage()}");
                Log::warning('holidays:import failed', ['year' => $year, 'error' => $e->getMessage()]);
            }
        }

        return $hadError ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 取り込む年を決定する。
     *
     * @return array<int, int>
     */
    private function resolveYears(): array
    {
        $arg = $this->argument('year');
        if ($arg !== null) {
            return [(int) $arg];
        }

        $option = $this->option('years');
        if ($option) {
            $now = (int) now()->year;
            $years = [];
            foreach (explode(',', $option) as $token) {
                $years[] = match (trim($token)) {
                    'current' => $now,
                    'next' => $now + 1,
                    'prev' => $now - 1,
                    default => (int) trim($token),
                };
            }

            return array_values(array_unique(array_filter($years)));
        }

        // 引数・オプションなし: 全年度に対して当年分
        return FiscalYear::orderBy('year')->pluck('year')->map(fn ($y) => (int) $y)->all();
    }
}
