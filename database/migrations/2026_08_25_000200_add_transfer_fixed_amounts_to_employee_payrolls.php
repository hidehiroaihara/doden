<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 賃金台帳の振込支給1/2（MF: 振込支給１・振込支給２）用。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->unsignedInteger('transfer_fixed_amount1')->default(0)->after('account_holder_kana')
                ->comment('振込支給1（円・固定）');
            $table->unsignedInteger('transfer_fixed_amount2')->default(0)->after('transfer_fixed_amount1')
                ->comment('振込支給2（円・固定）');
        });
    }

    public function down(): void
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->dropColumn(['transfer_fixed_amount1', 'transfer_fixed_amount2']);
        });
    }
};
