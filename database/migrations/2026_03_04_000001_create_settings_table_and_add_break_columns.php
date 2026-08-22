<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        DB::table('settings')->insert([
            [
                'key' => 'default_break_minutes',
                'value' => '60',
                'description' => 'デフォルト休憩時間（分）',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'break_start_time',
                'value' => '12:00',
                'description' => '規定休憩 開始時刻',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'break_end_time',
                'value' => '13:00',
                'description' => '規定休憩 終了時刻',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'salary_round_minutes',
                'value' => '15',
                'description' => '給料計算の丸め単位（分）',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'salary_round_rule',
                'value' => 'floor',
                'description' => '給料計算の丸めルール（floor=切り捨て, round=四捨五入, ceil=切り上げ）',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'work_start_time',
                'value' => null,
                'description' => '所定出勤時刻',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'work_end_time',
                'value' => null,
                'description' => '所定退勤時刻',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'work_hours_per_day',
                'value' => null,
                'description' => '1日の所定労働時間（分）',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'month_closing_day',
                'value' => null,
                'description' => '月締め日(1〜31, 未設定なら月末締め)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
