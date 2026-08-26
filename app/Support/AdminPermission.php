<?php

namespace App\Support;

/**
 * ルート名 → (セクション, 必要レベル) のマッピング
 *
 * level:
 *   read  … 閲覧のみで到達できるルート
 *   write … 作成・更新・削除など状態を変えるルート（create ページも含む）
 */
class AdminPermission
{
    /** 利用可能なセクション一覧 */
    public const SECTIONS = [
        'dashboard',
        'users',
        'attendances',
        'terminals',
        'settings',
        'payroll',
    ];

    /** 権限レベル */
    public const LEVELS = ['none', 'read', 'write'];

    /** デフォルト権限（新規管理者作成時） */
    public const DEFAULTS = [
        'dashboard'   => 'none',
        'users'       => 'none',
        'attendances' => 'none',
        'terminals'   => 'none',
        'settings'    => 'none',
        'payroll'     => 'none',
    ];

    /** role=1 に付与する全権限（admins セクションも含む） */
    public const SUPER = [
        'dashboard'   => 'write',
        'users'       => 'write',
        'attendances' => 'write',
        'terminals'   => 'write',
        'settings'    => 'write',
        'payroll'     => 'write',
        'admins'      => 'write',
    ];

    /**
     * ルート名 => [section, required_level]
     *
     * このマップに載っていないルート（logout、login 等）は
     * ミドルウェアでスルーする。
     */
    private const ROUTE_MAP = [
        // ダッシュボード
        'admin.dashboard'                    => ['dashboard', 'read'],
        'admin.monthly-summary'              => ['dashboard', 'read'],
        'admin.monthly-summary.export-csv'   => ['dashboard', 'read'],

        // 従業員情報 — 閲覧
        'admin.users.index'                  => ['users', 'read'],
        'admin.users.show'                   => ['users', 'read'],
        'admin.users.attendances'            => ['users', 'read'],
        'admin.users.document'               => ['users', 'read'],
        // 従業員情報 — 編集
        'admin.users.create'                 => ['users', 'write'],
        'admin.users.store'                  => ['users', 'write'],
        'admin.users.section'                => ['users', 'write'],
        'admin.users.destroy'                => ['users', 'write'],
        'admin.users.toggle-active'          => ['users', 'write'],

        // 店舗（部門）マスタ
        'admin.departments.index'            => ['users', 'read'],
        'admin.departments.store'            => ['users', 'write'],
        'admin.departments.update'           => ['users', 'write'],
        'admin.departments.destroy'          => ['users', 'write'],
        'admin.departments.terminals.store'  => ['users', 'write'],
        'admin.departments.terminals.reissue' => ['users', 'write'],
        'admin.departments.terminals.destroy' => ['users', 'write'],

        // 打刻管理 — 閲覧
        'admin.attendances.index'            => ['attendances', 'read'],
        'admin.attendances.photo'            => ['attendances', 'read'],
        'admin.attendances.export-csv'       => ['attendances', 'read'],
        // 打刻管理 — 編集
        'admin.attendances.create'           => ['attendances', 'write'],
        'admin.attendances.store'            => ['attendances', 'write'],
        'admin.attendances.edit'             => ['attendances', 'write'],
        'admin.attendances.update'           => ['attendances', 'write'],

        // 端末管理 — 閲覧
        'admin.terminals.index'              => ['terminals', 'read'],
        // 端末管理 — 編集
        'admin.terminals.create'             => ['terminals', 'write'],
        'admin.terminals.store'              => ['terminals', 'write'],
        'admin.terminals.edit'               => ['terminals', 'write'],
        'admin.terminals.update'             => ['terminals', 'write'],
        'admin.terminals.destroy'            => ['terminals', 'write'],
        'admin.terminals.reissue-key'        => ['terminals', 'write'],

        // 勤怠設定（基本設定 > 勤怠タブに統合。保存エンドポイントのみ）
        'admin.settings.update'              => ['settings', 'write'],

        // 給与設定（基本設定マスタ）— 閲覧
        'admin.payroll.settings.index'            => ['payroll', 'read'],
        // 給与設定 — 編集
        'admin.payroll.locations.store'           => ['payroll', 'write'],
        'admin.payroll.locations.update'          => ['payroll', 'write'],
        'admin.payroll.locations.destroy'         => ['payroll', 'write'],
        'admin.payroll.settings.general'          => ['payroll', 'write'],
        'admin.payroll.settings.pay-items'        => ['payroll', 'write'],
        'admin.payroll.settings.deduction-items'  => ['payroll', 'write'],
        'admin.payroll.settings.attendance-items' => ['payroll', 'write'],
        'admin.payroll.settings.insurance-rates'  => ['payroll', 'write'],
        'admin.payroll.settings.locations.labor-insurance' => ['payroll', 'write'],
        'admin.payroll.settings.municipalities'   => ['payroll', 'write'],
        'admin.payroll.settings.closing-groups.store'   => ['payroll', 'write'],
        'admin.payroll.settings.closing-groups.update'  => ['payroll', 'write'],
        'admin.payroll.settings.closing-groups.destroy' => ['payroll', 'write'],
        'admin.payroll.settings.job-titles.store'       => ['payroll', 'write'],
        'admin.payroll.settings.job-titles.update'      => ['payroll', 'write'],
        'admin.payroll.settings.job-titles.destroy'     => ['payroll', 'write'],
        'admin.payroll.settings.leave-types.store'      => ['payroll', 'write'],
        'admin.payroll.settings.leave-types.update'     => ['payroll', 'write'],
        'admin.payroll.settings.leave-types.destroy'    => ['payroll', 'write'],
        'admin.payroll.settings.pay-items.store'        => ['payroll', 'write'],
        'admin.payroll.settings.pay-items.destroy'      => ['payroll', 'write'],
        'admin.payroll.settings.deduction-items.store'  => ['payroll', 'write'],
        'admin.payroll.settings.deduction-items.destroy' => ['payroll', 'write'],
        'admin.payroll.settings.attendance-items.store'  => ['payroll', 'write'],
        'admin.payroll.settings.attendance-items.destroy' => ['payroll', 'write'],
        'admin.payroll.settings.insurance-sets.store'    => ['payroll', 'write'],
        'admin.payroll.settings.insurance-sets.destroy'  => ['payroll', 'write'],
        'admin.payroll.settings.pension-funds.store'     => ['payroll', 'write'],
        'admin.payroll.settings.pension-funds.update'    => ['payroll', 'write'],
        'admin.payroll.settings.pension-funds.destroy'   => ['payroll', 'write'],

        // 従業員給与情報 — 閲覧
        'admin.payroll.employees.index'           => ['payroll', 'read'],
        // 従業員給与情報 — 編集
        'admin.payroll.employees.edit'            => ['payroll', 'write'],
        'admin.payroll.employees.update'          => ['payroll', 'write'],

        // 給与計算 — 閲覧
        'admin.payroll.runs.index'                => ['payroll', 'read'],
        'admin.payroll.runs.show'                 => ['payroll', 'read'],
        // 給与計算 — 編集
        'admin.payroll.runs.store'                => ['payroll', 'write'],
        'admin.payroll.runs.calculate'            => ['payroll', 'write'],
        'admin.payroll.runs.finalize'             => ['payroll', 'write'],
        'admin.payroll.runs.reopen'               => ['payroll', 'write'],
        'admin.payroll.runs.payslips.update'      => ['payroll', 'write'],
        'admin.payroll.runs.bonus-inputs'         => ['payroll', 'write'],
        'admin.payroll.runs.destroy'              => ['payroll', 'write'],

        // 支払業務: 給与振込一覧表 / FBデータ — 閲覧
        'admin.payroll.transfers.show'            => ['payroll', 'read'],
        'admin.payroll.transfers.pdf'             => ['payroll', 'read'],
        'admin.payroll.transfers.fb-data'         => ['payroll', 'read'],
        // 支払業務: 住民税徴収額一覧表 / CSV — 閲覧
        'admin.payroll.resident-tax.show'         => ['payroll', 'read'],
        'admin.payroll.resident-tax.pdf'          => ['payroll', 'read'],
        'admin.payroll.resident-tax.csv'          => ['payroll', 'read'],

        // 給与明細ZIP出力 — 閲覧
        'admin.payroll.exports.index'             => ['payroll', 'read'],
        'admin.payroll.exports.status'            => ['payroll', 'read'],
        'admin.payroll.exports.download'          => ['payroll', 'read'],
        // 給与明細ZIP出力 — 編集
        'admin.payroll.exports.store'             => ['payroll', 'write'],
        'admin.payroll.exports.destroy'           => ['payroll', 'write'],

        // 帳票一覧（すべて閲覧扱い）
        'admin.payroll.reports.index'                    => ['payroll', 'read'],
        'admin.payroll.reports.resident-tax-picker'      => ['payroll', 'read'],
        'admin.payroll.reports.payslips'                 => ['payroll', 'read'],
        'admin.payroll.reports.payslips.preview'         => ['payroll', 'read'],
        'admin.payroll.reports.payslips.pdf'             => ['payroll', 'read'],
        'admin.payroll.reports.payslips.batch-pdf'       => ['payroll', 'read'],
        'admin.payroll.reports.summary'                  => ['payroll', 'read'],
        'admin.payroll.reports.summary.csv'              => ['payroll', 'read'],
        'admin.payroll.reports.summary.patterns.store'   => ['payroll', 'write'],
        'admin.payroll.reports.summary.patterns.destroy' => ['payroll', 'write'],
        'admin.payroll.reports.wage-ledger'              => ['payroll', 'read'],
        'admin.payroll.reports.wage-ledger.pdf'          => ['payroll', 'read'],
        'admin.payroll.reports.wage-ledger.csv'          => ['payroll', 'read'],
        'admin.payroll.reports.wage-ledger.bulk-csv'     => ['payroll', 'read'],
        'admin.payroll.reports.wage-ledger.bulk-pdf'     => ['payroll', 'read'],
        'admin.payroll.reports.withholding-book'         => ['payroll', 'read'],
        'admin.payroll.reports.withholding-book.pdf'     => ['payroll', 'read'],
        'admin.payroll.reports.roster'                   => ['payroll', 'read'],
        'admin.payroll.reports.roster.pdf'               => ['payroll', 'read'],
        'admin.payroll.reports.income-tax-statement'     => ['payroll', 'read'],
        'admin.payroll.reports.income-tax-statement.preview' => ['payroll', 'read'],
        'admin.payroll.reports.income-tax-statement.pdf' => ['payroll', 'read'],
        'admin.payroll.reports.commute'                  => ['payroll', 'read'],
        'admin.payroll.reports.commute.csv'              => ['payroll', 'read'],
        'admin.payroll.reports.flat-tax'                 => ['payroll', 'read'],
        'admin.payroll.reports.flat-tax.csv'             => ['payroll', 'read'],
        'admin.payroll.reports.tax-slip'                 => ['payroll', 'read'],
        'admin.payroll.reports.tax-slip.pdf'             => ['payroll', 'read'],
        'admin.payroll.year-end.index'                   => ['payroll', 'read'],
        'admin.payroll.year-end.edit'                    => ['payroll', 'read'],
        'admin.payroll.year-end.slip'                    => ['payroll', 'read'],
        'admin.payroll.year-end.update'                  => ['payroll', 'write'],
        'admin.payroll.year-end.reflect'                 => ['payroll', 'write'],
        'admin.payroll.tax-measures.index'               => ['payroll', 'read'],
        'admin.payroll.tax-measures.store'               => ['payroll', 'write'],
        'admin.payroll.tax-measures.update'              => ['payroll', 'write'],
        'admin.payroll.tax-measures.destroy'             => ['payroll', 'write'],

        // 帳票の一括作成 — 閲覧／編集
        'admin.payroll.report-exports.index'             => ['payroll', 'read'],
        'admin.payroll.report-exports.status'            => ['payroll', 'read'],
        'admin.payroll.report-exports.download'          => ['payroll', 'read'],
        'admin.payroll.report-exports.store'             => ['payroll', 'write'],
        'admin.payroll.report-exports.destroy'           => ['payroll', 'write'],

        // 管理ユーザー管理（role=1 のみ — ミドルウェアで別途判定）
        'admin.managers.index'               => ['admins', 'read'],
        'admin.managers.create'              => ['admins', 'write'],
        'admin.managers.store'               => ['admins', 'write'],
        'admin.managers.edit'                => ['admins', 'write'],
        'admin.managers.update'              => ['admins', 'write'],
        'admin.managers.destroy'             => ['admins', 'write'],
    ];

    /**
     * ルート名から [section, required_level] を返す。
     * マップにない場合は null。
     */
    public static function forRoute(string $routeName): ?array
    {
        return self::ROUTE_MAP[$routeName] ?? null;
    }

    /**
     * 管理者が指定セクションの指定レベルを持つか。
     *
     * permissions 配列の値: 'none' | 'read' | 'write'
     * write >= read >= none の順序で判定。
     */
    public static function can(array $permissions, string $section, string $requiredLevel): bool
    {
        $levelOrder = ['none' => 0, 'read' => 1, 'write' => 2];
        $has      = $levelOrder[$permissions[$section] ?? 'none'] ?? 0;
        $required = $levelOrder[$requiredLevel] ?? 0;
        return $has >= $required;
    }
}
