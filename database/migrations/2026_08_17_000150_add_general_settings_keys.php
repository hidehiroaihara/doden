<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 基本設定＞全般 で編集する会社設定キーを追加（settings key-value）。
 * 参照: MF「全般」画面
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            ['income_tax_calc_method', 'monthly_table', '源泉徴収税額の計算方法: monthly_table(税額表 月額表)/computer_special(電算機計算の特例)'],
            ['corporate_individual_number', null, '個人番号又は法人番号'],
            ['social_insurance_doc_submitter', 'employer', '社会保険関係書類の提出元: employer(事業主)/sharoushi(社労士)'],
            ['tax_office_name', null, '所轄税務署'],
            ['tax_office_sign_number', null, '署番号'],
            ['tax_office_number', '000', '税務署番号'],
            ['employee_sort_key', 'join_date', '従業員の並び順に使用する情報: join_date/employee_no_text/employee_no_number'],
            ['employee_sort_direction', 'asc', '従業員の並び順: asc(昇順)/desc(降順)'],
            ['payment_account_bank_name', null, '支払口座 金融機関名'],
            ['payment_account_branch_name', null, '支払口座 支店名'],
            ['payment_account_type', 'ordinary', '支払口座 種別: ordinary(普通)/checking(当座)'],
            ['payment_account_number', null, '支払口座 口座番号'],
            ['payment_account_holder', null, '支払口座 口座名義'],
            ['payment_account_transfer_code', null, '振込依頼人コード'],
        ];

        foreach ($rows as [$key, $value, $description]) {
            if (DB::table('settings')->where('key', $key)->exists()) {
                continue;
            }
            DB::table('settings')->insert([
                'key' => $key,
                'value' => $value,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'income_tax_calc_method', 'corporate_individual_number', 'social_insurance_doc_submitter',
            'tax_office_name', 'tax_office_sign_number', 'tax_office_number',
            'employee_sort_key', 'employee_sort_direction',
            'payment_account_bank_name', 'payment_account_branch_name', 'payment_account_type',
            'payment_account_number', 'payment_account_holder', 'payment_account_transfer_code',
        ])->delete();
    }
};
