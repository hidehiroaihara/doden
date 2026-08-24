<?php

namespace App\Http\Middleware;

use App\Models\Terminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 打刻画面・打刻APIのアクセス制御（IP 一致 OR 端末キーの「どちらか」で許可）。
 *
 * ┌─ 判定ルール ───────────────────────────────────────────────┐
 * │ ・ALLOWED_IPS も端末も未登録  → 制限なし（開発・初期状態）      │
 * │ ・クライアントIPが ALLOWED_IPS に一致 → 通過（店内のノーマルURL）│
 * │ ・有効な端末Cookie／terminal_id+key で認証 → 通過（複雑URL直打ち）│
 * │ ・いずれも満たさない → 403                                    │
 * └───────────────────────────────────────────────────────────┘
 *
 * これにより「店内なら普通のURL、外からは認証付きの複雑URL」が両立する。
 */
class PunchAccess
{
    /** 端末認証Cookie名 */
    private const COOKIE_NAME = 'punch_terminal';

    /** Cookie有効期間（分）: 30日 */
    private const COOKIE_TTL = 60 * 24 * 30;

    public function handle(Request $request, Closure $next): Response
    {
        $ipConfigured       = $this->allowedIps() !== [];
        $terminalConfigured = Terminal::exists();

        // どちらも未設定 → 制限なし（従来どおり）
        if (! $ipConfigured && ! $terminalConfigured) {
            return $next($request);
        }

        // ① IP 一致で通過（店内のノーマルURL）
        if ($ipConfigured && $this->ipMatches((string) $request->ip())) {
            return $next($request);
        }

        // ② 端末認証で通過（複雑URL直打ち / Cookie）
        if ($terminalConfigured) {
            $viaTerminal = $this->tryTerminal($request, $next);
            if ($viaTerminal !== null) {
                return $viaTerminal;
            }
        }

        abort(403, 'アクセスが許可されていません。');
    }

    /**
     * 端末Cookie／terminal_id+key による認証を試みる。
     * 認証できた場合は Response を返し、できなければ null を返す（呼び出し側で403）。
     */
    private function tryTerminal(Request $request, Closure $next): ?Response
    {
        // ── 既存Cookieで認証 ──
        $cookieTerminalId = $request->cookie(self::COOKIE_NAME);
        if ($cookieTerminalId) {
            $terminal = Terminal::where('terminal_id', $cookieTerminalId)
                ->where('is_active', true)
                ->first();

            if ($terminal) {
                return $next($request);
            }
            // 無効化された端末のCookieは無視して以降のキー認証へ
        }

        // ── クエリ／ボディの terminal_id + terminal_key で認証 ──
        $terminalId  = (string) ($request->query('terminal_id')  ?? $request->input('terminal_id',  ''));
        $terminalKey = (string) ($request->query('terminal_key') ?? $request->input('terminal_key', ''));

        if ($terminalId === '' || $terminalKey === '') {
            return null;
        }

        $terminal = Terminal::where('terminal_id', $terminalId)->first();
        if (! $terminal || ! $terminal->is_active) {
            return null;
        }

        // タイミング攻撃対策
        if (! hash_equals($terminal->terminal_key, $terminalKey)) {
            return null;
        }

        // 認証成功。GETの画面リクエスト（非JSON）はCookieをセットしてクリーンURLへリダイレクト
        // → アドレスバーから terminal_key が消え、以降はCookieで自動認証される
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect($request->url())
                ->withCookie(cookie(
                    self::COOKIE_NAME,
                    $terminal->terminal_id,
                    self::COOKIE_TTL,
                    '/',
                    null,
                    $request->secure(),
                    true,   // HttpOnly
                    false,
                    'Strict',
                ));
        }

        // POST（打刻API等）はそのまま通過（ブラウザがCookieを自動送信）
        return $next($request);
    }

    /**
     * .env の ALLOWED_IPS をパースして配列で返す。
     * 例: ALLOWED_IPS=1.2.3.4,5.6.7.8,192.168.0.0/24
     *
     * @return array<int, string>
     */
    private function allowedIps(): array
    {
        $value = (string) env('ALLOWED_IPS', '');
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
    }

    /** クライアントIPが ALLOWED_IPS のいずれかに一致するか。 */
    private function ipMatches(string $clientIp): bool
    {
        foreach ($this->allowedIps() as $allowed) {
            if (str_contains($allowed, '/')) {
                if ($this->ipInCidr($clientIp, $allowed)) {
                    return true;
                }
            } elseif ($clientIp === $allowed) {
                return true;
            }
        }

        return false;
    }

    /** IPアドレスが CIDR 範囲内かどうか（IPv4）。 */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefix] = array_pad(explode('/', $cidr), 2, '32');
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
