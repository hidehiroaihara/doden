<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\FiscalYearCustomHoliday;
use App\Services\JapaneseHolidayImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 内閣府CSVからの祝日取込（パース・年フィルタ・upsert・手入力保持）を検証する。
 */
class JapaneseHolidayImporterTest extends TestCase
{
    use RefreshDatabase;

    private JapaneseHolidayImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = new JapaneseHolidayImporter();
    }

    /** シーダー等で既存の年度があっても衝突しないよう firstOrCreate し、独自休日をクリアする。 */
    private function freshFiscalYear(int $year): FiscalYear
    {
        $fy = FiscalYear::firstOrCreate(
            ['year' => $year],
            ['work_hours_per_day_minutes' => 480],
        );
        $fy->customHolidays()->delete();
        $fy->update(['holidays_imported_at' => null]);

        return $fy;
    }

    /** ヘッダ行・不正行を無視し、YYYY/M/D を Y-m-d へ正規化する */
    public function test_parse_normalizes_dates_and_skips_header(): void
    {
        $csv = "国民の祝日・休日月日,国民の祝日・休日名称\n"
            ."2026/1/1,元日\n"
            ."2026/1/12,成人の日\n"
            ."2027/1/1,元日\n"
            ."不正な行\n";

        $parsed = $this->importer->parse($csv);

        $this->assertSame('元日', $parsed['2026-01-01']);
        $this->assertSame('成人の日', $parsed['2026-01-12']);
        $this->assertSame('元日', $parsed['2027-01-01']);
        $this->assertArrayNotHasKey('国民の祝日・休日月日', $parsed);
        $this->assertCount(3, $parsed);
    }

    /** Shift_JIS のCSVでも文字化けせずパースできる */
    public function test_parse_handles_shift_jis(): void
    {
        $utf8 = "国民の祝日・休日月日,国民の祝日・休日名称\n2026/2/11,建国記念の日\n";
        $sjis = mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');

        $parsed = $this->importer->parse($sjis);

        $this->assertSame('建国記念の日', $parsed['2026-02-11']);
    }

    /** 年フィルタが対象年のみ抽出する */
    public function test_filter_by_year(): void
    {
        $all = [
            '2025-12-31' => 'x',
            '2026-01-01' => '元日',
            '2026-12-31' => 'y',
            '2027-01-01' => 'z',
        ];

        $filtered = $this->importer->filterByYear($all, 2026);

        $this->assertSame(['2026-01-01' => '元日', '2026-12-31' => 'y'], $filtered);
    }

    /** store: 対象年のみ登録し source=cabinet_office を付与する */
    public function test_store_inserts_only_target_year(): void
    {
        $fy = $this->freshFiscalYear(2026);
        $all = [
            '2026-01-01' => '元日',
            '2026-01-12' => '成人の日',
            '2027-01-01' => '元日',
        ];

        $count = $this->importer->store($fy, 2026, $all);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('fiscal_year_custom_holidays', [
            'fiscal_year_id' => $fy->id,
            'date' => '2026-01-01',
            'label' => '元日',
            'source' => FiscalYearCustomHoliday::SOURCE_CABINET_OFFICE,
        ]);
        $this->assertDatabaseMissing('fiscal_year_custom_holidays', ['date' => '2027-01-01']);
        $this->assertNotNull($fy->fresh()->holidays_imported_at);
    }

    /** 再取込は内閣府由来のみ入れ替え、手入力は保持する */
    public function test_store_keeps_manual_and_replaces_cabinet_office(): void
    {
        $fy = $this->freshFiscalYear(2026);

        // 手入力の会社独自休日
        FiscalYearCustomHoliday::create([
            'fiscal_year_id' => $fy->id,
            'date' => '2026-06-15',
            'label' => '創立記念日',
            'source' => FiscalYearCustomHoliday::SOURCE_MANUAL,
        ]);
        // 古い内閣府由来（入れ替え対象）
        FiscalYearCustomHoliday::create([
            'fiscal_year_id' => $fy->id,
            'date' => '2026-01-01',
            'label' => '古い元日',
            'source' => FiscalYearCustomHoliday::SOURCE_CABINET_OFFICE,
        ]);

        $this->importer->store($fy, 2026, ['2026-01-01' => '元日', '2026-01-12' => '成人の日']);

        // 手入力は残る
        $this->assertDatabaseHas('fiscal_year_custom_holidays', [
            'date' => '2026-06-15',
            'source' => FiscalYearCustomHoliday::SOURCE_MANUAL,
        ]);
        // 内閣府由来は最新に置き換わる
        $this->assertDatabaseHas('fiscal_year_custom_holidays', [
            'date' => '2026-01-01',
            'label' => '元日',
            'source' => FiscalYearCustomHoliday::SOURCE_CABINET_OFFICE,
        ]);
        $this->assertDatabaseMissing('fiscal_year_custom_holidays', ['label' => '古い元日']);
    }

    /** 同日に手入力がある場合は内閣府分をスキップ（手入力優先） */
    public function test_store_manual_date_takes_priority(): void
    {
        $fy = $this->freshFiscalYear(2026);
        FiscalYearCustomHoliday::create([
            'fiscal_year_id' => $fy->id,
            'date' => '2026-01-01',
            'label' => '会社の元日休み',
            'source' => FiscalYearCustomHoliday::SOURCE_MANUAL,
        ]);

        $count = $this->importer->store($fy, 2026, ['2026-01-01' => '元日']);

        $this->assertSame(0, $count);
        $this->assertSame(1, FiscalYearCustomHoliday::where('date', '2026-01-01')->count());
        $this->assertDatabaseHas('fiscal_year_custom_holidays', [
            'date' => '2026-01-01',
            'label' => '会社の元日休み',
            'source' => FiscalYearCustomHoliday::SOURCE_MANUAL,
        ]);
    }

    /** importYear: HTTPをモックしてCSV取得〜登録まで通す */
    public function test_import_year_fetches_and_stores(): void
    {
        $fy = $this->freshFiscalYear(2026);

        $csv = "国民の祝日・休日月日,国民の祝日・休日名称\n2026/1/1,元日\n2026/5/5,こどもの日\n2027/1/1,元日\n";
        Http::fake([
            JapaneseHolidayImporter::CSV_URL => Http::response(
                mb_convert_encoding($csv, 'SJIS-win', 'UTF-8'),
                200
            ),
        ]);

        $count = $this->importer->importYear(2026);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('fiscal_year_custom_holidays', ['date' => '2026-05-05', 'label' => 'こどもの日']);
    }

    /** 年度未作成なら例外 */
    public function test_import_year_throws_when_fiscal_year_missing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->importer->importYear(2099);
    }
}
