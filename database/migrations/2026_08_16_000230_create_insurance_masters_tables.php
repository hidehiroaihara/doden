<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 給与: 保険マスタ（標準報酬等級 / 料率セット / 料率）
 *
 * 料率は年次で改定されるため「事業所 × 適用期間」で世代管理し、
 * 計算時は支給対象日が属する期間の料率を引く（確定済みは当時の率で固定）。
 * 参照: 資料/設計書 12_社会保険 / 13_労働保険、payroll-design-guide §5.2
 */
return new class extends Migration
{
    public function up(): void
    {
        // 標準報酬月額 等級表（健康保険 / 厚生年金 で別系列。適用期間で世代管理）
        Schema::create('standard_reward_grades', function (Blueprint $table) {
            $table->id();
            $table->string('insurance_type')->comment('health(健康保険) / pension(厚生年金)');
            $table->unsignedSmallInteger('grade')->comment('等級');
            $table->unsignedInteger('monthly_amount')->comment('標準報酬月額（円）');
            $table->unsignedInteger('lower_bound')->comment('報酬月額 下限（円・以上）');
            $table->unsignedInteger('upper_bound')->nullable()->comment('報酬月額 上限（円・未満）。最上位はnull');
            $table->date('effective_from')->comment('適用開始日');
            $table->date('effective_to')->nullable()->comment('適用終了日（nullは現行）');
            $table->timestamps();

            $table->index(['insurance_type', 'effective_from']);
        });

        // 料率セット（事業所 × 適用期間）
        Schema::create('insurance_rate_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_location_id')->constrained()->cascadeOnDelete();
            $table->string('name')->comment('例: 2026年度 東京');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['business_location_id', 'effective_from']);
        });

        // 各保険の料率（従業員負担率・事業主負担率を個別保持。折半でない保険にも対応）
        Schema::create('insurance_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_rate_set_id')->constrained()->cascadeOnDelete();
            // kind: health(健康)/nursing(介護)/pension(厚生年金)/child_contribution(子ども子育て拠出金)/
            //       employment(雇用)/accident(労災)/pension_fund(厚生年金基金)
            $table->string('kind');
            $table->decimal('employee_rate', 8, 5)->default(0)->comment('従業員負担率（%）');
            $table->decimal('employer_rate', 8, 5)->default(0)->comment('事業主負担率（%）');
            $table->timestamps();

            $table->unique(['insurance_rate_set_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_rates');
        Schema::dropIfExists('insurance_rate_sets');
        Schema::dropIfExists('standard_reward_grades');
    }
};
