<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 雇用保険の管轄（ハローワーク／公共職業安定所）を保持する。
 * 既存の labor_bureau は労災の管轄（労働基準監督署）として使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->string('employment_bureau')->nullable()->after('labor_bureau')
                ->comment('雇用保険の管轄ハローワーク（公共職業安定所）');
        });
    }

    public function down(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->dropColumn('employment_bureau');
        });
    }
};
