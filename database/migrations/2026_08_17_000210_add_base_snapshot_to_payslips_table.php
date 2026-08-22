<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 給与明細に「割増基礎・控除基礎・当月所定」のスナップショットを保存する。
 * 翌月計算時の「前月の割増基礎/控除基礎」を正確な実績値で参照するために使用。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->integer('allowance_base')->default(0)->after('net_pay')->comment('当月の割増基礎（円・スナップショット）');
            $table->integer('deduction_base')->default(0)->after('allowance_base')->comment('当月の控除基礎（円・スナップショット）');
            $table->decimal('scheduled_work_days', 6, 2)->default(0)->after('deduction_base')->comment('当月の所定労働日数');
            $table->integer('scheduled_work_minutes')->default(0)->after('scheduled_work_days')->comment('当月の所定労働時間（分）');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['allowance_base', 'deduction_base', 'scheduled_work_days', 'scheduled_work_minutes']);
        });
    }
};
