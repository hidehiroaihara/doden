<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 従業員の扶養情報（配偶者・扶養家族）。従業員情報＞一般情報の扶養情報セクションで管理。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_dependents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('last_name')->nullable()->comment('姓');
            $table->string('first_name')->nullable()->comment('名');
            $table->string('last_name_kana')->nullable()->comment('姓カナ');
            $table->string('first_name_kana')->nullable()->comment('名カナ');
            $table->date('birth_date')->nullable()->comment('生年月日');
            $table->string('relationship')->nullable()->comment('続柄');
            $table->text('my_number')->nullable()->comment('個人番号（暗号化）');

            $table->boolean('lives_together')->default(true)->comment('同居区分');
            $table->boolean('is_income_tax_dependent')->default(false)->comment('源泉控除対象');
            // 扶養区分: general(一般)/specific(特定)/elderly_live_together(老人・同居)/elderly(老人)/other
            $table->string('dependent_type')->default('general')->comment('扶養区分');
            $table->boolean('is_same_livelihood_spouse')->default(false)->comment('同一生計配偶者');
            // 障害者区分: none/general/special
            $table->string('disability_type')->default('none')->comment('障害者区分');
            $table->boolean('is_health_insurance_dependent')->default(false)->comment('健保扶養区分');
            $table->unsignedInteger('annual_income')->nullable()->comment('合計所得（円）');

            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_dependents');
    }
};
