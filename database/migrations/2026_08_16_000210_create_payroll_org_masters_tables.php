<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 給与: 組織マスタ（事業所 / 職種 / 締め日グループ）
 *
 * 既存 departments は「店舗/部門」(勤怠表示・集計区分)として継続利用する。
 * ここで追加する business_locations は保険料率・労働保険番号の帰属先となる「事業所」。
 * 参照: 資料/設計書 07_全般 / 08_事業所 / 04_給与計算(絞り込み)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 事業所（保険料率・労働保険の帰属先）
        Schema::create('business_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->comment('事業所コード');
            $table->boolean('is_main')->default(false)->comment('本社（主たる事業所）');
            // 健康保険の管掌区分: kyokai(協会けんぽ) / kumiai(組合管掌) / kokuho(国保組合)
            $table->string('health_insurance_type')->default('kyokai')->comment('健康保険の管掌区分');
            $table->string('prefecture')->nullable()->comment('協会けんぽ都道府県（料率参照用）');
            $table->string('labor_insurance_number')->nullable()->comment('労働保険番号');
            $table->string('office_number')->nullable()->comment('事業所整理記号/番号');
            $table->string('postal_code')->nullable();
            $table->string('address')->nullable();
            $table->text('note')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // 職種（給与計算画面の絞り込み・従業員情報で使用）
        Schema::create('job_titles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // 締め日グループ（締め日・支給日・支給月オフセット）
        Schema::create('closing_date_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedTinyInteger('closing_day')->comment('締め日 1-31（31=月末扱い）');
            $table->unsignedTinyInteger('payment_day')->comment('支給日 1-31（31=月末扱い）');
            $table->unsignedTinyInteger('payment_month_offset')->default(1)->comment('締め月からの支給月オフセット（0=当月,1=翌月）');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closing_date_groups');
        Schema::dropIfExists('job_titles');
        Schema::dropIfExists('business_locations');
    }
};
