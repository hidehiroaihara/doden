<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 給与計算バッチの期間メモ。
 * ※給与明細の備考(payslips.remarks)とは別物。メモは明細には表示しない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->text('memo')->nullable()->after('finalized_at')->comment('期間メモ（明細には非表示）');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('memo');
        });
    }
};
