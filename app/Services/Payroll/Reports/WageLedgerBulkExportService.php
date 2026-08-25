<?php

namespace App\Services\Payroll\Reports;

use App\Models\BusinessLocation;
use Barryvdh\DomPDF\Facade\Pdf;
use ZipArchive;

/**
 * 賃金台帳の一括出力（全従業員 CSV / PDF ZIP）。
 */
class WageLedgerBulkExportService
{
    public function __construct(
        private WageLedgerService $ledger,
        private WageLedgerCsvExporter $csvExporter,
    ) {}

    /**
     * @param  array<string, mixed>  $periodInput
     * @param  array<int, int>  $userIds  空配列なら全従業員。指定時は該当IDのみ出力。
     */
    public function buildCsv(?int $locationId, array $periodInput, array $userIds = []): string
    {
        $lines = $this->collectEmployeeLines($locationId, $periodInput, $userIds);

        return $this->csvExporter->encode($lines);
    }

    /**
     * @param  array<string, mixed>  $periodInput
     * @param  array<int, int>  $userIds  空配列なら全従業員。指定時は該当IDのみ出力。
     * @return array{0: string, 1: string} [zip binary, filename]
     */
    public function buildPdfZip(?int $locationId, array $periodInput, array $userIds = []): array
    {
        $period = $this->ledger->resolvePeriod($periodInput);
        $employees = $this->filterEmployees($this->ledger->employeeList($locationId), $userIds);

        if ($employees->isEmpty()) {
            abort(422, '対象従業員がいません。');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'wage_ledger_zip_');
        if ($tmp === false) {
            abort(500, 'ZIPファイルを作成できませんでした。');
        }

        $zipPath = $tmp.'.zip';
        @unlink($tmp);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'ZIPファイルを作成できませんでした。');
        }

        foreach ($employees as $emp) {
            $matrix = $this->ledger->build((int) $emp['id'], $period, $locationId);
            $pdf = Pdf::loadView('payslips.wage_ledger', [
                'periodLabel' => $period['label'],
                'userName' => $emp['name'],
                'matrix' => $matrix,
            ])->setPaper('a4', 'landscape')->output();

            $dir = $this->dirName($emp['name'], $emp['employee_no'] ?? null);
            $zip->addFromString("{$dir}/賃金台帳_{$period['year']}.pdf", $pdf);
        }

        $zip->close();
        $binary = file_get_contents($zipPath) ?: '';
        @unlink($zipPath);

        return [$binary, sprintf('賃金台帳_%s_%d.zip', $this->locationLabel($locationId), $period['year'])];
    }

    /**
     * @param  array<string, mixed>  $periodInput
     * @param  array<int, int>  $userIds
     * @return array<int, string>
     */
    private function collectEmployeeLines(?int $locationId, array $periodInput, array $userIds = []): array
    {
        $period = $this->ledger->resolvePeriod($periodInput);
        $employees = $this->filterEmployees($this->ledger->employeeList($locationId), $userIds);

        if ($employees->isEmpty()) {
            abort(422, '対象従業員がいません。');
        }

        $lines = [];
        foreach ($employees as $emp) {
            $matrix = $this->ledger->build((int) $emp['id'], $period, $locationId);
            array_push($lines, ...$this->csvExporter->employeeBlockLines($matrix));
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $periodInput
     */
    public function csvFileName(?int $locationId, array $periodInput): string
    {
        $period = $this->ledger->resolvePeriod($periodInput);

        return sprintf('賃金台帳_%s_%d.csv', $this->locationLabel($locationId), $period['year']);
    }

    /**
     * 指定IDのみに絞り込む（空なら全件）。employeeList の並び順は維持する。
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $employees
     * @param  array<int, int>  $userIds
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function filterEmployees($employees, array $userIds)
    {
        if ($userIds === []) {
            return $employees;
        }

        $allowed = array_flip(array_map('intval', $userIds));

        return $employees->filter(fn ($emp) => isset($allowed[(int) $emp['id']]))->values();
    }

    private function locationLabel(?int $locationId): string
    {
        if (! $locationId) {
            return '全事業所';
        }

        return BusinessLocation::find($locationId)?->name ?? "location_{$locationId}";
    }

    private function dirName(?string $name, ?string $employeeNo): string
    {
        $label = ($name ?? 'unknown').($employeeNo ? "_{$employeeNo}" : '');

        return preg_replace('/[\/\\\\:*?"<>|]/', '_', $label) ?: 'unknown';
    }
}
