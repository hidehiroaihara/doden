# 日付範囲ドロップダウンコンポーネント 作成指示書

他プロジェクトで「今月を規定値にした」日付選択ドロップダウンを同じ見た目・挙動で作るための手順です。

---

## 1. 概要

- **役割**: 開始日・終了日を選ぶUI。ボタンクリックでパネルが開き、日付入力とプリセット（今日・昨日・今月・先月・過去3ヶ月・全期間）を選べる。
- **規定値**: **今月**（当月1日 〜 今日）。未指定時は今月で表示・検索する。
- **技術**: React + TypeScript、Tailwind CSS、日付は `YYYY-MM-DD` 文字列。アイコンは Font Awesome（`fa-solid fa-calendar-days`, `fa-chevron-down`, `fa-calendar`）または同等のSVGで可。

---

## 2. 必要なユーティリティ

### 2.1 日付を YYYY-MM-DD にする

```ts
function toDateStr(d: Date): string {
    const pad = (n: number) => n.toString().padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
```

### 2.2 ボタンラベル用の表示文字列

```ts
function formatFilterLabel(from: string, to: string): string {
    if (!from && !to) return '全期間';
    const fmt = (s: string) => {
        const d = new Date(s + 'T00:00:00');
        if (isNaN(d.getTime())) return s;
        return `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()}`;
    };
    if (from && to && from === to) return fmt(from);
    if (from && to) return `${fmt(from)} 〜 ${fmt(to)}`;
    if (from) return `${fmt(from)} 〜`;
    return to ? `〜 ${fmt(to)}` : '全期間';
}
```

### 2.3 今月の規定値（初期値用）

```ts
function getThisMonthRange(): { from: string; to: string } {
    const today = new Date();
    const thisMonthStart = new Date(today.getFullYear(), today.getMonth(), 1);
    return {
        from: toDateStr(thisMonthStart),
        to: toDateStr(today),
    };
}
```

---

## 3. DateDropdown コンポーネント

### 3.1 Props

| 名前 | 型 | 説明 |
|------|-----|------|
| `dateFrom` | `string` | 開始日（YYYY-MM-DD）。空文字で「指定なし」 |
| `dateTo` | `string` | 終了日（YYYY-MM-DD） |
| `onChange` | `(from: string, to: string) => void` | 開始日・終了日が変わったときに呼ぶ |

### 3.2 プリセット定義（規定値＝今月）

```ts
const today = new Date();
const yesterday = new Date(today);
yesterday.setDate(today.getDate() - 1);

const thisMonthStart = new Date(today.getFullYear(), today.getMonth(), 1);
const lastMonthStart = new Date(today.getFullYear(), today.getMonth() - 1, 1);
const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0);
const past3Start = new Date(today.getFullYear(), today.getMonth() - 3, today.getDate());

const presets = [
    { label: '今日', from: toDateStr(today), to: toDateStr(today) },
    { label: '昨日', from: toDateStr(yesterday), to: toDateStr(yesterday) },
    { label: '今月', from: toDateStr(thisMonthStart), to: toDateStr(today) },
    { label: '先月', from: toDateStr(lastMonthStart), to: toDateStr(lastMonthEnd) },
    { label: '過去3ヶ月', from: toDateStr(past3Start), to: toDateStr(today) },
    { label: '全期間', from: '', to: '' },
];
```

### 3.3 挙動

- **開閉**: ボタンで `open` の true/false を切り替え。外クリックで閉じる（`mousedown` で `ref` 外なら `setOpen(false)`）。
- **日付入力**: `type="date"` の input を2つ（開始日・終了日）。クリックでネイティブカレンダーを開く場合は `input.showPicker?.()` を呼ぶ。
- **プリセット**: 各ボタンで `onChange(p.from, p.to)` を実行し、その後 `setOpen(false)`。
- **アクティブ表示**: `dateFrom === p.from && dateTo === p.to` のプリセットをハイライトする。

### 3.4 見た目（Tailwind の目安）

- **ラッパー**: `relative`。
- **トリガーボタン**
  - 通常: `inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold`、`bg-indigo-50 text-indigo-700 hover:bg-indigo-100`。
  - 開いているとき: `bg-indigo-600 text-white`。
  - アイコン: 左にカレンダー、右に `chevron-down`（開時は `rotate-180`）。
- **パネル**
  - `absolute left-0 top-full z-30 mt-2 w-72 rounded-xl bg-white p-4 shadow-xl ring-1 ring-gray-200`。
- **日付入力**
  - 2カラム: `grid grid-cols-2 gap-3`。
  - ラベル: `mb-1 block text-[11px] font-semibold text-gray-500`（「開始日」「終了日」）。
  - 入力の外枠: `flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 hover:border-indigo-300`。中に `type="date"` の input（`border-0 focus:ring-0` などでスタイルを抑える）。
- **プリセット**
  - `flex flex-col gap-1.5`。各ボタン: `rounded-lg px-3 py-2 text-sm font-medium`。
  - 通常: `text-gray-600 hover:bg-gray-50`。
  - 選択中: `bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200`。

アイコンが使えない場合は、テキストや Unicode（▼・📅）で代用可能。

---

## 4. 親コンポーネントでの使い方（規定値＝今月）

### 4.1 状態の初期値

バックエンドから「今月」を渡す場合:

```ts
// 例: サーバーが filters.date_from / filters.date_to を返す場合
const thisMonth = getThisMonthRange();

const [form, setForm] = useState({
    date_from: filters.date_from ?? thisMonth.from,
    date_to: filters.date_to ?? thisMonth.to,
});
```

バックエンドを使わない場合:

```ts
const thisMonth = getThisMonthRange();
const [form, setForm] = useState({
    date_from: thisMonth.from,
    date_to: thisMonth.to,
});
```

### 4.2 描画例

```tsx
<DateDropdown
    dateFrom={form.date_from}
    dateTo={form.date_to}
    onChange={(from, to) => setForm((prev) => ({ ...prev, date_from: from, date_to: to }))}
/>
```

### 4.3 検索・API に渡すとき

- 検索送信時は `form.date_from` / `form.date_to` をそのままクエリや body に載せる。
- 規定値「今月」にしたい場合は、**初期の form を今月で埋めておく**＋**バックエンドの未指定時も今月にする**と、初回表示と検索結果が揃う。

---

## 5. バックエンドで規定値を「今月」にする例（PHP/Laravel）

リクエストに `date_from` / `date_to` が無いときだけ今月を代入する例:

```php
$today = Carbon::today()->toDateString();
$thisMonthStart = Carbon::today()->startOfMonth()->toDateString();

$dateFrom = $request->input('date_from');
$dateTo = $request->input('date_to');

// 明示的にパラメータが渡されていないときだけ規定値（今月）
if (! $request->has('date_from') && ! $request->has('date_to')) {
    $dateFrom = $thisMonthStart;
    $dateTo = $today;
}

// そのあと $dateFrom / $dateTo でクエリやレスポンスを組み立てる
```

フロントに渡す `filters` にも同じ `date_from` / `date_to` を入れておくと、ボタンラベルと検索結果が一致する。

---

## 6. チェックリスト（他プロジェクトで再現するとき）

- [ ] `toDateStr` / `formatFilterLabel` / `getThisMonthRange` を実装
- [ ] DateDropdown の state（open）、ref（外クリック用）、presets を実装
- [ ] トリガーボタン（ラベルは `formatFilterLabel(dateFrom, dateTo)`）
- [ ] パネル内に開始日・終了日の `type="date"` を2つ
- [ ] プリセットボタン6つ（今日・昨日・今月・先月・過去3ヶ月・全期間）
- [ ] 親の form 初期値を今月（`getThisMonthRange()` またはバックエンドの今月）
- [ ] バックエンドの規定値も今月にする（未指定時は今月で検索）
- [ ] Tailwind（または同等のクラス）で上記の見た目を再現
- [ ] アイコンは Font Awesome または SVG/テキストで代用

---

## 7. 参考: このプロジェクトでの該当ファイル

- フロント: `resources/js/Pages/Admin/Attendances/Index.tsx`（DateDropdown と `toDateStr` / `formatFilterLabel`）
- 規定値「今月」: 同ファイルの `form` 初期値と、`app/Http/Controllers/Admin/AttendanceController.php` の `index` 内の `date_from` / `date_to` 初期化
