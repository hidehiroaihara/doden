<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 退勤打刻時の店舗（事業所）スナップショットを保持するカラムを追加する。
     * 既存の department_id は「出勤店舗」を表し、こちらは「退勤店舗」を表す。
     * 出勤店舗と退勤店舗が異なる場合（勤務中に別店舗へ移動）に両方を残せるようにする。
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('clock_out_department_id')
                ->nullable()
                ->after('department_id')
                ->comment('退勤打刻時点の店舗(事業所)スナップショット')
                ->constrained('departments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('clock_out_department_id');
        });
    }
};
