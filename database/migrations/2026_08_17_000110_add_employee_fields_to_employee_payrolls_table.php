<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 従業員情報リニューアル: employee_payrolls へ業務情報・所得税区分を追加。
 * 業務情報（所定労働）は給与計算の勤怠表示・カスタム計算式の除算基礎にも連動する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            // 業務情報
            $table->string('position')->nullable()->after('job_title_id')->comment('役職');
            $table->decimal('work_hours_per_day', 5, 2)->nullable()->comment('1日の所定労働時間');
            $table->decimal('work_days_per_month', 5, 2)->nullable()->comment('所定労働日数（当月）');
            $table->decimal('work_days_monthly_avg', 5, 2)->nullable()->comment('所定労働日数（月平均）');
            $table->decimal('work_hours_per_month', 6, 2)->nullable()->comment('所定労働時間（当月）');
            $table->decimal('work_hours_monthly_avg', 6, 2)->nullable()->comment('所定労働時間（月平均）');

            // 所得税の区分（甲乙のほかの特例区分）
            $table->boolean('is_widow')->default(false)->comment('寡婦');
            $table->boolean('is_single_parent')->default(false)->comment('ひとり親');
            $table->string('disability_type')->default('none')->comment('障害者区分: none/general/special');
            $table->boolean('is_working_student')->default(false)->comment('勤労学生');
            $table->boolean('is_minor')->default(false)->comment('未成年者');
            $table->boolean('is_disaster')->default(false)->comment('災害者');
            $table->boolean('is_foreigner')->default(false)->comment('外国人');
            $table->string('residency_type')->default('resident')->comment('居住区分: resident/non_resident');

            // 給与支払報告書の提出先市区町村
            $table->string('report_municipality')->nullable()->comment('給与支払報告書 提出先市区町村');
        });
    }

    public function down(): void
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->dropColumn([
                'position', 'work_hours_per_day', 'work_days_per_month', 'work_days_monthly_avg',
                'work_hours_per_month', 'work_hours_monthly_avg',
                'is_widow', 'is_single_parent', 'disability_type', 'is_working_student',
                'is_minor', 'is_disaster', 'is_foreigner', 'residency_type', 'report_municipality',
            ]);
        });
    }
};
