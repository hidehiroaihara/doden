<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 所得税徴収高計算書の手入力値。
 * 対象年月・帳票種別ごとに自動集計できない区分や延滞税・摘要を JSON で保持する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_tax_statement_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month')->comment('一般分=対象月 / 納特分=6 or 12');
            $table->string('form_type')->default('general')->comment('general / special');
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->json('data');
            $table->timestamps();

            $table->unique(['year', 'month', 'form_type', 'business_location_id'], 'income_tax_override_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_tax_statement_overrides');
    }
};
