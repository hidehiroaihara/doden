<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * フェーズ2: 賞与計算バッチにおける従業員ごとの賞与総支給額の入力値。
 * 賞与は勤怠から自動算出できないため、担当者が入力した総支給額を保持し、
 * BonusCalculator が社会保険・所得税を算出する起点とする。
 *
 * 参照: 資料/設計書 04_給与計算（賞与計算）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonus_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('gross_amount')->default(0)->comment('賞与総支給額（円）');
            // 前月給与（社会保険料等控除後）— 賞与源泉徴収税率の判定に使用
            $table->unsignedInteger('previous_month_taxable')->default(0)->comment('前月の社保控除後給与（円）');
            $table->timestamps();

            $table->unique(['payroll_run_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_inputs');
    }
};
