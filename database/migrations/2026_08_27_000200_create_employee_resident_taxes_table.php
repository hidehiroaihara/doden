<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 従業員ごとの住民税納付額（年度・月別）。
 *
 * 住民税は6月〜翌5月を1年度として、支給月ごとに控除額が異なる（特別徴収税額通知）。
 * fiscal_year は年度の起点となる6月の西暦（例: 2026 = 2026年6月〜2027年5月）。
 * month は暦月（1〜12）。amount はその月に控除する住民税額。
 *
 * 既存 employee_payrolls.resident_tax_monthly / resident_tax_june は後方互換として残す。
 * 該当年度・月の行があればそれを優先し、なければ従来列にフォールバックする。
 *
 * 参照: 資料/設計書 05_従業員情報 / MF em05 住民税納付額
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_resident_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year')->comment('年度（起点6月の西暦）');
            $table->unsignedTinyInteger('month')->comment('暦月 1-12');
            $table->unsignedInteger('amount')->default(0)->comment('住民税 控除額（円）');
            $table->timestamps();

            $table->unique(['user_id', 'fiscal_year', 'month']);
            $table->index(['user_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_resident_taxes');
    }
};
