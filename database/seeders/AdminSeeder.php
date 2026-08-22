<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Admin::create([
            'name' => '管理者',
            'email' => 'system@frontier-dakoku.com',
            'password' => Hash::make('5zz+8cN^ZGY8'),
            'role' => 1,
        ]);
    }
}
