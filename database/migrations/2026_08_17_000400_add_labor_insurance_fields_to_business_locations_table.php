<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 事業所に労働保険（労災・雇用）の業種区分と帳票用の任意項目を追加する。
 * 業種を選択すると保険料率が自動セットされる（LaborInsuranceRates プリセット参照）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->string('accident_industry_code')->nullable()->after('office_number')
                ->comment('労災保険の業種コード（LaborInsuranceRates::accidentIndustries）');
            $table->string('employment_industry_type')->nullable()->after('accident_industry_code')
                ->comment('雇用保険の事業区分（general/agri_sake_forestry/construction）');
            $table->string('labor_bureau')->nullable()->after('employment_industry_type')
                ->comment('管轄労働局・労働基準監督署（帳票用・任意）');
            $table->string('accident_business_desc')->nullable()->after('labor_bureau')
                ->comment('労災: 事業の具体的内容（帳票用・任意）');
            $table->string('employment_office_number')->nullable()->after('accident_business_desc')
                ->comment('雇用保険適用事業所番号（帳票用・任意）');
        });
    }

    public function down(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->dropColumn([
                'accident_industry_code',
                'employment_industry_type',
                'labor_bureau',
                'accident_business_desc',
                'employment_office_number',
            ]);
        });
    }
};
