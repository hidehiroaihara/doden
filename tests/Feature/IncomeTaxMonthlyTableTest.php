<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\IncomeTaxMonthlyTable;
use App\Models\Setting;
use App\Services\Payroll\IncomeTaxCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 源泉徴収税額表（月額表）の引き当てと、電算機計算の特例との切替。
 *
 * 検算の基準値は国税庁 令和8年分 月額表の
 * 「440,000円以上443,000円未満・甲欄・扶養0人 = 19,080円」（復興特別所得税込み）。
 */
class IncomeTaxMonthlyTableTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => '管理者',
            'email' => 'admin@example.com',
            'password' => 'password',
            'role' => 1,
        ]);
    }

    private function seedTable(): IncomeTaxMonthlyTable
    {
        $table = IncomeTaxMonthlyTable::create([
            'name' => '令和8年分 給与所得の源泉徴収税額表（月額表）',
            'target_year' => 2026,
            'effective_from' => '2026-01-01',
            'dependent_over7_deduction' => 1610,
            'source_note' => 'テスト用の抜粋',
        ]);

        $table->rows()->createMany([
            // 甲欄 扶養0人
            ['tax_table' => 'kou', 'min_amount' => 0, 'max_amount' => 88000, 'dependents' => 0, 'tax_amount' => 0],
            ['tax_table' => 'kou', 'min_amount' => 440000, 'max_amount' => 443000, 'dependents' => 0, 'tax_amount' => 19080],
            // 甲欄 扶養7人（7人超の控除確認用）
            ['tax_table' => 'kou', 'min_amount' => 440000, 'max_amount' => 443000, 'dependents' => 7, 'tax_amount' => 3300],
            // 乙欄は率指定の階級も持てる
            ['tax_table' => 'otsu', 'min_amount' => 440000, 'max_amount' => null, 'dependents' => 0, 'rate' => 0.3063, 'deduction' => 0],
        ]);

        return $table;
    }

    public function test_monthly_table_is_used_by_default(): void
    {
        $this->seedTable();
        $calculator = new IncomeTaxCalculator;

        $this->assertSame(19080, $calculator->monthly(442817, 0, 'kou', '2026-09-25'));
        $this->assertSame('monthly_table:2026-01-01', $calculator->lastSource);
    }

    public function test_band_boundaries_are_inclusive_lower_exclusive_upper(): void
    {
        $this->seedTable();
        $calculator = new IncomeTaxCalculator;

        $this->assertSame(19080, $calculator->monthly(440000, 0, 'kou', '2026-09-25'));
        $this->assertSame(19080, $calculator->monthly(442999, 0, 'kou', '2026-09-25'));
        // 443,000 は次の階級。未登録なので特例へフォールバックする。
        $calculator->monthly(443000, 0, 'kou', '2026-09-25');
        $this->assertSame('builtin', $calculator->lastSource);
    }

    public function test_dependents_over_seven_are_deducted_per_person(): void
    {
        $this->seedTable();
        $calculator = new IncomeTaxCalculator;

        $this->assertSame(3300, $calculator->monthly(442817, 7, 'kou', '2026-09-25'));
        $this->assertSame(3300 - 1610, $calculator->monthly(442817, 8, 'kou', '2026-09-25'));
        // 控除しきっても負にならない
        $this->assertSame(0, $calculator->monthly(442817, 12, 'kou', '2026-09-25'));
    }

    public function test_rate_rows_are_calculated(): void
    {
        $this->seedTable();
        $calculator = new IncomeTaxCalculator;

        $this->assertSame((int) floor(442817 * 0.3063), $calculator->monthly(442817, 0, 'otsu', '2026-09-25'));
    }

    public function test_computer_special_setting_bypasses_the_table(): void
    {
        $this->seedTable();
        Setting::setValue('income_tax_calc_method', 'computer_special');
        $calculator = new IncomeTaxCalculator;

        $this->assertSame(48538, $calculator->monthly(442817, 0, 'kou', '2026-09-25'));
        $this->assertSame('builtin', $calculator->lastSource);
    }

    public function test_falls_back_when_no_table_covers_the_date(): void
    {
        $this->seedTable();
        $calculator = new IncomeTaxCalculator;

        // 表の適用開始前の日付では月額表が引けない
        $this->assertSame(48538, $calculator->monthly(442817, 0, 'kou', '2025-09-25'));
        $this->assertSame('builtin', $calculator->lastSource);
    }

    public function test_wide_csv_import_expands_every_dependent_column(): void
    {
        // 国税庁の表と同じ並び（以上・未満・扶養0〜7人・乙）。扶養1〜7人は空欄のまま。
        $csv = implode("\n", [
            implode(',', ['以上', '未満', '0人', '1人', '2人', '3人', '4人', '5人', '6人', '7人', '乙']),
            implode(',', ['その月の社会保険料等控除後の給与等の金額', '', '扶養親族等の数', '', '', '', '', '', '', '', '']),
            implode(',', ['"440,000"', '"443,000"', '"19,080"', '', '', '', '', '', '', '', '30.63%']),
            implode(',', ['"3,500,000"', '', '', '', '', '', '', '', '', '', '40.84%']),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.payroll.settings.income-tax-monthly-table.store'), [
                'name' => '令和8年分 月額表',
                'target_year' => 2026,
                'effective_from' => '2026-01-01',
                'dependent_over7_deduction' => 1610,
                'format' => 'wide',
                'file' => UploadedFile::fake()->createWithContent('table.csv', $csv),
            ])
            ->assertSessionHasNoErrors();

        $table = IncomeTaxMonthlyTable::firstOrFail();
        // 空欄のマスは登録されない（19,080 と 乙欄の率2件のみ）
        $this->assertSame(3, $table->rows()->count());

        $calculator = new IncomeTaxCalculator;
        $this->assertSame(19080, $calculator->monthly(442817, 0, 'kou', '2026-09-25'));
        $this->assertSame((int) floor(442817 * 0.3063), $calculator->monthly(442817, 0, 'otsu', '2026-09-25'));

        // 「未満」空欄は最上位の階級として扱う
        $this->assertSame((int) floor(9000000 * 0.4084), $calculator->monthly(9000000, 0, 'otsu', '2026-09-25'));
    }

    public function test_high_bands_need_a_rate_and_are_skipped_without_one(): void
    {
        // 原表で「未満」が書かれていない階級は税額ではなく基準額なので、加算率が無ければ取り込まない
        $csv = implode("\n", [
            implode(',', ['以上', '未満', '扶養0人', '扶養1人', '扶養2人', '扶養3人', '扶養4人', '扶養5人', '扶養6人', '扶養7人', '乙', '甲の加算率', '乙の加算率']),
            implode(',', ['"440,000"', '"443,000"', '"19,080"', '', '', '', '', '', '', '', '', '', '']),
            // 加算率あり: 基準額として取り込む
            implode(',', ['"740,000"', '', '"71,680"', '', '', '', '', '', '', '', '"259,200"', '20.42%', '40.84%']),
            // 加算率なし: 取り込まない（甲1マス＋乙1マス）
            implode(',', ['"790,000"', '', '"81,890"', '', '', '', '', '', '', '"36,620"', '', '', '']),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.payroll.settings.income-tax-monthly-table.store'), [
                'name' => '令和8年分 月額表',
                'target_year' => 2026,
                'effective_from' => '2026-01-01',
                'dependent_over7_deduction' => 1610,
                'format' => 'wide',
                'file' => UploadedFile::fake()->createWithContent('table.csv', $csv),
            ])
            ->assertSessionHasNoErrors();

        $table = IncomeTaxMonthlyTable::firstOrFail();
        $this->assertSame(3, $table->rows()->count());

        $calculator = new IncomeTaxCalculator;
        // 740,000〜790,000 は 71,680 +（超過額 × 20.42%）
        $this->assertSame(71680, $calculator->monthly(740000, 0, 'kou', '2026-09-25'));
        $this->assertSame(71680 + (int) floor(10000 * 0.2042), $calculator->monthly(750000, 0, 'kou', '2026-09-25'));
        $this->assertSame(259200 + (int) floor(10000 * 0.4084), $calculator->monthly(750000, 0, 'otsu', '2026-09-25'));

        // 加算率が無かった 790,000 以上は未登録なので電算機特例へフォールバックする
        $calculator->monthly(800000, 0, 'kou', '2026-09-25');
        $this->assertSame('builtin', $calculator->lastSource);
    }

    public function test_reimporting_the_same_band_replaces_it_instead_of_duplicating(): void
    {
        $header = implode(',', ['以上', '未満', '扶養0人', '扶養1人', '扶養2人', '扶養3人', '扶養4人', '扶養5人', '扶養6人', '扶養7人', '乙']);
        $payload = [
            'name' => '令和8年分 月額表',
            'target_year' => 2026,
            'effective_from' => '2026-01-01',
            'dependent_over7_deduction' => 1610,
            'format' => 'wide',
        ];

        $admin = $this->admin();
        $post = fn (string $body) => $this->actingAs($admin, 'admin')
            ->post(route('admin.payroll.settings.income-tax-monthly-table.store'), [
                ...$payload,
                'file' => UploadedFile::fake()->createWithContent('table.csv', $body),
            ])->assertSessionHasNoErrors();

        $post($header."\n".implode(',', ['"440,000"', '"443,000"', '"19,080"', '', '', '', '', '', '', '', '']));
        // 同じ階級を入れ直しても重複せず上書きされる
        $post($header."\n".implode(',', ['"440,000"', '"443,000"', '"19,999"', '', '', '', '', '', '', '', '']));
        // 別の階級は追加される
        $post($header."\n".implode(',', ['"443,000"', '"446,000"', '"19,330"', '', '', '', '', '', '', '', '']));

        $this->assertSame(1, IncomeTaxMonthlyTable::count());
        $this->assertSame(2, IncomeTaxMonthlyTable::firstOrFail()->rows()->count());
        $this->assertSame(19999, (new IncomeTaxCalculator)->monthly(442817, 0, 'kou', '2026-09-25'));
    }

    public function test_long_format_accepts_base_amount_high_bands(): void
    {
        $csv = implode("\n", [
            implode(',', ['tax_table', 'min_amount', 'max_amount', 'dependents', 'tax_amount', 'rate', 'deduction', 'base_amount', 'excess_over']),
            'kou,740000,790000,0,,0.2042,0,71680,740000',
            'otsu,0,105000,0,,0.03063,0,,',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.payroll.settings.income-tax-monthly-table.store'), [
                'name' => '令和8年分 月額表（高額帯）',
                'target_year' => 2026,
                'effective_from' => '2026-01-01',
                'dependent_over7_deduction' => 1610,
                'format' => 'long',
                'file' => UploadedFile::fake()->createWithContent('high.csv', $csv),
            ])
            ->assertSessionHasNoErrors();

        $calculator = new IncomeTaxCalculator;
        $this->assertSame(71680 + (int) floor(40000 * 0.2042), $calculator->monthly(780000, 0, 'kou', '2026-09-25'));
        $this->assertSame((int) floor(100000 * 0.03063), $calculator->monthly(100000, 0, 'otsu', '2026-09-25'));
    }

    public function test_import_screen_shows_whether_current_year_is_registered(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.payroll.settings.income-tax-monthly-table'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Payroll/Settings/IncomeTaxMonthlyTable')
                ->where('hasCurrentYear', false)
                ->where('activeEffectiveFrom', null));
    }
}
