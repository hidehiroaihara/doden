<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('attendance:cleanup')->dailyAt('03:00');

Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=120')
    ->everyMinute()
    ->withoutOverlapping();

// 祝日の自動取込（内閣府CSV）。
// 2月: 春分・秋分確定後のCSV更新を反映（当年+翌年を再取込）。
Schedule::command('holidays:import --years=current,next')
    ->weeklyOn(1, '04:00')
    ->when(fn () => now()->month === 2);

// 1月: 新年度準備（翌年分を取込）。
Schedule::command('holidays:import --years=next')
    ->monthlyOn(5, '04:00')
    ->when(fn () => now()->month === 1);
