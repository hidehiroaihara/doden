<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 社内資料（docs/ 配下の Markdown）をブラウザで閲覧するための管理者向けビューア。
 *
 * config/docs.php に列挙したページだけを公開し、DOCS_PASSWORD による合言葉で保護する。
 * 管理画面にログイン中の管理者はパスワード不要。DOCS_PASSWORD 未設定なら機能全体が 404。
 */
class DocsController extends Controller
{
    /** パスワード認証済みを示すセッションキー。 */
    public const SESSION_KEY = 'docs_authorized_at';

    /** 認証の有効期間（分）。これを過ぎたら再入力を求める。 */
    private const SESSION_TTL_MINUTES = 480;

    public function index()
    {
        $this->ensureEnabled();

        if (! $this->isAuthorized()) {
            return response()->view('docs.login', ['intended' => null]);
        }

        return view('docs.index', ['pages' => $this->pages()]);
    }

    public function show(string $slug)
    {
        $this->ensureEnabled();

        $page = config("docs.pages.{$slug}");
        abort_if(! is_array($page), 404);

        if (! $this->isAuthorized()) {
            return response()->view('docs.login', ['intended' => $slug]);
        }

        $path = base_path('docs/'.$page['file']);
        abort_unless(is_file($path), 404);

        return view('docs.show', [
            'slug' => $slug,
            'title' => $page['title'],
            'html' => Str::markdown((string) file_get_contents($path), [
                // 資料内の生HTMLは信用せずエスケープする
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
            ]),
            'updatedAt' => date('Y-m-d H:i', (int) filemtime($path)),
            'pages' => $this->pages(),
        ]);
    }

    public function login(Request $request)
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'password' => ['required', 'string'],
            'intended' => ['nullable', 'string'],
        ]);

        if (! hash_equals((string) config('docs.password'), $validated['password'])) {
            throw ValidationException::withMessages(['password' => 'パスワードが違います。']);
        }

        $request->session()->put(self::SESSION_KEY, now()->timestamp);

        $slug = $validated['intended'] ?? null;

        return redirect($slug && config("docs.pages.{$slug}")
            ? route('docs.show', $slug)
            : route('docs.index'));
    }

    public function logout(Request $request)
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('docs.index');
    }

    /** DOCS_PASSWORD が未設定なら機能を無効にする（公開事故の防止）。 */
    private function ensureEnabled(): void
    {
        abort_if(blank(config('docs.password')), 404);
    }

    /** 管理画面ログイン中、またはパスワード認証が有効期間内か。 */
    private function isAuthorized(): bool
    {
        if (Auth::guard('admin')->check()) {
            return true;
        }

        $at = session(self::SESSION_KEY);

        return is_int($at) && $at > now()->subMinutes(self::SESSION_TTL_MINUTES)->timestamp;
    }

    /**
     * 一覧表示用のページ定義（スラッグつき）。存在しないファイルは除く。
     *
     * @return array<int, array<string, string>>
     */
    private function pages(): array
    {
        $pages = [];
        foreach ((array) config('docs.pages') as $slug => $page) {
            if (is_file(base_path('docs/'.$page['file']))) {
                $pages[] = ['slug' => $slug] + $page;
            }
        }

        return $pages;
    }
}
