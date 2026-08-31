<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    @include('payslips.partials.robots-noindex')
    <style>
        @include('payslips.partials.pdf-fonts')
        body { margin: 0; padding: 20px; color: #1f2937; font-size: 10px; }
        .header { border-bottom: 2px solid #0d9488; padding-bottom: 8px; margin-bottom: 12px; }
        .title { font-size: 16px; font-weight: bold; color: #0f766e; }
        .meta { margin-top: 3px; font-size: 9px; color: #6b7280; }
        .meta span { margin-right: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f0fdfa; color: #0f766e; font-size: 9px; }
        td.num { text-align: right; }
        .total-row td { border-top: 2px solid #0d9488; font-weight: bold; background: #f9fafb; }
        .warn { color: #dc2626; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">給与振込一覧表</div>
        <div class="meta">
            <span>対象期間: {{ $period }}</span>
            @if($paymentDate)<span>支給日: {{ $paymentDate }}</span>@endif
            @if($businessLocation)<span>事業所: {{ $businessLocation }}</span>@endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>従業員名</th>
                <th>振込先金融機関</th>
                <th>支店</th>
                <th>種目</th>
                <th>口座番号</th>
                <th>口座名義人</th>
                <th style="text-align:right">振込額</th>
                <th>備考</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $r)
                <tr>
                    <td>{{ $r['user_name'] }}</td>
                    <td>{{ $r['bank_name'] }}</td>
                    <td>{{ $r['branch_name'] }}</td>
                    <td>{{ $r['account_type'] }}</td>
                    <td>{{ $r['account_number'] }}</td>
                    <td>{{ $r['account_holder_kana'] }}</td>
                    <td class="num">¥{{ number_format($r['amount']) }}</td>
                    <td class="warn">{{ $r['remark'] }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="6">合計</td>
                <td class="num">¥{{ number_format($total) }}</td>
                <td>{{ $count }}件</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
