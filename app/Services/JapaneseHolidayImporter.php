<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\FiscalYearCustomHoliday;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 内閣府「国民の祝日について」CSV から日本の祝日を取り込むサービス。
 *
 * データ源: https://www8.cao.go.jp/chosei/shukujitsu/syukujitsu.csv （Shift_JIS, CC BY）
 * 取り込んだ祝日は fiscal_year_custom_holidays に source='cabinet_office' として保存する。
 * 会社独自休日（source='manual'）は取り込みで削除・上書きしない。
 */
class JapaneseHolidayImporter
{
    public const CSV_URL = 'https://www8.cao.go.jp/chosei/shukujitsu/syukujitsu.csv';

    public function __construct(private ?string $url = null) {}

    /**
     * 指定年の祝日を取り込み、登録件数を返す。
     * 年度(FiscalYear)が未作成の場合は例外。
     */
    public function importYear(int $year): int
    {
        $fy = FiscalYear::where('year', $year)->first();
        if (! $fy) {
            throw new RuntimeException("年度 {$year} が未作成のため祝日を取り込めません。先に年度を作成してください。");
        }

        $all = $this->parse($this->fetchCsv());

        return $this->store($fy, $year, $all);
    }

    /** 内閣府CSVを取得し本文を返す。 */
    public function fetchCsv(): string
    {
        $res = Http::timeout(30)->retry(2, 500)->get($this->url ?? self::CSV_URL);

        if (! $res->successful()) {
            throw new RuntimeException('内閣府の祝日CSVの取得に失敗しました (HTTP '.$res->status().')');
        }

        return $res->body();
    }

    /**
     * CSV本文をパースして [ 'Y-m-d' => 名称 ] の連想配列を返す（全年）。
     *
     * @return array<string, string>
     */
    public function parse(string $raw): array
    {
        $text = $this->decode($raw);
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];

        $out = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $cols = str_getcsv($line);
            if (count($cols) < 2) {
                continue;
            }
            $rawDate = trim($cols[0]);
            $name = trim($cols[1]);
            // ヘッダ行や不正行はスキップ（YYYY/M/D 形式のみ採用）
            if (! preg_match('#^\d{4}/\d{1,2}/\d{1,2}$#', $rawDate)) {
                continue;
            }
            try {
                $date = Carbon::createFromFormat('Y/n/j', $rawDate)->format('Y-m-d');
            } catch (\Throwable) {
                continue;
            }
            $out[$date] = $name;
        }

        return $out;
    }

    /**
     * 指定年の祝日のみ抽出する。
     *
     * @param  array<string, string>  $all
     * @return array<string, string>
     */
    public function filterByYear(array $all, int $year): array
    {
        return array_filter(
            $all,
            fn (string $date) => (int) substr($date, 0, 4) === $year,
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * 指定年の祝日を年度に保存する。
     * 既存の内閣府由来(source='cabinet_office')は入れ替え、手入力(source='manual')は保持する。
     * 同一日に手入力がある場合は手入力を優先し、内閣府分は登録しない。
     *
     * @param  array<string, string>  $all  全年の [ 'Y-m-d' => 名称 ]
     * @return int  新規登録した内閣府由来の件数
     */
    public function store(FiscalYear $fy, int $year, array $all): int
    {
        $holidays = $this->filterByYear($all, $year);
        ksort($holidays);

        $inserted = 0;
        DB::transaction(function () use ($fy, $holidays, &$inserted) {
            $fy->customHolidays()
                ->where('source', FiscalYearCustomHoliday::SOURCE_CABINET_OFFICE)
                ->delete();

            $manualDates = $fy->customHolidays()
                ->where('source', FiscalYearCustomHoliday::SOURCE_MANUAL)
                ->pluck('date')
                ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d)
                ->flip();

            foreach ($holidays as $date => $name) {
                if ($manualDates->has($date)) {
                    continue;
                }
                FiscalYearCustomHoliday::create([
                    'fiscal_year_id' => $fy->id,
                    'date' => $date,
                    'label' => $name,
                    'source' => FiscalYearCustomHoliday::SOURCE_CABINET_OFFICE,
                ]);
                $inserted++;
            }

            $fy->update(['holidays_imported_at' => now()]);
        });

        return $inserted;
    }

    /** Shift_JIS 等を UTF-8 に変換する（既に UTF-8 ならそのまま）。 */
    private function decode(string $raw): string
    {
        // BOM 除去
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        return mb_convert_encoding($raw, 'UTF-8', 'SJIS-win');
    }
}
