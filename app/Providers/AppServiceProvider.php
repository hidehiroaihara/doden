<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $this->applyFakeNow();
    }

    /**
     * ローカル検証用: APP_FAKE_NOW が設定されていればアプリ全体の現在時刻を固定する。
     * APP_DEBUG=true のときのみ有効。本番では無視される。
     */
    private function applyFakeNow(): void
    {
        if (! config('app.debug')) {
            return;
        }

        $raw = env('APP_FAKE_NOW');
        if (! is_string($raw) || trim($raw) === '') {
            return;
        }

        $raw = trim($raw, " \t\n\r\0\x0B\"'");
        Carbon::setTestNow(Carbon::parse($raw));
    }
}
