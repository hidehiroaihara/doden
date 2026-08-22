<?php

namespace App\Console\Commands;

use App\Models\BusinessLocation;
use App\Models\KyokaiKenpoRate;
use App\Support\LaborInsuranceRates;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 保険料率マスタを検索・確認するコマンド。
 *
 * 公開APIは無いため、同梱マスタ（kyokai_kenpo_rates）と事業所の料率セットを検索する。
 * 改定値を取り込むときは LegalMasterSeeder に新しい effective_from 行を追加する。
 */
class PayrollRatesCommand extends Command
{
    protected $signature = 'payroll:rates
        {query? : 都道府県名・事業所名の部分一致（例: 東京）}
        {--date= : 適用日 (Y-m-d)。省略時は今日}
        {--compare= : 比較する適用日 (Y-m-d)。--date と並べて差分表示（現在→更新後の突合）}
        {--kind=all : health|nursing|pension|employment|accident|all}
        {--office : 事業所の料率セットも表示する}
        {--csv= : 協会けんぽ料率をCSVへ出力（パス省略時は storage/app 配下に自動保存）}
        {--xlsx= : 全保険料率をタブ(シート)分けのExcelへ出力（パス省略時は storage/app 配下に自動保存）}
        {--urls : 公式の料率公表ページURLを表示する}';

    protected $description = '保険料率マスタを検索・突合する（協会けんぽ都道府県 / 労災・雇用 / 事業所セット / CSV・Excel出力 / 期間比較）';

    public function handle(): int
    {
        $query = trim((string) $this->argument('query'));
        $date = $this->option('date') ?: now()->toDateString();
        if (! $this->isYmd($date)) {
            $this->error('--date は Y-m-d 形式で指定してください（例: 2026-04-01）');

            return self::FAILURE;
        }
        $compare = $this->option('compare');
        if ($compare !== null && ! $this->isYmd($compare)) {
            $this->error('--compare は Y-m-d 形式で指定してください（例: 2027-03-01）');

            return self::FAILURE;
        }
        $kind = (string) $this->option('kind');

        // Excel出力モード（全保険料率をタブ分けで1ファイルに）
        if ($this->input->hasParameterOption('--xlsx')) {
            return $this->exportXlsx($query, $date, (string) ($this->option('xlsx') ?? ''));
        }

        // CSV出力モード（突合しやすいよう全都道府県・全期間を書き出す）
        // --csv（値なし）でも検知する
        if ($this->input->hasParameterOption('--csv')) {
            return $this->exportCsv($query, (string) ($this->option('csv') ?? ''));
        }

        // 比較モード（現在 vs 更新後を並べて差分表示）
        if ($compare) {
            $this->compareKyokai($query, $date, $compare);
            $this->comment('差分「→」の右が更新後です。0.00の行は変化なし。');

            return self::SUCCESS;
        }

        $this->info("適用日: {$date}");
        if ($query !== '') {
            $this->line("検索語: {$query}");
        }
        $this->newLine();

        $this->printKyokai($query, $date, $kind);
        $this->printLabor($kind);
        $this->printNationwide();

        if ($this->option('office')) {
            $this->printOffices($query, $date);
        }

        if ($this->option('urls') || $query === '') {
            $this->printOfficialUrls();
        }

        $this->comment('全保険料率をタブ分けExcelで: php artisan payroll:rates --xlsx');
        $this->comment('協会けんぽのみCSVで突合: php artisan payroll:rates --csv');
        $this->comment('現在→更新後の差分: php artisan payroll:rates --date=2026-04-01 --compare=2027-03-01');
        $this->comment('過去を塗り替えない更新: LegalMasterSeeder に新しい effective_from の行を追加 → php artisan db:seed --class=LegalMasterSeeder');

        return self::SUCCESS;
    }

    /**
     * 全保険料率を「タブ(シート)分け」のExcel(.xlsx)へ出力する。
     * シート名は各区分のタイトル（協会けんぽ / 雇用保険 / 労災保険 / 全国一律 / 事業所セット）。
     */
    private function exportXlsx(string $query, string $date, string $path): int
    {
        if ($path === '') {
            $path = storage_path('app/payroll_rates_'.now()->format('Ymd_His').'.xlsx');
        } elseif (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        $book = new Spreadsheet();
        $index = 0;

        // ① 協会けんぽ（都道府県別・全期間）
        $kyokai = KyokaiKenpoRate::query()
            ->when($query !== '', fn ($q) => $q->where('prefecture', 'like', '%'.$query.'%'))
            ->orderBy('prefecture')->orderBy('effective_from')->get();
        $kyokaiRows = $kyokai->map(function ($r) {
            $h = (float) $r->health_permille;
            $n = (float) $r->nursing_permille;

            return [
                $r->prefecture,
                $r->effective_from?->toDateString(),
                $r->effective_to?->toDateString() ?? '現行',
                round($h, 2), round($h / 10, 2), round($h / 20, 3),
                round($n, 2), round($n / 10, 2), round($n / 20, 3),
            ];
        })->all();
        $this->fillSheet($book, $index++, '協会けんぽ', [
            '都道府県', '適用開始日', '適用終了日',
            '健保_総額‰', '健保_総額%', '健保_折半%(従業員)',
            '介護_総額‰', '介護_総額%', '介護_折半%(従業員)',
        ], $kyokaiRows);

        // ② 雇用保険（業種プリセット）
        $empRows = [];
        foreach (LaborInsuranceRates::employmentIndustryLabels() as $code => $label) {
            $r = LaborInsuranceRates::employmentRates($code);
            $empRows[] = [$code, $label, round((float) $r['employee'], 2), round((float) $r['employer'], 2), round((float) $r['employee'] / 10, 3), round((float) $r['employer'] / 10, 3)];
        }
        $this->fillSheet($book, $index++, '雇用保険', ['コード', '事業の種類', '従業員‰', '事業主‰', '従業員%', '事業主%'], $empRows);

        // ③ 労災保険（業種プリセット・事業主のみ）
        $accRows = [];
        foreach (LaborInsuranceRates::accidentIndustryLabels() as $code => $label) {
            $rate = (float) LaborInsuranceRates::accidentEmployerRate($code);
            $accRows[] = [$code, $label, round($rate, 2), round($rate / 10, 3)];
        }
        $this->fillSheet($book, $index++, '労災保険', ['コード', '業種', '事業主‰', '事業主%'], $accRows);

        // ④ 全国一律（厚生年金・拠出金の標準値）
        $this->fillSheet($book, $index++, '全国一律', ['項目', '従業員‰', '事業主‰', '備考'], [
            ['厚生年金', 91.500, 91.500, '総額183.00‰の折半'],
            ['子ども・子育て拠出金', 0.000, 3.600, '事業主のみ'],
        ]);

        // ⑤ 事業所セット（給与計算が実際に参照する値・全期間）
        $officeRows = [];
        $locations = BusinessLocation::query()
            ->when($query !== '', fn ($q) => $q->where(function ($w) use ($query) {
                $w->where('name', 'like', '%'.$query.'%')->orWhere('prefecture', 'like', '%'.$query.'%');
            }))
            ->with(['insuranceRateSets' => fn ($q) => $q->orderBy('effective_from')->with('rates')])
            ->orderBy('sort_order')->get();
        foreach ($locations as $loc) {
            foreach ($loc->insuranceRateSets as $set) {
                foreach ($set->rates as $rate) {
                    $officeRows[] = [
                        $loc->name,
                        $loc->prefecture,
                        $loc->health_insurance_type,
                        $set->name,
                        $set->effective_from?->toDateString(),
                        $set->effective_to?->toDateString() ?? '現行',
                        $rate->kind,
                        round((float) $rate->employee_rate, 3),
                        round((float) $rate->employer_rate, 3),
                    ];
                }
            }
        }
        $this->fillSheet($book, $index++, '事業所セット', [
            '事業所', '都道府県', '健保種別', 'セット名', '適用開始日', '適用終了日', '保険種類', '従業員‰', '事業主‰',
        ], $officeRows);

        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        $this->info('Excel(.xlsx)を出力しました: '.$path);
        $this->line('  タブ: 協会けんぽ / 雇用保険 / 労災保険 / 全国一律 / 事業所セット');
        $this->comment('※‰は千分率、%は総額。従業員負担は折半列（協会けんぽ）。公式表と突合してください。');

        return self::SUCCESS;
    }

    /**
     * ワークブックの指定インデックスにシートを用意し、タイトル・見出し・データを書き込む。
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function fillSheet(Spreadsheet $book, int $index, string $title, array $headers, array $rows): void
    {
        $sheet = $index === 0 ? $book->getActiveSheet() : $book->createSheet($index);
        // シート名（タブ名）は31文字・禁則文字の制限があるため安全化
        $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title), 0, 31));

        $sheet->fromArray($headers, null, 'A1');
        if ($rows) {
            $sheet->fromArray($rows, null, 'A2');
        }

        // 見出し行を太字＋簡易列幅
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        foreach (range(1, count($headers)) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
    }

    private function isYmd(string $v): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
    }

    /** 都道府県の指定日に有効な料率を1件返す。 */
    private function kyokaiAt(string $prefecture, string $date): ?KyokaiKenpoRate
    {
        return KyokaiKenpoRate::resolve($prefecture, $date);
    }

    /** 現在(--date)と更新後(--compare)の協会けんぽ料率を並べて差分表示。 */
    private function compareKyokai(string $query, string $dateA, string $dateB): void
    {
        $prefs = KyokaiKenpoRate::query()
            ->when($query !== '', fn ($q) => $q->where('prefecture', 'like', '%'.$query.'%'))
            ->distinct()->orderBy('prefecture')->pluck('prefecture');

        $this->line("■ 協会けんぽ 料率比較（%表示） A={$dateA} → B={$dateB}");
        if ($prefs->isEmpty()) {
            $this->warn('  該当なし。');

            return;
        }

        $pct = fn ($permille) => $permille === null ? '—' : number_format((float) $permille / 10, 2);
        $rows = [];
        foreach ($prefs as $pref) {
            $a = $this->kyokaiAt($pref, $dateA);
            $b = $this->kyokaiAt($pref, $dateB);
            $hA = $a?->health_permille;
            $hB = $b?->health_permille;
            $nA = $a?->nursing_permille;
            $nB = $b?->nursing_permille;
            $hDiff = ($hA !== null && $hB !== null) ? number_format(((float) $hB - (float) $hA) / 10, 2) : '—';
            $nDiff = ($nA !== null && $nB !== null) ? number_format(((float) $nB - (float) $nA) / 10, 2) : '—';
            $rows[] = [
                $pref,
                $pct($hA).' → '.$pct($hB),
                $hDiff,
                $pct($nA).' → '.$pct($nB),
                $nDiff,
            ];
        }
        $this->table(['都道府県', '健保% A→B', '差', '介護% A→B', '差'], $rows);
        $this->newLine();
    }

    /** 協会けんぽ料率(全都道府県・全期間)をCSVへ出力する。Excelで公式表と突合しやすい形。 */
    private function exportCsv(string $query, string $path): int
    {
        if ($path === '') {
            $path = storage_path('app/payroll_kyokai_rates_'.now()->format('Ymd_His').'.csv');
        } elseif (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        $rows = KyokaiKenpoRate::query()
            ->when($query !== '', fn ($q) => $q->where('prefecture', 'like', '%'.$query.'%'))
            ->orderBy('prefecture')->orderBy('effective_from')->get();

        if ($rows->isEmpty()) {
            $this->warn('出力対象がありません。LegalMasterSeeder が未投入か、検索語が一致しません。');

            return self::FAILURE;
        }

        $fp = fopen($path, 'w');
        if ($fp === false) {
            $this->error("ファイルを作成できません: {$path}");

            return self::FAILURE;
        }

        // ExcelでUTF-8日本語が化けないようBOMを付与
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, [
            '都道府県', '適用開始日', '適用終了日',
            '健保_総額‰', '健保_総額%', '健保_折半%(従業員)',
            '介護_総額‰', '介護_総額%', '介護_折半%(従業員)',
        ]);
        foreach ($rows as $r) {
            $h = (float) $r->health_permille;
            $n = (float) $r->nursing_permille;
            fputcsv($fp, [
                $r->prefecture,
                $r->effective_from?->toDateString(),
                $r->effective_to?->toDateString() ?? '現行',
                number_format($h, 2, '.', ''),
                number_format($h / 10, 2, '.', ''),
                number_format($h / 20, 3, '.', ''),
                number_format($n, 2, '.', ''),
                number_format($n / 10, 2, '.', ''),
                number_format($n / 20, 3, '.', ''),
            ]);
        }
        fclose($fp);

        $this->info('CSVを出力しました: '.$path);
        $this->line('  行数: '.$rows->count().'（Excel等で公式の保険料額表と突合してください）');
        $this->comment('※%は総額。従業員負担は折半列（総額÷2）。協会けんぽの表は総額%表記です。');

        return self::SUCCESS;
    }

    private function printKyokai(string $query, string $date, string $kind): void
    {
        $q = KyokaiKenpoRate::query()
            ->where('effective_from', '<=', $date)
            ->where(function ($w) use ($date) {
                $w->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            });
        if ($query !== '') {
            $q->where('prefecture', 'like', '%'.$query.'%');
        }
        $rows = $q->orderBy('prefecture')->orderByDesc('effective_from')->get();

        $this->line('■ 協会けんぽ（都道府県別・総額 千分率 /1,000）');
        if ($rows->isEmpty()) {
            $this->warn('  該当なし。LegalMasterSeeder が未投入、または検索語が一致しません。');
            $this->newLine();

            return;
        }

        $seen = [];
        $table = [];
        foreach ($rows as $r) {
            if (isset($seen[$r->prefecture])) {
                continue; // 同一県は最新の適用開始日のみ
            }
            $seen[$r->prefecture] = true;
            if (in_array($kind, ['all', 'health', 'nursing'], true)) {
                $table[] = [
                    $r->prefecture,
                    $r->effective_from?->toDateString(),
                    number_format((float) $r->health_permille, 2),
                    number_format((float) $r->health_permille / 2, 3),
                    number_format((float) $r->nursing_permille, 2),
                    number_format((float) $r->nursing_permille / 2, 3),
                ];
            }
        }
        if ($table) {
            $this->table(
                ['都道府県', '適用開始', '健保総額‰', '折半(従業員)', '介護総額‰', '折半(従業員)'],
                $table,
            );
        }
        $this->newLine();
    }

    private function printLabor(string $kind): void
    {
        if (! in_array($kind, ['all', 'employment', 'accident'], true)) {
            return;
        }

        $this->line('■ 雇用保険（業種プリセット・千分率 /1,000）');
        $empRows = [];
        foreach (LaborInsuranceRates::employmentIndustryLabels() as $code => $label) {
            $rates = LaborInsuranceRates::employmentRates($code);
            $empRows[] = [$code, $label, number_format((float) $rates['employee'], 2), number_format((float) $rates['employer'], 2)];
        }
        $this->table(['code', '事業の種類', '従業員‰', '事業主‰'], $empRows);

        $this->line('■ 労災保険（業種プリセット・事業主のみ /1,000）');
        $accRows = [];
        foreach (LaborInsuranceRates::accidentIndustryLabels() as $code => $label) {
            $accRows[] = [$code, $label, number_format((float) LaborInsuranceRates::accidentEmployerRate($code), 2)];
        }
        $this->table(['code', '業種', '事業主‰'], $accRows);
        $this->newLine();
    }

    private function printNationwide(): void
    {
        $this->line('■ 全国一律（料率反映ボタンでセットされる標準値・千分率 /1,000）');
        $this->table(
            ['項目', '従業員‰', '事業主‰', '備考'],
            [
                ['厚生年金', '91.500', '91.500', '総額 183.00‰ の折半'],
                ['子ども・子育て拠出金', '0.000', '3.600', '事業主のみ'],
            ],
        );
        $this->newLine();
    }

    private function printOffices(string $query, string $date): void
    {
        $this->line('■ 事業所の料率セット（給与計算が実際に参照する値）');
        $locations = BusinessLocation::query()
            ->when($query !== '', fn ($q) => $q->where(function ($w) use ($query) {
                $w->where('name', 'like', '%'.$query.'%')
                    ->orWhere('prefecture', 'like', '%'.$query.'%');
            }))
            ->orderBy('sort_order')
            ->get();

        if ($locations->isEmpty()) {
            $this->warn('  該当する事業所がありません。');
            $this->newLine();

            return;
        }

        foreach ($locations as $loc) {
            $set = $loc->rateSetForDate($date);
            $this->info("  {$loc->name}（{$loc->prefecture} / {$loc->health_insurance_type}）");
            if (! $set) {
                $this->warn('    適用中の料率セットなし');
                continue;
            }
            $set->load('rates');
            $this->line('    セット: '.$set->name.'（'.$set->effective_from?->toDateString().'〜'.($set->effective_to?->toDateString() ?? '現行').'）');
            $rows = [];
            foreach ($set->rates as $rate) {
                $rows[] = [$rate->kind, number_format((float) $rate->employee_rate, 3), number_format((float) $rate->employer_rate, 3)];
            }
            $this->table(['kind', '従業員‰', '事業主‰'], $rows);
        }
        $this->newLine();
    }

    private function printOfficialUrls(): void
    {
        $date = Carbon::parse($this->option('date') ?: now()->toDateString());
        $reiwa = $date->year - 2018;
        $this->line('■ 公式公表ページ（突合用。公開APIは無いため人手確認）');
        $this->table(
            ['マスタ', 'URL'],
            [
                ['協会けんぽ 都道府県別保険料額表', 'https://www.kyoukaikenpo.or.jp/g7/cat330/'],
                ['日本年金機構 標準報酬月額表', 'https://www.nenkin.go.jp/service/kounen/hokenryo/ryogaku/'],
                ['国税庁 源泉徴収税額表', 'https://www.nta.go.jp/publication/pamph/gensen/'],
                ['厚労省 雇用保険料率', 'https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/koyou_roudou/koyou/koyouhoken/index_00013.html'],
                ['厚労省 労災保険率', 'https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/koyou_roudou/roudoukijun/rousaihoken.html'],
                ['検索ヒント', "協会けんぽ 保険料額表 令和{$reiwa}年度"],
            ],
        );
        $this->newLine();
    }
}
