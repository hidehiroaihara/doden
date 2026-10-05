<?php

namespace App\Console\Commands;

use App\Models\BusinessLocation;
use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Services\Payroll\Migration\MfPayslipImporter;
use App\Services\Payroll\Migration\MfPayslipPdfParser;
use App\Services\Payroll\Reports\PayrollReportService;
use Illuminate\Console\Command;

/**
 * 旧システム（MFクラウド給与）の給与明細一括PDFを、確定済みの過去給与バッチとして取り込む（移行用・一回限り）。
 *
 * 例:
 *   php artisan payroll:import-mf-pdf storage/app/mf-migration --dry-run --summary
 *   php artisan payroll:import-mf-pdf storage/app/mf-migration
 */
class ImportMfPayslipPdf extends Command
{
    protected $signature = 'payroll:import-mf-pdf
                            {paths* : PDFファイルまたはPDFを置いたフォルダ（複数可）}
                            {--location= : 取込先の事業所ID（none=事業所なし。省略時は既存の給与バッチと同じ事業所）}
                            {--dry-run : 登録せず、読み取り結果とエラーだけ表示}
                            {--summary : 従業員ごとの支給合計・社会保険料・所得税の合計を表示（登録済みデータとも照合）}
                            {--skip-unknown : システムに見つからない従業員の明細をエラーにせずスキップ}
                            {--force : 確認なしで登録}';

    protected $description = 'MFクラウド給与の給与明細PDFを過去の給与データとして取り込む（移行用）';

    public function handle(MfPayslipPdfParser $parser, MfPayslipImporter $importer, PayrollReportService $reports): int
    {
        $files = $this->resolveFiles((array) $this->argument('paths'));
        if ($files === []) {
            $this->error('PDFが見つかりません。');

            return self::FAILURE;
        }

        [$locationId, $locationLabel] = $this->resolveLocation();
        if ($locationId === false) {
            return self::FAILURE;
        }

        $slips = [];
        foreach ($files as $file) {
            foreach ($parser->parseFile($file) as $slip) {
                $slips[] = $slip + ['file' => basename($file)];
            }
        }
        $this->line(sprintf('PDF %d 件 / %d ページを読み取りました。取込先の事業所: %s', count($files), count($slips), $locationLabel));

        $prepared = $importer->prepare($slips, $locationId, (bool) $this->option('skip-unknown'));
        $this->printPrepared($prepared);

        if ($this->option('summary')) {
            $this->printSummary($prepared, $reports);
        }

        if ($prepared['errors'] !== []) {
            $this->error(sprintf('エラーが %d 件あるため登録しません。', count($prepared['errors'])));

            return self::FAILURE;
        }
        if ($prepared['runs'] === []) {
            $this->warn('登録する明細がありません。');

            return self::SUCCESS;
        }
        if ($this->option('dry-run')) {
            $this->info('エラーはありません（--dry-run のため登録していません）。');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('上記の内容を確定済みの給与バッチとして登録します。よろしいですか？')) {
            $this->info('キャンセルしました。');

            return self::SUCCESS;
        }

        $counts = $importer->commit($prepared, $locationId);
        $this->info(sprintf('登録しました（給与バッチ %d 件 / 明細 %d 件）。', $counts['runs'], $counts['payslips']));

        if ($this->option('summary')) {
            $this->printSummary($prepared, $reports);
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $prepared */
    private function printPrepared(array $prepared): void
    {
        $rows = [];
        foreach ($prepared['runs'] as $run) {
            $rows[] = [
                $run['period_key'],
                $run['payment_date'],
                count($run['slips']),
                number_format(array_sum(array_column($run['slips'], 'total_earnings'))),
                number_format(array_sum(array_column($run['slips'], 'total_deductions'))),
                number_format(array_sum(array_column($run['slips'], 'net_pay'))),
                $run['existing_run_id'] ? '作り直し' : '新規',
            ];
        }
        if ($rows !== []) {
            $this->table(['締め月(period_key)', '支給日', '人数', '支給合計', '控除合計', '差引支給額', '登録'], $rows);
        }

        $this->printList('スキップ', $prepared['skipped'], 'comment');
        $this->printList('注意', $prepared['warnings'], 'warn');
        $this->printList('エラー', $prepared['errors'], 'error');
    }

    /** @param array<int, string> $messages */
    private function printList(string $title, array $messages, string $style): void
    {
        if ($messages === []) {
            return;
        }
        $this->newLine();
        $this->{$style}(sprintf('【%s】%d 件', $title, count($messages)));
        foreach ($messages as $m) {
            $this->line('  - ' . $m);
        }
    }

    /**
     * PDF から計算した従業員ごとの年間合計と、登録済みの移行データ（支給日の年で集計）を並べる。
     *
     * @param  array<string, mixed>  $prepared
     */
    private function printSummary(array $prepared, PayrollReportService $reports): void
    {
        $totals = [];
        foreach ($prepared['runs'] as $run) {
            $year = (int) substr($run['payment_date'], 0, 4);
            foreach ($run['slips'] as $s) {
                $key = $year . ':' . $s['user_id'];
                $totals[$key] ??= [
                    'year' => $year,
                    'user_id' => $s['user_id'],
                    'employee_no' => $s['employee_no'],
                    'name' => $s['name'],
                    'months' => 0,
                    'gross' => 0,
                    'social' => 0,
                    'tax' => 0,
                ];
                $totals[$key]['months']++;
                $totals[$key]['gross'] += $s['total_earnings'];
                foreach ($s['items'] as $item) {
                    if ($item['item_type'] !== 'deduction') {
                        continue;
                    }
                    if (in_array($item['code'], PayrollReportService::SOCIAL_INSURANCE_CODES, true)) {
                        $totals[$key]['social'] += $item['amount'];
                    } elseif ($item['code'] === PayrollReportService::INCOME_TAX_CODE) {
                        $totals[$key]['tax'] += $item['amount'];
                    }
                }
            }
        }
        if ($totals === []) {
            return;
        }

        $migratedRunIds = PayrollRun::query()->where('memo', MfPayslipImporter::RUN_MEMO)->pluck('id')->all();

        $rows = [];
        $mismatch = 0;
        foreach (collect($totals)->sortBy([['year', 'asc'], fn ($a, $b) => strnatcmp((string) $a['employee_no'], (string) $b['employee_no'])]) as $t) {
            $db = ['gross' => 0, 'social' => 0, 'tax' => 0];
            $data = $reports->employeeYearlyPayslips($t['user_id'], $t['year']);
            foreach ($data['salary'] as $payslip) {
                /** @var Payslip $payslip */
                if (! in_array($payslip->payroll_run_id, $migratedRunIds, true)) {
                    continue;
                }
                $s = $reports->summarize($payslip);
                $db['gross'] += $s['gross'];
                $db['social'] += $s['social_insurance'];
                $db['tax'] += $s['income_tax'];
            }
            $registered = $db['gross'] !== 0 || $db['social'] !== 0 || $db['tax'] !== 0;
            $match = $db['gross'] === $t['gross'] && $db['social'] === $t['social'] && $db['tax'] === $t['tax'];
            if ($registered && ! $match) {
                $mismatch++;
            }
            $rows[] = [
                $t['year'],
                $t['employee_no'],
                $t['name'],
                $t['months'],
                number_format($t['gross']),
                number_format($t['social']),
                number_format($t['tax']),
                match (true) {
                    $match => '一致',
                    ! $registered => '未登録',
                    default => sprintf('不一致（登録済 %s / %s / %s）', number_format($db['gross']), number_format($db['social']), number_format($db['tax'])),
                },
            ];
        }

        $this->newLine();
        $this->info('【従業員ごとの合計（PDFから計算）】年は支給日の年');
        $this->table(['年', '従業員番号', '氏名', '月数', '支給合計', '社会保険料', '所得税', '登録済みの移行データ'], $rows);
        if ($mismatch > 0) {
            $this->warn(sprintf('  登録済みデータと一致しない従業員: %d 名', $mismatch));
        }
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function resolveFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (! file_exists($path) && file_exists(storage_path('app/mf-migration/' . $path))) {
                $path = storage_path('app/mf-migration/' . $path);
            }
            if (is_dir($path)) {
                $found = glob(rtrim($path, '/') . '/*.{pdf,PDF}', GLOB_BRACE) ?: [];
                sort($found, SORT_NATURAL);
                array_push($files, ...$found);
            } elseif (is_file($path)) {
                $files[] = $path;
            } else {
                $this->warn("見つかりません: {$path}");
            }
        }

        return array_values(array_unique($files));
    }

    /** @return array{0: int|null|false, 1: string} */
    private function resolveLocation(): array
    {
        $option = $this->option('location');

        if ($option === 'none') {
            return [null, 'なし'];
        }
        if ($option !== null && $option !== '') {
            $location = BusinessLocation::find((int) $option);
            if (! $location) {
                $this->error("事業所ID {$option} が見つかりません。");

                return [false, ''];
            }

            return [$location->id, $location->name];
        }

        $run = PayrollRun::query()
            ->where('pay_type', 'salary')
            ->where(fn ($q) => $q->whereNull('memo')->orWhere('memo', '!=', MfPayslipImporter::RUN_MEMO))
            ->orderBy('period_key')
            ->first();
        $id = $run?->business_location_id;

        return [$id, $id ? (BusinessLocation::find($id)?->name ?? "ID {$id}") : 'なし'];
    }
}
