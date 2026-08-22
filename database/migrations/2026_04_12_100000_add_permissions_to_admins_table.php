<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** セクションの初期権限（role=1以外のデフォルト: 全セクション none） */
    private const DEFAULT_PERMISSIONS = [
        'dashboard'  => 'none',
        'users'      => 'none',
        'attendances'=> 'none',
        'terminals'  => 'none',
        'settings'   => 'none',
    ];

    /** role=1 はすべて write */
    private const SUPER_PERMISSIONS = [
        'dashboard'  => 'write',
        'users'      => 'write',
        'attendances'=> 'write',
        'terminals'  => 'write',
        'settings'   => 'write',
    ];

    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
        });

        // 既存の role=1 管理者には全権限を付与
        DB::table('admins')->where('role', 1)->update([
            'permissions' => json_encode(self::SUPER_PERMISSIONS),
        ]);

        // role=1 以外（既存がいれば）にはデフォルトを付与
        DB::table('admins')->where('role', '!=', 1)->whereNull('permissions')->update([
            'permissions' => json_encode(self::DEFAULT_PERMISSIONS),
        ]);
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
