<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 従業員別の支給項目金額（calc_method='employee' の項目）。
 * MFの「従業員情報 > 給与情報 > 支給項目」に相当し、給与計算へ反映される。
 *
 * 既存の employee_payrolls.base_salary をバックフィルして表示・計算の整合を保つ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_pay_item_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_item_master_id')->constrained('pay_item_masters')->cascadeOnDelete();
            $table->integer('amount')->default(0)->comment('従業員別の支給額（円）');
            $table->timestamps();

            $table->unique(['user_id', 'pay_item_master_id']);
        });

        // 既存 base_salary をバックフィル（monthly の base_salary 項目へ）
        $baseItem = DB::table('pay_item_masters')
            ->where('pay_type', 'monthly')
            ->where('code', 'base_salary')
            ->first();

        if ($baseItem) {
            DB::table('employee_payrolls')
                ->select('id', 'user_id', 'base_salary')
                ->chunkById(200, function ($rows) use ($baseItem) {
                    foreach ($rows as $r) {
                        if ((int) $r->base_salary <= 0) {
                            continue;
                        }
                        DB::table('employee_pay_item_values')->updateOrInsert(
                            ['user_id' => $r->user_id, 'pay_item_master_id' => $baseItem->id],
                            ['amount' => (int) $r->base_salary, 'updated_at' => now(), 'created_at' => now()],
                        );
                    }
                }, 'id');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_pay_item_values');
    }
};
