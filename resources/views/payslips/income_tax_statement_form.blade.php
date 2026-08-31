<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    @include('payslips.partials.robots-noindex')
    <title>所得税徴収高計算書</title>
    <style>
        @include('payslips.partials.pdf-fonts')
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            margin: 0;
            padding: 16px;
            background: #e5e7eb;
            color: #111;
            font-family: "Noto Sans JP", "Hiragino Sans", "Yu Gothic", "MS Gothic", sans-serif;
        }
        body.preview-single { padding: 8px; background: #f3f4f6; }
        @media print {
            body { background: #fff; padding: 0; }
            .no-print { display: none !important; }
        }
        .sheet {
            width: 210mm;
            height: 297mm;
            margin: 0 auto;
            position: relative;
            overflow: hidden;
            background: #fff;
        }
        /** MF 同様: プレビューは中連（領収済通知書）1 枚のみ表示 */
        .sheet.preview-single {
            height: 99mm;
        }
        .sheet.preview-single .sheet-bg {
            top: {{ -1 * ($previewSlipTop ?? 98.9) }}mm;
        }
        .sheet-bg {
            position: absolute;
            left: 0;
            width: 210mm;
            height: 297mm;
            object-fit: fill;
            pointer-events: none;
            user-select: none;
        }
        .overlay-layer {
            position: absolute;
            inset: 0;
            pointer-events: none;
        }
        .overlay-digit {
            position: absolute;
            display: flex;
            align-items: center;
            justify-content: center;
            transform: translate(-50%, -50%);
            font-weight: 600;
            line-height: 1;
            background: transparent;
            border: none;
        }
        .overlay-text {
            position: absolute;
            transform: translateY(-50%);
            line-height: 1.35;
            white-space: pre-wrap;
            word-break: break-all;
        }
    </style>
</head>
<body class="{{ !empty($previewSingle) ? 'preview-single' : '' }}">
    @if(!empty($preview))
        <div class="no-print" style="max-width:210mm;margin:0 auto 8px;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-size:12px;color:#6b7280;">{{ $periodLabel }}（{{ !empty($previewSingle) ? '領収済通知書' : '3連複写' }}・{{ $mode === 'special' ? '納特' : '一般' }}）</span>
            <button type="button" onclick="window.print()" style="padding:6px 14px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;">印刷</button>
        </div>
    @endif

    <div class="sheet {{ !empty($previewSingle) ? 'preview-single' : '' }}">
        <img
            class="sheet-bg"
            src="{{ $backgroundUrl }}"
            alt="所得税徴収高計算書（国税庁様式）"
            width="210"
            height="297"
        >
        <div class="overlay-layer">
            @foreach ($slipTops as $slipTop)
                @include('payslips.partials.income-tax-slip-overlay', [
                    'slipTop' => $slipTop,
                    'form' => $form,
                    'result' => $result,
                ])
            @endforeach
        </div>
    </div>
</body>
</html>
