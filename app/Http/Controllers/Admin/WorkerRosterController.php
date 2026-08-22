<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * 労働者名簿（労働基準法第107条・施行規則第53条準拠）。
 * 全従業員を一覧表示し、従業員単位で法定様式のPDFを出力する。
 * 「従事する業務の種類」「履歴」欄は自動出力対象外（印刷後に手書き補記する運用）。
 *
 * 参照: 資料/設計書 29_労働者名簿
 */
class WorkerRosterController extends Controller
{
    private const EMPLOYMENT_TYPES = [
        'executive' => '役員',
        'employee_executive' => '使用人兼務役員',
        'full_time' => '正社員',
        'contract' => '契約社員',
        'entrusted' => '嘱託',
        'part_time' => 'パート',
        'arbeit' => 'アルバイト',
        'dispatch' => '派遣',
        'other' => 'その他',
    ];

    private const PAY_TYPES = [
        'monthly' => '月給制',
        'hourly' => '時給制',
        'daily' => '日給制',
    ];

    public function index(Request $request)
    {
        $includeRetired = $request->boolean('include_retired');

        $rows = User::query()
            ->when(! $includeRetired, fn ($q) => $q->where('is_active', true))
            ->with(['employeePayroll.businessLocation:id,name'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'employee_no' => $u->employeePayroll?->employee_no,
                'business_location' => $u->employeePayroll?->businessLocation?->name,
                'employment_type' => self::EMPLOYMENT_TYPES[$u->employeePayroll?->employment_type] ?? '—',
                'pay_type' => self::PAY_TYPES[$u->employeePayroll?->pay_type] ?? '—',
                'status' => $u->is_active ? '在籍中' : '退職',
            ]);

        return Inertia::render('Admin/Payroll/Reports/Roster', [
            'includeRetired' => $includeRetired,
            'rows' => $rows,
        ]);
    }

    public function pdf(User $user)
    {
        $user->load('employeePayroll');

        $pdf = Pdf::loadView('payslips.worker_roster', [
            'name' => $user->name,
            'birthDate' => $user->birth_date?->format('Y年n月j日'),
            'postalCode' => $user->postal_code,
            'address' => $user->address,
            'hireDate' => $user->joined_at?->format('Y年n月j日'),
            'isActive' => (bool) $user->is_active,
            'employmentType' => self::EMPLOYMENT_TYPES[$user->employeePayroll?->employment_type] ?? '',
        ])->setPaper('a4', 'portrait');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="worker_roster_' . $user->id . '.pdf"',
        ]);
    }
}
