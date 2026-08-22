<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 給与: 項目マスタ（支給項目 / 控除項目 / 勤怠項目）
 *
 * 設計原則(payroll-design-guide §5): 表示項目は直書きせずマスタ駆動でレンダリングする。
 * 各項目は内部 code を持ち、数式・他画面からは name ではなく code で参照する。
 * 参照: 資料/設計書 09_支給項目 / 10_控除項目 / 11_勤怠項目
 */
return new class extends Migration
{
    public function up(): void
    {
        // 支給項目マスタ（給与区分 monthly/hourly/daily/bonus ごとに独立）
        Schema::create('pay_item_masters', function (Blueprint $table) {
            $table->id();
            $table->string('pay_type')->default('monthly')->comment('給与区分: monthly/hourly/daily/bonus');
            $table->string('code')->comment('内部参照コード（数式・連携キー）');
            $table->string('name');
            // 区分: basic/overtime/deduction/commute/manual/fixed_overtime/other/custom
            $table->string('category')->default('other');
            $table->boolean('is_active')->default(true);

            // 計算方法: manual(毎月手入力)/employee(従業員情報で設定)/allowance_base(割増基礎)/
            //           prev_allowance_base/deduction_base/prev_deduction_base/custom(カスタム計算式)
            $table->string('calc_method')->default('employee');
            $table->decimal('divisor_base', 10, 2)->nullable()->comment('数式ビルダー: 除数の基礎値（未使用時null）');
            $table->string('divisor_unit')->nullable()->comment('除算単位: work_hours_monthly_avg / work_days_monthly_avg 等');
            $table->decimal('multiplier', 6, 3)->nullable()->comment('割増倍率（例 1.25/0.25/1.35）');
            $table->string('quantity_unit')->nullable()->comment('時間数/日数として参照する勤怠項目code');
            $table->json('custom_formula')->nullable()->comment('カスタム計算式（トークン列）');

            // 詳細設定フラグ（設計書09 3-5）
            $table->boolean('is_income_tax_target')->default(false)->comment('所得税の計算対象');
            $table->boolean('is_labor_insurance_target')->default(false)->comment('労働保険の計算対象');
            $table->boolean('is_social_insurance_target')->default(false)->comment('社会保険の計算対象');
            $table->boolean('is_fixed_wage')->default(false)->comment('固定的賃金（月額変更用）');
            $table->boolean('is_in_kind')->default(false)->comment('現物');
            $table->boolean('is_allowance_base')->default(false)->comment('割増基礎');
            $table->boolean('is_deduction_base')->default(false)->comment('控除基礎');
            $table->boolean('is_leave_target')->default(false)->comment('休職・休業の計算対象');
            $table->boolean('show_zero')->default(false)->comment('0円でも表示');
            $table->boolean('is_daily_proration_base')->default(false)->comment('日割り計算基礎');

            $table->string('sign')->default('plus')->comment('plus/minus（欠勤控除等はminus）');
            $table->string('rounding')->default('round')->comment('端数処理: ceil/round/floor');
            $table->boolean('is_system')->default(false)->comment('システム標準項目（削除不可）');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['pay_type', 'code']);
        });

        // 控除項目マスタ（給与区分によらず単一・全従業員共通）
        Schema::create('deduction_item_masters', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            // 区分: social_insurance/pension/labor_insurance/tax/custom
            $table->string('category')->default('custom');
            $table->boolean('is_active')->default(true);
            // 計算方法: statutory(法定固定計算)/manual(毎月手入力)/employee(従業員情報で設定)
            $table->string('calc_method')->default('statutory');
            $table->string('calc_description')->nullable()->comment('計算方法の説明文（法定項目の固定表示用）');
            $table->boolean('show_zero')->default(false)->comment('0円でも表示');
            $table->boolean('is_system')->default(false)->comment('システム標準項目（削除不可）');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique('code');
        });

        // 勤怠項目マスタ（カテゴリ×計測種別）
        Schema::create('attendance_item_masters', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            // カテゴリ: fixed_work(所定労働)/attendance(出欠勤)/actual_work(実働時間)/leave(休暇)
            $table->string('category')->default('actual_work');
            $table->boolean('is_active')->default(true);
            // 表示単位: hour / hour_1 / hour_decimal(10進) / hour_min60(60進) / day / day_decimal / count
            $table->string('unit_format')->default('hour_decimal');
            $table->boolean('show_zero')->default(false)->comment('0でも表示');
            $table->boolean('is_system')->default(false)->comment('システム標準項目（削除不可・改名不可）');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_item_masters');
        Schema::dropIfExists('deduction_item_masters');
        Schema::dropIfExists('pay_item_masters');
    }
};
