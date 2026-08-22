<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 休職・休業種別マスタ（基本設定＞全般で管理）。
 * 従業員情報の休職・休業情報が leave_type_id で参照する。
 * 参照: MF「全般」画面（休職・休業種別）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->comment('休職・休業コード');
            $table->string('name')->comment('休職・休業名');
            // 種別: childcare(育児休業)/maternity(産前産後休業)/work_injury(業務上の傷病)/nursing(介護休業)/other(その他)
            $table->string('leave_kind')->default('other')->comment('休職・休業種別');
            // 支給項目の計算方法: all_zero(全て0円)/same_as_normal(休職期間外と同じ)/leave_target_only(計算対象のみ)
            $table->string('pay_calc_method')->default('all_zero')->comment('支給項目の計算方法');
            $table->boolean('is_active')->default(true)->comment('有効（従業員情報の選択肢に表示）');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
