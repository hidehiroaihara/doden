<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IncomeTaxMonthlyTable;
use App\Models\Setting;
use App\Services\Payroll\IncomeTaxCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 源泉徴収税額表（月額表）の年分マスタ管理。
 *
 * 国税庁が毎年公表する表を年分ごとにCSVで取り込み、どの年分が登録済みか・当年が
 * カバーされているかを画面で明示する（毎年の更新漏れを防ぐのが主目的）。
 *
 * 参照: docs/annual-master-updates.md
 */
class IncomeTaxMonthlyTableController extends Controller
{
    /** 1行=1マスの「長い形式」CSVの列順。取込・テンプレート・エラー表示で共通に使う。 */
    private const COLUMNS = [
        'tax_table', 'min_amount', 'max_amount', 'dependents', 'tax_amount', 'rate', 'deduction',
        'base_amount', 'excess_over',
    ];

    /**
     * 国税庁の表をそのまま貼った「横並び形式」の列順。
     * 末尾2列（加算率）は高額帯のみ使う任意列。
     */
    private const WIDE_COLUMNS = [
        '以上', '未満', '扶養0人', '扶養1人', '扶養2人', '扶養3人', '扶養4人', '扶養5人', '扶養6人', '扶養7人', '乙',
        '甲の加算率', '乙の加算率',
    ];

    /** 横並び形式の列位置。 */
    private const WIDE_KOU_FIRST = 2;

    private const WIDE_OTSU = 10;

    private const WIDE_RATE_KOU = 11;

    private const WIDE_RATE_OTSU = 12;

    public function index(Request $request)
    {
        $today = now()->toDateString();
        $currentYear = (int) now()->year;
        $active = IncomeTaxMonthlyTable::forDate($today);

        $tables = IncomeTaxMonthlyTable::withCount('rows')
            ->orderByDesc('effective_from')
            ->get()
            ->map(fn (IncomeTaxMonthlyTable $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'target_year' => $t->target_year,
                'effective_from' => $t->effective_from->toDateString(),
                'effective_to' => $t->effective_to?->toDateString(),
                'dependent_over7_deduction' => $t->dependent_over7_deduction,
                'source_note' => $t->source_note,
                'rows_count' => $t->rows_count,
                'is_active' => $active && $active->id === $t->id,
                'updated_at' => $t->updated_at?->format('Y-m-d H:i'),
            ])
            ->all();

        return Inertia::render('Admin/Payroll/Settings/IncomeTaxMonthlyTable', [
            'tables' => $tables,
            'calcMethod' => Setting::getValue('income_tax_calc_method', 'monthly_table'),
            // 当年分が登録されているか（毎年の更新チェック用）
            'currentYear' => $currentYear,
            'hasCurrentYear' => IncomeTaxMonthlyTable::where('target_year', $currentYear)->exists(),
            'activeEffectiveFrom' => $active?->effective_from->toDateString(),
            'csvColumns' => self::COLUMNS,
            'csvWideColumns' => self::WIDE_COLUMNS,
            'lookup' => $this->lookup($request),
        ]);
    }

    /**
     * 取込用テンプレートのダウンロード。
     *  - format=xlsx … docs/data の Excel ひな形（以上・未満の枠＋入力欄）
     *  - format=wide … 横並び CSV（短いサンプル）
     *  - それ以外 … 長い形式 CSV
     */
    public function template(Request $request): StreamedResponse|BinaryFileResponse
    {
        if ($request->query('format') === 'xlsx') {
            $path = base_path('docs/data/月額表_取込用_テンプレート.xlsx');
            abort_unless(is_file($path), 404, 'Excelテンプレートが見つかりません。');

            return response()->download($path, '月額表_取込用_テンプレート.xlsx');
        }

        $wide = $request->query('format') === 'wide';

        $header = $wide ? self::WIDE_COLUMNS : self::COLUMNS;
        $samples = $wide
            // 記入例は公表値を推測しないよう、確認済みのマスだけ埋めてある
            ? [
                [105000, 107000, 170, 0, 0, 0, 0, 0, 0, 0, 3800, '', ''],
                [440000, 443000, 19080, '', '', '', '', '', '', '', '', '', ''],
                // 高額帯は税額の代わりに基準額を入れ、末尾に加算率を書く
                [740000, 790000, 71680, '', '', '', '', '', '', '', 259200, '20.42%', '40.84%'],
            ]
            : [
                ['kou', 440000, 443000, 0, 19080, '', 0, '', ''],
                ['otsu', 0, 105000, 0, '', 0.03063, 0, '', ''],
                ['kou', 740000, 790000, 0, '', 0.2042, 0, 71680, 740000],
            ];

        $filename = $wide
            ? 'income_tax_monthly_table_template_wide.csv'
            : 'income_tax_monthly_table_template.csv';

        return response()->streamDownload(function () use ($header, $samples) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($samples as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * 年分を1件追加して表を取り込む。既に同じ適用開始日があればその年分へ追加する
     * （高額帯シートなどを分けてアップロードできるようにするため）。
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'target_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
            'dependent_over7_deduction' => ['required', 'integer', 'min:0', 'max:100000'],
            'source_note' => ['nullable', 'string', 'max:255'],
            'format' => ['nullable', 'in:long,wide'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        $file = $request->file('file');
        $lines = in_array(strtolower((string) $file->getClientOriginalExtension()), ['xlsx', 'xls'], true)
            ? $this->readSpreadsheet($file->getRealPath())
            : $this->readCsv($file->getRealPath());

        [$rows, $errors, $skipped] = ($validated['format'] ?? 'long') === 'wide'
            ? $this->parseWideRows($lines)
            : $this->parseRows($lines);

        if ($errors !== []) {
            return back()->withErrors(['file' => implode(' / ', array_slice($errors, 0, 5))]);
        }
        if ($rows === []) {
            return back()->withErrors(['file' => '取り込める行がありません。列の並びが「以上・未満・扶養0〜7人・乙」になっているか確認してください。']);
        }

        $table = DB::transaction(function () use ($validated, $rows) {
            $table = IncomeTaxMonthlyTable::firstOrNew(['effective_from' => $validated['effective_from']]);
            $table->fill([
                'name' => $validated['name'],
                'target_year' => $validated['target_year'],
                'effective_to' => $validated['effective_to'] ?? null,
                'dependent_over7_deduction' => $validated['dependent_over7_deduction'],
                'source_note' => $validated['source_note'] ?? null,
            ])->save();

            // 同じ階級を再アップロードしたときに重複しないよう、取り込む階級の既存行は消す
            $this->deleteOverlappingRows($table, $rows);

            foreach (array_chunk($rows, 500) as $chunk) {
                $table->rows()->createMany($chunk);
            }

            return $table;
        });

        $message = sprintf(
            '%d行を取り込みました（この年分の合計 %d行）。検算ツールで税額を突合してください。',
            count($rows),
            $table->rows()->count(),
        );
        if ($skipped > 0) {
            $message .= sprintf(
                'なお %d マスは「未満」が空欄のため取り込みませんでした。原表で「未満」が無い階級（740,000円以上の高額帯）は税額ではなく基準額なので、末尾に「甲の加算率」「乙の加算率」の列を足してから取り込んでください。',
                $skipped,
            );
        }

        return back()->with('success', $message);
    }

    /**
     * 今回取り込む階級と同じ「甲乙・扶養人数・下限」の既存行を削除する。
     * 同じファイルを撮り直したときや、高額帯だけ入れ替えたいときに二重登録を防ぐ。
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function deleteOverlappingRows(IncomeTaxMonthlyTable $table, array $rows): void
    {
        if (! $table->exists) {
            return;
        }

        $keys = [];
        foreach ($rows as $row) {
            $keys[$row['tax_table'].':'.$row['dependents']][] = $row['min_amount'];
        }

        foreach ($keys as $key => $minAmounts) {
            [$taxTable, $dependents] = explode(':', $key);
            $table->rows()
                ->where('tax_table', $taxTable)
                ->where('dependents', (int) $dependents)
                ->whereIn('min_amount', array_unique($minAmounts))
                ->delete();
        }
    }

    /** 年分ごと削除（行はカスケード）。確定済み給与の再計算に影響するため注意喚起つき。 */
    public function destroy(IncomeTaxMonthlyTable $incomeTaxMonthlyTable)
    {
        $incomeTaxMonthlyTable->delete();

        return back()->with('success', '税額表を削除しました。');
    }

    /**
     * 検算ツール: 社保控除後の金額・扶養人数・税額表区分から、月額表と電算機特例の税額を並べて返す。
     * クエリが無ければ null（一覧表示のみ）。
     *
     * @return array<string, mixed>|null
     */
    private function lookup(Request $request): ?array
    {
        if (! $request->filled('after_social_insurance')) {
            return null;
        }

        $validated = $request->validate([
            'after_social_insurance' => ['required', 'integer', 'min:0', 'max:99999999'],
            'dependents' => ['nullable', 'integer', 'min:0', 'max:20'],
            'tax_table' => ['nullable', 'in:kou,otsu'],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $validated = [
            'after_social_insurance' => (int) $validated['after_social_insurance'],
            'dependents' => (int) ($validated['dependents'] ?? 0),
            'tax_table' => $validated['tax_table'] ?? 'kou',
            'effective_date' => $validated['effective_date'] ?? now()->toDateString(),
        ];

        $calculator = app(IncomeTaxCalculator::class);

        $monthly = $calculator->monthly(
            $validated['after_social_insurance'],
            $validated['dependents'],
            $validated['tax_table'],
            $validated['effective_date'],
            'monthly_table',
        );
        $monthlySource = $calculator->lastSource;

        $special = $calculator->monthly(
            $validated['after_social_insurance'],
            $validated['dependents'],
            $validated['tax_table'],
            $validated['effective_date'],
            'computer_special',
        );

        return [
            'input' => $validated,
            'monthly_table' => $monthly,
            'monthly_table_source' => $monthlySource,
            'computer_special' => $special,
            // 月額表が引けなかった場合は特例へフォールバックしているため、同額になる
            'monthly_table_found' => str_starts_with($monthlySource, 'monthly_table:'),
        ];
    }

    /**
     * Excel(.xlsx/.xls)を配列へ読み込む。国税庁の表を転記したブックをそのまま受け取れるように、
     * 全シートを縦に連結する（見出し・注記の行はパーサ側で読み飛ばす）。
     *
     * @return array<int, array<int, string|null>>
     */
    private function readSpreadsheet(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        $lines = [];
        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->toArray(null, false, false, false) as $row) {
                $lines[] = array_map(
                    fn ($cell) => $cell === null ? '' : (string) $cell,
                    $row,
                );
            }
        }

        return $lines;
    }

    /**
     * CSVを配列へ読み込む（BOM除去のみ）。
     *
     * @return array<int, array<int, string|null>>
     */
    private function readCsv(string $path): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($path));

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $lines = [];
        while (($data = fgetcsv($handle)) !== false) {
            $lines[] = $data;
        }
        fclose($handle);

        return $lines;
    }

    /**
     * 1行=1マスの「長い形式」CSVを検証しつつ行データへ変換する。
     *
     * @param  array<int, array<int, string|null>>  $lines
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>, 2: int} [行, エラー, 見送った階級数]
     */
    private function parseRows(array $lines): array
    {
        $rows = [];
        $errors = [];
        $lineNo = 0;

        foreach ($lines as $data) {
            $lineNo++;
            $first = trim((string) ($data[0] ?? ''));

            // 見出し行・空行は読み飛ばす
            if ($first === '' || $first === 'tax_table') {
                continue;
            }
            if (! in_array($first, ['kou', 'otsu'], true)) {
                $errors[] = "{$lineNo}行目: tax_table は kou / otsu で指定してください（{$first}）";

                continue;
            }

            $taxAmount = $this->intOrNull($data[4] ?? null);
            $rate = $this->floatOrNull($data[5] ?? null);
            $baseAmount = $this->intOrNull($data[7] ?? null);
            if ($taxAmount === null && $rate === null) {
                $errors[] = "{$lineNo}行目: tax_amount か rate のどちらかを入力してください";

                continue;
            }
            if ($baseAmount !== null && $rate === null) {
                $errors[] = "{$lineNo}行目: base_amount を使う階級は rate も入力してください";

                continue;
            }

            $minAmount = (int) $this->numeric($data[1] ?? 0);

            $rows[] = [
                'tax_table' => $first,
                'min_amount' => $minAmount,
                'max_amount' => $this->intOrNull($data[2] ?? null),
                'dependents' => (int) $this->numeric($data[3] ?? 0),
                'tax_amount' => $baseAmount === null ? $taxAmount : null,
                'base_amount' => $baseAmount,
                'excess_over' => $baseAmount === null ? null : ($this->intOrNull($data[8] ?? null) ?? $minAmount),
                'rate' => $rate,
                'deduction' => (int) $this->numeric($data[6] ?? 0),
            ];
        }

        return [$rows, $errors, 0];
    }

    /**
     * 国税庁の表と同じ「横並び形式」（以上・未満・扶養0〜7人・乙［・加算率］）を行データへ展開する。
     *
     * 空欄のマスは登録しない（該当階級が引けなければ電算機特例へフォールバックする）。
     * 「未満」が空欄の階級は、次に大きい階級の下限で自動的に閉じる。最上位だけ上限なしになる。
     *
     * 740,000円以上の高額帯は原表に税額が載らず「〇〇円の税額に、△円を超える金額の□％を加算」
     * と定義されるため、加算率の列が必要。加算率が無い上限なしの階級は取り込まず件数を返す。
     *
     * @param  array<int, array<int, string|null>>  $lines
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>, 2: int} [行, エラー, 見送った階級数]
     */
    private function parseWideRows(array $lines): array
    {
        $bands = [];
        $errors = [];
        $lineNo = 0;

        foreach ($lines as $data) {
            $lineNo++;
            $min = trim((string) ($data[0] ?? ''));

            // 見出し行・注記行・空行は読み飛ばす（先頭列が金額でない行）
            if (! preg_match('/^[\d,¥\s　]+$/u', $min)) {
                continue;
            }

            $minAmount = (int) $this->numeric($min);
            $maxAmount = $this->intOrNull($data[1] ?? null);
            if ($maxAmount !== null && $maxAmount <= $minAmount) {
                $errors[] = "{$lineNo}行目: 「未満」は「以上」より大きい金額にしてください";

                continue;
            }

            $bands[] = ['min' => $minAmount, 'max' => $maxAmount, 'cells' => $data];
        }

        $nextMin = $this->nextBandLowerBounds($bands);

        $rows = [];
        $skipped = 0;

        foreach ($bands as $band) {
            // 「未満」空欄は次の階級の下限で閉じる（最上位のみ上限なしのまま）
            $max = $band['max'] ?? ($nextMin[$band['min']] ?? null);

            // 原表で「未満」が書かれていない階級は高額帯。マスの値は税額ではなく基準額なので、
            // 加算率が無い限り取り込まない（定額として扱うと高額者の税額が過少になる）。
            $needsRate = $band['max'] === null;

            // 甲と乙で加算率が違うため、乙の加算率は甲から流用しない
            $kouRate = $this->rateOrNull($band['cells'][self::WIDE_RATE_KOU] ?? null);
            $otsuRate = $this->rateOrNull($band['cells'][self::WIDE_RATE_OTSU] ?? null);

            $columns = [];
            for ($d = 0; $d <= IncomeTaxMonthlyTable::MAX_TABLE_DEPENDENTS; $d++) {
                $columns[] = [self::WIDE_KOU_FIRST + $d, 'kou', $d, $kouRate];
            }
            $columns[] = [self::WIDE_OTSU, 'otsu', 0, $otsuRate];

            foreach ($columns as [$index, $taxTable, $dependents, $rate]) {
                $cell = $this->cell($band['cells'], $index, $band['min'], $max, $taxTable, $dependents, $rate);
                if ($cell === null) {
                    continue;
                }
                if ($needsRate && $cell['rate'] === null) {
                    $skipped++;

                    continue;
                }
                $rows[] = $cell;
            }
        }

        return [$rows, $errors, $skipped];
    }

    /**
     * 各階級の「次に大きい階級の下限」を求める（未満が空欄の階級を閉じるため）。
     *
     * @param  array<int, array{min:int, max:int|null, cells:array<int, string|null>}>  $bands
     * @return array<int, int>
     */
    private function nextBandLowerBounds(array $bands): array
    {
        $mins = array_values(array_unique(array_column($bands, 'min')));
        sort($mins);

        $next = [];
        foreach ($mins as $i => $min) {
            if (isset($mins[$i + 1])) {
                $next[$min] = $mins[$i + 1];
            }
        }

        return $next;
    }

    /**
     * 横並び形式の1マスを行データへ変換する。空欄なら null。
     *
     * 加算率が渡された階級は、マスの値を「基準となる税額」として扱う。
     *
     * @param  array<int, string|null>  $data
     * @return array<string, mixed>|null
     */
    private function cell(array $data, int $index, int $minAmount, ?int $maxAmount, string $taxTable, int $dependents, ?float $rate = null): ?array
    {
        $raw = trim((string) ($data[$index] ?? ''));
        if ($raw === '' || ! preg_match('/^[\d.,¥%\s　]+$/u', $raw)) {
            return null;
        }

        $base = [
            'tax_table' => $taxTable,
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
            'dependents' => $dependents,
            'tax_amount' => null,
            'base_amount' => null,
            'excess_over' => null,
            'rate' => null,
            'deduction' => 0,
        ];

        // 加算率つき（高額帯）: マスの値は基準となる税額
        if ($rate !== null) {
            return [...$base, 'base_amount' => (int) $this->numeric($raw), 'excess_over' => $minAmount, 'rate' => $rate];
        }

        // マス自体が率の階級（乙欄の低額帯など）。「3.063%」も「0.03063」も受け付ける
        $cellRate = $this->rateOrNull($raw);
        if ($cellRate !== null) {
            return [...$base, 'rate' => $cellRate];
        }

        return [...$base, 'tax_amount' => (int) $this->numeric($raw)];
    }

    /** 「30.63%」「0.3063」を率として解釈する。率でなければ null。 */
    private function rateOrNull(mixed $value): ?float
    {
        $raw = trim((string) $value);
        if ($raw === '' || ! preg_match('/^[\d.,%\s　]+$/u', $raw)) {
            return null;
        }

        $number = $this->numeric(str_replace('%', '', $raw));
        if ($number <= 0) {
            return null;
        }

        if (str_contains($raw, '%')) {
            return $number / 100;
        }

        // パーセント記号が無い場合、1未満なら小数表記の率と判断する
        return $number < 1 ? $number : null;
    }

    private function numeric(mixed $value): float
    {
        return (float) str_replace([',', '¥', ' ', '　'], '', (string) $value);
    }

    private function intOrNull(mixed $value): ?int
    {
        $raw = trim((string) $value);

        return $raw === '' ? null : (int) $this->numeric($raw);
    }

    private function floatOrNull(mixed $value): ?float
    {
        $raw = trim((string) $value);

        return $raw === '' ? null : $this->numeric($raw);
    }
}
