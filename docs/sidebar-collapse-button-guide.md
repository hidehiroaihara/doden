# サイドバー折りたたみボタンの他プロジェクトへの反映手順

## 概要

サイドバーを開閉するボタンです。
- **サイドバー展開時**: 三本線（ハンバーガー）アイコン表示。ホバーで左矢印に変わり「閉じる」を示す。
- **サイドバー折りたたみ時**: 三本線アイコン表示。ホバーで右矢印に変わり「開く」を示す。

---

## 1. 必要な状態（state）

レイアウトコンポーネントで `collapsed` を管理します。

```tsx
const [collapsed, setCollapsed] = useState(false);
```

---

## 2. サイドバーの幅を collapsed で切り替える

`aside` のクラスで、`collapsed` のとき幅を狭くします。

```tsx
<aside className={`
    ... 既存のクラス ...
    ${collapsed ? 'w-[72px]' : 'w-64'}
`}>
```

---

## 3. 折りたたみ時（サイドバーが狭いとき）のボタン

サイドバーがすでに折りたたまれているときに表示する「開く」用ボタンです。
ホバーで右矢印（→）になり、クリックで `setCollapsed(false)` を実行します。

```tsx
{collapsed ? (
    <button
        onClick={() => setCollapsed(false)}
        className="group mx-auto hidden lg:flex h-9 w-9 items-center justify-center rounded-lg text-white/60 hover:bg-white/10 hover:text-white transition"
    >
        <svg className="h-5 w-5 group-hover:hidden" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
        </svg>
        <svg className="hidden h-5 w-5 group-hover:block" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
        </svg>
    </button>
) : (
    // 下の「展開時のボタン」をここに置く
)}
```

- `group`: ホバー時に子の `group-hover:*` を制御するため。
- 通常時: 三本線の SVG を表示（`group-hover:hidden` でホバー時に非表示）。
- ホバー時: 右矢印の SVG を表示（`hidden` + `group-hover:block`）。
- `hidden lg:flex`: スマホでは非表示、PC（lg以上）で表示。

---

## 4. 展開時（サイドバーが広いとき）のボタン（質問のボタン）

ロゴの右隣に置く「閉じる」用ボタンです。
ホバーで左矢印（←）になり、クリックで `setCollapsed(true)` を実行します。

```tsx
<button
    onClick={() => setCollapsed(true)}
    className="group hidden lg:flex h-8 w-8 items-center justify-center rounded-lg text-white/60 hover:bg-white/10 hover:text-white transition"
>
    <svg className="h-5 w-5 group-hover:hidden" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
    </svg>
    <svg className="hidden h-5 w-5 group-hover:block" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
    </svg>
</button>
```

- 三本線アイコン: `d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"`（横線3本）。
- 左矢印: `d="M15.75 19.5L8.25 12l7.5-7.5"`（左向き矢印）。
- 表示切替は「3. 折りたたみ時のボタン」と同じ（通常＝三本線、ホバー＝矢印）。

---

## 5. レイアウト上の配置例

ヘッダー部分を「ロゴ＋閉じるボタン」または「開くボタンのみ」でそろえる例です。

```tsx
<div className="flex h-16 shrink-0 items-center justify-between border-b border-white/10 px-4">
    {collapsed ? (
        // 折りたたみ時: 中央に「開く」ボタンのみ（上記 3 のコード）
        <button onClick={() => setCollapsed(false)} ...>...</button>
    ) : (
        <>
            <Link href={...}>
                <img src="/img/logo.png" alt="..." className="h-10 w-auto" />
            </Link>
            {/* 展開時: ロゴの右に「閉じる」ボタン（上記 4 のコード） */}
            <button onClick={() => setCollapsed(true)} ...>...</button>
        </>
    )}
</div>
```

---

## 6. ナビゲーションとの連動

折りたたみ時はラベルを隠してアイコンのみにするとよいです。ナビリンクのクラスに `collapsed` を渡します。

```tsx
<Link className={`... ${collapsed ? 'justify-center' : ''}`}>
    <span>{item.icon}</span>
    {!collapsed && <span>{item.label}</span>}
</Link>
```

---

## 7. チェックリスト（他プロジェクトでやること）

- [ ] `useState(false)` で `collapsed` を追加
- [ ] `aside` の幅を `collapsed ? 'w-[72px]' : 'w-64'` で切り替え
- [ ] ヘッダー内で `collapsed ? 開くボタン : (ロゴ + 閉じるボタン)` を分岐
- [ ] 閉じるボタン: 三本線＋ホバーで左矢印、`onClick={() => setCollapsed(true)}`
- [ ] 開くボタン: 三本線＋ホバーで右矢印、`onClick={() => setCollapsed(false)}`
- [ ] ナビ項目で `collapsed` のときラベル非表示（任意）
- [ ] 色（`text-white/60` など）はプロジェクトのテーマに合わせて変更
