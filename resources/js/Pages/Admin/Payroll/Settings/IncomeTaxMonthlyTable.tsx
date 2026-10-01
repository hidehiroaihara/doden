import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface TableRow {
    id: number;
    name: string;
    target_year: number | null;
    effective_from: string;
    effective_to: string | null;
    dependent_over7_deduction: number;
    source_note: string | null;
    rows_count: number;
    is_active: boolean;
    updated_at: string | null;
}

interface LookupResult {
    input: { after_social_insurance: number; dependents: number; tax_table: string; effective_date: string };
    monthly_table: number;
    monthly_table_source: string;
    computer_special: number;
    monthly_table_found: boolean;
}

interface Props {
    tables: TableRow[];
    calcMethod: string;
    currentYear: number;
    hasCurrentYear: boolean;
    activeEffectiveFrom: string | null;
    csvColumns: string[];
    csvWideColumns: string[];
    lookup: LookupResult | null;
}

const yen = (v: number) => (v || 0).toLocaleString();

const CSV_HELP: Record<string, string> = {
    tax_table: 'kou（甲欄）または otsu（乙欄）',
    min_amount: '社会保険料等控除後の給与等の金額の下限（この額以上）',
    max_amount: '上限（この額未満）。最上位の階級は空欄',
    dependents: '扶養親族等の数。甲欄は 0〜7、乙欄は 0',
    tax_amount: '表の税額（円・復興特別所得税込み）',
    rate: '率で決まる階級・高額帯の加算率（例 0.2042）',
    deduction: '率で計算する階級の控除額（円）。無ければ 0',
    base_amount: '高額帯の基準となる税額（円）。rate と併用',
    excess_over: '超過額の起点（円）。空欄なら min_amount を使う',
};

const FORMATS: { value: 'wide' | 'long'; label: string; hint: string }[] = [
    {
        value: 'wide',
        label: '国税庁の表のまま（横並び）',
        hint: '以上・未満・扶養0〜7人・乙 の順に並んだ表。ExcelファイルもCSVもそのまま使えます。',
    },
    {
        value: 'long',
        label: '1行=1マス（長い形式）',
        hint: '甲乙・階級・扶養人数・税額を1行ずつ持つ形式。一部のマスだけ直したいときに使います。',
    },
];

const card = 'overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100';
const input = 'w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500';
const label = 'mb-1 block text-xs font-medium text-gray-500';

export default function IncomeTaxMonthlyTablePage({
    tables, calcMethod, currentYear, hasCurrentYear, activeEffectiveFrom, csvColumns, csvWideColumns, lookup,
}: Props) {
    const canWrite = useAdminPermission('payroll');
    const [showImport, setShowImport] = useState(tables.length === 0);

    const importForm = useForm<{
        name: string; target_year: number; effective_from: string; effective_to: string;
        dependent_over7_deduction: number; source_note: string; format: 'wide' | 'long'; file: File | null;
    }>({
        name: `令和${currentYear - 2018}年分 給与所得の源泉徴収税額表（月額表）`,
        target_year: currentYear,
        effective_from: `${currentYear}-01-01`,
        effective_to: '',
        dependent_over7_deduction: 1610,
        source_note: '',
        format: 'wide',
        file: null,
    });

    const [lookupInput, setLookupInput] = useState({
        after_social_insurance: lookup?.input.after_social_insurance ?? 442817,
        dependents: lookup?.input.dependents ?? 0,
        tax_table: lookup?.input.tax_table ?? 'kou',
        effective_date: lookup?.input.effective_date ?? new Date().toISOString().slice(0, 10),
    });

    const submitImport = (e: React.FormEvent) => {
        e.preventDefault();
        importForm.post(route('admin.payroll.settings.income-tax-monthly-table.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { importForm.reset('file'); setShowImport(false); },
        });
    };

    const submitLookup = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(route('admin.payroll.settings.income-tax-monthly-table'), lookupInput, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const remove = (row: TableRow) => {
        if (!confirm(`${row.name} を削除します。この年分を使って計算した給与を再計算すると税額が変わります。よろしいですか？`)) return;
        router.delete(route('admin.payroll.settings.income-tax-monthly-table.destroy', row.id), { preserveScroll: true });
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">源泉徴収税額表（月額表）</h2>}>
            <Head title="源泉徴収税額表（月額表）" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-5xl space-y-5">
                    <Link href={route('admin.payroll.settings.index')}
                        className="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-800">
                        <i className="fa-solid fa-arrow-left" /> 基本設定へ戻る
                    </Link>

                    {/* 毎年更新のステータス。当年分が無い / 適用中の表が無い場合に目立たせる。 */}
                    <div className={`rounded-2xl p-5 ring-1 ${
                        activeEffectiveFrom && hasCurrentYear
                            ? 'bg-green-50 ring-green-200'
                            : 'bg-amber-50 ring-amber-200'
                    }`}>
                        <div className="flex items-start gap-3">
                            <i className={`fa-solid mt-0.5 text-lg ${
                                activeEffectiveFrom && hasCurrentYear
                                    ? 'fa-circle-check text-green-600'
                                    : 'fa-triangle-exclamation text-amber-600'
                            }`} />
                            <div className="text-sm">
                                <p className="font-bold text-gray-800">
                                    {activeEffectiveFrom && hasCurrentYear
                                        ? `${currentYear}年分の税額表が登録済みです`
                                        : '毎年の更新が必要です'}
                                </p>
                                <ul className="mt-1.5 space-y-1 text-gray-600">
                                    <li>
                                        現在適用中の税額表：
                                        {activeEffectiveFrom
                                            ? <span className="font-semibold text-gray-800">{activeEffectiveFrom} 以降の表</span>
                                            : <span className="font-semibold text-amber-700">なし（電算機計算の特例にフォールバックしています）</span>}
                                    </li>
                                    <li>
                                        {currentYear}年分の登録：
                                        {hasCurrentYear
                                            ? <span className="font-semibold text-gray-800">あり</span>
                                            : <span className="font-semibold text-amber-700">なし。国税庁の最新の月額表を取り込んでください</span>}
                                    </li>
                                    <li>
                                        計算方法の設定（基本設定＞全般）：
                                        <span className="font-semibold text-gray-800">
                                            {calcMethod === 'computer_special' ? '電算機計算の特例' : '税額表（月額表）'}
                                        </span>
                                        {calcMethod === 'computer_special' && (
                                            <span className="ml-1 text-amber-700">→ 月額表は使われません</span>
                                        )}
                                    </li>
                                </ul>
                                <p className="mt-2 text-xs text-gray-500">
                                    国税庁「給与所得の源泉徴収税額表」は毎年（通常12月〜1月）公表されます。年が変わったらこの画面で新しい年分を追加してください。過去分は編集せず追加する運用です（確定済み給与を再計算しても当時の税額が引けます）。
                                </p>
                                <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1">
                                    <a href={route('docs.show', 'annual-master-updates')}
                                        target="_blank" rel="noreferrer"
                                        className="inline-flex items-center gap-1.5 text-xs font-semibold text-teal-700 hover:text-teal-800">
                                        <i className="fa-solid fa-book" />毎年更新が必要な項目の一覧
                                    </a>
                                    <a href="https://www.nta.go.jp/taxes/shiraberu/taxanswer/gensen/2511.htm"
                                        target="_blank" rel="noreferrer"
                                        className="inline-flex items-center gap-1.5 text-xs font-semibold text-teal-700 hover:text-teal-800">
                                        <i className="fa-solid fa-up-right-from-square" />国税庁 源泉徴収税額表のページ
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* 登録済みの年分 */}
                    <div className={card}>
                        <div className="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-3.5">
                            <h3 className="flex items-center gap-2 text-sm font-bold text-gray-800">
                                <i className="fa-solid fa-table-list text-teal-600" /> 登録済みの年分
                            </h3>
                            {canWrite && (
                                <button type="button" onClick={() => setShowImport((v) => !v)}
                                    className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-teal-700">
                                    <i className="fa-solid fa-plus" />年分を追加（CSV取込）
                                </button>
                            )}
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">年分 / 名称</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">適用期間</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">行数</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-gray-500">7人超の控除</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">出典メモ</th>
                                        <th className="px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {tables.map((t) => (
                                        <tr key={t.id} className={t.is_active ? 'bg-teal-50/40' : 'hover:bg-gray-50'}>
                                            <td className="px-4 py-2.5 text-sm">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium text-gray-800">{t.name}</span>
                                                    {t.is_active && (
                                                        <span className="rounded bg-teal-100 px-1.5 py-0.5 text-[10px] font-bold text-teal-700">適用中</span>
                                                    )}
                                                </div>
                                                {t.updated_at && <span className="text-xs text-gray-400">取込 {t.updated_at}</span>}
                                            </td>
                                            <td className="px-4 py-2.5 text-sm tabular-nums text-gray-600">
                                                {t.effective_from} 〜 {t.effective_to ?? '現行'}
                                            </td>
                                            <td className="px-4 py-2.5 text-right text-sm tabular-nums text-gray-700">{yen(t.rows_count)}</td>
                                            <td className="px-4 py-2.5 text-right text-sm tabular-nums text-gray-600">{yen(t.dependent_over7_deduction)}円</td>
                                            <td className="px-4 py-2.5 text-xs text-gray-500">{t.source_note ?? '—'}</td>
                                            <td className="px-4 py-2.5 text-right">
                                                {canWrite && (
                                                    <button type="button" onClick={() => remove(t)}
                                                        className="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-600 transition hover:bg-red-50">
                                                        <i className="fa-solid fa-trash-can" />削除
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {tables.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="px-6 py-10 text-center text-sm text-gray-400">
                                                まだ税額表が登録されていません。国税庁の月額表をCSVで取り込んでください。
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* CSV取込 */}
                    {canWrite && showImport && (
                        <form onSubmit={submitImport} className={card}>
                            <div className="border-b border-gray-100 px-5 py-3.5">
                                <h3 className="flex items-center gap-2 text-sm font-bold text-gray-800">
                                    <i className="fa-solid fa-file-csv text-teal-600" /> 年分を追加（CSV取込）
                                </h3>
                                <p className="mt-1 text-xs text-gray-400">
                                    国税庁の月額表をCSVにして取り込みます。列の並びはテンプレートを参照してください。
                                </p>
                            </div>
                            <div className="space-y-4 p-5">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="sm:col-span-2">
                                        <label className={label}>名称</label>
                                        <input className={input} value={importForm.data.name}
                                            onChange={(e) => importForm.setData('name', e.target.value)} />
                                        {importForm.errors.name && <p className="mt-1 text-xs text-red-600">{importForm.errors.name}</p>}
                                    </div>
                                    <div>
                                        <label className={label}>対象年分（西暦）</label>
                                        <input type="number" className={input} value={importForm.data.target_year}
                                            onChange={(e) => importForm.setData('target_year', Number(e.target.value))} />
                                        {importForm.errors.target_year && <p className="mt-1 text-xs text-red-600">{importForm.errors.target_year}</p>}
                                    </div>
                                    <div>
                                        <label className={label}>扶養7人超の1人あたり控除額（円）</label>
                                        <input type="number" className={input} value={importForm.data.dependent_over7_deduction}
                                            onChange={(e) => importForm.setData('dependent_over7_deduction', Number(e.target.value))} />
                                        <p className="mt-1 text-xs text-gray-400">月額表の注記にある金額（例: 1,610円）</p>
                                    </div>
                                    <div>
                                        <label className={label}>適用開始日</label>
                                        <input type="date" className={input} value={importForm.data.effective_from}
                                            onChange={(e) => importForm.setData('effective_from', e.target.value)} />
                                        {importForm.errors.effective_from && <p className="mt-1 text-xs text-red-600">{importForm.errors.effective_from}</p>}
                                    </div>
                                    <div>
                                        <label className={label}>適用終了日（空欄＝現行）</label>
                                        <input type="date" className={input} value={importForm.data.effective_to}
                                            onChange={(e) => importForm.setData('effective_to', e.target.value)} />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <label className={label}>出典・突合メモ</label>
                                        <input className={input} placeholder="例: 国税庁 令和8年分 月額表 2026-01-15 確認"
                                            value={importForm.data.source_note}
                                            onChange={(e) => importForm.setData('source_note', e.target.value)} />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <label className={label}>CSVの形式</label>
                                        <div className="space-y-2">
                                            {FORMATS.map((f) => (
                                                <label key={f.value} className="flex cursor-pointer items-start gap-2.5">
                                                    <input type="radio" name="format" value={f.value}
                                                        className="mt-0.5 border-gray-300 text-teal-600 focus:ring-teal-500"
                                                        checked={importForm.data.format === f.value}
                                                        onChange={() => importForm.setData('format', f.value)} />
                                                    <span className="text-sm">
                                                        <span className="font-medium text-gray-700">{f.label}</span>
                                                        <span className="block text-xs text-gray-400">{f.hint}</span>
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                    <div className="sm:col-span-2">
                                        <label className={label}>ファイル（Excel または CSV）</label>
                                        <div className="flex flex-wrap items-center gap-3">
                                            <input type="file" accept=".csv,.xlsx,.xls,text/csv"
                                                className="text-sm"
                                                onChange={(e) => importForm.setData('file', e.target.files?.[0] ?? null)} />
                                            <a href={route('admin.payroll.settings.income-tax-monthly-table.template', { format: importForm.data.format })}
                                                className="inline-flex items-center gap-1.5 text-xs font-semibold text-teal-700 hover:text-teal-800">
                                                <i className="fa-solid fa-download" />テンプレートCSV（短いサンプル）
                                            </a>
                                            <a href={route('admin.payroll.settings.income-tax-monthly-table.template', { format: 'xlsx' })}
                                                className="inline-flex items-center gap-1.5 text-xs font-semibold text-teal-700 hover:text-teal-800">
                                                <i className="fa-solid fa-download" />Excelテンプレート（232階級）
                                            </a>
                                        </div>
                                        <p className="mt-1 text-xs text-gray-400">
                                            Excelは全シートを続けて読み込みます。同じ適用開始日で何度でも追加取込でき、同じ階級は上書きされます。
                                        </p>
                                        {importForm.errors.file && <p className="mt-1 text-xs text-red-600">{importForm.errors.file}</p>}
                                    </div>
                                </div>

                                <div className="rounded-xl bg-gray-50 p-4">
                                    {importForm.data.format === 'wide' ? (
                                        <>
                                            <p className="mb-2 text-xs font-semibold text-gray-600">列（左から順に）</p>
                                            <p className="font-mono text-xs text-gray-700">{csvWideColumns.join(' , ')}</p>
                                            <ul className="mt-2 space-y-1 text-xs text-gray-500">
                                                <li>金額で始まらない行（見出し・注記）は自動で読み飛ばします。</li>
                                                <li>「未満」が空欄の階級は、次に大きい階級の下限で自動的に閉じます。</li>
                                                <li>空欄のマスは登録しません。あとから不足分だけ追加取込できます。</li>
                                                <li>
                                                    末尾2列の<span className="font-medium text-gray-600">加算率</span>は 740,000円以上の高額帯だけに使います。
                                                    原表が「〇〇円の税額に、△円を超える金額の□％を加算」と書いている階級は、税額の欄に基準額を入れ、加算率に <span className="font-mono">20.42%</span> と書いてください。
                                                </li>
                                                <li>上限も加算率も無い階級は、金額全体に定額を当ててしまうため取り込みません（件数をお知らせします）。</li>
                                            </ul>
                                        </>
                                    ) : (
                                        <>
                                            <p className="mb-2 text-xs font-semibold text-gray-600">CSVの列</p>
                                            <dl className="grid gap-1.5 text-xs sm:grid-cols-2">
                                                {csvColumns.map((c) => (
                                                    <div key={c} className="flex gap-2">
                                                        <dt className="w-28 flex-none font-mono font-semibold text-gray-700">{c}</dt>
                                                        <dd className="text-gray-500">{CSV_HELP[c]}</dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        </>
                                    )}
                                </div>

                                <div className="flex justify-end gap-2">
                                    <button type="button" onClick={() => setShowImport(false)}
                                        className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                                        キャンセル
                                    </button>
                                    <button type="submit" disabled={importForm.processing}
                                        className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-6 py-2 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                                        <i className="fa-solid fa-upload" />取り込む
                                    </button>
                                </div>
                            </div>
                        </form>
                    )}

                    {/* 検算ツール */}
                    <form onSubmit={submitLookup} className={card}>
                        <div className="border-b border-gray-100 px-5 py-3.5">
                            <h3 className="flex items-center gap-2 text-sm font-bold text-gray-800">
                                <i className="fa-solid fa-calculator text-teal-600" /> 検算ツール
                            </h3>
                            <p className="mt-1 text-xs text-gray-400">
                                社会保険料等控除後の金額を入れて、月額表と電算機特例の税額を比べます。MFクラウドの明細と突合するときに使ってください。
                            </p>
                        </div>
                        <div className="space-y-4 p-5">
                            <div className="grid gap-4 sm:grid-cols-4">
                                <div>
                                    <label className={label}>社保控除後の給与（円）</label>
                                    <input type="number" className={input} value={lookupInput.after_social_insurance}
                                        onChange={(e) => setLookupInput({ ...lookupInput, after_social_insurance: Number(e.target.value) })} />
                                </div>
                                <div>
                                    <label className={label}>扶養親族等の数</label>
                                    <input type="number" min={0} className={input} value={lookupInput.dependents}
                                        onChange={(e) => setLookupInput({ ...lookupInput, dependents: Number(e.target.value) })} />
                                </div>
                                <div>
                                    <label className={label}>税額表</label>
                                    <select className={input} value={lookupInput.tax_table}
                                        onChange={(e) => setLookupInput({ ...lookupInput, tax_table: e.target.value })}>
                                        <option value="kou">甲欄</option>
                                        <option value="otsu">乙欄</option>
                                    </select>
                                </div>
                                <div>
                                    <label className={label}>適用日</label>
                                    <input type="date" className={input} value={lookupInput.effective_date}
                                        onChange={(e) => setLookupInput({ ...lookupInput, effective_date: e.target.value })} />
                                </div>
                            </div>
                            <div className="flex justify-end">
                                <button type="submit"
                                    className="inline-flex items-center gap-2 rounded-lg border border-teal-600 px-5 py-2 text-sm font-semibold text-teal-700 transition hover:bg-teal-50">
                                    <i className="fa-solid fa-magnifying-glass" />税額を引く
                                </button>
                            </div>

                            {lookup && (
                                <div className="rounded-xl bg-gray-50 p-4 text-sm">
                                    <p className="mb-2 text-xs text-gray-500">
                                        社保控除後 {yen(lookup.input.after_social_insurance)}円 ・ 扶養{lookup.input.dependents}人 ・
                                        {lookup.input.tax_table === 'otsu' ? '乙欄' : '甲欄'} ・ {lookup.input.effective_date}
                                    </p>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                                            <p className="text-xs text-gray-500">月額表</p>
                                            <p className="text-lg font-bold tabular-nums text-gray-900">
                                                {lookup.monthly_table_found ? `${yen(lookup.monthly_table)}円` : '—'}
                                            </p>
                                            <p className="mt-0.5 text-[11px] text-gray-400">
                                                {lookup.monthly_table_found
                                                    ? lookup.monthly_table_source
                                                    : 'この適用日の月額表が未登録のため引けません'}
                                            </p>
                                        </div>
                                        <div className="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                                            <p className="text-xs text-gray-500">電算機計算の特例</p>
                                            <p className="text-lg font-bold tabular-nums text-gray-900">{yen(lookup.computer_special)}円</p>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </form>
                </div>
            </div>
        </AdminLayout>
    );
}
