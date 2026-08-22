<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <style>
        @include('payslips.partials.pdf-fonts')
        body { margin: 0; padding: 28px; color: #1f2937; font-size: 11px; }
        .doc-title { text-align: center; font-size: 16px; font-weight: bold; margin-bottom: 2px; }
        .doc-sub { text-align: center; font-size: 10px; color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #4b5563; padding: 6px 9px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; width: 150px; }
        td.num { text-align: right; }
        .amounts th { text-align: center; width: auto; background: #f0fdfa; color: #0f766e; }
        .badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 9px; }
        .badge-adj { background: #dcfce7; color: #166534; }
        .badge-prov { background: #fef3c7; color: #92400e; }
        .grid { width: 100%; border-collapse: collapse; }
        .grid th { width: 25%; }
        .note { font-size: 9px; color: #9ca3af; margin-top: 10px; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="doc-title">令和{{ $reiwa }}年分 給与所得の源泉徴収票</div>
    <div class="doc-sub">
        @if(!empty($retiree))（退職者用）@endif
        @if($adjusted)
            <span class="badge badge-adj">年末調整済（{{ $status_label }}）</span>
        @else
            <span class="badge badge-prov">年末調整未実施（速報値）</span>
        @endif
    </div>

    <table>
        <tr><th>支払を受ける者 住所</th><td>〒{{ $postal_code ?? '' }}　{{ $address ?? '' }}</td></tr>
        <tr><th>氏名</th><td>{{ $name }}（{{ $employee_no ?? '—' }}）</td></tr>
        <tr><th>生年月日</th><td>{{ $birth_date ?? '' }}</td></tr>
        <tr><th>支払者（事業所）</th><td>{{ $business_location ?? '' }}</td></tr>
    </table>

    <table class="amounts">
        <thead>
            <tr>
                <th>種別</th>
                <th>支払金額</th>
                <th>給与所得控除後の金額</th>
                <th>所得控除の額の合計額</th>
                <th>源泉徴収税額</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>給与・賞与</td>
                <td class="num">¥{{ number_format($payment) }}</td>
                <td class="num">{{ $salary_income !== null ? '¥'.number_format($salary_income) : '—' }}</td>
                <td class="num">{{ $income_deductions_total !== null ? '¥'.number_format($income_deductions_total) : '—' }}</td>
                <td class="num">¥{{ number_format($income_tax) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="grid">
        <tr>
            <th>社会保険料等の金額</th>
            <td class="num">¥{{ number_format($social) }}</td>
            <th>生命保険料の控除額</th>
            <td class="num">¥{{ number_format($life_insurance) }}</td>
        </tr>
        <tr>
            <th>地震保険料の控除額</th>
            <td class="num">¥{{ number_format($earthquake_insurance) }}</td>
            <th>住宅借入金等特別控除の額</th>
            <td class="num">¥{{ number_format($housing_loan_credit) }}</td>
        </tr>
        <tr>
            <th>配偶者(特別)控除の額</th>
            <td class="num">¥{{ number_format($spouse_deduction) }}</td>
            <th>控除対象扶養親族の数</th>
            <td>{{ $dependent_count }} 人</td>
        </tr>
    </table>

    @if($adjusted)
        <div class="note">
            ※ 本票は年末調整の結果を反映しています。源泉徴収税額は年調年税額（復興特別所得税込）です。
            実際に給与・賞与から源泉徴収した合計額は ¥{{ number_format($withheld) }} で、差額は過不足として調整済みです。
        </div>
    @else
        <div class="note">
            ※ 本票は給与計算確定データを年次集計した速報値です。給与所得控除後の金額・所得控除の額の合計額・
            年末調整の結果等は、年末調整を確定すると自動的に反映されます。
        </div>
    @endif
</body>
</html>
