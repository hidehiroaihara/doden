<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * insurance_rates.employee_rate / employer_rate を「%」から「千分率(/1,000)」へ移行する。
 * 例) 健康 4.955% → 49.55‰。
 *
 * 冪等性: settings に移行済みフラグを立て、再実行しても二重変換しない。
 * PayrollCalculator 側の除数を /100 → /1000 に変更するのと対で、計算結果は不変。
 */
return new class extends Migration
{
    private const FLAG_KEY = 'insurance_rates_permille_migrated';

    public function up(): void
    {
        if (! Schema::hasTable('insurance_rates') || ! Schema::hasTable('settings')) {
            return;
        }

        $already = DB::table('settings')->where('key', self::FLAG_KEY)->value('value');
        if ($already === '1') {
            return;
        }

        DB::table('insurance_rates')->update([
            'employee_rate' => DB::raw('employee_rate * 10'),
            'employer_rate' => DB::raw('employer_rate * 10'),
        ]);

        DB::table('settings')->updateOrInsert(
            ['key' => self::FLAG_KEY],
            ['value' => '1', 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('insurance_rates') || ! Schema::hasTable('settings')) {
            return;
        }

        $already = DB::table('settings')->where('key', self::FLAG_KEY)->value('value');
        if ($already !== '1') {
            return;
        }

        DB::table('insurance_rates')->update([
            'employee_rate' => DB::raw('employee_rate / 10'),
            'employer_rate' => DB::raw('employer_rate / 10'),
        ]);

        DB::table('settings')->where('key', self::FLAG_KEY)->delete();
    }
};
