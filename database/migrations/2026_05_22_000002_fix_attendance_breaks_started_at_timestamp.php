<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MySQL の暗黙的な ON UPDATE CURRENT_TIMESTAMP 動作により
 * started_at が休憩終了時に上書きされる問題を修正する。
 *
 * DEFAULT CURRENT_TIMESTAMP のみ（ON UPDATE なし）に変更し、
 * 既存の壊れたデータを created_at で復元する。
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL 固有の ON UPDATE CURRENT_TIMESTAMP 挙動への対処。sqlite 等では不要。
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // ON UPDATE なしの DEFAULT CURRENT_TIMESTAMP に変更
        DB::statement('ALTER TABLE attendance_breaks MODIFY COLUMN started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');

        // 既存データ: ended_at が設定済みの行は started_at が上書きされているため created_at で復元
        DB::statement('UPDATE attendance_breaks SET started_at = created_at WHERE ended_at IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE attendance_breaks MODIFY COLUMN started_at TIMESTAMP NOT NULL');
    }
};
