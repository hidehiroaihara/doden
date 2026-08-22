<?php

namespace App\Http\Middleware;

use App\Services\MonthPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $admin = $request->user('admin');
        $flashSuccess = $request->session()->get('success');
        $flashError = $request->session()->get('error');

        return [
            ...parent::share($request),
            'auth' => [
                'user'  => $request->user(),
                'admin' => $admin ? [
                    'id'          => $admin->id,
                    'name'        => $admin->name,
                    'email'       => $admin->email,
                    'role'        => $admin->role,
                    'permissions' => $admin->isSuperAdmin()
                        ? \App\Support\AdminPermission::SUPER
                        : $admin->normalizedPermissions(),
                ] : null,
            ],
            'flash' => [
                'success' => $flashSuccess,
                'error'   => $flashError,
                'token'   => ($flashSuccess || $flashError) ? (string) Str::uuid() : null,
            ],
            'monthClosingDay' => MonthPeriod::closingDay(),
        ];
    }
}
