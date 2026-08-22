<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->timestamp('clock_in_at')->nullable();
            $table->string('clock_in_photo_path')->nullable();
            $table->string('clock_in_ip', 45)->nullable();
            $table->timestamp('clock_out_at')->nullable();
            $table->string('clock_out_photo_path')->nullable();
            $table->string('clock_out_ip', 45)->nullable();
            $table->unsignedInteger('break_minutes')->nullable()->comment('休憩時間（分）');
            $table->timestamps();

            $table->unique(['user_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
