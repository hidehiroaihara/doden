<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

/**
 * 帳票一覧（ランチャー／ポータル）。
 * 業務シーン別にグルーピングした帳票カードを一覧表示し、各帳票画面への入口とする。
 * 本画面は薄いビューであり、カテゴリ構造はサーバ側の帳票メニュー定義から動的にレンダリングする。
 *
 * 参照: 資料/設計書 18_帳票一覧_全体像
 */
class ReportController extends Controller
{
    public function index()
    {
        $currentYear = (int) now()->format('Y');
        $reiwaCommute = $currentYear - 2018;   // 令和N年 通勤手当
        $reiwaFlatTax = 2024 - 2018;           // 令和6年 定額減税（所得税）

        return Inertia::render('Admin/Payroll/Reports/Index', [
            'currentYear' => $currentYear,
            'categories' => [
                [
                    'title' => '毎月確認するもの',
                    'cards' => [
                        ['label' => '給与明細', 'route' => 'admin.payroll.reports.payslips', 'icon' => 'file-lines'],
                        ['label' => '支給控除一覧表', 'route' => 'admin.payroll.reports.summary', 'icon' => 'table-list'],
                        ['label' => '支給控除一覧表(部門別)', 'route' => 'admin.payroll.reports.summary', 'params' => ['group' => 'department'], 'icon' => 'table-cells'],
                    ],
                ],
                [
                    'title' => '税務署へ届出が必要な書類',
                    'cards' => [
                        ['label' => '所得税徴収高計算書', 'route' => 'admin.payroll.reports.income-tax-statement', 'icon' => 'receipt'],
                        ['label' => '所得税徴収高計算書(納特)', 'route' => 'admin.payroll.reports.income-tax-statement', 'params' => ['mode' => 'special'], 'icon' => 'receipt'],
                    ],
                ],
                [
                    'title' => '住民税額の確認に使うもの',
                    'cards' => [
                        ['label' => '住民税徴収額一覧表', 'route' => 'admin.payroll.reports.resident-tax-picker', 'icon' => 'city'],
                    ],
                ],
                [
                    'title' => "令和{$reiwaCommute}年分 通勤手当一覧 (交通用具)",
                    'cards' => [
                        ['label' => '通勤手当一覧 (交通用具)', 'route' => 'admin.payroll.reports.commute', 'icon' => 'train-subway'],
                    ],
                ],
                [
                    'title' => "令和{$reiwaFlatTax}年分 定額減税 (所得税)",
                    'cards' => [
                        ['label' => '各人別控除事績簿', 'route' => 'admin.payroll.reports.flat-tax', 'icon' => 'percent'],
                        ['label' => '制度マスタ（適用期間の管理）', 'route' => 'admin.payroll.tax-measures.index', 'icon' => 'sliders'],
                    ],
                ],
                [
                    'title' => '退職者へ発行するもの',
                    'cards' => [
                        ['label' => '退職者の源泉徴収票', 'route' => 'admin.payroll.reports.tax-slip', 'icon' => 'user-minus'],
                    ],
                ],
                [
                    'title' => '帳簿作成・保管義務のある書類',
                    'cards' => [
                        ['label' => '賃金台帳', 'route' => 'admin.payroll.reports.wage-ledger', 'icon' => 'book'],
                        ['label' => '労働者名簿', 'route' => 'admin.payroll.reports.roster', 'icon' => 'address-book'],
                    ],
                ],
                [
                    'title' => '一括作成',
                    'cards' => [
                        ['label' => '帳票の一括作成（源泉徴収簿PDF / 賃金台帳CSV）', 'route' => 'admin.payroll.report-exports.index', 'icon' => 'layer-group'],
                    ],
                ],
                [
                    'title' => '年末調整関係書類',
                    'cards' => [
                        ['label' => '年末調整', 'route' => 'admin.payroll.year-end.index', 'icon' => 'calculator'],
                        ['label' => '源泉徴収簿', 'route' => 'admin.payroll.reports.withholding-book', 'icon' => 'book-open'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * 住民税徴収額一覧表はバッチ単位のため、直近の給与バッチを選ばせる中間ピッカー。
     */
    public function residentTaxPicker()
    {
        $runs = \App\Models\PayrollRun::where('pay_type', 'salary')
            ->with('businessLocation:id,name')
            ->orderByDesc('period_key')
            ->limit(24)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'period_key' => $r->period_key,
                'business_location' => $r->businessLocation?->name,
                'status' => $r->status,
            ]);

        return Inertia::render('Admin/Payroll/Reports/RunPicker', [
            'title' => '住民税徴収額一覧表',
            'description' => '対象の給与バッチを選択してください。',
            'routeName' => 'admin.payroll.resident-tax.show',
            'runs' => $runs,
        ]);
    }
}
