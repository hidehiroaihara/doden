<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 従業員情報リニューアル: users へ MoneyForward 準拠の基本情報を追加。
 *
 * 既存の name カラムは残し、User の saving フックで last_name + ' ' + first_name を
 * 自動合成する（打刻画面・帳票など name 参照箇所の後方互換のため）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 氏名（分割）
            $table->string('last_name')->nullable()->after('name')->comment('姓');
            $table->string('first_name')->nullable()->after('last_name')->comment('名');
            $table->string('last_name_kana')->nullable()->after('first_name')->comment('姓カナ');
            $table->string('first_name_kana')->nullable()->after('last_name_kana')->comment('名カナ');
            $table->string('gender')->nullable()->after('first_name_kana')->comment('性別: male/female/other');

            // 住所（分割）。既存 address は合成/後方互換で温存
            $table->string('prefecture')->nullable()->after('postal_code')->comment('都道府県');
            $table->string('city')->nullable()->after('prefecture')->comment('市区町村');
            $table->string('street')->nullable()->after('city')->comment('番地');
            $table->string('building')->nullable()->after('street')->comment('建物名・部屋番号');
            $table->string('address_kana')->nullable()->after('building')->comment('住所カナ');

            // マイナンバー（暗号化保存）
            $table->text('my_number')->nullable()->after('address_kana')->comment('個人番号（暗号化）');

            // 退職情報
            $table->date('retirement_date')->nullable()->comment('退職年月日');
            $table->string('retirement_type')->nullable()->comment('退職区分');
            $table->string('retirement_reason')->nullable()->comment('退職事由');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'last_name', 'first_name', 'last_name_kana', 'first_name_kana', 'gender',
                'prefecture', 'city', 'street', 'building', 'address_kana',
                'my_number', 'retirement_date', 'retirement_type', 'retirement_reason',
            ]);
        });
    }
};
