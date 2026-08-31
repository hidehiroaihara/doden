<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 従業員ごとの定額減税（所得税）総額の手動上書きを保持する。
 *
 * - null  … 自動（税制措置マスタの1人あたり額 × 対象人数）
 * - 0以上 … 手動で総額を固定（給与計算の残額計算に使用）
 *
 * 参照: 資料/設計書 28_定額減税 / MF 給与情報「控除項目」
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->unsignedInteger('flat_tax_reduction_total')->nullable()->after('dependents_count')
                ->comment('定額減税 総額の手動上書き（円）。null=自動計算');
        });
    }

    public function down(): void
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->dropColumn('flat_tax_reduction_total');
        });
    }
};
