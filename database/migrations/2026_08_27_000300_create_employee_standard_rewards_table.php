<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 従業員ごとの標準報酬月額 履歴（適用開始月つき）。
 *
 * 定時決定・随時改定で標準報酬月額は改定されるため、「適用開始月」の異なる複数の
 * 標準報酬を保持し、給与計算では支給月に有効な最新の行を用いる。
 * 行が無い年月は employee_payrolls.standard_reward_health / _pension（現行の単一値）へフォールバックする。
 *
 * 参照: 資料/設計書 12_社会保険 / MF em05 健康保険・厚生年金保険
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_standard_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('applied_from')->comment('適用開始月（月初日）');
            $table->unsignedSmallInteger('health_grade')->nullable()->comment('標準報酬等級（健保）');
            $table->unsignedInteger('health_amount')->nullable()->comment('標準報酬月額（健保・円）');
            $table->unsignedSmallInteger('pension_grade')->nullable()->comment('標準報酬等級（厚年）');
            $table->unsignedInteger('pension_amount')->nullable()->comment('標準報酬月額（厚年・円）');
            $table->timestamps();

            $table->index(['user_id', 'applied_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_standard_rewards');
    }
};
