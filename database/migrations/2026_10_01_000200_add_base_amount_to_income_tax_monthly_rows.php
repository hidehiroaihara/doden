<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 月額表の高額帯（740,000円以上）を「基準額＋超過額×率」の形で持てるようにする。
 *
 * 原表の高額帯は税額が表に載らず「〇〇円の税額に、〇〇円を超える金額の△％を加算」と
 * 定義されるため、基準額(base_amount)と超過額の起点(excess_over)を保持する。
 * 税額 = base_amount + floor((給与 − excess_over) × rate)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('income_tax_monthly_rows', function (Blueprint $table) {
            $table->unsignedInteger('base_amount')->nullable()->after('tax_amount')
                ->comment('高額帯の基準となる税額（円）。rate と併用');
            $table->unsignedInteger('excess_over')->nullable()->after('base_amount')
                ->comment('超過額を計算する起点の給与額（円）');
            // 45.945% のように小数第3位までの率があるため桁を広げる
            $table->decimal('rate', 8, 5)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('income_tax_monthly_rows', function (Blueprint $table) {
            $table->dropColumn(['base_amount', 'excess_over']);
            $table->decimal('rate', 6, 4)->nullable()->change();
        });
    }
};
