<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 労働保険設定をMFクラウド給与に合わせて拡張する。
 *
 * 1) 労災保険のメリット制（適用あり時は事業主料率を上書き）。
 * 2) 労働保険番号を府県(2)/所掌(1)/管轄(2)/基幹番号(6)/枝番号(3) に分割保持。
 *    既存の labor_insurance_number（連結値）は表示・後方互換のため残し、
 *    保存時に分割値から自動合成する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            // 労災メリット制
            $table->boolean('accident_merit_enabled')->default(false)->after('accident_industry_code')
                ->comment('労災保険のメリット制 適用あり');
            $table->decimal('accident_merit_rate', 8, 3)->nullable()->after('accident_merit_enabled')
                ->comment('メリット制の労災保険料率（/1,000・事業主負担）。適用時は業種プリセットを上書き');

            // 労働保険番号の分割
            $table->string('labor_insurance_pref_code', 2)->nullable()->after('labor_insurance_number')->comment('労働保険番号: 府県(2桁)');
            $table->string('labor_insurance_jurisdiction_code', 1)->nullable()->after('labor_insurance_pref_code')->comment('労働保険番号: 所掌(1桁)');
            $table->string('labor_insurance_office_code', 2)->nullable()->after('labor_insurance_jurisdiction_code')->comment('労働保険番号: 管轄(2桁)');
            $table->string('labor_insurance_serial_number', 6)->nullable()->after('labor_insurance_office_code')->comment('労働保険番号: 基幹番号(6桁)');
            $table->string('labor_insurance_branch_code', 3)->nullable()->after('labor_insurance_serial_number')->comment('労働保険番号: 枝番号(3桁)');
        });

        $this->backfillNumberParts();
    }

    /**
     * 既存の連結値（例: "13312962420-256" / "1331296242 0256"）を分割カラムへ取り込む。
     * 数字のみ14桁とみなせる場合のみ分割する。
     */
    private function backfillNumberParts(): void
    {
        $rows = DB::table('business_locations')
            ->whereNotNull('labor_insurance_number')
            ->get(['id', 'labor_insurance_number']);

        foreach ($rows as $row) {
            $digits = preg_replace('/\D/', '', (string) $row->labor_insurance_number);
            if (strlen($digits) !== 14) {
                continue;
            }

            DB::table('business_locations')->where('id', $row->id)->update([
                'labor_insurance_pref_code' => substr($digits, 0, 2),
                'labor_insurance_jurisdiction_code' => substr($digits, 2, 1),
                'labor_insurance_office_code' => substr($digits, 3, 2),
                'labor_insurance_serial_number' => substr($digits, 5, 6),
                'labor_insurance_branch_code' => substr($digits, 11, 3),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->dropColumn([
                'accident_merit_enabled',
                'accident_merit_rate',
                'labor_insurance_pref_code',
                'labor_insurance_jurisdiction_code',
                'labor_insurance_office_code',
                'labor_insurance_serial_number',
                'labor_insurance_branch_code',
            ]);
        });
    }
};
