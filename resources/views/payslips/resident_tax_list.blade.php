<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <style>
        @include('payslips.partials.pdf-fonts')
        body { margin: 0; padding: 24px; color: #1f2937; font-size: 11px; }
        .header { border-bottom: 2px solid #0d9488; padding-bottom: 8px; margin-bottom: 14px; }
        .title { font-size: 16px; font-weight: bold; color: #0f766e; }
        .meta { margin-top: 3px; font-size: 9px; color: #6b7280; }
        .meta span { margin-right: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f0fdfa; color: #0f766e; font-size: 10px; }
        td.num { text-align: right; }
        .total-row td { border-top: 2px solid #0d9488; font-weight: bold; background: #f9fafb; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">住民税徴収額一覧表</div>
        <div class="meta">
            <span>対象期間: {{ $period }}</span>
            @if($businessLocation)<span>事業所: {{ $businessLocation }}</span>@endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>市区町村</th>
                <th>指定番号</th>
                <th style="text-align:right">人数</th>
                <th style="text-align:right">納付額</th>
            </tr>
        </thead>
        <tbody>
            @foreach($groups as $g)
                <tr>
                    <td>{{ $g['municipality'] }}</td>
                    <td>{{ $g['designation_number'] }}</td>
                    <td class="num">{{ $g['count'] }}</td>
                    <td class="num">¥{{ number_format($g['amount']) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="3">合計</td>
                <td class="num">¥{{ number_format($total) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
