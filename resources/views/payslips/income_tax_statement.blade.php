<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <style>
        @include('payslips.partials.pdf-fonts')
        body { margin: 0; padding: 28px; color: #1f2937; font-size: 11px; }
        .header { border-bottom: 2px solid #0d9488; padding-bottom: 8px; margin-bottom: 16px; }
        .title { font-size: 16px; font-weight: bold; color: #0f766e; }
        .meta { margin-top: 3px; font-size: 10px; color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px 10px; border: 1px solid #d1d5db; text-align: right; }
        th { background: #f0fdfa; color: #0f766e; text-align: center; }
        td.label { text-align: left; font-weight: bold; background: #f9fafb; }
        .total-row td { border-top: 2px solid #0d9488; font-weight: bold; background: #f9fafb; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">所得税徴収高計算書{{ $mode === 'special' ? '（納期特例）' : '' }}</div>
        <div class="meta">対象期間: {{ $periodLabel }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="text-align:left">区分</th>
                <th>支給人員</th>
                <th>支給額</th>
                <th>税額</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="label">俸給・給料等</td>
                <td>{{ number_format($result['salary']['count']) }} 人</td>
                <td>¥{{ number_format($result['salary']['amount']) }}</td>
                <td>¥{{ number_format($result['salary']['tax']) }}</td>
            </tr>
            <tr>
                <td class="label">賞与（役員賞与を除く）</td>
                <td>{{ number_format($result['bonus']['count']) }} 人</td>
                <td>¥{{ number_format($result['bonus']['amount']) }}</td>
                <td>¥{{ number_format($result['bonus']['tax']) }}</td>
            </tr>
            <tr class="total-row">
                <td class="label">合計（本税）</td>
                <td>{{ number_format($result['total']['count']) }} 人</td>
                <td>¥{{ number_format($result['total']['amount']) }}</td>
                <td>¥{{ number_format($result['total']['tax']) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
