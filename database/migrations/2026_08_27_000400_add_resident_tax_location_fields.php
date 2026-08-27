<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 住民税（MF em05 準拠）の拡張。
 * - resident_tax_municipalities: 都道府県で市区町村を絞り込むため prefecture を追加。
 * - employee_payrolls: 提出先/納付先の都道府県、宛名番号（整理番号）を追加。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resident_tax_municipalities', function (Blueprint $table) {
            $table->string('prefecture')->nullable()->after('name')->comment('都道府県');
            $table->index('prefecture');
        });

        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->string('report_prefecture')->nullable()->after('report_municipality')
                ->comment('給与支払報告書 提出先 都道府県');
            $table->string('resident_tax_prefecture')->nullable()->after('resident_tax_municipality')
                ->comment('住民税 納付先 都道府県');
            $table->string('resident_tax_reference_number')->nullable()->after('resident_tax_recipient_number')
                ->comment('宛名番号（整理番号）');
        });
    }

    public function down(): void
    {
        Schema::table('resident_tax_municipalities', function (Blueprint $table) {
            $table->dropIndex(['prefecture']);
            $table->dropColumn('prefecture');
        });

        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->dropColumn([
                'report_prefecture',
                'resident_tax_prefecture',
                'resident_tax_reference_number',
            ]);
        });
    }
};
