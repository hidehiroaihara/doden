<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 全 HTTP レスポンスに noindex を付与する（検索エンジン・ブラウザ向け）。
 * Inertia ページの meta robots に加え、帳票 Blade や API なども含めて統一する。
 */
class PreventSearchEngineIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive', false);

        return $response;
    }
}
