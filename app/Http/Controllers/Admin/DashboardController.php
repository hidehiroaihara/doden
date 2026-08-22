<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\User;
use App\Models\UserStatusHistory;
use App\Services\AttendanceSummaryService;
use App\Services\MonthPeriod;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        private AttendanceSummaryService $summaries,
    ) {}

    public function index()
    {
        $today = Carbon::today()->toDateString();
        $monthKey = MonthPeriod::currentKey();

        $result = $this->summaries->forMonth($monthKey);
        $hasSchedule = $result['hasSchedule'];

        $monthlySummary = array_map(
            fn (array $s) => $this->presentRow($s, $hasSchedule),
            $result['users'],
        );

        $recentStatusChanges = UserStatusHistory::with(['user:id,name', 'changedBy:id,name'])
            ->where('from_status', '!=', 'none')
            ->orderByDesc('changed_at')
            ->limit(10)
            ->get()
            ->map(fn ($h) => [
                'id'          => $h->id,
                'user_id'     => $h->user_id,
                'user_name'   => $h->user?->name,
                'from_label'  => UserStatusHistory::statusLabel($h->from_status),
                'to_label'    => UserStatusHistory::statusLabel($h->to_status),
                'changed_by'  => $h->changedBy?->name,
                'changed_at'  => $h->changed_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'totalUsers' => $result['company']['user_count'],
                'todayClockIns' => Attendance::where('work_date', $today)->whereNotNull('clock_in_at')->count(),
                'todayClockOuts' => Attendance::where('work_date', $today)->whereNotNull('clock_out_at')->count(),
            ],
            'monthlySummary' => $monthlySummary,
            'hasSchedule' => $hasSchedule,
            'currentMonth' => MonthPeriod::label($monthKey),
            'recentStatusChanges' => $recentStatusChanges,
        ]);
    }

    public function monthlySummary(\Illuminate\Http\Request $request)
    {
        $monthParam = $request->input('month');
        $monthKey = ($monthParam && preg_match('/^\d{4}-\d{2}$/', $monthParam))
            ? $monthParam
            : MonthPeriod::currentKey();

        $result = $this->summaries->forMonth($monthKey);
        $hasSchedule = $result['hasSchedule'];
        $company = $result['company'];

        $monthlySummary = array_map(
            fn (array $s) => $this->presentRow($s, $hasSchedule),
            $result['users'],
        );

        $fmt = fn (int $m) => AttendanceSummaryService::formatMinutesToHM($m);

        $companyTotal = [
            'work_days' => $company['work_days'],
            'total_work' => $fmt($company['total_work_minutes']),
            'rounded_work' => $fmt($company['total_rounded_minutes']),
            'total_break' => $fmt($company['total_break_minutes']),
            'avg_per_day' => $company['work_days'] > 0
                ? $fmt(intdiv($company['total_work_minutes'], $company['work_days']))
                : '0:00',
            'missing_clock_out' => $company['missing_clock_out'],
            'user_count' => $company['user_count'],
        ];

        if ($hasSchedule) {
            $companyTotal['late_count'] = $company['late_count'];
            $companyTotal['early_leave_count'] = $company['early_leave_count'];
            $companyTotal['overtime'] = $fmt($company['overtime_minutes']);
        }

        return Inertia::render('Admin/MonthlySummary/Index', [
            'monthlySummary' => $monthlySummary,
            'companyTotal' => $companyTotal,
            'hasSchedule' => $hasSchedule,
            'monthLabel' => MonthPeriod::label($monthKey),
            'monthKey' => $monthKey,
            'prevMonth' => MonthPeriod::shift($monthKey, -1),
            'nextMonth' => MonthPeriod::shift($monthKey, 1),
        ]);
    }

    public function exportMonthlyCsv(\Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $monthParam = $request->input('month');
        $monthKey = ($monthParam && preg_match('/^\d{4}-\d{2}$/', $monthParam))
            ? $monthParam
            : MonthPeriod::currentKey();

        $result = $this->summaries->forMonth($monthKey);
        $hasSchedule = $result['hasSchedule'];
        $company = $result['company'];
        $fmt = fn (int $m) => AttendanceSummaryService::formatMinutesToHM($m);

        $rows = [];
        foreach ($result['users'] as $s) {
            $row = [
                $s['user_name'],
                $s['work_days'],
                $fmt($s['total_work_minutes']),
                $fmt($s['total_rounded_minutes']),
                $fmt($s['total_break_minutes']),
                $s['work_days'] > 0 ? $fmt(intdiv($s['total_work_minutes'], $s['work_days'])) : '0:00',
                $s['missing_clock_out'],
            ];
            if ($hasSchedule) {
                $row[] = $s['late_count'];
                $row[] = $s['early_leave_count'];
                $row[] = $fmt($s['overtime_minutes']);
            }
            $rows[] = $row;
        }

        // 合計行を追加
        $totalRow = [
            '合計',
            $company['work_days'],
            $fmt($company['total_work_minutes']),
            $fmt($company['total_rounded_minutes']),
            $fmt($company['total_break_minutes']),
            $company['work_days'] > 0 ? $fmt(intdiv($company['total_work_minutes'], $company['work_days'])) : '0:00',
            $company['missing_clock_out'],
        ];
        if ($hasSchedule) {
            $totalRow[] = $company['late_count'];
            $totalRow[] = $company['early_leave_count'];
            $totalRow[] = $fmt($company['overtime_minutes']);
        }
        $rows[] = $totalRow;

        $headers = ['ユーザー名', '出勤日数', '総労働時間', '丸め後労働時間', '休憩合計', '平均/日', '退勤忘れ'];
        if ($hasSchedule) {
            $headers[] = '遅刻回数';
            $headers[] = '早退回数';
            $headers[] = '残業時間';
        }

        $filename = 'monthly_summary_' . str_replace('-', '', $monthKey) . '.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * サービスの生サマリ(分数)を、これまでの画面向けレスポンス形へ整形する。
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function presentRow(array $s, bool $hasSchedule): array
    {
        $fmt = fn (int $m) => AttendanceSummaryService::formatMinutesToHM($m);

        $row = [
            'user_id' => $s['user_id'],
            'user_name' => $s['user_name'],
            'work_days' => $s['work_days'],
            'total_work_hours' => $fmt($s['total_work_minutes']),
            'total_work_minutes' => $s['total_work_minutes'],
            'rounded_work_hours' => $fmt($s['total_rounded_minutes']),
            'avg_per_day' => $s['work_days'] > 0
                ? $fmt(intdiv($s['total_work_minutes'], $s['work_days']))
                : '0:00',
            'missing_clock_out' => $s['missing_clock_out'],
        ];

        if ($hasSchedule) {
            $row['late_count'] = $s['late_count'];
            $row['early_leave_count'] = $s['early_leave_count'];
            $row['overtime_hours'] = $fmt($s['overtime_minutes']);
            $row['overtime_minutes'] = $s['overtime_minutes'];
        }

        return $row;
    }
}
