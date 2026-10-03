<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 給与計算の丸め方式を「打刻時刻の丸め（出勤=切上げ / 退勤=切捨て）」へ切り替える。
 *
 * time    : 出勤を30分グリッドへ切上げ、退勤を切捨て（8:09→8:30, 17:15→17:00）
 * minutes : 従来どおりシフトごとの実労働分を丸め単位・ルールで丸める
 *
 * 運用ルールに合わせ、丸め単位も30分へ揃える。
 */
return new class extends Migration
{
    private const KEY = 'salary_round_mode';

    public function up(): void
    {
        if (! DB::table('settings')->where('key', self::KEY)->exists()) {
            DB::table('settings')->insert([
                'key' => self::KEY,
                'value' => 'time',
                'description' => '給与計算の丸め方式（time=打刻時刻を丸める, minutes=実労働分を丸める）',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('settings')->updateOrInsert(
            ['key' => 'salary_round_minutes'],
            ['value' => '30', 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::KEY)->delete();
    }
};
