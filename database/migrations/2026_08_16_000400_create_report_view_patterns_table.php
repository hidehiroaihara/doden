<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 帳票の「表示パターン（列カスタマイズ）」保存。
 * 支給控除一覧表など、動的にピボット列が決まる帳票で、非表示にする列の組み合わせに
 * 名前を付けて保存・呼び出しできるようにする。
 *
 * hidden_columns には列キー（例: e_base_salary / d_income_tax / net_pay）を配列で保存する。
 * 新設された列は保存パターンに含まれないため既定で表示となる（前方互換）。
 *
 * 参照: 資料/設計書 21_支給控除一覧表
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_view_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('report_key')->default('summary')->comment('対象帳票の識別子');
            $table->string('name');
            $table->json('hidden_columns')->comment('非表示にする列キーの配列');
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['report_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_view_patterns');
    }
};
