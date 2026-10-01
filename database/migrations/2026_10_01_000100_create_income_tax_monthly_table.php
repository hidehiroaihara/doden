<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 給与所得の源泉徴収税額表（月額表）のマスタ。
 *
 * 国税庁が毎年公表する「給与所得の源泉徴収税額表（月額表）」を年分ごとに保持する。
 * 電算機計算の特例（income_tax_brackets）とは別方式で、社会保険料等控除後の
 * 給与等の金額の階級 × 扶養親族等の数 から税額を直接引く（復興特別所得税込み）。
 *
 * 運用方針:
 *  - 年分ごとに income_tax_monthly_tables を1件追加し、行を CSV で取り込む（過去分は編集しない）。
 *  - 適用日は effective_from / effective_to で判定する。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 年分（適用期間）ごとのヘッダ。どの年分が登録済みかを画面で示すために使う。
        Schema::create('income_tax_monthly_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('例: 令和8年分 給与所得の源泉徴収税額表（月額表）');
            $table->unsignedSmallInteger('target_year')->nullable()->comment('対象年分（西暦）');
            $table->date('effective_from')->comment('適用開始日');
            $table->date('effective_to')->nullable()->comment('適用終了日（nullは現行）');
            $table->unsignedInteger('dependent_over7_deduction')->default(0)
                ->comment('扶養親族等が7人を超える場合の1人あたり控除額（円）');
            $table->string('source_note')->nullable()->comment('出典・突合メモ（例: 国税庁 令和8年分 2026-01-15確認）');
            $table->timestamps();

            $table->unique('effective_from');
        });

        // 階級（以上・未満）× 扶養親族等の数 → 税額。
        Schema::create('income_tax_monthly_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('income_tax_monthly_table_id')->constrained()->cascadeOnDelete();
            $table->string('tax_table')->comment('kou(甲) / otsu(乙)');
            $table->unsignedInteger('min_amount')->default(0)->comment('社保控除後の給与 下限（円・以上）');
            $table->unsignedBigInteger('max_amount')->nullable()->comment('上限（円・未満）。最上位はnull');
            $table->unsignedTinyInteger('dependents')->default(0)->comment('扶養親族等の数（甲欄 0〜7 / 乙欄は0）');
            $table->unsignedInteger('tax_amount')->nullable()->comment('税額（円・復興特別所得税込み）');
            $table->decimal('rate', 6, 4)->nullable()->comment('率計算の階級で使う税率（乙欄の高額帯など）');
            $table->integer('deduction')->default(0)->comment('率計算時の控除額（円）');
            $table->timestamps();

            $table->index(['income_tax_monthly_table_id', 'tax_table', 'dependents', 'min_amount'], 'income_tax_monthly_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_tax_monthly_rows');
        Schema::dropIfExists('income_tax_monthly_tables');
    }
};
