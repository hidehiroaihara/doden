<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * フェーズ2: 支払情報（振込先口座）・住民税納付先を従業員給与情報へ追加。
 * 給与振込一覧表 / 全銀FBデータ / 住民税徴収額一覧表 の生成に必要。
 *
 * 参照: 資料/設計書 05_従業員情報（支払情報タブ）/ 14_住民税 / 20_給与振込一覧表
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            // 支払情報（振込先口座）
            $table->string('bank_name')->nullable()->after('resident_tax_june')->comment('振込先金融機関名');
            $table->string('bank_code', 4)->nullable()->after('bank_name')->comment('金融機関コード（4桁）');
            $table->string('branch_name')->nullable()->after('bank_code')->comment('支店名');
            $table->string('branch_code', 3)->nullable()->after('branch_name')->comment('支店コード（3桁）');
            // account_type: ordinary(普通)/checking(当座)/savings(貯蓄)
            $table->string('account_type')->default('ordinary')->after('branch_code')->comment('預金種目');
            $table->string('account_number', 7)->nullable()->after('account_type')->comment('口座番号（7桁）');
            $table->string('account_holder_kana')->nullable()->after('account_number')->comment('口座名義人（半角カナ）');

            // 住民税納付先
            $table->string('resident_tax_municipality')->nullable()->after('account_holder_kana')->comment('納付先市区町村名');
            $table->string('resident_tax_recipient_number')->nullable()->after('resident_tax_municipality')->comment('受給者番号');
        });
    }

    public function down(): void
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->dropColumn([
                'bank_name', 'bank_code', 'branch_name', 'branch_code',
                'account_type', 'account_number', 'account_holder_kana',
                'resident_tax_municipality', 'resident_tax_recipient_number',
            ]);
        });
    }
};
