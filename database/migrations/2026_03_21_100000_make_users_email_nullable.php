<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 既存の users_email_unique はそのまま（NULL は複数行可）
            $table->string('email')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // NULL を許可しない状態へ戻す前に、NULL 値を一時的な一意メールへ置換する
        // （users.email に unique 制約があるため全行同一値は使えない）
        DB::table('users')
            ->select('id')
            ->whereNull('email')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'email' => sprintf('restored-user-%d@example.invalid', $user->id),
                        ]);
                }
            });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
