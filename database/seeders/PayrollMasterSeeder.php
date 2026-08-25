<?php

namespace Database\Seeders;

use App\Models\AttendanceItemMaster;
use App\Models\BusinessLocation;
use App\Models\DeductionItemMaster;
use App\Models\InsuranceRate;
use App\Models\InsuranceRateSet;
use App\Models\LeaveType;
use App\Models\PayItemMaster;
use App\Models\TaxMeasure;
use App\Support\PayrollMasterSync;
use Illuminate\Database\Seeder;

/**
 * 給与マスタの標準データ。
 *
 * - 支給/控除/勤怠 の標準項目（設計書09/10/11）を投入。code をキーに冪等。
 * - 保険料率・標準報酬等級は「サンプル値」。実運用値は管理画面から
 *   事業所×適用期間で登録・改定する前提（payroll-design-guide §5.2）。
 */
class PayrollMasterSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAttendanceItems();
        $this->seedPayItems();
        $this->seedDeductionItems();
        $this->seedInsuranceSample();
        $this->seedTaxMeasures();
        $this->seedLeaveTypes();

        PayrollMasterSync::syncLateEarlyAttendanceItems();
    }

    /** 休職・休業種別マスタ（基本設定＞全般 / 従業員情報の休職で使用）。 */
    private function seedLeaveTypes(): void
    {
        $types = [
            ['childcare', '育児休業', 'childcare', 'all_zero'],
            ['maternity', '産前産後休業', 'maternity', 'all_zero'],
            ['nursing', '介護休業', 'nursing', 'all_zero'],
            ['work_injury', '業務上の傷病による休業', 'work_injury', 'all_zero'],
            ['other', 'その他休職・休業', 'other', 'all_zero'],
        ];
        foreach ($types as $i => [$code, $name, $kind, $calc]) {
            LeaveType::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'leave_kind' => $kind, 'pay_calc_method' => $calc, 'is_active' => true, 'sort_order' => $i],
            );
        }
    }

    /** 時限的な税制措置（令和6年分 定額減税）。 */
    private function seedTaxMeasures(): void
    {
        TaxMeasure::updateOrCreate(
            ['type' => TaxMeasure::TYPE_FLAT_TAX, 'target_year' => 2024],
            [
                'name' => '令和6年分 定額減税（所得税）',
                'start_period' => '2024-06',
                'end_period' => '2024-12',
                'per_person_amount' => 30000,
                'is_active' => true,
                'note' => '本人＋同一生計配偶者・扶養親族1人につき3万円。甲欄（居住者）のみ対象。2024年6月以降支給分から適用。',
            ],
        );
    }

    private function seedAttendanceItems(): void
    {
        // [code, name, category, unit_format, is_active]
        // 並び順・有効/無効はMFクラウドの勤怠項目一覧（初期表示）に準拠。
        $items = [
            // 所定労働（従業員情報由来の固定値・削除不可・常時有効）
            ['fixed_work_hours_per_day', '1日の所定労働時間', 'fixed_work', 'hour_decimal', true],
            ['scheduled_days_month', '所定労働日数(当月)', 'fixed_work', 'day', true],
            ['scheduled_days_month_avg', '所定労働日数(月平均)', 'fixed_work', 'day_decimal', true],
            ['scheduled_hours_month', '所定労働時間(当月)', 'fixed_work', 'hour_decimal', true],
            ['scheduled_hours_month_avg', '所定労働時間(月平均)', 'fixed_work', 'hour_decimal', true],
            // 出欠勤
            ['work_days_weekday', '出勤日数（平日）', 'attendance', 'day', true],
            ['work_days_prescribed_holiday', '出勤日数（所定休日）', 'attendance', 'day', true],
            ['work_days_legal_holiday', '出勤日数（法定休日）', 'attendance', 'day', true],
            ['work_days_total', '出勤日数（合算）', 'attendance', 'day', true],
            ['absence_days_weekday', '欠勤日数（平日）', 'attendance', 'day', true],
            ['absence_days_prescribed_holiday', '欠勤日数（所定休日）', 'attendance', 'day', false],
            ['absence_days_legal_holiday', '欠勤日数（法定休日）', 'attendance', 'day', false],
            // 遅刻・早退時間（分→時間）
            ['late_minutes_weekday', '遅刻時間（平日）', 'attendance', 'hour_decimal', false],
            ['late_minutes_prescribed_holiday', '遅刻時間（所定休日）', 'attendance', 'hour_decimal', false],
            ['late_minutes_legal_holiday', '遅刻時間（法定休日）', 'attendance', 'hour_decimal', false],
            ['early_leave_minutes_weekday', '早退時間（平日）', 'attendance', 'hour_decimal', false],
            ['early_leave_minutes_prescribed_holiday', '早退時間（所定休日）', 'attendance', 'hour_decimal', false],
            ['early_leave_minutes_legal_holiday', '早退時間（法定休日）', 'attendance', 'hour_decimal', false],
            // 遅刻・早退回数
            ['late_count', '遅刻回数（平日）', 'attendance', 'count', false],
            ['late_count_prescribed_holiday', '遅刻回数（所定休日）', 'attendance', 'count', false],
            ['late_count_legal_holiday', '遅刻回数（法定休日）', 'attendance', 'count', false],
            ['early_leave_count', '早退回数（平日）', 'attendance', 'count', false],
            ['early_leave_count_prescribed_holiday', '早退回数（所定休日）', 'attendance', 'count', false],
            ['early_leave_count_legal_holiday', '早退回数（法定休日）', 'attendance', 'count', false],
            // 総労働時間（MF名目準拠: 平日/所定休日/法定休日）
            ['actual_total_weekday', '総労働時間（平日）', 'actual_work', 'hour_decimal', false],
            ['work_prescribed_holiday', '総労働時間（所定休日）', 'actual_work', 'hour_decimal', false],
            ['work_statutory_holiday', '総労働時間（法定休日）', 'actual_work', 'hour_decimal', false],
            // 所定時間
            ['scheduled_time_weekday', '所定時間（平日）', 'actual_work', 'hour_decimal', true],
            ['scheduled_time_prescribed_holiday', '所定時間（所定休日）', 'actual_work', 'hour_decimal', true],
            ['scheduled_time_legal_holiday', '所定時間（法定休日）', 'actual_work', 'hour_decimal', true],
            // 所定外時間（所定超〜8h）
            ['overtime_weekday', '所定外時間（平日）', 'actual_work', 'hour_decimal', true],
            ['overtime_prescribed_holiday', '所定外時間（所定休日）', 'actual_work', 'hour_decimal', true],
            ['overtime_legal_holiday', '所定外時間（法定休日）', 'actual_work', 'hour_decimal', false],
            // 法定外時間（8h超）
            ['statutory_overtime_weekday', '法定外時間（平日）', 'actual_work', 'hour_decimal', false],
            ['statutory_overtime_prescribed_holiday', '法定外時間（所定休日）', 'actual_work', 'hour_decimal', false],
            ['statutory_overtime_legal_holiday', '法定外時間（法定休日）', 'actual_work', 'hour_decimal', false],
            // 深夜所定時間
            ['night_weekday', '深夜所定時間（平日）', 'actual_work', 'hour_decimal', true],
            ['night_prescribed_holiday', '深夜所定時間（所定休日）', 'actual_work', 'hour_decimal', true],
            ['night_statutory_holiday', '深夜所定時間（法定休日）', 'actual_work', 'hour_decimal', false],
            // 深夜所定外時間
            ['night_overtime_weekday', '深夜所定外時間（平日）', 'actual_work', 'hour_decimal', false],
            ['night_overtime_prescribed_holiday', '深夜所定外時間（所定休日）', 'actual_work', 'hour_decimal', false],
            ['night_overtime_legal_holiday', '深夜所定外時間（法定休日）', 'actual_work', 'hour_decimal', false],
            // 深夜法定外時間
            ['night_statutory_overtime_weekday', '深夜法定外時間（平日）', 'actual_work', 'hour_decimal', false],
            ['night_statutory_overtime_prescribed_holiday', '深夜法定外時間（所定休日）', 'actual_work', 'hour_decimal', false],
            ['night_statutory_overtime_legal_holiday', '深夜法定外時間（法定休日）', 'actual_work', 'hour_decimal', false],
            // 休憩時間
            ['break_weekday', '休憩時間（平日）', 'actual_work', 'hour_decimal', false],
            ['break_prescribed_holiday', '休憩時間（所定休日）', 'actual_work', 'hour_decimal', false],
            ['break_legal_holiday', '休憩時間（法定休日）', 'actual_work', 'hour_decimal', false],
            // 所定外休憩時間
            ['break_overtime_weekday', '所定外休憩時間（平日）', 'actual_work', 'hour_decimal', false],
            ['break_overtime_prescribed_holiday', '所定外休憩時間（所定休日）', 'actual_work', 'hour_decimal', false],
            ['break_overtime_legal_holiday', '所定外休憩時間（法定休日）', 'actual_work', 'hour_decimal', false],
            // 法定外休憩時間
            ['break_statutory_weekday', '法定外休憩時間（平日）', 'actual_work', 'hour_decimal', false],
            ['break_statutory_prescribed_holiday', '法定外休憩時間（所定休日）', 'actual_work', 'hour_decimal', false],
            ['break_statutory_legal_holiday', '法定外休憩時間（法定休日）', 'actual_work', 'hour_decimal', false],
            // 深夜休憩時間
            ['break_night_weekday', '深夜休憩時間（平日）', 'actual_work', 'hour_decimal', false],
            ['break_night_prescribed_holiday', '深夜休憩時間（所定休日）', 'actual_work', 'hour_decimal', false],
            ['break_night_legal_holiday', '深夜休憩時間（法定休日）', 'actual_work', 'hour_decimal', false],
            // 深夜所定外休憩時間
            ['break_night_overtime_weekday', '深夜所定外休憩時間（平日）', 'actual_work', 'hour_decimal', false],
            ['break_night_overtime_prescribed_holiday', '深夜所定外休憩時間（所定休日）', 'actual_work', 'hour_decimal', false],
            ['break_night_overtime_legal_holiday', '深夜所定外休憩時間（法定休日）', 'actual_work', 'hour_decimal', false],
            // 深夜法定外休憩時間
            ['break_night_statutory_weekday', '深夜法定外休憩時間（平日）', 'actual_work', 'hour_decimal', false],
            ['break_night_statutory_prescribed_holiday', '深夜法定外休憩時間（所定休日）', 'actual_work', 'hour_decimal', false],
            ['break_night_statutory_legal_holiday', '深夜法定外休憩時間（法定休日）', 'actual_work', 'hour_decimal', false],
            // 休暇みなし時間
            ['deemed_leave_hours', '休暇みなし時間', 'actual_work', 'hour_decimal', false],
            // 休暇（デフォルト無効）
            ['paid_leave_days', '有休取得日数', 'leave', 'day', false],
            ['paid_leave_remaining_days', '有休残日数', 'leave', 'day', false],
            ['paid_leave_granted_days', '有休付与日数', 'leave', 'day', false],
            ['paid_leave_hours', '有休取得時間数', 'leave', 'hour_decimal', false],
            ['paid_leave_remaining_hours', '有休残時間数', 'leave', 'hour_decimal', false],
            ['comp_leave_days', '代休取得日数', 'leave', 'day', false],
            ['comp_leave_remaining_days', '代休残日数', 'leave', 'day', false],
            ['comp_leave_granted_days', '代休付与日数', 'leave', 'day', false],
            ['comp_leave_hours', '代休取得時間数', 'leave', 'hour_decimal', false],
            ['comp_leave_remaining_hours', '代休残時間数', 'leave', 'hour_decimal', false],
            ['substitute_holiday_days', '振替休日取得日数', 'leave', 'day', false],
        ];

        foreach ($items as $i => [$code, $name, $category, $unit, $active]) {
            AttendanceItemMaster::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'category' => $category,
                    'unit_format' => $unit,
                    'is_active' => $active,
                    'is_system' => true,
                    'sort_order' => $i,
                ],
            );
        }
    }

    private function seedPayItems(): void
    {
        // 従業員情報参照の固定支給（基本給系）: 課税・社保・労保の算定対象
        $employeeBase = [
            'calc_method' => 'employee',
            'is_income_tax_target' => true,
            'is_labor_insurance_target' => true,
            'is_social_insurance_target' => true,
            'is_fixed_wage' => true,
            'is_allowance_base' => true,
            'is_deduction_base' => true,
            'rounding' => 'ceil',
        ];

        // 月給区分の初期支給項目（管理画面で確定した運用基準）
        // 有効: 基本給・役職手当・住宅手当・通勤手当
        // 無効: 残業系・欠勤/遅刻早退控除・手入力系 等
        $items = [
            ['base_salary', '基本給', 'basic', $employeeBase],
            ['executive_salary', '役員報酬', 'basic', array_merge($employeeBase, ['is_active' => false])],
            ['position_allowance', '役職手当', 'basic', $employeeBase],
            ['family_allowance', '家族手当', 'basic', array_merge($employeeBase, ['is_active' => false])],
            ['housing_allowance', '住宅手当', 'basic', array_merge($employeeBase, ['is_deduction_base' => false])],
            ['sales_allowance', '営業手当', 'basic', array_merge($employeeBase, ['is_active' => false])],

            // 割増賃金系（初期は無効。必要になったら管理画面で有効化）
            ['overtime_allowance', '残業手当', 'overtime', [
                'calc_method' => 'allowance_base',
                'divisor_unit' => 'scheduled_hours_month_avg',
                'multiplier' => 1.250,
                'quantity_unit' => 'statutory_overtime_weekday',
                'is_active' => false,
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
            ]],
            ['legal_inside_overtime_allowance', '法定内残業手当', 'overtime', [
                'calc_method' => 'allowance_base',
                'divisor_unit' => 'scheduled_hours_month_avg',
                'multiplier' => 1.000,
                'quantity_unit' => 'overtime_weekday',
                'is_active' => false,
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
            ]],
            ['night_overtime_allowance', '深夜残業手当', 'overtime', [
                'calc_method' => 'allowance_base',
                'divisor_unit' => 'scheduled_hours_month_avg',
                'multiplier' => 0.250,
                'quantity_unit' => 'night_weekday',
                'is_active' => false,
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
            ]],
            ['statutory_holiday_allowance', '法定休日手当', 'overtime', [
                'calc_method' => 'allowance_base',
                'divisor_unit' => 'scheduled_hours_month_avg',
                'multiplier' => 1.350,
                'quantity_unit' => 'work_statutory_holiday',
                'is_active' => false,
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
            ]],

            // 通勤手当（課税/非課税を分離）
            ['commute_taxable', '通勤手当/課税', 'commute', [
                'calc_method' => 'employee',
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
                // MF準拠: 通勤手当は端数処理「切り上げ」固定
                'rounding' => 'ceil',
            ]],
            ['commute_non_taxable', '通勤手当/非課', 'commute', [
                'calc_method' => 'employee',
                'is_income_tax_target' => false,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
                // MF準拠: 通勤手当は端数処理「切り上げ」固定
                'rounding' => 'ceil',
            ]],

            // 控除系（支給項目タブ内・マイナス計算）— 初期は無効
            ['absence_deduction', '欠勤控除', 'deduction', [
                'calc_method' => 'deduction_base',
                'divisor_unit' => 'scheduled_days_month_avg',
                'multiplier' => 1.000,
                'quantity_unit' => 'absence_days_weekday',
                'sign' => 'minus',
                'is_active' => false,
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
            ]],
            ['late_early_deduction', '遅刻早退控除', 'deduction', [
                'calc_method' => 'deduction_base',
                'divisor_unit' => 'scheduled_hours_month_avg',
                'multiplier' => 1.000,
                'sign' => 'minus',
                'is_active' => false,
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
            ]],

            // 手入力系（初期は無効）
            ['retroactive_payment', '遡及支払額', 'manual', [
                'calc_method' => 'manual',
                'is_active' => false,
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
            ]],
            ['reimbursement', '立替経費', 'manual', [
                'calc_method' => 'manual',
                'is_active' => false,
                'is_income_tax_target' => false,
            ]],
        ];

        // 給与区分ごとに独立したマスタ（設計書09 / MF準拠）。
        // 時給・日給は「単価(時給1/2・日給1/2) × 打刻由来の勤務時間・日数」を初期式とする。
        $itemsByType = [
            'monthly' => $items,
            'hourly' => $this->hourlyPayItems(),
            'daily' => $this->dailyPayItems(),
            'bonus' => $this->bonusPayItems(),
        ];

        foreach ($itemsByType as $payType => $typeItems) {
            foreach ($typeItems as $i => [$code, $name, $category, $overrides]) {
                $active = $overrides['is_active'] ?? true;
                unset($overrides['is_active']);

                PayItemMaster::updateOrCreate(
                    ['pay_type' => $payType, 'code' => $code],
                    array_merge([
                        'name' => $name,
                        'category' => $category,
                        'is_active' => $active,
                        'is_system' => true,
                        'sort_order' => $i,
                        'sign' => 'plus',
                        'rounding' => 'round',
                    ], $overrides),
                );
            }
        }
    }

    /**
     * 時給区分の初期支給項目（MF準拠）。
     * 時給1/時給2 は「従業員ごとの単価入力（従業員情報＞給与情報）」であり支給項目ではない。
     * 支給項目としては「基本給」を用意し、その中で単価(時給1)×打刻の勤務時間を計算する。
     * 基本給は削除不可のシステム項目（MFは初期支給項目を削除不可）。
     *
     * @return array<int, array{0:string,1:string,2:string,3:array<string,mixed>}>
     */
    private function hourlyPayItems(): array
    {
        return [
            ['base_salary', '基本給', 'basic', [
                'calc_method' => 'custom',
                'custom_formula' => $this->rateTimesQty('hourly1', '時給1', 'actual_total_weekday', '総労働時間（平日）'),
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
                'is_fixed_wage' => true,
                // MF準拠: 時給の支給項目では割増基礎・控除基礎は使用しない
                'is_allowance_base' => false,
                'is_deduction_base' => false,
            ]],
            ['commute_taxable', '通勤手当/課税', 'commute', [
                'calc_method' => 'employee',
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
                // MF準拠: 通勤手当は端数処理「切り上げ」固定
                'rounding' => 'ceil',
            ]],
            ['commute_non_taxable', '通勤手当/非課', 'commute', [
                'calc_method' => 'employee',
                'is_income_tax_target' => false,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
                // MF準拠: 通勤手当は端数処理「切り上げ」固定
                'rounding' => 'ceil',
            ]],

            // ── どでん 運用で追加した時給の手当（初期データとして投入） ──
            // 手入力連動・0円でも表示（show_zero=true）。ユーザー追加項目のため is_system=false（削除可）。
            ['custom_wcnewgqg', '仕込み手当', 'custom', [
                'calc_method' => 'custom',
                'custom_formula' => $this->rateTimesQty('hourly2', '時給2', 'scheduled_time_prescribed_holiday', '所定時間（所定休日）'),
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
                'show_zero' => true,
                'is_system' => false,
            ]],
            ['custom_hs456twe', '深夜手当', 'custom', [
                'calc_method' => 'custom',
                'custom_formula' => $this->numTimesQty(300, 'night_weekday', '深夜所定時間（平日）'),
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
                'show_zero' => true,
                'is_system' => false,
            ]],
            ['custom_dmipwlfo', '祝日深夜手当', 'custom', [
                'calc_method' => 'custom',
                'custom_formula' => $this->numTimesQty(500, 'night_prescribed_holiday', '深夜所定時間（所定休日）'),
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
                'show_zero' => true,
                'is_system' => false,
            ]],
            ['custom_mziof4i0', '紹介料', 'custom', [
                'calc_method' => 'employee',
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
                'show_zero' => true,
                'is_system' => false,
            ]],
            ['custom_xbxntm9o', '特別手当', 'custom', [
                'calc_method' => 'employee',
                'divisor_unit' => 'one',
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
                'show_zero' => true,
                'is_system' => false,
            ]],
            ['custom_ra29jqqh', '所定休日手当', 'custom', [
                'calc_method' => 'custom',
                'custom_formula' => $this->numTimesQty(200, 'scheduled_time_prescribed_holiday', '所定時間（所定休日）'),
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
                'show_zero' => true,
                'is_system' => false,
            ]],
        ];
    }

    /**
     * 日給区分の初期支給項目（MF準拠）。
     * 日給1/日給2 は従業員ごとの単価入力。支給項目「基本給」で 単価(日給1)×出勤日数（合算） を計算する。
     *
     * @return array<int, array{0:string,1:string,2:string,3:array<string,mixed>}>
     */
    private function dailyPayItems(): array
    {
        return [
            ['base_salary', '基本給', 'basic', [
                'calc_method' => 'custom',
                'custom_formula' => $this->rateTimesQty('daily1', '日給1', 'work_days_total', '出勤日数（合算）'),
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
                // MF準拠: 日給の支給項目では割増基礎・控除基礎は使用しない
                'is_allowance_base' => false,
                'is_deduction_base' => false,
            ]],
            ['commute_taxable', '通勤手当/課税', 'commute', [
                'calc_method' => 'employee',
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
                // MF準拠: 通勤手当は端数処理「切り上げ」固定
                'rounding' => 'ceil',
            ]],
            ['commute_non_taxable', '通勤手当/非課', 'commute', [
                'calc_method' => 'employee',
                'is_income_tax_target' => false,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
                // MF準拠: 通勤手当は端数処理「切り上げ」固定
                'rounding' => 'ceil',
            ]],
        ];
    }

    /**
     * 賞与区分の初期支給項目（MF準拠）。
     * 賞与は勤怠から自動算出できないため「手入力」のみ（他の計算方法は選択不可）。
     *
     * @return array<int, array{0:string,1:string,2:string,3:array<string,mixed>}>
     */
    private function bonusPayItems(): array
    {
        return [
            ['bonus_allowance', '賞与手当', 'basic', [
                'calc_method' => 'manual',
                'is_income_tax_target' => true,
                'is_labor_insurance_target' => true,
                'is_social_insurance_target' => true,
            ]],
        ];
    }

    /**
     * 「単価(basis) × 勤怠数量」の custom_formula トークン列を返す。
     * 時給1×総労働時間（平日） / 日給1×出勤日数（合算） 等（いずれも打刻由来・二重計上なし）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function rateTimesQty(string $basisCode, string $basisLabel, string $qtyCode, string $qtyLabel): array
    {
        return [
            ['t' => 'ref', 'kind' => 'basis', 'code' => $basisCode, 'label' => $basisLabel],
            ['t' => 'op', 'value' => '*'],
            ['t' => 'ref', 'kind' => 'attendance', 'code' => $qtyCode, 'label' => $qtyLabel],
        ];
    }

    /**
     * 「固定単価(円) × 勤怠数量」の custom_formula トークン列を返す。
     * 例: 300円 × 深夜所定時間（平日）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function numTimesQty(int|float $amount, string $qtyCode, string $qtyLabel): array
    {
        return [
            ['t' => 'num', 'value' => $amount],
            ['t' => 'op', 'value' => '*'],
            ['t' => 'ref', 'kind' => 'attendance', 'code' => $qtyCode, 'label' => $qtyLabel],
        ];
    }

    private function seedDeductionItems(): void
    {
        // [code, name, category, calc_method, calc_description, is_active]
        $items = [
            ['health_insurance', '健康保険料', 'social_insurance', 'statutory', '標準報酬月額 × 健康保険料率（従業員負担）', true],
            ['nursing_insurance', '介護保険料', 'social_insurance', 'statutory', '標準報酬月額 × 介護保険料率（40〜64歳）', true],
            ['child_contribution', '子ども・子育て支援金', 'social_insurance', 'statutory', '標準報酬月額 × 拠出金率', true],
            ['pension_insurance', '厚生年金保険料', 'pension', 'statutory', '標準報酬月額 × 厚生年金保険料率（従業員負担）', true],
            ['pension_fund', '厚生年金基金掛金', 'pension', 'statutory', '標準報酬月額 × 厚生年金基金掛金率', false],
            ['social_insurance_adjust', '社会保険料調整', 'social_insurance', 'manual', '毎月手入力', false],
            ['defined_contribution', '確定拠出年金', 'pension', 'employee', '従業員情報で設定', false],
            ['employment_insurance', '雇用保険料', 'labor_insurance', 'statutory', '労働保険の計算対象 × 雇用保険料率（従業員負担）', true],
            ['income_tax', '所得税', 'tax', 'statutory', '(所得税の計算対象 − 社会保険料合計) × 所得税率', true],
            ['resident_tax', '住民税', 'tax', 'employee', '従業員情報で設定', true],
            ['year_end_adjustment', '年調過不足税額', 'tax', 'employee', '年末調整で算出した過不足税額を設定', false],
        ];

        foreach ($items as $i => [$code, $name, $category, $method, $desc, $active]) {
            DeductionItemMaster::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'category' => $category,
                    'calc_method' => $method,
                    'calc_description' => $desc,
                    'is_active' => $active,
                    'is_system' => true,
                    'sort_order' => $i,
                ],
            );
        }
    }

    /**
     * サンプルの事業所・料率・標準報酬等級。実運用値は管理画面で登録し直す。
     */
    private function seedInsuranceSample(): void
    {
        $location = BusinessLocation::firstOrCreate(
            ['name' => '本社'],
            [
                'is_main' => true,
                'health_insurance_type' => 'kyokai',
                'prefecture' => '東京都',
                'accident_industry_code' => 'wholesale_retail_food_lodging',
                'employment_industry_type' => 'general',
                'sort_order' => 0,
            ],
        );

        $set = InsuranceRateSet::firstOrCreate(
            [
                'business_location_id' => $location->id,
                'effective_from' => '2026-04-01',
            ],
            [
                'name' => '2026年度 サンプル（東京・協会けんぽ）',
                'effective_to' => null,
            ],
        );

        // [kind, employee_rate(/1,000), employer_rate(/1,000)]  ※サンプル値（千分率）
        $rates = [
            ['health', 49.55, 49.55],
            ['nursing', 7.95, 7.95],
            ['pension', 91.50, 91.50],
            ['child_contribution', 0.00, 3.60],
            ['employment', 6.00, 9.50],
            ['accident', 0.00, 3.00],
        ];

        foreach ($rates as [$kind, $emp, $empr]) {
            InsuranceRate::updateOrCreate(
                ['insurance_rate_set_id' => $set->id, 'kind' => $kind],
                ['employee_rate' => $emp, 'employer_rate' => $empr],
            );
        }

        // 標準報酬月額の等級表は LegalMasterSeeder が全等級を投入する（ここでは投入しない）。
    }
}
