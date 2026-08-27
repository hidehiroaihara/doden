<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MFクラウド給与「給与情報」の資格・区分・社会保険料（本人/会社・額表/手入力）に合わせて
 * employee_payrolls へ届出向け項目と保険料上書きを追加する。
 *
 * - 健康保険 / 厚生年金保険: 区分（短時間就労者・坑内夫）、資格取得/喪失日、被保険者整理番号・基礎年金番号、資格喪失原因
 * - 労災 / 雇用保険: 労災 従業員区分、雇用 資格取得/喪失日・被保険者番号・資格喪失原因
 * - 社会保険料: 健保/介護/子ども子育て/厚年ごとに「額表(table) or 手入力(manual)」と本人/会社の手入力額
 *
 * 参照: 資料/設計書 05_従業員情報 / MF em05 給与情報
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            // 健康保険 / 厚生年金保険 区分
            $table->boolean('is_short_time_worker')->default(false)->after('is_care_insurance_target')
                ->comment('短時間就労者（パートタイマー）');
            $table->boolean('is_miner')->default(false)->after('is_short_time_worker')
                ->comment('坑内夫');

            // 健康保険 資格情報
            $table->date('health_qualified_at')->nullable()->after('standard_reward_pension')
                ->comment('健康保険 資格取得年月日');
            $table->date('health_lost_at')->nullable()->after('health_qualified_at')
                ->comment('健康保険 資格喪失年月日');
            $table->string('health_lost_reason')->nullable()->after('health_lost_at')
                ->comment('健康保険 資格喪失原因');
            $table->string('health_insured_number')->nullable()->after('health_lost_reason')
                ->comment('被保険者整理番号');

            // 厚生年金 資格情報
            $table->date('pension_qualified_at')->nullable()->after('health_insured_number')
                ->comment('厚生年金 資格取得年月日');
            $table->date('pension_lost_at')->nullable()->after('pension_qualified_at')
                ->comment('厚生年金 資格喪失年月日');
            $table->string('pension_lost_reason')->nullable()->after('pension_lost_at')
                ->comment('厚生年金 資格喪失原因');
            $table->string('basic_pension_number')->nullable()->after('pension_lost_reason')
                ->comment('基礎年金番号');

            // 労災 / 雇用保険
            $table->string('accident_employee_type')->default('regular')->after('basic_pension_number')
                ->comment('労災 従業員区分: regular(常用)/temporary(臨時)/director_worker(役員で労働者扱い)');
            $table->date('employment_qualified_at')->nullable()->after('accident_employee_type')
                ->comment('雇用保険 資格取得年月日');
            $table->date('employment_lost_at')->nullable()->after('employment_qualified_at')
                ->comment('雇用保険 離職等年月日');
            $table->string('employment_lost_reason')->nullable()->after('employment_lost_at')
                ->comment('雇用保険 資格喪失原因');
            $table->string('employment_insured_number')->nullable()->after('employment_lost_reason')
                ->comment('雇用保険 被保険者番号');

            // 社会保険料 手入力上書き（本人/会社）。mode=table のとき料率表で自動計算、manual のとき手入力額を使用。
            foreach (['health', 'nursing', 'child', 'pension'] as $k) {
                $table->string("{$k}_premium_mode")->default('table')->comment("{$k} 保険料: table(額表)/manual(手入力)");
                $table->unsignedInteger("{$k}_premium_employee")->nullable()->comment("{$k} 保険料 本人（手入力・円）");
                $table->unsignedInteger("{$k}_premium_employer")->nullable()->comment("{$k} 保険料 会社（手入力・円）");
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->dropColumn([
                'is_short_time_worker',
                'is_miner',
                'health_qualified_at',
                'health_lost_at',
                'health_lost_reason',
                'health_insured_number',
                'pension_qualified_at',
                'pension_lost_at',
                'pension_lost_reason',
                'basic_pension_number',
                'accident_employee_type',
                'employment_qualified_at',
                'employment_lost_at',
                'employment_lost_reason',
                'employment_insured_number',
            ]);
            foreach (['health', 'nursing', 'child', 'pension'] as $k) {
                $table->dropColumn(["{$k}_premium_mode", "{$k}_premium_employee", "{$k}_premium_employer"]);
            }
        });
    }
};
