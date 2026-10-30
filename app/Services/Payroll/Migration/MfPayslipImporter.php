<?php

namespace App\Services\Payroll\Migration;

use App\Models\DeductionItemMaster;
use App\Models\EmployeePayroll;
use App\Models\PayItemMaster;
use App\Models\Payslip;
use App\Models\PayrollRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MF給与明細PDF（MfPayslipPdfParser の結果）を、確定済みの過去給与バッチとして登録する。
 *
 * - 従業員は従業員番号で突合し、氏名が一致しない場合は氏名の完全一致（1名のみ）で救済する。
 * - 項目は名称で支給項目マスタ（従業員の給与区分を優先）・控除項目マスタへ突合する。
 * - 1件でもエラーがあれば登録しない。
 */
class MfPayslipImporter
{
    public const RUN_MEMO = 'MF給与PDFから移行';

    public const INCOME_TAX_SOURCE = 'migrated';

    private const PAY_TYPE_ORDER = ['monthly', 'hourly', 'daily', 'bonus'];

    /** @var Collection<int, EmployeePayroll>|null */
    private ?Collection $employees = null;

    /** @var Collection<int, PayItemMaster>|null */
    private ?Collection $payItems = null;

    /** @var Collection<string, DeductionItemMaster>|null */
    private ?Collection $deductionItems = null;

    /**
     * 解析結果を検証し、period_key ごとの登録内容を組み立てる（DBへは書き込まない）。
     *
     * @param  array<int, array<string, mixed>>  $slips  parser の結果（file キー付き）
     * @return array{
     *   runs: array<string, array{period_key:string, payment_date:string, closing_date:string, existing_run_id:?int, slips: array<int, array<string, mixed>>}>,
     *   errors: array<int, string>, warnings: array<int, string>, skipped: array<int, string>
     * }
     */
    public function prepare(array $slips, ?int $locationId, bool $skipUnknownEmployees = false): array
    {
        $runs = [];
        $errors = [];
        $warnings = [];
        $skipped = [];
        $seen = [];

        foreach ($slips as $slip) {
            $where = $this->where($slip);

            foreach ($slip['errors'] as $e) {
                $errors[] = "{$where}: {$e}";
            }
            if ($slip['errors'] !== []) {
                continue;
            }

            if ($this->isEmpty($slip)) {
                $skipped[] = "{$where}: 支給・控除がすべて0円のため登録しません";
                continue;
            }

            [$employee, $message] = $this->resolveEmployee($slip);
            if (! $employee) {
                if ($skipUnknownEmployees) {
                    $skipped[] = "{$where}: {$message}（--skip-unknown によりスキップ）";
                } else {
                    $errors[] = "{$where}: {$message}";
                }
                continue;
            }
            if ($message) {
                $warnings[] = "{$where}: {$message}";
            }

            $periodKey = $slip['period_key'];
            $dupKey = $periodKey . ':' . $employee->user_id;
            if (isset($seen[$dupKey])) {
                $errors[] = "{$where}: 同じ対象期間に同じ従業員の明細が重複しています（{$seen[$dupKey]}）";
                continue;
            }
            $seen[$dupKey] = $where;

            $items = [];
            $itemErrors = [];
            foreach (['earning' => 'earnings', 'deduction' => 'deductions'] as $type => $key) {
                foreach ($slip[$key] as $row) {
                    $master = $type === 'earning'
                        ? $this->payItem($row['name'], (string) $employee->pay_type)
                        : $this->deductionItem($row['name']);
                    if (! $master) {
                        $itemErrors[] = sprintf('%s項目「%s」に対応するマスタがありません', $type === 'earning' ? '支給' : '控除', $row['name']);
                        continue;
                    }
                    $items[] = [
                        'item_type' => $type,
                        'source_master_id' => $master->id,
                        'code' => $master->code,
                        'name' => $master->name,
                        'category' => $master->category,
                        'amount' => (int) $row['amount'],
                        'sort_order' => (int) $master->sort_order,
                    ];
                }
            }
            foreach ($itemErrors as $e) {
                $errors[] = "{$where}: {$e}";
            }
            if ($itemErrors !== []) {
                continue;
            }

            if (! isset($runs[$periodKey])) {
                $runs[$periodKey] = [
                    'period_key' => $periodKey,
                    'payment_date' => $slip['payment_date'],
                    'closing_date' => $slip['period_end'],
                    'existing_run_id' => null,
                    'slips' => [],
                ];
            } elseif ($runs[$periodKey]['payment_date'] !== $slip['payment_date']) {
                $errors[] = sprintf(
                    '%s: 同じ対象期間（%s）で支給日が異なります（%s / %s）',
                    $where,
                    $periodKey,
                    $runs[$periodKey]['payment_date'],
                    $slip['payment_date'],
                );
                continue;
            }

            $runs[$periodKey]['slips'][] = [
                'user_id' => $employee->user_id,
                'employee_no' => $employee->employee_no,
                'name' => $employee->user?->name,
                'source' => $where,
                'total_earnings' => (int) $slip['total_earnings'],
                'total_deductions' => (int) $slip['total_deductions'],
                'net_pay' => (int) $slip['net_pay'],
                'items' => $items,
            ];
        }

        ksort($runs);
        foreach ($runs as $periodKey => &$run) {
            $existing = PayrollRun::query()
                ->where('period_key', $periodKey)
                ->where('pay_type', 'salary')
                ->where('business_location_id', $locationId)
                ->first();
            if (! $existing) {
                continue;
            }
            if ($existing->memo === self::RUN_MEMO) {
                $run['existing_run_id'] = $existing->id;
                $warnings[] = "{$periodKey}: 以前に移行したバッチ（ID {$existing->id}）を削除して作り直します";
            } else {
                $errors[] = "{$periodKey}: 本システムで作成した給与バッチ（ID {$existing->id}）が既にあるため登録できません";
            }
        }
        unset($run);

        return compact('runs', 'errors', 'warnings', 'skipped');
    }

    /**
     * prepare() の結果を登録する。エラーがある場合は何もしない。
     *
     * @param  array<string, mixed>  $prepared
     * @return array{runs:int, payslips:int}
     */
    public function commit(array $prepared, ?int $locationId): array
    {
        if ($prepared['errors'] !== []) {
            throw new \RuntimeException('エラーがあるため登録できません。');
        }

        $counts = ['runs' => 0, 'payslips' => 0];

        DB::transaction(function () use ($prepared, $locationId, &$counts) {
            $now = now();

            foreach ($prepared['runs'] as $data) {
                if ($data['existing_run_id']) {
                    PayrollRun::query()
                        ->whereKey($data['existing_run_id'])
                        ->where('memo', self::RUN_MEMO)
                        ->delete();
                }

                $run = PayrollRun::create([
                    'business_location_id' => $locationId,
                    'period_key' => $data['period_key'],
                    'pay_type' => 'salary',
                    'closing_date' => $data['closing_date'],
                    'payment_date' => $data['payment_date'],
                    'publish_date' => $data['payment_date'],
                    'status' => 'finalized',
                    'finalized_at' => $now,
                    'memo' => self::RUN_MEMO,
                ]);
                $counts['runs']++;

                foreach ($data['slips'] as $s) {
                    $payslip = Payslip::create([
                        'payroll_run_id' => $run->id,
                        'user_id' => $s['user_id'],
                        'total_earnings' => $s['total_earnings'],
                        'total_deductions' => $s['total_deductions'],
                        'net_pay' => $s['net_pay'],
                        'income_tax_source' => self::INCOME_TAX_SOURCE,
                        'is_confirmed' => true,
                        'calculated_at' => $now,
                    ]);
                    foreach ($s['items'] as $item) {
                        $payslip->items()->create($item + ['is_manual_override' => true]);
                    }
                    $counts['payslips']++;
                }
            }
        });

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $slip
     * @return array{0: ?EmployeePayroll, 1: ?string} [従業員, 警告またはエラー文]
     */
    private function resolveEmployee(array $slip): array
    {
        return (new MfMigrationEmployeeResolver())->resolve((string) $slip['employee_no'], (string) $slip['name']);
    }

    /** 空白・全角半角の違いを無視して氏名を比較する。 */
    public static function normalizeName(string $name): string
    {
        return preg_replace('/\s+/u', '', mb_convert_kana($name, 'asKV'));
    }

    private function payItem(string $name, string $payType): ?PayItemMaster
    {
        $candidates = $this->payItems()->filter(fn (PayItemMaster $m) => $m->name === $name);
        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates->firstWhere('pay_type', $payType)
            ?? $candidates->sortBy(fn (PayItemMaster $m) => array_search($m->pay_type, self::PAY_TYPE_ORDER, true))->first();
    }

    private function deductionItem(string $name): ?DeductionItemMaster
    {
        return $this->deductionItems()->get($name);
    }

    /** @param array<string, mixed> $slip */
    private function isEmpty(array $slip): bool
    {
        if ($slip['total_earnings'] !== 0 || $slip['total_deductions'] !== 0 || $slip['net_pay'] !== 0) {
            return false;
        }

        foreach (array_merge($slip['earnings'], $slip['deductions']) as $row) {
            if ($row['amount'] !== 0) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $slip */
    private function where(array $slip): string
    {
        return sprintf(
            '%s %dページ（%s %s）',
            $slip['file'] ?? '',
            $slip['page'],
            $slip['employee_no'] ?? '-',
            $slip['name'] ?? '-',
        );
    }

    /** @return Collection<int, EmployeePayroll> */
    private function employees(): Collection
    {
        return $this->employees ??= EmployeePayroll::query()
            ->with('user:id,name')
            ->whereHas('user')
            ->get(['id', 'user_id', 'employee_no', 'pay_type']);
    }

    /** @return Collection<int, PayItemMaster> */
    private function payItems(): Collection
    {
        return $this->payItems ??= PayItemMaster::query()->orderBy('id')->get();
    }

    /** @return Collection<string, DeductionItemMaster> */
    private function deductionItems(): Collection
    {
        return $this->deductionItems ??= DeductionItemMaster::query()->orderBy('id')->get()->keyBy('name');
    }
}
