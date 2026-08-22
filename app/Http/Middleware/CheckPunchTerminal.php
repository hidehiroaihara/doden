<?php

namespace App\Http\Middleware;

use App\Models\Terminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPunchTerminal
{
    /** クッキー名 */
    private const COOKIE_NAME = 'punch_terminal';

    /** クッキー有効期間（分）: 30日 */
    private const COOKIE_TTL = 60 * 24 * 30;

    public function handle(Request $request, Closure $next): Response
    {
        // ── Step 0: 端末が1件も登録されていない場合はチェックをスキップ ──
        // 未設定状態では従来通りアクセス制限なし。
        // 端末を1件以上登録した時点から制限が有効になる。
        if (! Terminal::exists()) {
            return $next($request);
        }

        // ── Step 1: 既存クッキーで認証 ──────────────────────────────
        // Laravel がクッキーを自動復号するため、値は平文の terminal_id
        $cookieTerminalId = $request->cookie(self::COOKIE_NAME);

        if ($cookieTerminalId) {
            $terminal = Terminal::where('terminal_id', $cookieTerminalId)
                ->where('is_active', true)
                ->first();

            if ($terminal) {
                // クッキーが有効 → そのまま通過
                return $next($request);
            }

            // 無効化された端末のクッキーは後続でクリアする
        }

        // ── Step 2: クエリ / ボディパラメータで認証 ──────────────────
        $terminalId  = (string) ($request->query('terminal_id')  ?? $request->input('terminal_id',  ''));
        $terminalKey = (string) ($request->query('terminal_key') ?? $request->input('terminal_key', ''));

        if ($terminalId === '' || $terminalKey === '') {
            abort(403, 'This terminal is not allowed.');
        }

        $terminal = Terminal::where('terminal_id', $terminalId)->first();

        if (! $terminal || ! $terminal->is_active) {
            abort(403, 'This terminal is not allowed.');
        }

        // タイミング攻撃対策
        if (! hash_equals($terminal->terminal_key, $terminalKey)) {
            abort(403, 'This terminal is not allowed.');
        }

        // ── Step 3: 認証成功 ─────────────────────────────────────────
        // GET の画面リクエスト（非 JSON）の場合: クッキーをセットしてクリーン URL にリダイレクト
        // → アドレスバーから terminal_key が消え、以降はクッキーで自動認証
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect($request->url())  // query string なしの URL に戻す
                ->withCookie(cookie(
                    self::COOKIE_NAME,
                    $terminal->terminal_id,
                    self::COOKIE_TTL,
                    '/',
                    null,
                    $request->secure(), // 本番 HTTPS では Secure フラグ
                    true,               // HttpOnly（JS から読めない）
                    false,
                    'Strict',           // CSRF 対策
                ));
        }

        // POST（打刻 API 等）は通過させる（ブラウザがクッキーを自動送信）
        return $next($request);
    }
}
