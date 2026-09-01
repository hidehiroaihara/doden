<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 独自休日に出典(source)を追加し、年度に祝日の最終取込日時を追加する。
 *   source='manual'         … 手入力（会社独自休日など）
 *   source='cabinet_office' … 内閣府CSVからの取込
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_year_custom_holidays', function (Blueprint $table) {
            $table->string('source', 32)->default('manual')->after('label')
                ->comment('出典: manual=手入力 / cabinet_office=内閣府CSV取込');
        });

        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->timestamp('holidays_imported_at')->nullable()->after('monthly_avg_work_hours')
                ->comment('内閣府CSVからの祝日 最終取込日時');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_year_custom_holidays', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropColumn('holidays_imported_at');
        });
    }
};
