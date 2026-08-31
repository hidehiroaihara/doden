<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    @include('payslips.partials.robots-noindex')
    <style>
        @include('payslips.partials.pdf-fonts')
        body { margin: 0; padding: 16px; color: #1f2937; font-size: 8px; }
        .header { border-bottom: 2px solid #0d9488; padding-bottom: 6px; margin-bottom: 10px; }
        .title { font-size: 15px; font-weight: bold; color: #0f766e; }
        .meta { margin-top: 3px; font-size: 9px; color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 2px 4px; border: 1px solid #e5e7eb; text-align: right; }
        th { background: #f0fdfa; color: #0f766e; font-size: 7px; text-align: center; }
        th .period { display: block; font-weight: normal; color: #9ca3af; font-size: 6px; }
        td.label, th.label { text-align: left; background: #f9fafb; font-weight: bold; white-space: nowrap; }
        .section td.section-title { text-align: left; background: #ccfbf1; color: #0f766e; font-weight: bold; }
    </style>
</head>
<body>
    @php
        $months = $matrix['months'];
        $emp = $matrix['employee'];
        $colspan = count($months) + 2;
        $fmt = function ($v, $format) {
            if ($format === 'text') {
                return is_string($v) ? $v : '';
            }
            $n = (float) $v;
            if (! $n) {
                return '';
            }
            return match ($format) {
                'yen' => number_format($n),
                'hours' => number_format($n, 2),
                'days' => number_format($n, 1),
                'count' => (string) (int) $n,
                default => (string) $n,
            };
        };
    @endphp
    <div class="header">
        <div class="title">賃金台帳</div>
        <div class="meta">
            {{ $periodLabel }} ／ {{ $emp['name'] ?? $userName }}
            @if(!empty($emp['employee_no'])) ／ No.{{ $emp['employee_no'] }} @endif
            @if(!empty($emp['business_location'])) ／ {{ $emp['business_location'] }} @endif
            ／ {{ $emp['pay_type_label'] }} ／ {{ $emp['tax_table_label'] }} ／ 扶養 {{ $emp['dependents_count'] }} 人
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="label">項目</th>
                @foreach($months as $mo)
                    <th>{{ $mo['label'] }}<span class="period">{{ $mo['period'] }}</span></th>
                @endforeach
                <th>合計</th>
            </tr>
        </thead>
        <tbody>
            @foreach($matrix['sections'] as $section)
                <tr class="section"><td class="section-title" colspan="{{ $colspan }}">{{ $section['title'] }}</td></tr>
                @foreach($section['rows'] as $row)
                    <tr>
                        <td class="label">{{ $row['name'] }}</td>
                        @foreach($months as $mo)
                            <td>{{ $fmt($row['values'][$mo['month']] ?? 0, $row['format']) }}</td>
                        @endforeach
                        <td>{{ $fmt($row['total'], $row['format']) }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</body>
</html>
