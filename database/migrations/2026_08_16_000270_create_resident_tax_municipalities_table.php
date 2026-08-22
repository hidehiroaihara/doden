<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * フェーズ2: 住民税納付先市区町村マスタ。
 * 従業員の「納付先市区町村」を保存すると自動的に行が追加され、
 * 事業所側で市区町村ごとの指定番号（特別徴収義務者番号）を管理する。
 *
 * 参照: 資料/設計書 14_基本設定_住民税 / 22_住民税徴収額一覧表
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_tax_municipalities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('市区町村名');
            $table->string('designation_number')->nullable()->comment('指定番号（特別徴収義務者番号）');
            $table->timestamps();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_tax_municipalities');
    }
};
