@include('payslips.partials.pdf-fonts')
body { margin: 0; padding: 28px; color: #1f2937; font-size: 11px; background: #ffffff; }
.slip { page-break-inside: avoid; }
.slip + .slip { page-break-before: always; }

/* ヘッダー */
.ps-head { width: 100%; border-collapse: collapse; }
.ps-head td { vertical-align: top; }
.ps-title { font-size: 17px; font-weight: bold; color: #1f3f6b; }
.ps-meta { margin-top: 3px; font-size: 9.5px; color: #4b5563; line-height: 1.6; }
.ps-name { margin-top: 10px; font-size: 17px; font-weight: bold; color: #111827; }
.ps-sub { margin-top: 3px; font-size: 9.5px; color: #4b5563; line-height: 1.6; }

.ps-head-right { width: 90px; text-align: right; }
.ps-seal { width: 66px; height: 66px; border: 1px solid #cbd5e1; border-radius: 8px; margin-left: auto; }

.ps-net-row { width: 100%; border-collapse: collapse; margin-top: 6px; }
.ps-net-cell { text-align: right; }
.ps-net-label { font-size: 10px; color: #4b5563; }
.ps-net-value { font-size: 21px; font-weight: bold; color: #111827; margin-left: 12px; }
.ps-net-yen { font-size: 10px; color: #4b5563; margin-left: 4px; }

.ps-divider { border: none; border-top: 2px solid #1f3f6b; margin: 5px 0 12px; }

/* 4カラム */
.cols { width: 100%; border-collapse: separate; border-spacing: 7px 0; table-layout: fixed; }
.col { width: 25%; border: 1px solid #c3d0e0; vertical-align: top; padding: 0; }
.col-panel { box-sizing: border-box; overflow: hidden; }
.col-body { box-sizing: border-box; }
.col-gap { display: block; width: 100%; }
.col-head {
    background: #2b5a9c; color: #ffffff; font-weight: bold;
    font-size: 11px; padding: 5px 6px; text-align: center;
}
.fill { width: 100%; height: 100%; border-collapse: collapse; }
.fill > tbody > tr > td { padding: 0; }

.items { width: 100%; border-collapse: collapse; }
.items td { padding: 6px 7px; font-size: 10px; line-height: 1.35; }
.items td.name { color: #374151; }
.items td.num { text-align: right; color: #111827; }
.items tr.alt td { background: #eef4fb; }
.items tr.total td { background: #dbe6f4; font-weight: bold; border-top: 1px solid #b8c9e0; }

/* 本年累計・備考 */
.extra { width: 100%; border-collapse: separate; border-spacing: 7px 0; table-layout: fixed; margin-top: 7px; }
.extra td { width: 25%; vertical-align: top; padding: 0; }
.remarks { margin-top: 12px; font-size: 10px; color: #4b5563; }
.remarks .box { border: 1px solid #e5e7eb; padding: 8px; min-height: 24px; margin-top: 4px; white-space: pre-wrap; }
