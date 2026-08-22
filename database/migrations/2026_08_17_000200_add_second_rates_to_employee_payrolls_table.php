<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->unsignedInteger('hourly_wage2')->nullable()->after('hourly_wage');
            $table->unsignedInteger('daily_wage2')->nullable()->after('daily_wage');
        });
    }

    public function down(): void
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->dropColumn(['hourly_wage2', 'daily_wage2']);
        });
    }
};
