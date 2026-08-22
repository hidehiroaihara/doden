<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 協会けんぽ 都道府県別 健康保険料率マスタ（適用期間つき）。
 *
 * 健康保険料率は都道府県ごとに異なる。介護保険料率は全国一律だが、
 * 事業所へ一括反映しやすいよう当テーブルに併記する。
 * いずれも「総額（労使合算）の千分率(/1,000)」で保持する。
 * 事業所の料率セットへは PayrollSettingController から折半して反映する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyokai_kenpo_rates', function (Blueprint $table) {
            $table->id();
            $table->string('prefecture')->comment('都道府県名（例: 東京都）');
            $table->decimal('health_permille', 8, 3)->comment('健康保険料率 総額（千分率 例: 99.8）');
            $table->decimal('nursing_permille', 8, 3)->comment('介護保険料率 総額（千分率・全国一律 例: 16.0）');
            $table->date('effective_from')->comment('適用開始日');
            $table->date('effective_to')->nullable()->comment('適用終了日（nullは現行）');
            $table->timestamps();

            $table->index(['prefecture', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyokai_kenpo_rates');
    }
};
