<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            // 端末を店舗（部門）に紐付ける。未設定（全店共通の端末）も許容するため nullable。
            $table->foreignId('department_id')
                ->nullable()
                ->after('terminal_key')
                ->constrained('departments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
