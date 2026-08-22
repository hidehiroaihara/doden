<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 年末調整。
 * 従業員・年ごとに、年間給与総額・源泉徴収税額・社会保険料等の集計値と、
 * 各種申告控除（生命保険料・地震保険料・配偶者控除・住宅ローン控除 等）の入力値、
 * および算出された年調年税額・過不足税額を保持する。
 *
 * 過不足税額は控除項目「年調過不足税額（year_end_adjustment）」として
 * 12月等の給与バッチへ反映する。
 *
 * 参照: 資料/設計書 30_源泉徴収簿 / 04_給与計算（控除項目 year_end_adjustment）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('year_end_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');

            // 集計値（給与計算確定データから取込）
            $table->integer('gross_total')->default(0)->comment('年間給与総額');
            $table->integer('social_insurance_withheld')->default(0)->comment('源泉控除済み社会保険料');
            $table->integer('withheld_tax')->default(0)->comment('徴収済み所得税額');

            // 申告控除の入力値
            $table->integer('social_insurance_declared')->default(0)->comment('申告social保険料（給与天引き外）');
            $table->integer('life_insurance_deduction')->default(0)->comment('生命保険料控除');
            $table->integer('earthquake_insurance_deduction')->default(0)->comment('地震保険料控除');
            $table->integer('spouse_deduction')->default(0)->comment('配偶者(特別)控除');
            $table->unsignedSmallInteger('dependent_count')->default(0)->comment('扶養控除対象人数');
            $table->integer('housing_loan_credit')->default(0)->comment('住宅借入金等特別控除（税額控除）');
            $table->integer('other_deduction')->default(0)->comment('その他の所得控除');

            // 算出結果
            $table->integer('salary_income')->default(0)->comment('給与所得控除後の金額');
            $table->integer('taxable_income')->default(0)->comment('課税給与所得金額');
            $table->integer('calculated_tax')->default(0)->comment('算出所得税額');
            $table->integer('yearly_tax')->default(0)->comment('年調年税額（復興税込）');
            $table->integer('adjustment_amount')->default(0)->comment('過不足税額（＋追徴／−還付）');

            // status: draft(下書き) / confirmed(確定) / reflected(給与反映済)
            $table->string('status')->default('draft');
            $table->foreignId('reflected_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->timestamp('reflected_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'year']);
            $table->index(['year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('year_end_adjustments');
    }
};
