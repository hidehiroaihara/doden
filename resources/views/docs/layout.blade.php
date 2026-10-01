{{-- 社内資料ビューアの共通レイアウト。Vite ビルドに依存しないよう CSS は埋め込み。 --}}
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', '社内資料')</title>
    <style>
        :root { --ink: #1f2937; --muted: #6b7280; --line: #e5e7eb; --accent: #0d9488; --bg: #f3f4f6; }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font-family: -apple-system, BlinkMacSystemFont, "Hiragino Sans", "Noto Sans JP", "Meiryo", sans-serif;
            line-height: 1.8; -webkit-font-smoothing: antialiased;
        }
        a { color: var(--accent); }
        .wrap { max-width: 900px; margin: 0 auto; padding: 32px 20px 80px; }
        .bar {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px;
            margin-bottom: 20px; font-size: 13px; color: var(--muted);
        }
        .bar a { text-decoration: none; font-weight: 600; }
        .bar a:hover { text-decoration: underline; }
        .card { background: #fff; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.06); padding: 32px 36px; }
        .note { font-size: 13px; color: var(--muted); margin: 0 0 24px; }

        /* Markdown 本文 */
        .md > :first-child { margin-top: 0; }
        .md h1 { font-size: 26px; margin: 0 0 8px; }
        .md h2 { font-size: 20px; margin: 40px 0 12px; padding-bottom: 8px; border-bottom: 2px solid var(--line); }
        .md h3 { font-size: 16px; margin: 28px 0 10px; }
        .md h4 { font-size: 14px; margin: 20px 0 8px; color: var(--muted); }
        .md p, .md li { font-size: 14.5px; }
        .md ul, .md ol { padding-left: 1.4em; }
        .md li { margin: 4px 0; }
        .md hr { border: 0; border-top: 1px solid var(--line); margin: 36px 0; }
        .md code {
            background: #f3f4f6; border-radius: 4px; padding: 2px 5px; font-size: 13px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .md pre { background: #1f2937; color: #f9fafb; padding: 14px 16px; border-radius: 10px; overflow-x: auto; }
        .md pre code { background: none; color: inherit; padding: 0; }
        .md blockquote {
            margin: 16px 0; padding: 12px 16px; border-left: 4px solid #fbbf24;
            background: #fffbeb; border-radius: 0 8px 8px 0;
        }
        .md blockquote p { margin: 0; font-size: 13.5px; }
        .md table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13.5px; display: block; overflow-x: auto; }
        .md th, .md td { border: 1px solid var(--line); padding: 8px 12px; text-align: left; vertical-align: top; }
        .md th { background: #f9fafb; font-weight: 700; white-space: nowrap; }

        /* パスワード画面 */
        .gate { max-width: 400px; margin: 12vh auto; padding: 0 20px; }
        .gate h1 { font-size: 18px; margin: 0 0 4px; }
        .gate input[type=password] {
            width: 100%; padding: 10px 12px; font-size: 15px; margin-top: 16px;
            border: 1px solid #d1d5db; border-radius: 8px;
        }
        .gate input[type=password]:focus { outline: 2px solid var(--accent); outline-offset: -1px; border-color: var(--accent); }
        .gate button {
            width: 100%; margin-top: 12px; padding: 11px; font-size: 15px; font-weight: 700; cursor: pointer;
            background: var(--accent); color: #fff; border: 0; border-radius: 8px;
        }
        .gate button:hover { background: #0f766e; }
        .error { margin-top: 10px; font-size: 13px; color: #dc2626; }
        .list { list-style: none; margin: 0; padding: 0; }
        .list li + li { margin-top: 10px; }
        .list a { display: block; padding: 14px 16px; background: #f9fafb; border-radius: 10px; text-decoration: none; }
        .list a:hover { background: #f0fdfa; }
        .list strong { display: block; color: var(--ink); font-size: 15px; }
        .list span { font-size: 13px; color: var(--muted); }
    </style>
</head>
<body>
    @yield('body')
</body>
</html>
