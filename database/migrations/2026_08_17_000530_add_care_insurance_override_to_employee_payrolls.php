<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 介護保険該当を「生年月日からの自動判定」を既定にしつつ、手動上書きも残す。
 *
 * care_insurance_override: null=自動判定 / true=対象にする / false=対象外にする。
 * 既存の is_care_insurance_target=true のデータは、明示的に対象化していた意思を尊重して
 * override=true へ移行する（自動判定で外れてしまうのを防ぐ）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->boolean('care_insurance_override')->nullable()->after('is_care_insurance_target')
                ->comment('介護保険該当の手動上書き: null=自動判定 / 1=対象 / 0=対象外');
        });

        // 既存で対象=trueだったものは明示上書き(true)として引き継ぐ。
        DB::table('employee_payrolls')->where('is_care_insurance_target', true)
            ->update(['care_insurance_override' => true]);
    }

    public function down(): void
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->dropColumn('care_insurance_override');
        });
    }
};
