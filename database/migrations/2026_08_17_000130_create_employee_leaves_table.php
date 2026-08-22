<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 従業員の休職・休業情報。休職・休業名は leave_types マスタから選択する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->nullable()->constrained()->nullOnDelete();
            $table->date('start_date')->nullable()->comment('休職・休業開始日');
            $table->date('end_date')->nullable()->comment('休職・休業終了日（予定）');
            $table->string('note')->nullable()->comment('メモ');
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leaves');
    }
};
