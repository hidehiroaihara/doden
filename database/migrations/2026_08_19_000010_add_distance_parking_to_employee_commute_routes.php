<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 通勤ルートに「片道の通勤距離」と「通勤用の駐車場等」を追加（MF準拠）。
 * - 自動車/バイク/自転車: 片道の通勤距離(km) と 駐車場利用有無 を持つ。
 * - 駐車場利用時は駐車場代の支給条件（定額/出勤日数）・支給月・支給額・支払手段・上限を保持し、
 *   通勤手当（課税）へ合算する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_commute_routes', function (Blueprint $table) {
            $table->decimal('one_way_distance_km', 6, 1)->default(0)->after('to_place')->comment('片道の通勤距離(km)');
            $table->boolean('uses_parking')->default(false)->after('non_taxable_limit')->comment('通勤用の駐車場等を利用しているか');
            // 駐車場代の支給条件: fixed(定額で支給) / by_workdays(出勤日数に応じて支給)
            $table->string('parking_condition')->default('fixed')->after('uses_parking')->comment('駐車場代の支給条件');
            $table->json('parking_payment_months')->nullable()->after('parking_condition')->comment('駐車場代・定額時の支給月（空=毎月）');
            $table->string('parking_attendance_item_code')->nullable()->after('parking_payment_months')->comment('駐車場代・出勤日数連動時の勤怠項目コード');
            $table->integer('parking_amount')->default(0)->after('parking_attendance_item_code')->comment('駐車場代（定額=月額 / 出勤日数連動=日額）');
            $table->string('parking_payment_method')->default('cash')->after('parking_amount')->comment('駐車場代の支払手段: cash/in_kind');
            $table->integer('parking_cap_amount')->nullable()->after('parking_payment_method')->comment('駐車場代の上限支給額（null=上限なし）');
        });
    }

    public function down(): void
    {
        Schema::table('employee_commute_routes', function (Blueprint $table) {
            $table->dropColumn([
                'one_way_distance_km',
                'uses_parking',
                'parking_condition',
                'parking_payment_months',
                'parking_attendance_item_code',
                'parking_amount',
                'parking_payment_method',
                'parking_cap_amount',
            ]);
        });
    }
};
