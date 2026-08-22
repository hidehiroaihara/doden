<?php

use App\Http\Controllers\Admin\AdminManagerController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\BusinessLocationController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\EmployeePayrollController;
use App\Http\Controllers\Admin\PayrollCsvController;
use App\Http\Controllers\Admin\PayrollRunController;
use App\Http\Controllers\Admin\PayrollSettingController;
use App\Http\Controllers\Admin\PayslipExportController;
use App\Http\Controllers\Admin\PayslipReportController;
use App\Http\Controllers\Admin\CommuteAllowanceController;
use App\Http\Controllers\Admin\FlatTaxReductionController;
use App\Http\Controllers\Admin\IncomeTaxStatementController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReportExportController;
use App\Http\Controllers\Admin\ResidentTaxReportController;
use App\Http\Controllers\Admin\SummaryReportController;
use App\Http\Controllers\Admin\TaxMeasureController;
use App\Http\Controllers\Admin\TaxSlipController;
use App\Http\Controllers\Admin\YearEndAdjustmentController;
use App\Http\Controllers\Admin\TransferListController;
use App\Http\Controllers\Admin\WageLedgerController;
use App\Http\Controllers\Admin\WithholdingBookController;
use App\Http\Controllers\Admin\WorkerRosterController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TerminalController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function () {
    // ログインURLは .env の ADMIN_LOGIN_PATH で指定（例: secret-gate）
    $loginPath = env('ADMIN_LOGIN_PATH', 'management-console');

    Route::get($loginPath, [LoginController::class, 'create'])->name('login');
    Route::post($loginPath, [LoginController::class, 'store'])->name('login.store');

    Route::middleware(['admin.auth', 'admin.permission'])->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('monthly-summary', [DashboardController::class, 'monthlySummary'])->name('monthly-summary');
        Route::get('monthly-summary/export-csv', [DashboardController::class, 'exportMonthlyCsv'])->name('monthly-summary.export-csv');
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

        // 従業員情報（旧ユーザー管理）
        Route::get('users/{user}/attendances', [AttendanceController::class, 'userAttendances'])->name('users.attendances');
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::put('users/{user}/section/{section}', [UserController::class, 'updateSection'])->name('users.section');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::patch('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::get('users/{user}/document/{type}', [UserController::class, 'downloadDocument'])->name('users.document')->where('type', 'resume|identification');

        // 店舗（部門）マスタ
        Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');

        // 打刻管理
        Route::get('attendances', [AttendanceController::class, 'index'])->name('attendances.index');
        Route::get('attendances/monthly', [AttendanceController::class, 'monthlySheet'])->name('attendances.monthly');
        Route::get('attendances/create', [AttendanceController::class, 'create'])->name('attendances.create');
        Route::post('attendances', [AttendanceController::class, 'store'])->name('attendances.store');
        Route::get('attendances/export-csv', [AttendanceController::class, 'exportCsv'])->name('attendances.export-csv');
        Route::get('attendances/{attendance}/edit', [AttendanceController::class, 'edit'])->name('attendances.edit');
        Route::put('attendances/{attendance}', [AttendanceController::class, 'update'])->name('attendances.update');
        Route::delete('attendances/{attendance}', [AttendanceController::class, 'destroy'])->name('attendances.destroy');
        Route::get('attendances/{attendance}/photo/{type}', [AttendanceController::class, 'photo'])->name('attendances.photo')->where('type', 'in|out');
        Route::put('attendances/{attendance}/breaks/{break}', [AttendanceController::class, 'breakUpdate'])->name('attendances.breaks.update');
        Route::delete('attendances/{attendance}/breaks/{break}', [AttendanceController::class, 'breakDestroy'])->name('attendances.breaks.destroy');
        Route::get('attendances/{attendance}/breaks/{break}/photo/{type}', [AttendanceController::class, 'breakPhoto'])->name('attendances.breaks.photo')->where('type', 'start|end');

        // 設定
        // 勤怠・締めの会社共通設定（画面は「基本設定 > 勤怠」タブに統合。保存のみ本エンドポイント）
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');

        // 事業所マスタ（給与設定画面内の「事業所」タブで管理）
        Route::post('payroll/locations', [BusinessLocationController::class, 'store'])->name('payroll.locations.store');
        Route::put('payroll/locations/{location}', [BusinessLocationController::class, 'update'])->name('payroll.locations.update');
        Route::delete('payroll/locations/{location}', [BusinessLocationController::class, 'destroy'])->name('payroll.locations.destroy');

        // 基本設定（旧 給与設定）
        Route::get('payroll/settings', [PayrollSettingController::class, 'index'])->name('payroll.settings.index');
        Route::put('payroll/settings/general', [PayrollSettingController::class, 'updateGeneral'])->name('payroll.settings.general');
        Route::put('payroll/settings/pay-items', [PayrollSettingController::class, 'updatePayItems'])->name('payroll.settings.pay-items');
        Route::put('payroll/settings/deduction-items', [PayrollSettingController::class, 'updateDeductionItems'])->name('payroll.settings.deduction-items');
        Route::put('payroll/settings/attendance-items', [PayrollSettingController::class, 'updateAttendanceItems'])->name('payroll.settings.attendance-items');
        Route::put('payroll/settings/insurance-rates', [PayrollSettingController::class, 'updateInsuranceRates'])->name('payroll.settings.insurance-rates');
        Route::put('payroll/settings/municipalities', [PayrollSettingController::class, 'updateMunicipalities'])->name('payroll.settings.municipalities');

        // 基本設定＞年度設定(se15)
        Route::post('payroll/settings/fiscal-years', [PayrollSettingController::class, 'storeFiscalYear'])->name('payroll.settings.fiscal-years.store');
        Route::put('payroll/settings/fiscal-years/{fiscalYear}', [PayrollSettingController::class, 'updateFiscalYear'])->name('payroll.settings.fiscal-years.update');

        // 基本設定＞明細設定(se17)
        Route::put('payroll/settings/payslip', [PayrollSettingController::class, 'updatePayslipSettings'])->name('payroll.settings.payslip');
        Route::put('payroll/settings/payslip-notify', [PayrollSettingController::class, 'updatePayslipNotify'])->name('payroll.settings.payslip-notify');

        // 基本設定＞全般: 各マスタの行追加・削除
        Route::post('payroll/settings/closing-groups', [PayrollSettingController::class, 'storeClosingGroup'])->name('payroll.settings.closing-groups.store');
        Route::put('payroll/settings/closing-groups/{group}', [PayrollSettingController::class, 'updateClosingGroup'])->name('payroll.settings.closing-groups.update');
        Route::delete('payroll/settings/closing-groups/{group}', [PayrollSettingController::class, 'destroyClosingGroup'])->name('payroll.settings.closing-groups.destroy');
        Route::post('payroll/settings/job-titles', [PayrollSettingController::class, 'storeJobTitle'])->name('payroll.settings.job-titles.store');
        Route::put('payroll/settings/job-titles/{jobTitle}', [PayrollSettingController::class, 'updateJobTitle'])->name('payroll.settings.job-titles.update');
        Route::delete('payroll/settings/job-titles/{jobTitle}', [PayrollSettingController::class, 'destroyJobTitle'])->name('payroll.settings.job-titles.destroy');
        Route::post('payroll/settings/leave-types', [PayrollSettingController::class, 'storeLeaveType'])->name('payroll.settings.leave-types.store');
        Route::put('payroll/settings/leave-types/{leaveType}', [PayrollSettingController::class, 'updateLeaveType'])->name('payroll.settings.leave-types.update');
        Route::delete('payroll/settings/leave-types/{leaveType}', [PayrollSettingController::class, 'destroyLeaveType'])->name('payroll.settings.leave-types.destroy');

        // 基本設定: 項目マスタの行追加・削除
        Route::post('payroll/settings/pay-items', [PayrollSettingController::class, 'storePayItem'])->name('payroll.settings.pay-items.store');
        Route::delete('payroll/settings/pay-items/{payItem}', [PayrollSettingController::class, 'destroyPayItem'])->name('payroll.settings.pay-items.destroy');
        Route::post('payroll/settings/deduction-items', [PayrollSettingController::class, 'storeDeductionItem'])->name('payroll.settings.deduction-items.store');
        Route::delete('payroll/settings/deduction-items/{deductionItem}', [PayrollSettingController::class, 'destroyDeductionItem'])->name('payroll.settings.deduction-items.destroy');
        Route::post('payroll/settings/attendance-items', [PayrollSettingController::class, 'storeAttendanceItem'])->name('payroll.settings.attendance-items.store');
        Route::delete('payroll/settings/attendance-items/{attendanceItem}', [PayrollSettingController::class, 'destroyAttendanceItem'])->name('payroll.settings.attendance-items.destroy');
        Route::post('payroll/settings/insurance-sets', [PayrollSettingController::class, 'storeInsuranceSet'])->name('payroll.settings.insurance-sets.store');
        Route::delete('payroll/settings/insurance-sets/{insuranceSet}', [PayrollSettingController::class, 'destroyInsuranceSet'])->name('payroll.settings.insurance-sets.destroy');
        Route::post('payroll/settings/insurance-sets/{insuranceSet}/apply-kyokai', [PayrollSettingController::class, 'applyKyokaiRates'])->name('payroll.settings.insurance-sets.apply-kyokai');

        // 従業員給与情報（従業員情報の「給与情報」タブへ統合。旧URLは新画面へリダイレクト）
        Route::get('payroll/employees', fn () => redirect()->route('admin.users.index'))->name('payroll.employees.index');
        Route::get('payroll/employees/{user}/edit', fn (\App\Models\User $user) => redirect()->route('admin.users.show', $user->id))->name('payroll.employees.edit');
        Route::put('payroll/employees/{user}', [EmployeePayrollController::class, 'update'])->name('payroll.employees.update');

        // 給与計算（バッチ）
        Route::get('payroll/runs', [PayrollRunController::class, 'index'])->name('payroll.runs.index');
        Route::post('payroll/runs', [PayrollRunController::class, 'store'])->name('payroll.runs.store');
        Route::get('payroll/runs/{run}', [PayrollRunController::class, 'show'])->name('payroll.runs.show');
        Route::post('payroll/runs/{run}/calculate', [PayrollRunController::class, 'calculate'])->name('payroll.runs.calculate');
        Route::post('payroll/runs/{run}/finalize', [PayrollRunController::class, 'finalize'])->name('payroll.runs.finalize');
        Route::post('payroll/runs/{run}/reopen', [PayrollRunController::class, 'reopen'])->name('payroll.runs.reopen');
        Route::put('payroll/runs/{run}/payslips/{payslip}', [PayrollRunController::class, 'updatePayslip'])->name('payroll.runs.payslips.update');
        Route::post('payroll/runs/{run}/payslips/{payslip}/items/{item}/revert', [PayrollRunController::class, 'revertItem'])->name('payroll.runs.items.revert');
        Route::put('payroll/runs/{run}/bulk-update', [PayrollRunController::class, 'bulkUpdate'])->name('payroll.runs.bulk-update');
        Route::put('payroll/runs/{run}/bonus-inputs', [PayrollRunController::class, 'saveBonusInputs'])->name('payroll.runs.bonus-inputs');
        Route::patch('payroll/runs/{run}/dates', [PayrollRunController::class, 'updateDates'])->name('payroll.runs.dates.update');
        Route::patch('payroll/runs/{run}/memo', [PayrollRunController::class, 'updateMemo'])->name('payroll.runs.memo.update');
        Route::post('payroll/runs/{run}/reset-overrides', [PayrollRunController::class, 'resetOverrides'])->name('payroll.runs.reset-overrides');
        Route::delete('payroll/runs/{run}', [PayrollRunController::class, 'destroy'])->name('payroll.runs.destroy');

        // 給与計算: 支給/控除/勤怠 CSV ダウンロード・インポート（MFメニュー）
        Route::get('payroll/runs/{run}/csv', [PayrollCsvController::class, 'download'])->name('payroll.runs.csv.download');
        Route::post('payroll/runs/{run}/csv', [PayrollCsvController::class, 'import'])->name('payroll.runs.csv.import');

        // 支払業務: 給与振込一覧表 / 全銀FBデータ
        Route::get('payroll/runs/{run}/transfer-list', [TransferListController::class, 'show'])->name('payroll.transfers.show');
        Route::get('payroll/runs/{run}/transfer-list/pdf', [TransferListController::class, 'pdf'])->name('payroll.transfers.pdf');
        Route::get('payroll/runs/{run}/transfer-list/fb', [TransferListController::class, 'fbData'])->name('payroll.transfers.fb-data');

        // 支払業務: 住民税徴収額一覧表 / CSV
        Route::get('payroll/runs/{run}/resident-tax', [ResidentTaxReportController::class, 'show'])->name('payroll.resident-tax.show');
        Route::get('payroll/runs/{run}/resident-tax/pdf', [ResidentTaxReportController::class, 'pdf'])->name('payroll.resident-tax.pdf');
        Route::get('payroll/runs/{run}/resident-tax/csv', [ResidentTaxReportController::class, 'csv'])->name('payroll.resident-tax.csv');

        // 給与明細ZIP出力
        Route::get('payroll/exports', [PayslipExportController::class, 'index'])->name('payroll.exports.index');
        Route::post('payroll/exports', [PayslipExportController::class, 'store'])->name('payroll.exports.store');
        Route::get('payroll/exports/status', [PayslipExportController::class, 'status'])->name('payroll.exports.status');
        Route::get('payroll/exports/{export}/download', [PayslipExportController::class, 'download'])->name('payroll.exports.download');
        Route::delete('payroll/exports/{export}', [PayslipExportController::class, 'destroy'])->name('payroll.exports.destroy');

        // 帳票一覧（ランチャー）
        Route::get('payroll/reports', [ReportController::class, 'index'])->name('payroll.reports.index');
        Route::get('payroll/reports/resident-tax-picker', [ReportController::class, 'residentTaxPicker'])->name('payroll.reports.resident-tax-picker');

        // 帳票: 給与明細（一覧・プレビュー・個別/一括PDF）
        Route::get('payroll/reports/payslips', [PayslipReportController::class, 'index'])->name('payroll.reports.payslips');
        Route::post('payroll/reports/payslips/batch-pdf', [PayslipReportController::class, 'batchPdf'])->name('payroll.reports.payslips.batch-pdf');
        Route::get('payroll/reports/payslips/{payslip}/preview', [PayslipReportController::class, 'preview'])->name('payroll.reports.payslips.preview');
        Route::get('payroll/reports/payslips/{payslip}/pdf', [PayslipReportController::class, 'pdf'])->name('payroll.reports.payslips.pdf');

        // 帳票の一括作成（源泉徴収簿PDF一括 / 賃金台帳CSV一括）
        Route::get('payroll/report-exports', [ReportExportController::class, 'index'])->name('payroll.report-exports.index');
        Route::post('payroll/report-exports', [ReportExportController::class, 'store'])->name('payroll.report-exports.store');
        Route::get('payroll/report-exports/status', [ReportExportController::class, 'status'])->name('payroll.report-exports.status');
        Route::get('payroll/report-exports/{export}/download', [ReportExportController::class, 'download'])->name('payroll.report-exports.download');
        Route::delete('payroll/report-exports/{export}', [ReportExportController::class, 'destroy'])->name('payroll.report-exports.destroy');

        // 帳票: 支給控除一覧表（通常／部門別）
        Route::get('payroll/reports/summary', [SummaryReportController::class, 'show'])->name('payroll.reports.summary');
        Route::get('payroll/reports/summary/csv', [SummaryReportController::class, 'csv'])->name('payroll.reports.summary.csv');
        Route::post('payroll/reports/summary/patterns', [SummaryReportController::class, 'storePattern'])->name('payroll.reports.summary.patterns.store');
        Route::delete('payroll/reports/summary/patterns/{pattern}', [SummaryReportController::class, 'destroyPattern'])->name('payroll.reports.summary.patterns.destroy');

        // 帳票: 賃金台帳
        Route::get('payroll/reports/wage-ledger', [WageLedgerController::class, 'show'])->name('payroll.reports.wage-ledger');
        Route::get('payroll/reports/wage-ledger/{user}/pdf', [WageLedgerController::class, 'pdf'])->name('payroll.reports.wage-ledger.pdf');

        // 帳票: 源泉徴収簿
        Route::get('payroll/reports/withholding-book', [WithholdingBookController::class, 'show'])->name('payroll.reports.withholding-book');
        Route::get('payroll/reports/withholding-book/{user}/pdf', [WithholdingBookController::class, 'pdf'])->name('payroll.reports.withholding-book.pdf');

        // 帳票: 労働者名簿
        Route::get('payroll/reports/roster', [WorkerRosterController::class, 'index'])->name('payroll.reports.roster');
        Route::get('payroll/reports/roster/{user}/pdf', [WorkerRosterController::class, 'pdf'])->name('payroll.reports.roster.pdf');

        // 帳票: 所得税徴収高計算書（通常／納特）
        Route::get('payroll/reports/income-tax-statement', [IncomeTaxStatementController::class, 'show'])->name('payroll.reports.income-tax-statement');
        Route::get('payroll/reports/income-tax-statement/pdf', [IncomeTaxStatementController::class, 'pdf'])->name('payroll.reports.income-tax-statement.pdf');

        // 帳票: 通勤手当一覧
        Route::get('payroll/reports/commute', [CommuteAllowanceController::class, 'show'])->name('payroll.reports.commute');
        Route::get('payroll/reports/commute/csv', [CommuteAllowanceController::class, 'csv'])->name('payroll.reports.commute.csv');

        // 帳票: 定額減税 各人別控除事績簿
        Route::get('payroll/reports/flat-tax', [FlatTaxReductionController::class, 'show'])->name('payroll.reports.flat-tax');
        Route::get('payroll/reports/flat-tax/csv', [FlatTaxReductionController::class, 'csv'])->name('payroll.reports.flat-tax.csv');

        // 帳票: 退職者の源泉徴収票
        Route::get('payroll/reports/tax-slip', [TaxSlipController::class, 'index'])->name('payroll.reports.tax-slip');
        Route::get('payroll/reports/tax-slip/{user}/pdf', [TaxSlipController::class, 'pdf'])->name('payroll.reports.tax-slip.pdf');

        // 年末調整
        Route::get('payroll/year-end', [YearEndAdjustmentController::class, 'index'])->name('payroll.year-end.index');
        Route::get('payroll/year-end/{user}/edit', [YearEndAdjustmentController::class, 'edit'])->name('payroll.year-end.edit');
        Route::get('payroll/year-end/{user}/slip', [YearEndAdjustmentController::class, 'slip'])->name('payroll.year-end.slip');
        Route::post('payroll/year-end/{user}', [YearEndAdjustmentController::class, 'update'])->name('payroll.year-end.update');
        Route::post('payroll/year-end/{adjustment}/reflect', [YearEndAdjustmentController::class, 'reflect'])->name('payroll.year-end.reflect');

        // 税制措置マスタ（定額減税など時限的な税制対応）
        Route::get('payroll/tax-measures', [TaxMeasureController::class, 'index'])->name('payroll.tax-measures.index');
        Route::post('payroll/tax-measures', [TaxMeasureController::class, 'store'])->name('payroll.tax-measures.store');
        Route::put('payroll/tax-measures/{taxMeasure}', [TaxMeasureController::class, 'update'])->name('payroll.tax-measures.update');
        Route::delete('payroll/tax-measures/{taxMeasure}', [TaxMeasureController::class, 'destroy'])->name('payroll.tax-measures.destroy');

        // 端末管理
        Route::resource('terminals', TerminalController::class)->except(['show']);
        Route::post('terminals/{terminal}/reissue-key', [TerminalController::class, 'reissueKey'])->name('terminals.reissue-key');

        // 管理ユーザー管理（role=1 のみ — CheckAdminPermission で admins セクションとして制御）
        Route::resource('managers', AdminManagerController::class)->except(['show']);
    });
});
