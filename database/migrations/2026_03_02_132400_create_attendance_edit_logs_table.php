<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_edit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->string('field_name');
            $table->string('before_value')->nullable();
            $table->string('after_value')->nullable();
            $table->unsignedBigInteger('modified_by_user_id');
            $table->timestamp('modified_at');
            $table->text('reason')->nullable();

            $table->foreign('modified_by_user_id')->references('id')->on('admins');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_edit_logs');
    }
};
