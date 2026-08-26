<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>所得税徴収高計算書</title>
    <style>
        @include('payslips.partials.pdf-fonts')
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: {{ !empty($preview) ? '12px' : '10mm' }};
            background: #fff;
            color: #111;
            font-family: "Noto Sans JP", "Hiragino Sans", "Yu Gothic", sans-serif;
            font-size: 10px;
        }
        @media print {
            body { padding: 8mm; }
            .no-print { display: none !important; }
        }
        .sheet { max-width: 190mm; margin: 0 auto; }
        .header { border-bottom: 2px solid #374151; padding-bottom: 8px; margin-bottom: 10px; }
        .header-top { display: flex; justify-content: space-between; align-items: flex-start; }
        .title { font-size: 16px; font-weight: 700; letter-spacing: 0.05em; }
        .subtitle { font-size: 9px; color: #6b7280; margin-top: 2px; }
        .badge {
            display: inline-block;
            border: 1px solid #374151;
            padding: 2px 8px;
            font-size: 9px;
            font-weight: 600;
        }
        .meta { margin-top: 8px; display: grid; grid-template-columns: 1fr 1fr; gap: 4px 12px; font-size: 9px; }
        .meta dt { color: #6b7280; float: left; clear: left; width: 72px; }
        .meta dd { margin: 0 0 3px 76px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { border: 1px solid #9ca3af; padding: 4px 6px; vertical-align: middle; }
        th { background: #f3f4f6; font-weight: 600; text-align: center; font-size: 9px; }
        td.label { text-align: left; font-size: 9px; white-space: nowrap; }
        td.num { text-align: right; font-size: 10px; }
        td.date { text-align: center; font-size: 9px; }
        .totals { width: 55%; margin-left: auto; margin-bottom: 10px; }
        .totals td.label { text-align: right; font-weight: 600; background: #f9fafb; }
        .section-label { font-size: 9px; font-weight: 600; margin: 8px 0 4px; color: #374151; }
        .footer-box { border: 1px solid #9ca3af; padding: 8px; margin-top: 6px; }
        .footer-box dt { float: left; clear: left; width: 64px; color: #6b7280; font-size: 9px; }
        .footer-box dd { margin: 0 0 4px 68px; font-size: 9px; min-height: 14px; }
        .remarks { min-height: 36px; white-space: pre-wrap; line-height: 1.4; }
    </style>
</head>
<body>
    @php
        $r = $report;
        $fmtDate = function (?string $d) {
            if (!$d) return '';
            try { return \Illuminate\Support\Carbon::parse($d)->format('Y/m/d'); } catch (\Throwable) { return $d; }
        };
        $fmtCount = fn (int $n) => $n > 0 ? (string) $n : '';
        $fmtAmount = fn (int $n) => $n > 0 ? number_format($n) : '';
        $fmtTotal = fn (int $n) => number_format($n);
        $rows = [
            ['label' => '俸給・給料等', 'data' => $r['salary']],
            ['label' => '賞与', 'data' => $r['bonus']],
            ['label' => '日雇労務者の賃金', 'data' => $r['daily_worker']],
            ['label' => '退職手当等', 'data' => $r['retirement']],
            ['label' => '税理士等の報酬', 'data' => $r['professional_fee']],
        ];
    @endphp

    @if(!empty($preview))
        <div class="no-print" style="max-width:190mm;margin:0 auto 8px;text-align:right;">
            <button type="button" onclick="window.print()" style="padding:6px 14px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:12px;">印刷</button>
        </div>
    @endif

    <div class="sheet">
        <div class="header">
            <div class="header-top">
                <div>
                    <div class="title">所得税徴収高計算書</div>
                    <div class="subtitle">給与所得・退職所得等</div>
                </div>
                <span class="badge">{{ $r['form_type_label'] }}</span>
            </div>
            <dl class="meta">
                <dt>対象期間</dt><dd>{{ $r['period_label'] }}</dd>
                <dt>年度</dt><dd>令和{{ $r['reiwa'] }}年</dd>
                <dt>税務署名</dt><dd>{{ $r['tax_office_name'] ?: '—' }}</dd>
                <dt>整理番号</dt><dd>{{ $r['reference_number'] ?: '—' }}</dd>
                <dt>署番号</dt><dd>{{ $r['tax_office_sign'] ?: '—' }}</dd>
                <dt>税務署番号</dt><dd>{{ $r['tax_office_number'] ?: '—' }}</dd>
            </dl>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:28%;">区分</th>
                    <th style="width:16%;">支払年月日</th>
                    <th style="width:10%;">人員</th>
                    <th style="width:23%;">支給額</th>
                    <th style="width:23%;">税額</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    @php $d = $row['data']; @endphp
                    <tr>
                        <td class="label">{{ $row['label'] }}</td>
                        <td class="date">{{ $fmtDate($d['payment_date'] ?? null) }}</td>
                        <td class="num">{{ $fmtCount((int)($d['employee_count'] ?? 0)) }}</td>
                        <td class="num">{{ $fmtAmount((int)($d['payment_amount'] ?? 0)) }}</td>
                        <td class="num">{{ $fmtAmount((int)($d['tax_amount'] ?? 0)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tbody>
                <tr>
                    <td class="label" colspan="2">年末調整による不足税額</td>
                    <td class="num">{{ $fmtAmount((int)$r['year_end_adjustment_shortage']) }}</td>
                </tr>
                <tr>
                    <td class="label" colspan="2">年末調整による超過税額</td>
                    <td class="num">{{ $fmtAmount((int)$r['year_end_adjustment_overpayment']) }}</td>
                </tr>
                <tr>
                    <td class="label" colspan="2">本税</td>
                    <td class="num">{{ $fmtTotal((int)$r['principal_tax']) }}</td>
                </tr>
                <tr>
                    <td class="label" colspan="2">延滞税</td>
                    <td class="num">{{ $fmtTotal((int)$r['late_payment_tax']) }}</td>
                </tr>
                <tr>
                    <td class="label" colspan="2">合計額</td>
                    <td class="num" style="font-weight:700;">{{ $fmtTotal((int)$r['total_tax']) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="section-label">納期等の区分：{{ $r['due_period_label'] }}</div>

        <div class="footer-box">
            <div style="font-weight:600;margin-bottom:6px;font-size:10px;">徴収義務者</div>
            <dl>
                @if($r['company']['postal_code'])
                    <dt>郵便番号</dt><dd>〒{{ $r['company']['postal_code'] }}</dd>
                @endif
                <dt>所在地</dt><dd>{{ $r['company']['address'] ?: '—' }}</dd>
                <dt>名称</dt><dd style="font-weight:600;">{{ $r['company']['name'] ?: '—' }}</dd>
                @if($r['company']['representative_name'])
                    <dt>代表者</dt><dd>{{ $r['company']['representative_name'] }}</dd>
                @endif
                <dt>電話</dt><dd>{{ $r['company']['phone'] ?: '—' }}</dd>
                <dt>摘要</dt><dd class="remarks">{{ $r['remarks'] ?: '—' }}</dd>
            </dl>
        </div>
    </div>
</body>
</html>
