<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 給与明細へ「計算時に適用した法定マスタ」をスナップショットする。
 *
 * 目的: 料率・標準報酬・税額表などのマスタが後日改定・修正されても、
 *       過去の給与明細の内容（と根拠）が塗り替わらないようにする。
 *       計算時に適用値を明細へ保存し、確定でロックする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->unsignedBigInteger('insurance_rate_set_id')->nullable()->after('scheduled_work_minutes')
                ->comment('適用した保険料率セットID（参照用・FK制約なし）');
            $table->json('applied_rates')->nullable()->after('insurance_rate_set_id')
                ->comment('適用した各保険の従業員負担率(千分率)スナップショット');
            $table->unsignedInteger('snapshot_standard_reward_health')->nullable()->after('applied_rates')
                ->comment('適用した標準報酬月額(健保)');
            $table->unsignedInteger('snapshot_standard_reward_pension')->nullable()->after('snapshot_standard_reward_health')
                ->comment('適用した標準報酬月額(厚年)');
            $table->unsignedSmallInteger('snapshot_grade_health')->nullable()->after('snapshot_standard_reward_pension')
                ->comment('適用した標準報酬等級(健保)');
            $table->unsignedSmallInteger('snapshot_grade_pension')->nullable()->after('snapshot_grade_health')
                ->comment('適用した標準報酬等級(厚年)');
            $table->string('snapshot_tax_table')->nullable()->after('snapshot_grade_pension')
                ->comment('適用した税額表区分 kou/otsu');
            $table->unsignedTinyInteger('snapshot_dependents_count')->nullable()->after('snapshot_tax_table')
                ->comment('適用した扶養親族等の数');
            $table->string('income_tax_source')->nullable()->after('snapshot_dependents_count')
                ->comment('適用した源泉所得税マスタの識別子（例: table:2024 / builtin）');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn([
                'insurance_rate_set_id',
                'applied_rates',
                'snapshot_standard_reward_health',
                'snapshot_standard_reward_pension',
                'snapshot_grade_health',
                'snapshot_grade_pension',
                'snapshot_tax_table',
                'snapshot_dependents_count',
                'income_tax_source',
            ]);
        });
    }
};
