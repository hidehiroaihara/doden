<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * ローカル開発用の管理ユーザー投入。
 *
 * ⚠ 本番（APP_ENV=production）では実行しないこと。
 *    固定パスワードがリポジトリに残るため、本番は `php artisan admin:create` を使用。
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('AdminSeeder は本番では使用しません。php artisan admin:create を実行してください。');

            return;
        }

        Admin::create([
            'name' => '管理者',
            'email' => 'system@frontier-dakoku.com',
            'password' => Hash::make('5zz+8cN^ZGY8'),
            'role' => 1,
        ]);
    }
}
