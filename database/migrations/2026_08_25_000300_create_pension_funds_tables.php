<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 厚生年金基金（MFクラウド給与準拠）。
 *
 * 1事業所に複数の基金を登録でき、基金ごとに「適用開始月」単位の掛金料率を持つ。
 * 料率は給与・賞与で別々に、被保険者負担／事業主負担を /1,000（千分率）で保持する。
 *
 * 旧: business_locations.pension_fund_* + insurance_rates(kind=pension_fund) 単一構成から移行する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pension_funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_location_id')->constrained()->cascadeOnDelete();
            $table->string('name')->comment('基金名');
            $table->string('number')->nullable()->comment('基金番号');
            $table->string('office_number')->nullable()->comment('基金の事業所番号');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['business_location_id', 'sort_order']);
        });

        Schema::create('pension_fund_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pension_fund_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from')->comment('適用開始月（月初日で保持）');
            $table->decimal('salary_employee_rate', 8, 5)->default(0)->comment('給与 被保険者負担（/1,000）');
            $table->decimal('salary_employer_rate', 8, 5)->default(0)->comment('給与 事業主負担（/1,000）');
            $table->decimal('bonus_employee_rate', 8, 5)->default(0)->comment('賞与 被保険者負担（/1,000）');
            $table->decimal('bonus_employer_rate', 8, 5)->default(0)->comment('賞与 事業主負担（/1,000）');
            $table->timestamps();

            $table->unique(['pension_fund_id', 'effective_from']);
        });

        $this->backfill();
    }

    /**
     * 旧構成（business_locations.pension_fund_* + insurance_rates(pension_fund)）から
     * 新テーブルへ移行する。給与料率は旧掛金料率、賞与料率は0で初期化する。
     */
    private function backfill(): void
    {
        $locations = DB::table('business_locations')->get();

        foreach ($locations as $loc) {
            // 旧掛金料率（この事業所の最新の料率セットにある pension_fund）
            $latestSet = DB::table('insurance_rate_sets')
                ->where('business_location_id', $loc->id)
                ->orderByDesc('effective_from')
                ->first();

            $oldRate = $latestSet
                ? DB::table('insurance_rates')
                    ->where('insurance_rate_set_id', $latestSet->id)
                    ->where('kind', 'pension_fund')
                    ->first()
                : null;

            $hasName = ! empty($loc->pension_fund_name);
            $employee = (float) ($oldRate->employee_rate ?? 0);
            $employer = (float) ($oldRate->employer_rate ?? 0);
            $hasRate = $employee > 0 || $employer > 0;

            if (! $hasName && ! $hasRate) {
                continue;
            }

            $fundId = DB::table('pension_funds')->insertGetId([
                'business_location_id' => $loc->id,
                'name' => $loc->pension_fund_name ?: '厚生年金基金',
                'number' => $loc->pension_fund_number ?? null,
                'office_number' => $loc->pension_fund_office_number ?? null,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $effectiveFrom = $latestSet->effective_from ?? '1900-01-01';

            DB::table('pension_fund_rates')->insert([
                'pension_fund_id' => $fundId,
                'effective_from' => $effectiveFrom,
                'salary_employee_rate' => $employee,
                'salary_employer_rate' => $employer,
                'bonus_employee_rate' => 0,
                'bonus_employer_rate' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pension_fund_rates');
        Schema::dropIfExists('pension_funds');
    }
};
