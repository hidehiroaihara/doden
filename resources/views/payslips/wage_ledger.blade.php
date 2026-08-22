<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <style>
        @include('payslips.partials.pdf-fonts')
        body { margin: 0; padding: 16px; color: #1f2937; font-size: 8px; }
        .header { border-bottom: 2px solid #0d9488; padding-bottom: 6px; margin-bottom: 10px; }
        .title { font-size: 15px; font-weight: bold; color: #0f766e; }
        .meta { margin-top: 3px; font-size: 9px; color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 2px 4px; border: 1px solid #e5e7eb; text-align: right; }
        th { background: #f0fdfa; color: #0f766e; font-size: 7px; text-align: center; }
        td.label, th.label { text-align: left; background: #f9fafb; font-weight: bold; white-space: nowrap; }
        .section td.section-title { text-align: left; background: #ccfbf1; color: #0f766e; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">賃金台帳</div>
        <div class="meta">{{ $year }}年1月1日〜{{ $year }}年12月31日 ／ {{ $userName }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="label">項目</th>
                @foreach($matrix['months'] as $i => $m)
                    <th>{{ $i + 1 }}月度</th>
                @endforeach
                <th>合計</th>
            </tr>
        </thead>
        <tbody>
            @foreach($matrix['sections'] as $section)
                <tr class="section"><td class="section-title" colspan="14">{{ $section['title'] }}</td></tr>
                @foreach($section['rows'] as $row)
                    <tr>
                        <td class="label">{{ $row['name'] }}</td>
                        @for($m = 1; $m <= 12; $m++)
                            <td>{{ $row['is_time'] ? number_format((float) ($row['values'][$m] ?? 0), 1) : number_format((int) ($row['values'][$m] ?? 0)) }}</td>
                        @endfor
                        <td>{{ $row['is_time'] ? number_format((float) $row['total'], 1) : number_format((int) $row['total']) }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</body>
</html>
