<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 従業員一覧の既定並び順を「従業員番号（自然順）」に変更する。
 * 既定値 join_date のままの場合のみ employee_no_number へ更新し、
 * 管理者が明示的に変更済みの設定は尊重する。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'employee_sort_key')
            ->where('value', 'join_date')
            ->update(['value' => 'employee_no_number', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'employee_sort_key')
            ->where('value', 'employee_no_number')
            ->update(['value' => 'join_date', 'updated_at' => now()]);
    }
};
