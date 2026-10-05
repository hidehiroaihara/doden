<?php

namespace Tests\Unit;

use App\Services\Payroll\Migration\MfPayslipPdfParser;
use PHPUnit\Framework\TestCase;

/**
 * MF給与明細PDF（1ページ分の抽出テキスト）の読み取り。
 */
class MfPayslipPdfParserTest extends TestCase
{
    public static function pageText(array $overrides = []): string
    {
        $v = array_merge([
            'net' => '270,830',
            'name' => '山田 太郎',
            'no' => '2',
            'payment' => '2026（令和08）年01月30日',
            'from' => '2025（令和07）年12月01日',
            'to' => '2025（令和07）年12月31日',
            'earnings' => "基本給\t300,000\n役職手当\t20,000\n通勤手当/非課\t5,000",
            'gross' => '325,000',
            'deductions' => "健康保険料\t16,000\n子ども・子育て支援金\t500\n厚生年金保険料\t29,280\n雇用保険料\t1,950\n所得税\t6,440",
            'ded' => '54,170',
        ], $overrides);

        return <<<TXT
{$v['net']}
<>
円
2026（令和08）年01月分　給与明細書
支給日：{$v['payment']}
対象期間：{$v['from']}〜{$v['to']}
{$v['name']} 様
<>
所属：株式会社テスト
部門：大宮
従業員番号：{$v['no']}
差引支給額
支給
<>
{$v['earnings']}
支給合計
<>
{$v['gross']}
<>
控除
<>
{$v['deductions']}
控除合計
<>
{$v['ded']}
<>
当月支払
<>
振込支給額\t{$v['net']}
給与関連情報
<>
時給1\t1,300 
TXT;
    }

    public function test_parses_header_items_and_totals(): void
    {
        $slip = (new MfPayslipPdfParser())->parsePage(self::pageText(), 3);

        $this->assertSame([], $slip['errors']);
        $this->assertSame(3, $slip['page']);
        $this->assertSame('山田 太郎', $slip['name']);
        $this->assertSame('2', $slip['employee_no']);
        $this->assertSame('大宮', $slip['department']);
        $this->assertSame('2026-01-30', $slip['payment_date']);
        $this->assertSame('2025-12-01', $slip['period_start']);
        $this->assertSame('2025-12-31', $slip['period_end']);
        $this->assertSame('2025-12', $slip['period_key']);
        $this->assertSame(325000, $slip['total_earnings']);
        $this->assertSame(54170, $slip['total_deductions']);
        $this->assertSame(270830, $slip['net_pay']);
        $this->assertSame([
            ['name' => '基本給', 'amount' => 300000],
            ['name' => '役職手当', 'amount' => 20000],
            ['name' => '通勤手当/非課', 'amount' => 5000],
        ], $slip['earnings']);
        $this->assertCount(5, $slip['deductions']);
        $this->assertSame([['name' => '振込支給額', 'amount' => 270830]], $slip['payment']);
        $this->assertSame([['name' => '時給1', 'amount' => 1300]], $slip['info']);
    }

    public function test_zero_slip_and_duplicated_total_format(): void
    {
        $text = self::pageText([
            'net' => '0',
            'earnings' => '',
            'gross' => '0',
            'deductions' => "雇用保険料\t0",
            'ded' => '0',
        ]);
        $slip = (new MfPayslipPdfParser())->parsePage($text);
        $this->assertSame([], $slip['errors']);
        $this->assertSame(0, $slip['total_earnings']);
        $this->assertSame([], $slip['earnings']);

        $inline = str_replace("支給合計\n<>\n325,000", '支給合計 支給合計 325,000 325,000', self::pageText());
        $slip = (new MfPayslipPdfParser())->parsePage($inline);
        $this->assertSame([], $slip['errors']);
        $this->assertSame(325000, $slip['total_earnings']);
    }

    public function test_reports_total_mismatch(): void
    {
        $slip = (new MfPayslipPdfParser())->parsePage(self::pageText(['gross' => '326,000']));

        $this->assertNotEmpty($slip['errors']);
        $this->assertStringContainsString('支給合計', $slip['errors'][0]);
    }
}
