<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 店舗(departments)に所属事業所(business_locations)を紐付ける。
 *
 * 店舗は事業所に振り分けられる（多対1）。保険・労働保険は事業所単位、
 * 打刻・勤怠表示は店舗単位という既存構造を保ったまま関連を追加する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('business_location_id')
                ->nullable()
                ->after('name')
                ->constrained('business_locations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_location_id');
        });
    }
};
