<?php

namespace App\Http\Middleware;

use App\Support\AdminPermission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckAdminPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin) {
            abort(404);
        }

        // スーパー管理者は常に通過
        if ($admin->isSuperAdmin()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName() ?? '';
        $mapping   = AdminPermission::forRoute($routeName);

        // マップにないルート（logout など）は通過
        if ($mapping === null) {
            return $next($request);
        }

        [$section, $requiredLevel] = $mapping;

        // admins セクションは role=1 のみ（スーパー管理者でない時点で403）
        if ($section === 'admins') {
            abort(403);
        }

        if (! $admin->hasPermission($section, $requiredLevel)) {
            abort(403);
        }

        return $next($request);
    }
}
