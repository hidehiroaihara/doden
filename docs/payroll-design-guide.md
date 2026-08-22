# 給与システム デザイン指針（payroll-design-guide）

打刻システムと給与計算システムを1つのアプリとして統合するにあたり、**画面の見た目・挙動を全画面で揃える**ための共通ルールをまとめる。

- **一次情報は `資料/設計書/*.md`（全30本）**。マネーフォワード クラウド給与の一般機能ではなく、この設計書に書かれた項目・レイアウト・挙動を正とする。本書はそこから抽出した「共通パターン」を実装用に翻訳したもの。
- 既存の打刻画面（`resources/js/Pages/Welcome.tsx` ほか）と管理画面（`resources/js/Layouts/AdminLayout.tsx` 配下）の Tailwind スタイルを基準トークンとする。新規画面もこのトークン・パターンから外れないこと。
- 既存の再利用ガイド（[`docs/DateDropdown-component-guide.md`](./DateDropdown-component-guide.md) / [`docs/sidebar-collapse-button-guide.md`](./sidebar-collapse-button-guide.md)）と矛盾しないこと。

---

## 1. 技術スタックと前提

| 項目 | 内容 |
|---|---|
| バックエンド | Laravel 13（Inertia でページを返す） |
| フロント | React + TypeScript（`resources/js/Pages` / `resources/js/Layouts`） |
| スタイル | Tailwind CSS（ユーティリティ直書き。CSS-in-JS やコンポーネントライブラリは持ち込まない） |
| 数値/時刻 | 金額は円（整数）、時間は分（整数）で保持し、表示側でフォーマット。日付は `YYYY-MM-DD` 文字列 |
| 個人情報 | 設計書に準拠し、氏名・番号・金額の実データはドキュメント/コミットに残さない |

---

## 2. デザイントークン

既存コードで実際に使われている値を「正」とする。新色をむやみに増やさない。

### 2.1 カラー

> **アクセント色の使い分け（重要）**: 管理画面（`AdminLayout` 配下）は既存アプリの慣習に合わせ **teal 系**（`teal-600`/`teal-700`、サイドバーは teal グラデーション）を主アクセントとする。`indigo` はマネーフォワード参照のためのトーンで、打刻・一般ユーザー画面側で使用。**新規の管理画面はマスタ編集・給与計算含めすべて teal に統一**すること。

| 用途 | クラス | 備考 |
|---|---|---|
| アクセント（管理画面・主）| `teal-600` / hover `teal-700` / 淡 `teal-100` | タブ選択・主ボタン・保存ボタン・サイドバー |
| アクセント（一般画面）| `indigo-600` / hover `indigo-700` / 淡 `indigo-50` `indigo-100` | 打刻・ユーザー向け画面のリンク/ボタン |
| 出勤/進行中 | `blue-500` | 「出勤中」バッジ等 |
| 完了/正常 | `emerald-500`（淡 `emerald-50`）/ `teal-600` | 「退勤済」バッジ・打刻完了トースト |
| 警告 | `amber-500` | 退勤忘れ等の注意 |
| エラー/削除 | `red-500` `rose-500` | |
| 背景 | ページ `bg-gray-50`、カード `bg-white` | |
| 文字 | 主 `text-gray-800`、副 `text-gray-500`、極淡 `text-gray-400` | |
| 罫線/リング | `border-gray-100` `border-gray-200` / `ring-1 ring-gray-100` | |

> アバター等のランダム色は `Welcome.tsx` の `COLORS` 配列（`bg-blue-500`〜`bg-amber-500`）＋ `id % COLORS.length` 方式を踏襲する。

### 2.2 角丸・余白・影・文字

| トークン | 値 |
|---|---|
| カード角丸 | `rounded-xl`（小）/ `rounded-2xl`（大パネル） |
| 影 | 通常 `shadow-sm`、ホバー `hover:shadow-md`、パネル `shadow-xl` |
| カード内パディング | `px-4 py-3`〜`px-5 py-5`（要素サイズに応じて） |
| セクション間隔 | `space-y-8`（大ブロック）/ `gap-3`〜`gap-6`（グリッド） |
| 見出し | セクション `text-lg font-bold text-gray-700`、カード内 `text-sm font-bold text-gray-700` |
| 数値表示 | `tabular-nums`（等幅数字）、時計は `font-mono` |

---

## 3. 共通レイアウトパターン

設計書全体で繰り返し登場する4パターン。新画面はこのいずれかに寄せる。

### 3.1 マスタ・ディテール（左フィルタ＋一覧／右詳細）

- 出典: `04_給与計算_設計書`。給与計算・従業員一覧など「対象を選んで右に詳細」の画面。
- 左ペイン: 固定幅・独立スクロール。上部に**絞り込み（ドロップダウン）＋検索ボックスを常時固定**、下にリスト。
- 右ペイン: 可変幅。選択中の対象の詳細を表示。
- 実装: `flex flex-col gap-6 lg:flex-row`（打刻画面 `Welcome.tsx` の2カラムと同系統）。左 `lg:w-[320px] shrink-0`、右 `min-w-0 flex-1`。狭幅では縦積み。

### 3.2 水平タブ・ハブ画面

- 出典: `06_基本設定_全体像` / `05_従業員情報`。1ページ内を水平テキストタブで切替。
- タブは**テキストのみ（アイコンなし）**。選択中は青字＋下線、非選択は `text-gray-500`。
- 実装トークン: 選択 `text-indigo-600 border-b-2 border-indigo-600 font-semibold`、非選択 `text-gray-500 hover:text-gray-700`。
- 同じタブコンポーネントを基本設定・従業員情報で使い回す（重複実装しない）。

### 3.3 セクション＋右上「編集」

- 出典: `06`・`04`・`05`。「セクション見出し＋右上に ✏編集＋2列テーブル」を縦に連続。
- **編集の起動方法は画面で異なる**ので厳守する:
  - **基本設定** … セクション右上「編集」で**モーダル**を開いて更新。
  - **従業員情報** … カード右上「編集」で**カード単位インライン編集**（同カード内で表示↔編集を切替）。
- 見出し右上リンク: `text-sm font-medium text-indigo-600 hover:text-indigo-700`＋鉛筆アイコン。

### 3.4 2列テーブル（ラベル／値）と複数列テーブル

- 「ラベル（左）＋値（右揃え・数値）」の2列を基本形とする。合計行のみ背景を軽く強調（`bg-gray-50 font-semibold`）。
- 罫線は薄いグレー（`divide-y divide-gray-100` など）。
- **空値は空欄のまま**にする（`−` などのプレースホルダを入れない）。＝ホーム/メンバー/給与計算で共通の方針。

---

## 4. コンポーネント規約

| コンポーネント | 規約 |
|---|---|
| 主ボタン | `inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold bg-indigo-600 text-white hover:bg-indigo-700` |
| 副/フィルタボタン | `bg-indigo-50 text-indigo-700 hover:bg-indigo-100`、開いている間は `bg-indigo-600 text-white`（DateDropdown 準拠） |
| ドロップダウンパネル | `absolute z-30 mt-2 rounded-xl bg-white p-4 shadow-xl ring-1 ring-gray-200`、外クリックで閉じる |
| バッジ | `rounded-full px-2 py-0.5 text-[10px] font-bold`。状態色は §2.1（出勤中=blue、退勤済=emerald） |
| 日付範囲選択 | 新規実装せず [`DateDropdown-component-guide.md`](./DateDropdown-component-guide.md) を踏襲（規定値＝今月） |
| サイドバー折りたたみ | [`sidebar-collapse-button-guide.md`](./sidebar-collapse-button-guide.md) を踏襲 |
| トースト/完了表示 | 画面右端固定・数秒で自動消去（`Welcome.tsx` の打刻完了トースト方式） |

---

## 5. マスタ駆動レンダリング（設計原則）

設計書共通の最重要方針。**表示項目をコードに直書きしない。**

- 支給・控除・勤怠の各項目は**マスタ（DB）**で定義し、有効/無効・課税/非課税・保険料算定対象・丸めルール等のフラグを持たせる。画面はマスタを元に**動的に行を増減**して描画する（`04_給与計算` 3-4/6章）。
- 従業員の契約条件（社会保険加入有無・雇用保険のみ等）で表示項目・行数が変わる前提で組む。
- 勤怠項目は「基本設定→勤怠項目」で有効化された項目だけを給与計算画面に出す（`04` 3-5 / `11_基本設定_勤怠項目`）。

### 5.1 割増・区分の考え方（勤怠→給与連携）

- 勤怠の集計値は **`App\Services\AttendanceSummaryService` を単一ソース**とする（ダッシュボード・月次集計・CSV・給与計算はすべてこれを参照）。
- 区分は「平日／所定休日／法定休日 × 所定内／所定外／法定外／深夜」を基本軸に持つ（`04` 補足・`11`）。
  - **深夜**は 22:00〜翌05:00 の実勤務重なりで算出（`AttendanceSummaryService::nightMinutes`）。
  - **法定外**は 1日8時間（480分）超で算出。
- **夜勤（日跨ぎ）は必須要件**。`clock_out_at` が翌日時刻でも出勤日基準で正しく集計する（深夜帯の走査で二重計上しない）。

### 5.2 社会保険料など「期間で変わる率」

- 健康保険・介護・厚生年金・子ども子育て拠出金・雇用保険・労災の**料率は事業所別・適用期間別のマスタ**で管理し、計算時は「支給対象日が属する期間」の率を引く（`12_社会保険`・`13_労働保険`）。
- 標準報酬月額の等級表もマスタ化し、改定に追従できるようにする。過去確定分は当時の率で固定（遡って変えない）。

---

## 6. 命名・データ規約

- 金額: 円・整数。時間: 分・整数で保持し、表示は `H:MM`（`AttendanceSummaryService::formatMinutesToHM`）。
- 締め期間: `App\Services\MonthPeriod`（`Y-m` キー→ from/to）を単一ソースとする。締め日はプロジェクトで可変。
- 店舗/事業所: 既存 `Department` モデルを「店舗（事業所）」として扱う。打刻時に `attendances.department_id` へ**スナップショット保存**（後からユーザーの所属が変わっても当時の店舗が残る）。
- 機能フラグ: `config/features.php`（例 `features.department`）。既定で店舗機能は ON。

---

## 7. レスポンシブ / アクセシビリティ

- モバイル優先。3カラム給与内訳などは狭幅で**縦積み**に落とす（`04` 5章）。
- 数値列は右揃え＋`tabular-nums`。ステータスは色だけに頼らずテキストラベルも併記（例「出勤中」）。
- トースト/ステータスには `role="status"` を付与。

---

## 8. 新規画面チェックリスト

- [ ] §3 の4パターンのどれに当てはまるかを最初に決めた
- [ ] 色・角丸・影・文字を §2 トークンから選んだ（新色を足していない）
- [ ] 表示項目はマスタ駆動（直書きしていない）
- [ ] 編集UIの起動方法が画面種別に合っている（基本設定=モーダル／従業員情報=インライン）
- [ ] 空値は空欄（プレースホルダを入れていない）
- [ ] 勤怠集計は `AttendanceSummaryService` を参照している（再計算を各所に散らさない）
- [ ] 期間で変わる率はマスタから期間で引いている
- [ ] 既存の再利用コンポーネント（DateDropdown / サイドバー折りたたみ）を使った
- [ ] 参照した設計書番号を PR / コミットに明記した

---

## 9. 参照

- 設計書: `資料/設計書/01_ホーム画面` 〜 `30_源泉徴収簿`（全30本。**一次情報**）
- 既存ガイド: [`DateDropdown-component-guide.md`](./DateDropdown-component-guide.md) / [`sidebar-collapse-button-guide.md`](./sidebar-collapse-button-guide.md)
- 主なコード基準: `resources/js/Pages/Welcome.tsx`（トークン）、`resources/js/Layouts/AdminLayout.tsx`（管理画面レイアウト）、`app/Services/AttendanceSummaryService.php`（勤怠集計の単一ソース）、`app/Services/MonthPeriod.php`（締め期間）
