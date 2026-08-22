<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 給与: コア（従業員給与情報 / 給与計算バッチ / 給与明細 / 明細内訳）
 *
 * 参照: 資料/設計書 04_給与計算 / 05_従業員情報 / 19_給与明細
 */
return new class extends Migration
{
    public function up(): void
    {
        // 従業員給与情報（users の1:1拡張。給与計算に必要な属性を集約）
        Schema::create('employee_payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_title_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('closing_date_group_id')->nullable()->constrained()->nullOnDelete();

            $table->string('employee_no')->nullable()->comment('従業員番号');
            // 契約種別: executive/employee_executive/full_time/contract/entrusted/part_time/arbeit/dispatch/other
            $table->string('employment_type')->default('full_time');
            // 給与区分: monthly(月給)/hourly(時給)/daily(日給)
            $table->string('pay_type')->default('monthly');

            $table->unsignedInteger('base_salary')->default(0)->comment('基本給（月給・円）');
            $table->unsignedInteger('hourly_wage')->default(0)->comment('時給（円）');
            $table->unsignedInteger('daily_wage')->default(0)->comment('日給（円）');

            // 所得税
            $table->string('tax_table')->default('kou')->comment('源泉徴収税額表区分: kou(甲)/otsu(乙)');
            $table->unsignedTinyInteger('dependents_count')->default(0)->comment('扶養親族等の数');

            // 社会保険
            $table->boolean('is_social_insurance_enrolled')->default(false)->comment('社会保険 加入');
            $table->boolean('is_employment_insurance_enrolled')->default(false)->comment('雇用保険 加入');
            $table->boolean('is_care_insurance_target')->default(false)->comment('介護保険 対象(40-64歳)');
            $table->unsignedSmallInteger('standard_reward_grade_health')->nullable()->comment('標準報酬等級（健保）');
            $table->unsignedInteger('standard_reward_health')->nullable()->comment('標準報酬月額（健保・円）');
            $table->unsignedSmallInteger('standard_reward_grade_pension')->nullable()->comment('標準報酬等級（厚年）');
            $table->unsignedInteger('standard_reward_pension')->nullable()->comment('標準報酬月額（厚年・円）');

            // 通勤手当（課税/非課税を分離管理: 設計書09）
            $table->unsignedInteger('commute_allowance_taxable')->default(0)->comment('通勤手当（課税・円）');
            $table->unsignedInteger('commute_allowance_non_taxable')->default(0)->comment('通勤手当（非課税・円）');

            // 住民税（従業員情報で設定）
            $table->unsignedInteger('resident_tax_monthly')->default(0)->comment('住民税 月額（7-5月・円）');
            $table->unsignedInteger('resident_tax_june')->default(0)->comment('住民税 6月分（円）');

            $table->timestamps();
            $table->unique('user_id');
        });

        // 給与計算バッチ（支給期間単位）
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('period_key', 7)->comment('対象期間 Y-m');
            $table->string('pay_type')->default('salary')->comment('salary(給与)/bonus(賞与)');
            $table->date('closing_date')->nullable()->comment('締め日');
            $table->date('payment_date')->nullable()->comment('支給日');
            $table->date('publish_date')->nullable()->comment('明細公開日');
            // status: draft(下書き)/calculated(計算済)/finalized(確定)
            $table->string('status')->default('draft');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['business_location_id', 'period_key', 'pay_type'], 'payroll_runs_scope_unique');
        });

        // 給与明細（従業員×バッチ）
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->integer('total_earnings')->default(0)->comment('支給合計（円）');
            $table->integer('total_deductions')->default(0)->comment('控除合計（円）');
            $table->integer('net_pay')->default(0)->comment('差引支給額（円）');
            $table->text('remarks')->nullable()->comment('備考（明細に反映・最大1000字）');
            $table->boolean('is_confirmed')->default(false)->comment('担当者 確認済');
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'user_id']);
        });

        // 明細内訳（支給/控除/勤怠の各行。マスタからスナップショット）
        Schema::create('payslip_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            // item_type: earning(支給)/deduction(控除)/attendance(勤怠)
            $table->string('item_type');
            $table->unsignedBigInteger('source_master_id')->nullable()->comment('由来マスタID（参照用・FK制約なし）');
            $table->string('code');
            $table->string('name');
            $table->string('category')->nullable();
            $table->integer('amount')->nullable()->comment('金額（円）。勤怠項目はnull');
            $table->integer('minutes')->nullable()->comment('時間（分）。勤怠の時間項目');
            $table->decimal('quantity', 8, 2)->nullable()->comment('数量（日数・回数等）');
            $table->boolean('is_manual_override')->default(false)->comment('手入力で上書きされた');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['payslip_id', 'item_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_items');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employee_payrolls');
    }
};
