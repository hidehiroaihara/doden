<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            // 打刻・管理用
            $table->boolean('is_active')->default(true);
            $table->string('chatwork_room_id')->nullable();
            $table->integer('role')->default(1);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_no')->nullable()->comment('他管理ツール連携用（顧客No等）');
            $table->unsignedInteger('break_minutes')->nullable()->comment('個別休憩時間（分）。NULLなら企業設定を使用');

            // ファイルアップロード（履歴書・学生証等）
            $table->string('resume_path')->nullable()->comment('履歴書');
            $table->string('identification_document_path')->nullable()->comment('学生証等');

            // 個人情報
            $table->string('phone')->nullable()->comment('電話番号');
            $table->string('postal_code')->nullable()->comment('郵便番号');
            $table->string('address')->nullable()->comment('住所');
            $table->date('birth_date')->nullable()->comment('生年月日');
            $table->string('emergency_contact_name')->nullable()->comment('緊急連絡先（氏名）');
            $table->string('emergency_contact_phone')->nullable()->comment('緊急連絡先（電話番号）');

            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
