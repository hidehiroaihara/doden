<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    @include('payslips.partials.robots-noindex')
    <style>
        @include('payslips.partials.pdf-fonts')
        body { margin: 0; padding: 20px; color: #1f2937; font-size: 9px; }
        .header { border-bottom: 2px solid #0d9488; padding-bottom: 6px; margin-bottom: 8px; }
        .title { font-size: 15px; font-weight: bold; color: #0f766e; }
        .title .tag { font-size: 10px; border: 1px solid #0f766e; border-radius: 999px; padding: 1px 8px; margin-left: 8px; }
        .meta { margin-top: 3px; font-size: 9px; color: #6b7280; }
        .meta span { margin-right: 14px; }
        h3 { font-size: 10px; color: #0f766e; margin: 12px 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3px 5px; border: 1px solid #e5e7eb; text-align: right; }
        th { background: #f0fdfa; color: #0f766e; font-size: 8px; text-align: center; }
        td.c { text-align: center; }
        .total td { background: #f9fafb; font-weight: bold; }
        .note { margin-top: 10px; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">源泉徴収簿 <span class="tag">{{ $book['employee']['tax_table_label'] }}</span></div>
        <div class="meta">
            <span>{{ $year }}年分</span>
            <span>氏名: {{ $book['employee']['name'] }}</span>
            @if($book['employee']['employee_no'])<span>整理番号: {{ $book['employee']['employee_no'] }}</span>@endif
            <span>扶養親族等の数: {{ $book['employee']['dependents'] }}</span>
            @if($book['employee']['business_location'])<span>所属: {{ $book['employee']['business_location'] }}</span>@endif
        </div>
    </div>

    <h3>給料・手当等</h3>
    <table>
        <thead>
            <tr>
                <th>月</th><th>支給月日</th><th>総支給金額</th><th>社会保険料等の控除額</th>
                <th>社保控除後の給与等の金額</th><th>扶養親族等の数</th><th>算出税額</th>
            </tr>
        </thead>
        <tbody>
            @foreach($book['salary']['rows'] as $r)
                <tr>
                    <td class="c">{{ $r['month'] }}</td>
                    <td class="c">{{ $r['payment_date'] ?? '' }}</td>
                    <td>{{ number_format($r['gross']) }}</td>
                    <td>{{ number_format($r['social']) }}</td>
                    <td>{{ number_format($r['after_social']) }}</td>
                    <td class="c">{{ $r['dependents'] }}</td>
                    <td>{{ number_format($r['tax']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td class="c" colspan="2">計</td>
                <td>{{ number_format($book['salary']['totals']['gross']) }}</td>
                <td>{{ number_format($book['salary']['totals']['social']) }}</td>
                <td>{{ number_format($book['salary']['totals']['after']) }}</td>
                <td></td>
                <td>{{ number_format($book['salary']['totals']['tax']) }}</td>
            </tr>
        </tbody>
    </table>

    <h3>賞与等</h3>
    <table>
        <thead>
            <tr>
                <th>支給期間</th><th>支給月日</th><th>総支給金額</th><th>社会保険料等の控除額</th>
                <th>社保控除後の金額</th><th>税率(%)</th><th>算出税額</th>
            </tr>
        </thead>
        <tbody>
            @forelse($book['bonus']['rows'] as $r)
                <tr>
                    <td class="c">{{ $r['period'] }}</td>
                    <td class="c">{{ $r['payment_date'] ?? '' }}</td>
                    <td>{{ number_format($r['gross']) }}</td>
                    <td>{{ number_format($r['social']) }}</td>
                    <td>{{ number_format($r['after_social']) }}</td>
                    <td class="c">{{ number_format($r['rate'], 2) }}</td>
                    <td>{{ number_format($r['tax']) }}</td>
                </tr>
            @empty
                <tr><td class="c" colspan="7">賞与の支給はありません</td></tr>
            @endforelse
            @if(count($book['bonus']['rows']) > 0)
                <tr class="total">
                    <td class="c" colspan="2">計</td>
                    <td>{{ number_format($book['bonus']['totals']['gross']) }}</td>
                    <td>{{ number_format($book['bonus']['totals']['social']) }}</td>
                    <td>{{ number_format($book['bonus']['totals']['after']) }}</td>
                    <td></td>
                    <td>{{ number_format($book['bonus']['totals']['tax']) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="note">
        ※ 本帳票は源泉徴収簿の左側（月次実績）のみを給与計算確定データから自動反映しています。
        右側（扶養控除等の申告・各種控除額、年末調整①〜㉞の計算）は年末調整の確定結果に基づき別途記入してください。
    </div>
</body>
</html>
