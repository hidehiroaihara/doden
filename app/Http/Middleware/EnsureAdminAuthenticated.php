<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAuthenticated
{
    /**
     * 未認証の場合は 404 を返してページの存在を隠す。
     * auth:admin のデフォルト（ログイン画面へリダイレクト）は使わない。
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('admin')->check()) {
            abort(404);
        }

        return $next($request);
    }
}
