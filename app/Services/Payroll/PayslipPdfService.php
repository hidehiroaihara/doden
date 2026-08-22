<?php

namespace App\Services\Payroll;

use App\Models\PayItemMaster;
use App\Models\DeductionItemMaster;
use App\Models\Payslip;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

/**
 * 給与明細1件をPDF（バイナリ）へレンダリングする。
 * ZIP一括出力ジョブ・単票ダウンロード・一覧プレビューの各所から利用する。
 * 表示項目は基本設定＞明細設定(se17)のトグルに従う。
 *
 * レイアウトはマネーフォワード クラウド給与の給与明細に準拠した
 * 「勤怠｜支給｜控除｜当月支払」の4カラム構成。
 */
class PayslipPdfService
{
    /** 4カラム見出し・明細行の高さ（PDF / プレビュー共通）。 */
    private const COL_HEAD_PX = 30;

    private const COL_ROW_PX = 26;

    /** 4カラムの最低行数（MF参考：項目が少なくても余白を確保）。 */
    private const COL_MIN_ROWS = 8;

    /**
     * 給与明細に載せない勤怠項目（所定労働カテゴリ＋MF明細に非表示の実働集計）。
     * 給与計算画面では引き続き表示。マスタは基本設定＞勤怠項目で管理。
     */
    private const PAYSLIP_ATTENDANCE_EXCLUDE = [
        'fixed_work_hours_per_day',
        'scheduled_days_month',
        'scheduled_days_month_avg',
        'scheduled_hours_month',
        'scheduled_hours_month_avg',
        'actual_total_weekday',
        'break_weekday',
        'overtime_weekday',
        'statutory_overtime_weekday',
        'absence_days_weekday',
        'late_count',
        'early_leave_count',
        'work_days_prescribed_holiday',
        'work_days_legal_holiday',
    ];

    /** 単票PDF（バイナリ）。 */
    public function render(Payslip $payslip): string
    {
        $pdf = Pdf::loadView('payslips.payslip', [
            'slip' => $this->viewData($payslip),
        ])->setPaper('a4', 'portrait');

        return $pdf->output();
    }

    /** 複数明細を1ファイル（改ページ区切り）にまとめたPDF（バイナリ）。 */
    public function renderBatch(iterable $payslips): string
    {
        $slips = [];
        foreach ($payslips as $payslip) {
            $slips[] = $this->viewData($payslip);
        }

        $pdf = Pdf::loadView('payslips.payslip-batch', [
            'slips' => $slips,
        ])->setPaper('a4', 'portrait');

        return $pdf->output();
    }

    /**
     * Blade / Inertia プレビューの双方で使う明細ビューデータ。
     * 金額は整数、勤怠値・日付は表示用の文字列に整形済みで返す。
     *
     * @return array<string, mixed>
     */
    public function viewData(Payslip $payslip): array
    {
        $payslip->loadMissing([
            'user:id,name',
            'items',
            'payrollRun.businessLocation:id,name',
            'user.employeePayroll.businessLocation:id,name',
            'user.department:id,name',
        ]);

        $run = $payslip->payrollRun;
        $employee = $payslip->user?->employeePayroll;
        $settings = $this->displaySettings();
        [$closingDate, $paymentDate] = $this->resolveRunDates($run);

        $earningItems = $payslip->items->where('item_type', 'earning');
        $deductionItems = $payslip->items->where('item_type', 'deduction');
        $showZeroEarnings = PayItemMaster::query()
            ->whereIn('id', $earningItems->pluck('source_master_id')->filter()->unique())
            ->pluck('show_zero', 'id');
        $showZeroDeductions = DeductionItemMaster::query()
            ->whereIn('id', $deductionItems->pluck('source_master_id')->filter()->unique())
            ->pluck('show_zero', 'id');

        $earnings = $earningItems
            ->filter(fn ($i) => $this->includeAmountOnPayslip((int) $i->amount, $i->source_master_id, $showZeroEarnings))
            ->map(fn ($i) => ['name' => $i->name, 'amount' => (int) $i->amount])
            ->values()->all();

        $deductions = $deductionItems
            ->filter(fn ($i) => $this->includeAmountOnPayslip((int) $i->amount, $i->source_master_id, $showZeroDeductions))
            ->map(fn ($i) => ['name' => $i->name, 'amount' => (int) $i->amount])
            ->values()->all();

        $attendances = $payslip->items->where('item_type', 'attendance')
            ->filter(fn ($i) => $this->includeAttendanceOnPayslip($i))
            ->map(fn ($i) => [
                'name' => $i->name,
                'value' => $this->formatAttendance($i->minutes, $i->quantity !== null ? (float) $i->quantity : null),
            ])
            ->values()->all();

        $attCount = $settings['payslip_show_attendance'] ? count($attendances) : 0;
        $alignRows = max(self::COL_MIN_ROWS, $attCount, count($earnings) + 1, count($deductions) + 1);
        $columnMinHeight = self::COL_HEAD_PX + $alignRows * self::COL_ROW_PX;
        $bodyHeight = $columnMinHeight - self::COL_HEAD_PX;
        $payments = [
            ['name' => '振込支給額', 'amount' => (int) $payslip->net_pay],
        ];
        $attItemRows = max(1, $attCount);
        $earnSpacerHeight = max(0, $bodyHeight - count($earnings) * self::COL_ROW_PX - self::COL_ROW_PX);
        $dedSpacerHeight = max(0, $bodyHeight - count($deductions) * self::COL_ROW_PX - self::COL_ROW_PX);
        $attSpacerHeight = max(0, $bodyHeight - $attItemRows * self::COL_ROW_PX);
        $paySpacerHeight = max(0, $bodyHeight - count($payments) * self::COL_ROW_PX);

        return [
            'id' => $payslip->id,
            'alignRows' => $alignRows,
            'columnMinHeight' => $columnMinHeight,
            'earnSpacerHeight' => $earnSpacerHeight,
            'dedSpacerHeight' => $dedSpacerHeight,
            'attSpacerHeight' => $attSpacerHeight,
            'paySpacerHeight' => $paySpacerHeight,
            'title' => $this->title($run, $settings['payslip_display_month'], $closingDate, $paymentDate),
            'paymentDate' => $this->wareki($paymentDate, true),
            'targetPeriod' => $settings['payslip_show_target_period'] ? $this->targetPeriod($closingDate) : null,
            'userName' => $payslip->user?->name ?? '—',
            'businessLocation' => $settings['payslip_show_affiliation']
                ? ($run?->businessLocation?->name ?? $employee?->businessLocation?->name)
                : null,
            'department' => $settings['payslip_show_department'] ? ($payslip->user?->department?->name ?? '') : null,
            'employeeNo' => $employee?->employee_no,
            'showAttendance' => (bool) $settings['payslip_show_attendance'],
            'attendances' => $attendances,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'totalEarnings' => (int) $payslip->total_earnings,
            'totalDeductions' => (int) $payslip->total_deductions,
            'netPay' => (int) $payslip->net_pay,
            'payments' => $payments,
            'relatedInfo' => $this->relatedInfo($payslip, $employee, $settings),
            'ytd' => $settings['payslip_show_ytd'] ? $this->yearToDate($payslip) : null,
            'remarks' => $payslip->remarks,
        ];
    }

    /** @return array<string, mixed> */
    private function displaySettings(): array
    {
        $bool = fn (string $key, string $default) => Setting::getValue($key, $default) === '1';

        return [
            'payslip_display_month' => Setting::getValue('payslip_display_month', 'payment'),
            'payslip_show_target_period' => $bool('payslip_show_target_period', '1'),
            'payslip_show_affiliation' => $bool('payslip_show_affiliation', '1'),
            'payslip_show_department' => $bool('payslip_show_department', '1'),
            'payslip_show_attendance' => $bool('payslip_show_attendance', '1'),
            'payslip_show_ytd' => $bool('payslip_show_ytd', '0'),
            'payslip_show_hourly' => $bool('payslip_show_hourly', '1'),
            'payslip_show_standard_monthly' => $bool('payslip_show_standard_monthly', '0'),
            'payslip_show_dependents' => $bool('payslip_show_dependents', '0'),
            'payslip_show_tax_category' => $bool('payslip_show_tax_category', '0'),
        ];
    }

    /** 給与明細に載せる勤怠行か（所定労働カテゴリ等は除外）。 */
    private function includeAttendanceOnPayslip($item): bool
    {
        if ($item->category === 'fixed_work') {
            return false;
        }

        return ! in_array($item->code, self::PAYSLIP_ATTENDANCE_EXCLUDE, true);
    }

    /**
     * 給与明細PDFに金額行を載せるか。
     * 0円はマスタの show_zero が true のときのみ表示（給与計算画面とは別ルール）。
     *
     * @param  \Illuminate\Support\Collection<int, bool>  $showZeroByMasterId
     */
    private function includeAmountOnPayslip(int $amount, ?int $masterId, $showZeroByMasterId): bool
    {
        if ($amount !== 0) {
            return true;
        }

        if ($masterId === null) {
            return false;
        }

        return (bool) ($showZeroByMasterId[$masterId] ?? false);
    }

    /**
     * 支給日・締め日。バッチに未設定の場合は period_key から補完する。
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveRunDates($run): array
    {
        if (! $run) {
            return [null, null];
        }

        $closing = $run->closing_date ? $run->closing_date->copy() : null;
        $payment = $run->payment_date ? $run->payment_date->copy() : null;

        if (! $closing && preg_match('/^(\d{4})-(\d{2})$/', (string) $run->period_key, $m)) {
            $closing = Carbon::create((int) $m[1], (int) $m[2], 1)->endOfMonth();
        }
        if (! $payment && $closing) {
            $payment = $closing->copy()->addMonth()->day(min(25, $closing->copy()->addMonth()->daysInMonth));
        }

        return [$closing, $payment];
    }

    /** 帳票タイトル「YYYY（令和NN）年MM月分　給与明細書」。 */
    private function title($run, string $mode, ?Carbon $closingDate, ?Carbon $paymentDate): string
    {
        if (! $run) {
            return '給与明細書';
        }

        $date = $mode === 'closing' ? $closingDate : $paymentDate;
        $date = $date ?? $paymentDate ?? $closingDate;

        if (! $date) {
            return '給与明細書';
        }

        return sprintf(
            '%d（令和%02d）年%02d月分　給与明細書',
            $date->year,
            $date->year - 2018,
            $date->month,
        );
    }

    /** 対象期間（締め日の月初〜締め日）。 */
    private function targetPeriod(?Carbon $closing): ?string
    {
        if (! $closing) {
            return null;
        }
        $start = $closing->copy()->startOfMonth();

        return $this->wareki($start, true) . '〜' . $this->wareki($closing, true);
    }

    /** 和暦表記。$withDay=true で日まで、false で月まで。 */
    private function wareki(?Carbon $date, bool $withDay): ?string
    {
        if (! $date) {
            return null;
        }
        $base = sprintf('%d（令和%02d）年%02d月', $date->year, $date->year - 2018, $date->month);

        return $withDay ? $base . sprintf('%02d日', $date->day) : $base;
    }

    /** 勤怠値の表示整形（時間は 9.00 / 日数は 2.0）。 */
    private function formatAttendance(?int $minutes, ?float $quantity): string
    {
        if ($minutes !== null) {
            return number_format($minutes / 60, 2);
        }
        if ($quantity !== null) {
            return number_format($quantity, 1);
        }

        return '—';
    }

    /**
     * 給与関連情報（時給/標準報酬月額/扶養親族等数/税額表区分）。トグルで出し分ける。
     *
     * 標準報酬月額・税額表区分・扶養数は「計算時のスナップショット」を優先し、
     * マスタ改定後も過去明細の表示が変わらないようにする（未保存の旧明細のみ従業員情報へフォールバック）。
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function relatedInfo(Payslip $payslip, $employee, array $s): array
    {
        if (! $employee) {
            return [];
        }
        $rows = [];
        if ($s['payslip_show_hourly'] && (int) $employee->hourly_wage > 0) {
            $rows[] = ['label' => '時給1', 'value' => number_format((int) $employee->hourly_wage)];
        }
        if ($s['payslip_show_standard_monthly']) {
            $stdHealth = $payslip->snapshot_standard_reward_health ?? (int) $employee->standard_reward_health;
            $stdPension = $payslip->snapshot_standard_reward_pension ?? (int) $employee->standard_reward_pension;
            $rows[] = ['label' => '標準報酬月額(健保)', 'value' => '¥' . number_format((int) $stdHealth)];
            $rows[] = ['label' => '標準報酬月額(厚年)', 'value' => '¥' . number_format((int) $stdPension)];
        }
        if ($s['payslip_show_dependents']) {
            $dep = $payslip->snapshot_dependents_count ?? (int) $employee->dependents_count;
            $rows[] = ['label' => '扶養親族等数', 'value' => (string) ((int) $dep) . '人'];
        }
        if ($s['payslip_show_tax_category']) {
            $taxTable = $payslip->snapshot_tax_table ?? $employee->tax_table;
            $rows[] = ['label' => '税額表区分', 'value' => $taxTable === 'otsu' ? '乙欄' : '甲欄'];
        }

        return $rows;
    }

    /**
     * 本年累計（課税支給額・社会保険料・所得税）。同一ユーザーの当年・当該支給日以前の明細を合算。
     *
     * @return array{taxable: int, social: int, income_tax: int}
     */
    private function yearToDate(Payslip $payslip): array
    {
        $run = $payslip->payrollRun;
        $year = $run?->payment_date?->year ?? (int) substr((string) $run?->period_key, 0, 4);
        $upto = $run?->payment_date?->toDateString();

        $siblings = Payslip::where('user_id', $payslip->user_id)
            ->whereHas('payrollRun', function ($q) use ($year, $upto) {
                $q->whereYear('payment_date', $year);
                if ($upto) {
                    $q->where('payment_date', '<=', $upto);
                }
            })
            ->with('items')
            ->get();

        $socialCodes = ['health_insurance', 'nursing_insurance', 'pension_insurance', 'employment_insurance'];
        $taxable = 0;
        $social = 0;
        $incomeTax = 0;
        foreach ($siblings as $slip) {
            $taxable += (int) $slip->total_earnings;
            foreach ($slip->items->where('item_type', 'deduction') as $item) {
                if ($item->code === 'income_tax') {
                    $incomeTax += (int) $item->amount;
                } elseif (in_array($item->code, $socialCodes, true)) {
                    $social += (int) $item->amount;
                }
            }
        }

        return ['taxable' => $taxable, 'social' => $social, 'income_tax' => $incomeTax];
    }
}
