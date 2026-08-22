<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::firstOrCreate(
            ['key' => 'month_closing_day'],
            ['value' => null, 'description' => '月締め日(1〜31, 未設定なら月末締め)']
        );
    }

    public function down(): void
    {
        Setting::where('key', 'month_closing_day')->delete();
    }
};
