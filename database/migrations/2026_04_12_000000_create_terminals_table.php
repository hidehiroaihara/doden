<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminals', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('管理用表示名（例: 受付タブレット）');
            $table->string('terminal_id')->unique()->comment('端末識別子（例: tablet01）');
            $table->string('terminal_key')->comment('認証用ランダムキー');
            $table->boolean('is_active')->default(true);
            $table->string('description')->nullable()->comment('設置場所など');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminals');
    }
};
