<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 1ユーザーが同一営業日に複数の打刻（昼夜の別店舗勤務や、早朝の新規出勤など）を
     * 記録できるよう、user_id + work_date のユニーク制約を解除する。
     * 検索性能維持のため通常インデックスへ置き換える。
     */
    public function up(): void
    {
        // 先に通常インデックスを追加してから unique を落とす。
        // user_id の外部キーがこの複合インデックスを参照しているため、
        // 代替インデックスが無い状態では unique を drop できない（MySQL errno 150/1553）。
        Schema::table('attendances', function (Blueprint $table) {
            $table->index(['user_id', 'work_date'], 'attendances_user_id_work_date_index');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('attendances_user_id_work_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unique(['user_id', 'work_date'], 'attendances_user_id_work_date_unique');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('attendances_user_id_work_date_index');
        });
    }
};
