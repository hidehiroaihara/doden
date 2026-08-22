<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 従業員の通勤手当（MF同様に複数ルートを登録可能）。
 * 通勤経路・支給条件（定額/出勤日数に応じて）・支給額・支払手段・上限・非課税限度額を保持し、
 * 給与計算で通勤手当（課税/非課税）を算出する。
 *
 * 既存 employee_payrolls.commute_allowance_taxable/non_taxable を1ルートへバックフィル。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_commute_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            // 通勤手段: train/bus/car/motorbike/bicycle/walk
            $table->string('transport_type')->default('train');
            $table->string('from_place')->nullable()->comment('出発駅/開始地点');
            $table->string('to_place')->nullable()->comment('到着駅/終了地点');
            // 支給条件: fixed(定額で支給) / by_workdays(出勤日数に応じて支給)
            $table->string('condition')->default('fixed');
            $table->json('payment_months')->nullable()->comment('定額時の支給月（1-12の配列。空=毎月）');
            $table->string('attendance_item_code')->nullable()->comment('出勤日数連動時に使用する勤怠項目コード');
            $table->integer('amount')->default(0)->comment('定額=月額 / 出勤日数連動=日額（円）');
            // 支払手段: cash(金銭) / in_kind(現物)
            $table->string('payment_method')->default('cash');
            $table->integer('cap_amount')->nullable()->comment('上限支給額（円・null=上限なし）');
            $table->integer('non_taxable_limit')->nullable()->comment('非課税限度額（円/月・null=全額非課税扱い）');
            $table->timestamps();

            $table->index(['user_id', 'sort_order']);
        });

        // 既存の通勤手当を1ルートへバックフィル（分割再現のため non_taxable_limit=非課税額）
        DB::table('employee_payrolls')
            ->select('id', 'user_id', 'commute_allowance_taxable', 'commute_allowance_non_taxable')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $r) {
                    $taxable = (int) $r->commute_allowance_taxable;
                    $nonTaxable = (int) $r->commute_allowance_non_taxable;
                    if ($taxable <= 0 && $nonTaxable <= 0) {
                        continue;
                    }
                    DB::table('employee_commute_routes')->insert([
                        'user_id' => $r->user_id,
                        'sort_order' => 0,
                        'transport_type' => 'train',
                        'condition' => 'fixed',
                        'payment_months' => null,
                        'amount' => $taxable + $nonTaxable,
                        'payment_method' => 'cash',
                        'cap_amount' => null,
                        'non_taxable_limit' => $nonTaxable,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_commute_routes');
    }
};
