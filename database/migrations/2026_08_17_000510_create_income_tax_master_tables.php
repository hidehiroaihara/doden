<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 源泉所得税マスタ（適用期間つき）。
 *
 * - income_tax_brackets: 月額の「電子計算機による計算の特例」係数（甲欄/乙欄）。
 *   税額 = floor(課税対象 × rate − deduction)。甲欄は課税対象=社保控除後−扶養控除×人数。
 * - bonus_tax_rate_brackets: 賞与に対する源泉徴収税額の算出率（前月社保控除後給与→率）。
 *
 * いずれも effective_from/effective_to で年度改定に対応（古い行は編集せず新年度行を追加）。
 * 係数は Seeder(LegalMasterSeeder) で投入。実運用前に対象年度の官報値と突合すること。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_tax_brackets', function (Blueprint $table) {
            $table->id();
            $table->string('tax_table')->comment('kou(甲) / otsu(乙)');
            $table->unsignedInteger('min_amount')->default(0)->comment('課税対象 下限（円・以上）');
            $table->unsignedBigInteger('max_amount')->nullable()->comment('課税対象 上限（円・未満）。最上位はnull');
            $table->decimal('rate', 6, 4)->default(0)->comment('税率（小数。0.05=5%）');
            $table->integer('deduction')->default(0)->comment('速算控除額（円）');
            $table->unsignedInteger('dependent_deduction')->nullable()->comment('扶養親族等1人あたり控除（甲欄・円/月）');
            $table->date('effective_from')->comment('適用開始日');
            $table->date('effective_to')->nullable()->comment('適用終了日（nullは現行）');
            $table->timestamps();

            $table->index(['tax_table', 'effective_from']);
        });

        Schema::create('bonus_tax_rate_brackets', function (Blueprint $table) {
            $table->id();
            $table->string('tax_table')->comment('kou(甲) / otsu(乙)');
            $table->unsignedInteger('min_prev_taxable')->default(0)->comment('前月社保控除後給与 下限（円・以上）');
            $table->unsignedBigInteger('max_prev_taxable')->nullable()->comment('前月社保控除後給与 上限（円・未満）。最上位はnull');
            $table->decimal('rate', 7, 3)->default(0)->comment('賞与源泉税率（%。6.126=6.126%）');
            $table->unsignedInteger('dependent_shift')->nullable()->comment('扶養1人あたり前月給与の閾値シフト額（円）');
            $table->date('effective_from')->comment('適用開始日');
            $table->date('effective_to')->nullable()->comment('適用終了日（nullは現行）');
            $table->timestamps();

            $table->index(['tax_table', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_tax_rate_brackets');
        Schema::dropIfExists('income_tax_brackets');
    }
};
