<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictByIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = $this->getAllowedIps();

        // ALLOWED_IPS が未設定（空）の場合はすべて許可
        if (empty($allowedIps)) {
            return $next($request);
        }

        $clientIp = $request->ip();

        foreach ($allowedIps as $allowed) {
            $allowed = trim($allowed);
            if ($allowed === '') continue;

            // CIDR 形式（例: 192.168.1.0/24）対応
            if (str_contains($allowed, '/')) {
                if ($this->ipInCidr($clientIp, $allowed)) {
                    return $next($request);
                }
            } else {
                // 完全一致
                if ($clientIp === $allowed) {
                    return $next($request);
                }
            }
        }

        abort(403, 'アクセスが許可されていません。');
    }

    /**
     * .env の ALLOWED_IPS をパースして配列で返す
     * 例: ALLOWED_IPS=1.2.3.4,5.6.7.8,192.168.0.0/24
     */
    private function getAllowedIps(): array
    {
        $value = env('ALLOWED_IPS', '');
        if (empty($value)) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $value)));
    }

    /**
     * IPアドレスが CIDR 範囲内かどうかを判定
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefix] = explode('/', $cidr);
        $prefix = (int) $prefix;

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix));
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
