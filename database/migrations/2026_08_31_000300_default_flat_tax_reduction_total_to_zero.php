<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 定額減税（所得税）総額の既定値を「自動計算オフ・0円」に統一する。
 *
 * 旧仕様: flat_tax_reduction_total = null → 自動計算 ON（チェック付き）
 * 新仕様: 0 → 手動 0 円（チェック外し）。自動計算は null を明示保存した場合のみ。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('employee_payrolls')
            ->whereNull('flat_tax_reduction_total')
            ->update(['flat_tax_reduction_total' => 0]);
    }

    public function down(): void
    {
        // 0 に統一したデータを自動計算(null)へ戻すと意図が不明なため no-op。
    }
};
