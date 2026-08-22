<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 既存 users.name を last_name / first_name へ暫定分割してバックフィルする。
 * 全角/半角スペースの最初の1つで分割。スペースが無い場合は全体を姓に入れる。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->select('id', 'name', 'last_name', 'first_name')->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $u) {
                    if (! empty($u->last_name) || ! empty($u->first_name)) {
                        continue; // 既に分割済みはスキップ
                    }
                    $name = trim((string) $u->name);
                    if ($name === '') {
                        continue;
                    }
                    $parts = preg_split('/[\s\x{3000}]+/u', $name, 2);
                    $last = $parts[0] ?? $name;
                    $first = $parts[1] ?? '';
                    DB::table('users')->where('id', $u->id)->update([
                        'last_name' => $last,
                        'first_name' => $first,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // バックフィルのみのため何もしない（カラム自体は別マイグレーションで管理）。
    }
};
