<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 税制措置マスタ（時限的な税制対応の制度管理）。
 * 定額減税のように適用期間が限定される制度を「種別＋適用開始/終了月＋金額」で管理し、
 * 給与計算エンジンは対象期間のバッチにのみ自動適用する。
 *
 * 参照: 資料/設計書 28_定額減税（6章 時限的税制対応の汎用化）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_measures', function (Blueprint $table) {
            $table->id();
            // type: flat_tax_reduction(定額減税・所得税) 等
            $table->string('type')->default('flat_tax_reduction');
            $table->string('name');
            $table->unsignedSmallInteger('target_year')->comment('対象年（暦年）');
            $table->char('start_period', 7)->comment('適用開始 Y-m');
            $table->char('end_period', 7)->nullable()->comment('適用終了 Y-m（無期限はnull）');
            $table->integer('per_person_amount')->default(0)->comment('1人あたりの控除額（円）');
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active', 'target_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_measures');
    }
};
