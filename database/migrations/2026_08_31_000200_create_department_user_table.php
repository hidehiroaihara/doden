<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 従業員(users)と店舗(departments)の多対多所属テーブル。
 *
 * これまで打刻画面の表示は users.department_id（1店舗のみ）で絞り込んでいたが、
 * 複数店舗を掛け持ちする従業員を各店舗の打刻画面に表示できるようにする。
 *
 * - 打刻画面の表示フィルタ専用（給与計算には使わない）。
 * - users.department_id は「主所属店舗」として残し、pivot にも is_primary=true で保持する。
 * - 給与の所属事業所は employee_payrolls.business_location_id（従来どおり1事業所）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false)->comment('主所属店舗（users.department_id と同期）');
            $table->timestamps();

            $table->unique(['user_id', 'department_id']);
        });

        // 既存の主所属(users.department_id)を pivot へ移行する。
        $now = now();
        DB::table('users')
            ->whereNotNull('department_id')
            ->orderBy('id')
            ->select(['id', 'department_id'])
            ->chunk(200, function ($users) use ($now) {
                $rows = [];
                foreach ($users as $u) {
                    $rows[] = [
                        'user_id' => $u->id,
                        'department_id' => $u->department_id,
                        'is_primary' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($rows) {
                    DB::table('department_user')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_user');
    }
};
