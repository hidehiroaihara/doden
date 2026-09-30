<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 深夜時間の月合計を丸め単位へ揃えるかの設定を追加する。
 *
 * 深夜帯の境界は 22:00 固定なので、打刻ごとに丸めた実労働から深夜分を切り出すと
 * 丸め単位の倍数にならない（例: 17:57〜23:27 → 深夜87分 = 1.45時間）。
 * 既定は ON。'0' にすると従来どおり端数のまま集計する。
 */
return new class extends Migration
{
    private const KEY = 'salary_round_night_total';

    public function up(): void
    {
        if (DB::table('settings')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key' => self::KEY,
            'value' => '1',
            'description' => '深夜時間の月合計も丸め単位へ揃える（1=する, 0=しない）',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::KEY)->delete();
    }
};
