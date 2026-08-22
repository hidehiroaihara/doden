import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import { Head, Link, useForm } from '@inertiajs/react';

interface Preview {
    gross: number;
    salary_income: number;
    dependent_deduction: number;
    income_deductions_total: number;
    taxable_income: number;
    calculated_tax: number;
    housing_loan_credit_applied: number;
    yearly_tax: number;
    withheld_tax: number;
    adjustment_amount: number;
}

interface Props {
    year: number;
    employee: { id: number; name: string; employee_no: string | null; tax_table: string };
    aggregate: { gross: number; social: number; withheld: number };
    inputs: {
        social_insurance_declared: number;
        life_insurance_deduction: number;
        earthquake_insurance_deduction: number;
        spouse_deduction: number;
        dependent_count: number;
        housing_loan_credit: number;
        other_deduction: number;
    };
    preview: Preview;
    record: { id: number; status: string; status_label: string; reflected_run_id: number | null } | null;
    runs: { id: number; label: string }[];
}

const yen = (v: number) => (v || 0).toLocaleString();

export default function YearEndEdit({ year, employee, aggregate, inputs, preview, record, runs }: Props) {
    const canWrite = useAdminPermission('payroll');
    const form = useForm({ ...inputs, year });
    const { data, setData, processing } = form;
    const reflectForm = useForm({ run_id: record?.reflected_run_id ?? runs[0]?.id ?? '' });

    const save = (confirm: boolean) => {
        form.transform((d) => ({ ...d, confirm }));
        form.post(route('admin.payroll.year-end.update', { user: employee.id }), { preserveScroll: true });
    };

    const reflect = () => {
        if (!record) return;
        if (!window.confirm('過不足税額を選択した給与バッチへ反映します。よろしいですか？')) return;
        reflectForm.post(route('admin.payroll.year-end.reflect', { adjustment: record.id }), { preserveScroll: true });
    };

    const numField = (label: string, field: keyof typeof inputs, hint?: string) => (
        <div>
            <label className="mb-1 block text-xs font-medium text-gray-500">{label}</label>
            <input type="number" min={0} value={(data as any)[field]}
                onChange={(e) => setData(field as any, e.target.value === '' ? 0 : Number(e.target.value))}
                className="w-full rounded-lg border-gray-300 text-right text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500" />
            {hint && <p className="mt-1 text-[10px] text-gray-400">{hint}</p>}
        </div>
    );

    const adj = preview.adjustment_amount;

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">年末調整 — {employee.name}</h2>}>
            <Head title={`年末調整 — ${employee.name}`} />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-5xl space-y-5">
                    <div className="flex items-center justify-between">
                        <Link href={route('admin.payroll.year-end.index', { year })}
                            className="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-800">
                            <i className="fa-solid fa-arrow-left" /> 年末調整一覧へ戻る
                        </Link>
        <div className="flex items-center gap-3">
                            <a href={route('admin.payroll.year-end.slip', { user: employee.id, year })} target="_blank" rel="noopener noreferrer"
                                className="inline-flex items-center gap-1.5 rounded-lg border border-teal-600 px-3 py-1.5 text-sm font-semibold text-teal-700 transition hover:bg-teal-50">
                                <i className="fa-solid fa-file-pdf" /> 源泉徴収票PDF
                            </a>
                            <div className="text-sm text-gray-500">
                                {year}年分 ・ {employee.employee_no ?? '—'}
                                {record && <span className="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600">{record.status_label}</span>}
                            </div>
                        </div>
                    </div>

                    {employee.tax_table !== 'kou' && (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            <i className="fa-solid fa-triangle-exclamation mr-1.5" />
                            税額表区分が甲欄以外の従業員です。年末調整の対象外の可能性があります。
                        </div>
                    )}

                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                        {/* 集計値 + 入力 */}
                        <div className="space-y-5">
                            <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                                <h3 className="mb-3 text-sm font-bold text-gray-700">給与計算からの集計（{year}年）</h3>
                                <dl className="grid grid-cols-3 gap-3 text-sm">
                                    <div><dt className="text-xs text-gray-400">給与総額</dt><dd className="tabular-nums font-semibold text-gray-800">{yen(aggregate.gross)}</dd></div>
                                    <div><dt className="text-xs text-gray-400">社会保険料</dt><dd className="tabular-nums font-semibold text-gray-800">{yen(aggregate.social)}</dd></div>
                                    <div><dt className="text-xs text-gray-400">徴収済所得税</dt><dd className="tabular-nums font-semibold text-gray-800">{yen(aggregate.withheld)}</dd></div>
                                </dl>
                            </div>

                            <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                                <h3 className="mb-4 text-sm font-bold text-gray-700">申告控除の入力</h3>
                                <div className="grid grid-cols-2 gap-4">
                                    {numField('扶養控除対象人数', 'dependent_count', '一般扶養1人38万円で計算')}
                                    {numField('配偶者(特別)控除', 'spouse_deduction', '対象なら38万円等')}
                                    {numField('生命保険料控除', 'life_insurance_deduction')}
                                    {numField('地震保険料控除', 'earthquake_insurance_deduction')}
                                    {numField('社会保険料控除(申告分)', 'social_insurance_declared', '給与天引き外')}
                                    {numField('その他の所得控除', 'other_deduction')}
                                    {numField('住宅借入金等特別控除', 'housing_loan_credit', '税額控除')}
                                </div>
                                {canWrite && (
                                    <div className="mt-5 flex items-center gap-2">
                                        <button onClick={() => save(false)} disabled={processing}
                                            className="inline-flex items-center gap-2 rounded-lg border border-teal-600 px-4 py-2 text-sm font-semibold text-teal-700 transition hover:bg-teal-50 disabled:opacity-50">
                                            <i className="fa-solid fa-calculator" /> 計算する（下書き）
                                        </button>
                                        <button onClick={() => save(true)} disabled={processing}
                                            className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                                            <i className="fa-solid fa-check" /> 計算して確定
                                        </button>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* 計算結果 */}
                        <div className="space-y-5">
                            <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                                <h3 className="mb-3 text-sm font-bold text-gray-700">年調計算結果</h3>
                                <dl className="space-y-2 text-sm">
                                    {[
                                        ['給与所得控除後の金額', preview.salary_income],
                                        ['所得控除の合計額', preview.income_deductions_total],
                                        ['課税給与所得金額', preview.taxable_income],
                                        ['算出所得税額', preview.calculated_tax],
                                        ['住宅ローン控除適用額', preview.housing_loan_credit_applied],
                                        ['年調年税額（復興税込）', preview.yearly_tax],
                                        ['徴収済所得税', preview.withheld_tax],
                                    ].map(([label, val]) => (
                                        <div key={label as string} className="flex items-center justify-between border-b border-gray-50 pb-1.5">
                                            <dt className="text-gray-500">{label}</dt>
                                            <dd className="tabular-nums font-medium text-gray-800">{yen(val as number)} 円</dd>
                                        </div>
                                    ))}
                                </dl>

                                <div className={`mt-4 rounded-xl px-4 py-3 ${adj > 0 ? 'bg-red-50' : adj < 0 ? 'bg-teal-50' : 'bg-gray-50'}`}>
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm font-bold text-gray-700">過不足税額</span>
                                        <span className={`text-lg font-bold tabular-nums ${adj > 0 ? 'text-red-600' : adj < 0 ? 'text-teal-700' : 'text-gray-600'}`}>
                                            {adj > 0 ? `不足（追徴） ${yen(adj)} 円` : adj < 0 ? `超過（還付） ${yen(-adj)} 円` : '過不足なし'}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-[11px] text-gray-500">
                                        {adj > 0 ? '控除項目「年調過不足税額」として加算されます。' : adj < 0 ? '控除項目「年調過不足税額」として減算（還付）されます。' : ''}
                                    </p>
                                </div>
                            </div>

                            {/* 給与反映 */}
                            <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                                <h3 className="mb-3 text-sm font-bold text-gray-700">給与バッチへ反映</h3>
                                {!record ? (
                                    <p className="text-sm text-gray-400">先に「計算する」を実行してください。</p>
                                ) : (
                                    <>
                                        <p className="mb-3 text-xs text-gray-500">過不足税額を選択した給与バッチの控除「年調過不足税額」へ反映します（手入力上書き扱い）。</p>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <select value={reflectForm.data.run_id} onChange={(e) => reflectForm.setData('run_id', Number(e.target.value))}
                                                className="flex-1 rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                                {runs.map((r) => <option key={r.id} value={r.id}>{r.label}</option>)}
                                            </select>
                                            {canWrite && (
                                                <button onClick={reflect} disabled={reflectForm.processing || runs.length === 0}
                                                    className="inline-flex items-center gap-2 rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-600 disabled:opacity-50">
                                                    <i className="fa-solid fa-share" /> 反映する
                                                </button>
                                            )}
                                        </div>
                                        {record.status === 'reflected' && (
                                            <p className="mt-2 text-xs text-green-700"><i className="fa-solid fa-circle-check mr-1" />反映済みです。再反映すると金額を上書きします。</p>
                                        )}
                                    </>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
