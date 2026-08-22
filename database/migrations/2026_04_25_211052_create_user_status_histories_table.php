<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('admins')->nullOnDelete();
            // active / pre_join / retired
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->text('note')->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();

            $table->index(['user_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_status_histories');
    }
};
