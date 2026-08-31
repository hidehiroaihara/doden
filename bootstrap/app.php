<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append([
            \App\Http\Middleware\PreventSearchEngineIndexing::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'restrict.ip'      => \App\Http\Middleware\RestrictByIp::class,
            'admin.auth'       => \App\Http\Middleware\EnsureAdminAuthenticated::class,
            'admin.permission' => \App\Http\Middleware\CheckAdminPermission::class,
            'check.terminal'   => \App\Http\Middleware\CheckPunchTerminal::class,
            // 打刻画面/API のアクセス制御（IP一致 OR 端末キーのどちらかで許可）
            'punch.access'     => \App\Http\Middleware\PunchAccess::class,
        ]);

        $middleware->redirectGuestsTo(function ($request) {
            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);
    })->create();
