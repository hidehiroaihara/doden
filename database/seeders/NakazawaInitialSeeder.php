<?php

namespace Database\Seeders;

use App\Models\BusinessLocation;
use App\Models\ClosingDateGroup;
use App\Models\InsuranceRate;
use App\Models\InsuranceRateSet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 株式会社ナカザワ 初期データ投入シーダー。
 *
 *   - 事業所（本社＋各店舗）と社会保険・労働保険の料率セット（全事業所共通）
 *   - 従業員（CSV: database/seeders/data/nakazawa_employees.csv）
 *
 * 従業員は「部門」列の店舗を所属事業所(business_location)へ割当。
 * 退職年月日があれば is_active=false（一覧では初期非表示）。
 *
 * ⚠ 既存の従業員データを全削除して置き換える（初期セットアップ用）。
 *
 * 実行:  php artisan db:seed --class=NakazawaInitialSeeder
 */
class NakazawaInitialSeeder extends Seeder
{
    private const CSV = __DIR__.'/data/nakazawa_employees.csv';

    private const MAIN = '株式会社ナカザワ';

    /** 店舗（所属事業所として作成）。本社以外。 */
    private const STORES = ['川口', '西川口', '大宮', '新都心', '北浦和'];

    /** 保険料率（千分率 /1,000）。全事業所共通・2026年度。 */
    private const RATES = [
        ['health', 49.25, 49.25],
        ['nursing', 8.10, 8.10],
        ['child_support', 1.15, 1.15],
        ['pension', 91.50, 91.50],
        ['child_contribution', 0.00, 3.60],
        ['employment', 5.00, 8.50],
        ['accident', 0.00, 3.00],
    ];

    /** 労働保険の共通項目（各事業所同一内容）。 */
    private const LABOR = [
        'accident_industry_code' => 'wholesale_retail_food_lodging', // 卸売業・小売業、飲食店又は宿泊業
        'employment_industry_type' => 'general',                     // 一般の事業
        'labor_bureau' => '荒川 労働基準監督署',
        'employment_bureau' => '荒川 ハローワーク（公共職業安定所）',
        'accident_business_desc' => '飲食店',
    ];

    public function run(): void
    {
        if (! is_file(self::CSV)) {
            $this->command->error('従業員CSVが見つかりません: '.self::CSV);

            return;
        }

        DB::transaction(function () {
            $this->clearEmployees();
            $locations = $this->seedLocations();
            $this->seedRateSets($locations);
            $created = $this->seedEmployees($locations);

            $this->command->info(sprintf(
                'NakazawaInitialSeeder: 事業所 %d 件 / 従業員 %d 件を投入しました。',
                count($locations),
                $created,
            ));
        });
    }

    /** 既存の従業員・事業所・保険・給与バッチを全削除（初期セットアップ用）。 */
    private function clearEmployees(): void
    {
        $tables = [
            'attendance_edit_logs', 'attendance_breaks', 'attendances',
            'payslip_items', 'payslips', 'payroll_runs',
            'employee_pay_item_values', 'employee_commute_routes', 'employee_dependents',
            'user_status_histories', 'employee_leaves', 'year_end_adjustments',
            'employee_payrolls', 'users',
            'insurance_rates', 'insurance_rate_sets', 'business_locations',
        ];

        Schema::disableForeignKeyConstraints();
        foreach ($tables as $t) {
            if (Schema::hasTable($t)) {
                DB::table($t)->delete();
            }
        }
        Schema::enableForeignKeyConstraints();
    }

    /**
     * 事業所（本社＋店舗）を作成。全て東京都・協会けんぽ。
     *
     * @return array<string, BusinessLocation>
     */
    private function seedLocations(): array
    {
        $map = [];

        $map[self::MAIN] = BusinessLocation::updateOrCreate(
            ['name' => self::MAIN],
            array_merge(self::LABOR, [
                'is_main' => true,
                'health_insurance_type' => 'kyokai',
                'prefecture' => '東京都',
                'labor_insurance_number' => '13312962420-256',
                'labor_insurance_pref_code' => '13',
                'labor_insurance_jurisdiction_code' => '3',
                'labor_insurance_office_code' => '12',
                'labor_insurance_serial_number' => '962420',
                'labor_insurance_branch_code' => '256',
                'employment_office_number' => '11316270614',
                'note' => 'TEL 048-607-1129',
                'sort_order' => 0,
            ]),
        );

        foreach (self::STORES as $i => $name) {
            $map[$name] = BusinessLocation::updateOrCreate(
                ['name' => $name],
                array_merge(self::LABOR, [
                    'is_main' => false,
                    'health_insurance_type' => 'kyokai',
                    'prefecture' => '東京都',
                    'sort_order' => $i + 1,
                ]),
            );
        }

        return $map;
    }

    /**
     * 各事業所に 2026年度 の料率セット（社会保険＋労働保険）を作成。
     *
     * @param  array<string, BusinessLocation>  $locations
     */
    private function seedRateSets(array $locations): void
    {
        foreach ($locations as $name => $loc) {
            $set = InsuranceRateSet::updateOrCreate(
                ['business_location_id' => $loc->id, 'effective_from' => '2026-04-01'],
                ['name' => '2026年度 '.$name, 'effective_to' => null],
            );

            foreach (self::RATES as [$kind, $emp, $empr]) {
                InsuranceRate::updateOrCreate(
                    ['insurance_rate_set_id' => $set->id, 'kind' => $kind],
                    ['employee_rate' => $emp, 'employer_rate' => $empr],
                );
            }
        }
    }

    /**
     * CSVから従業員を投入。
     *
     * @param  array<string, BusinessLocation>  $locations
     */
    private function seedEmployees(array $locations): int
    {
        $closingGroupId = ClosingDateGroup::orderBy('sort_order')->value('id');

        $rows = $this->readCsv();
        $created = 0;

        foreach ($rows as $r) {
            if (($r['last_name'] ?? '') === '' && ($r['first_name'] ?? '') === '') {
                continue;
            }

            $retirementDate = $this->parseDate($r['retirement_date'] ?? '');
            $isActive = $retirementDate === null;

            $user = User::create([
                'last_name' => $r['last_name'] ?? '',
                'first_name' => $r['first_name'] ?? null,
                'last_name_kana' => $r['last_name_kana'] ?: null,
                'first_name_kana' => $r['first_name_kana'] ?: null,
                'gender' => $this->mapGender($r['gender'] ?? ''),
                'customer_no' => $r['employee_no'] ?: null,
                'joined_at' => $this->parseDate($r['joined_at'] ?? ''),
                'retirement_date' => $retirementDate,
                'retirement_type' => $this->mapRetirementType($r['retirement_type'] ?? ''),
                'retirement_reason' => $r['retirement_reason'] ?: null,
                'is_active' => $isActive,
                'role' => 1,
                'password' => Str::password(32),
            ]);

            $location = $this->resolveLocation($r['department'] ?? '', $locations);

            $user->employeePayroll()->create([
                'business_location_id' => $location?->id,
                'closing_date_group_id' => $closingGroupId,
                'employee_no' => $r['employee_no'] ?: null,
                'employment_type' => $this->mapEmploymentType($r['contract'] ?? ''),
                'pay_type' => $this->mapPayType($r['pay'] ?? ''),
                'tax_table' => 'kou',
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * 「部門」列 → 所属事業所。空欄は本社。複合(北浦和・大宮)は先頭を採用。
     *
     * @param  array<string, BusinessLocation>  $locations
     */
    private function resolveLocation(string $dept, array $locations): ?BusinessLocation
    {
        $dept = trim($dept);
        if ($dept === '') {
            return $locations[self::MAIN] ?? null;
        }

        $first = preg_split('/[・,、]/u', $dept)[0] ?? $dept;
        $first = trim($first);

        return $locations[$first] ?? $locations[self::MAIN] ?? null;
    }

    /** @return list<array<string, string>> */
    private function readCsv(): array
    {
        $handle = fopen(self::CSV, 'r');
        $header = fgetcsv($handle);
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === false) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($data[$i]) ? trim((string) $data[$i]) : '';
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function parseDate(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        try {
            return Carbon::parse(str_replace('/', '-', $v))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function mapGender(string $v): ?string
    {
        return match (trim($v)) {
            '男' => 'male',
            '女' => 'female',
            default => null,
        };
    }

    private function mapEmploymentType(string $v): string
    {
        return match (trim($v)) {
            '正社員' => 'full_time',
            'アルバイト' => 'arbeit',
            'パート' => 'part_time',
            '契約社員' => 'contract',
            '嘱託社員' => 'entrusted',
            '役員' => 'executive',
            '派遣社員' => 'dispatch',
            default => 'other',
        };
    }

    private function mapPayType(string $v): string
    {
        return match (trim($v)) {
            '月給制' => 'monthly',
            '時給制' => 'hourly',
            '日給制' => 'daily',
            default => 'monthly',
        };
    }

    private function mapRetirementType(string $v): ?string
    {
        $v = trim($v);

        return $v === '' ? null : $v;
    }
}
