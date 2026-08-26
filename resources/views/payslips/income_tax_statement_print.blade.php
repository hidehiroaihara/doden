<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>所得税徴収高計算書</title>
    <style>
        @include('payslips.partials.pdf-fonts')
        {!! file_get_contents($layoutCss ?? resource_path('css/income-tax-statement-form-browser-print.css')) !!}

        @page {
            size: 728pt 380pt;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000;
        }

        body.screen-preview {
            padding: 12px;
            background: #f3f4f6;
        }

        .print-toolbar {
            max-width: 728px;
            margin: 0 auto 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: #6b7280;
        }

        .print-sheet {
            width: 728px;
            min-width: 728px;
            max-width: 728px;
            margin: 0 auto;
        }

        @media print {
            body.screen-preview {
                padding: 0;
                background: #fff;
            }

            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body @class(['screen-preview' => !empty($preview)])>
    @if(!empty($preview))
        <div class="no-print print-toolbar">
            <span>{{ $periodLabel }}（{{ $modeLabel }}）</span>
            <button type="button" onclick="window.print()" style="padding:6px 14px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:12px;">印刷</button>
        </div>
    @endif

    <div class="print-sheet">
        @include('payslips.partials.income-tax-statement-form-body', [
            'overlay' => $overlay,
            'backgroundSrc' => $backgroundSrc,
            'viewMode' => $viewMode ?? 'browser-print-view',
        ])
    </div>
</body>
</html>
