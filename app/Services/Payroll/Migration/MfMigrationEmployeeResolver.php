<?php

namespace App\Services\Payroll\Migration;

use App\Models\EmployeePayroll;
use Illuminate\Support\Collection;

/** MF移行データ（PDF・CSV）の従業員番号・氏名突合。 */
final class MfMigrationEmployeeResolver
{
    /**
     * @return array{0: ?EmployeePayroll, 1: ?string}
     */
    public function resolve(?string $employeeNo, string $name): array
    {
        $employeeNo = trim((string) $employeeNo);
        $employees = $this->employees();
        $pdfName = MfPayslipImporter::normalizeName($name);

        $byNo = $employeeNo !== ''
            ? $employees->first(fn (EmployeePayroll $e) => (string) $e->employee_no === $employeeNo)
            : null;

        $systemName = MfPayslipImporter::normalizeName((string) $byNo?->user?->name);
        if ($byNo && ($systemName === $pdfName || $this->nameLikelySamePerson($systemName, $pdfName))) {
            if ($systemName !== $pdfName) {
                return [$byNo, sprintf(
                    '従業員番号 %s は氏名表記が MF「%s」とシステム「%s」で異なりますが、同一人物として登録します',
                    $employeeNo,
                    $name,
                    $byNo->user?->name,
                )];
            }

            return [$byNo, null];
        }

        $byName = $employees->filter(fn (EmployeePayroll $e) => MfPayslipImporter::normalizeName((string) $e->user?->name) === $pdfName);
        if ($byName->count() === 1) {
            $e = $byName->first();

            return [$e, sprintf(
                '従業員番号 %s %sはシステム上の番号では見つからないため、氏名が一致する従業員番号 %s %sに登録します',
                $employeeNo ?: '(空)',
                $byNo ? "（システムでは {$byNo->user?->name}）" : '',
                $e->employee_no ?? '(未設定)',
                $e->user?->name,
            )];
        }
        if ($byName->count() > 1) {
            return [null, "氏名「{$name}」の従業員が複数いて特定できません"];
        }

        return [null, $byNo
            ? "従業員番号 {$employeeNo} はシステムでは「{$byNo->user?->name}」で、氏名「{$name}」と一致しません"
            : "従業員番号 {$employeeNo}「{$name}」がシステムに登録されていません"];
    }

    /** MF側のフルネームとシステム側の短縮名など、先頭一致で同一人物とみなす。 */
    private function nameLikelySamePerson(string $systemName, string $mfName): bool
    {
        if ($systemName === '' || $mfName === '') {
            return false;
        }
        if (str_starts_with($mfName, $systemName) || str_starts_with($systemName, $mfName)) {
            return mb_strlen($systemName) >= 3 || mb_strlen($mfName) >= 3;
        }

        return false;
    }

    /** @return Collection<int, EmployeePayroll> */
    private function employees(): Collection
    {
        return EmployeePayroll::query()
            ->with('user:id,name,department_id')
            ->whereHas('user')
            ->get(['id', 'user_id', 'employee_no', 'pay_type']);
    }
}
