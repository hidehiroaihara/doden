<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 事業所に社会保険（健康保険・厚生年金保険・厚生年金基金）の管理用メタ項目を追加する。
 * MFクラウド給与「社会保険」設定（se06）に準拠。
 * - 健康保険: 組合名・事業所整理記号（組合管掌用。帳票非反映・管理用）
 * - 厚生年金保険: 管轄・事業所番号・事業所整理番号（算定基礎届等の帳票印字用）
 * - 厚生年金基金: 基金名・基金番号・基金事業所番号（管理用）。掛金料率は insurance_rates(pension_fund) で管理
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            // 健康保険（組合管掌）
            $table->string('health_union_name')->nullable()->after('prefecture')
                ->comment('健康保険組合名（組合管掌・管理用）');
            $table->string('health_office_symbol')->nullable()->after('health_union_name')
                ->comment('健康保険 事業所整理記号（管理用）');

            // 厚生年金保険（帳票印字用）
            $table->string('pension_jurisdiction')->nullable()->after('health_office_symbol')
                ->comment('厚生年金保険 管轄（帳票用・任意）');
            $table->string('pension_office_number')->nullable()->after('pension_jurisdiction')
                ->comment('厚生年金保険 事業所番号（帳票用・任意）');
            $table->string('pension_office_symbol')->nullable()->after('pension_office_number')
                ->comment('厚生年金保険 事業所整理番号（帳票用・任意）');

            // 厚生年金基金（管理用）
            $table->string('pension_fund_name')->nullable()->after('pension_office_symbol')
                ->comment('厚生年金基金 基金名（管理用）');
            $table->string('pension_fund_number')->nullable()->after('pension_fund_name')
                ->comment('厚生年金基金 基金番号（管理用）');
            $table->string('pension_fund_office_number')->nullable()->after('pension_fund_number')
                ->comment('厚生年金基金 基金事業所番号（管理用）');
        });

        // 既存の汎用 office_number を厚生年金の事業所整理番号へ引き継ぐ（未設定のもののみ）
        DB::table('business_locations')
            ->whereNotNull('office_number')
            ->whereNull('pension_office_symbol')
            ->update(['pension_office_symbol' => DB::raw('office_number')]);
    }

    public function down(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->dropColumn([
                'health_union_name',
                'health_office_symbol',
                'pension_jurisdiction',
                'pension_office_number',
                'pension_office_symbol',
                'pension_fund_name',
                'pension_fund_number',
                'pension_fund_office_number',
            ]);
        });
    }
};
