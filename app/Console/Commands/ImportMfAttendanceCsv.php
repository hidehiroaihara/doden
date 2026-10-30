<?php

namespace App\Console\Commands;

use App\Services\Payroll\Migration\MfAttendanceImporter;
use App\Services\Payroll\Migration\MfDailyAttendanceCsvParser;
use Illuminate\Console\Command;

/**
 * MF「日次勤怠レポート」CSVから過去の打刻履歴を取り込む（移行用）。
 *
 * 例:
 *   php artisan attendance:import-mf-csv storage/app/mf-migration/daily_attendance.csv --dry-run
 *   php artisan attendance:import-mf-csv storage/app/mf-migration/daily_attendance.csv --replace
 */
class ImportMfAttendanceCsv extends Command
{
    protected $signature = 'attendance:import-mf-csv
                            {path : CSVファイルのパス}
                            {--dry-run : 登録せずプレビューのみ}
                            {--skip-unknown : 未登録従業員の行をスキップ}
                            {--replace : 同じ期間の移行済み打刻（mf-csv-migration）を削除してから登録}
                            {--force : 確認なしで登録}';

    protected $description = 'MF日次勤怠CSVから打刻履歴を取り込む（移行用）';

    public function handle(MfDailyAttendanceCsvParser $parser, MfAttendanceImporter $importer): int
    {
        $path = $this->resolvePath((string) $this->argument('path'));
        if (! is_file($path)) {
            $this->error("CSVが見つかりません: {$path}");

            return self::FAILURE;
        }

        $rows = $parser->parseFile($path);
        $this->line(sprintf('CSV %d 行（打刻あり行のみ登録対象）', count($rows)));

        $prepared = $importer->prepare($rows, (bool) $this->option('skip-unknown'));
        $this->printPrepared($prepared);

        if ($prepared['errors'] !== []) {
            $this->error(sprintf('エラー %d 件のため登録しません。', count($prepared['errors'])));

            return self::FAILURE;
        }
        if ($prepared['to_import'] === []) {
            $this->warn('登録する打刻がありません。');

            return self::SUCCESS;
        }
        if ($this->option('dry-run')) {
            $this->info('エラーはありません（--dry-run のため登録していません）。');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('上記の打刻を登録します。よろしいですか？')) {
            $this->info('キャンセルしました。');

            return self::SUCCESS;
        }

        $counts = $importer->commit($prepared, (bool) $this->option('replace'));
        $this->info(sprintf(
            '登録しました（新規 %d 件%s）。',
            $counts['created'],
            $counts['replaced'] > 0 ? " / 移行済み削除 {$counts['replaced']} 件" : '',
        ));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $prepared */
    private function printPrepared(array $prepared): void
    {
        $this->table(
            ['項目', '値'],
            [
                ['期間', ($prepared['stats']['from'] ?? '-') . ' 〜 ' . ($prepared['stats']['to'] ?? '-')],
                ['登録予定', count($prepared['to_import'])],
                ['スキップ', count($prepared['skipped'])],
                ['注意', count($prepared['warnings'])],
                ['エラー', count($prepared['errors'])],
            ],
        );

        foreach (['skipped' => 'スキップ', 'warnings' => '注意', 'errors' => 'エラー'] as $key => $title) {
            if ($prepared[$key] === []) {
                continue;
            }
            $this->newLine();
            $this->line("【{$title}】");
            foreach (array_slice($prepared[$key], 0, 30) as $m) {
                $this->line('  - ' . $m);
            }
            if (count($prepared[$key]) > 30) {
                $this->line('  ... 他 ' . (count($prepared[$key]) - 30) . ' 件');
            }
        }
    }

    private function resolvePath(string $path): string
    {
        if (file_exists($path)) {
            return $path;
        }
        $alt = storage_path('app/mf-migration/' . ltrim($path, '/'));

        return file_exists($alt) ? $alt : $path;
    }
}
