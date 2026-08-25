<?php

namespace App\Services\Payroll;

use App\Models\PayrollRun;
use App\Models\Payslip;

/**
 * 全銀協フォーマット（給与振込FBデータ）の生成。
 *
 * 120バイト固定長・4レコード種別（ヘッダー1 / データ2 / トレーラー8 / エンド9）。
 * 半角カナ・半角英数のみ。金額は右詰めゼロ埋め、名称は左詰めスペース埋め。
 * 出力は Shift-JIS（銀行取込の標準）でエンコードする。
 *
 * 参照: 資料/設計書 20_給与振込一覧表（FBデータ出力）
 *
 * 注: 委託者情報（委託者コード・仕向銀行/支店）は自社の口座マスタが未整備のため、
 *     暫定値（引数 or 既定）で出力する。実運用では会社銀行マスタから供給すること。
 */
class ZenginFormatService
{
    private const RECORD_LENGTH = 120;

    // 種別コード: 21=給与振込, 11=総合振込。給与振込を既定とする。
    private const CATEGORY_CODE = '21';

    /**
     * @param  array{consignor_code?: string, consignor_name?: string, bank_code?: string, bank_name?: string, branch_code?: string, branch_name?: string}  $sender
     */
    public function build(PayrollRun $run, array $sender = []): string
    {
        $payslips = $run->payslips()
            ->with(['user.employeePayroll'])
            ->orderByEmployeeNo()
            ->get()
            ->filter(fn (Payslip $p) => $this->hasAccount($p) && $p->net_pay > 0)
            ->values();

        $lines = [];
        $lines[] = $this->headerRecord($run, $sender);

        $total = 0;
        foreach ($payslips as $p) {
            $lines[] = $this->dataRecord($p);
            $total += (int) $p->net_pay;
        }

        $lines[] = $this->trailerRecord($payslips->count(), $total);
        $lines[] = $this->endRecord();

        // 各レコードは既に SJIS バイト列（120バイト固定長）。改行を挟んで結合。
        return implode("\r\n", $lines) . "\r\n";
    }

    private function hasAccount(Payslip $p): bool
    {
        $ep = $p->user?->employeePayroll;

        return $ep && filled($ep->bank_code) && filled($ep->branch_code) && filled($ep->account_number);
    }

    private function headerRecord(PayrollRun $run, array $sender): string
    {
        $date = $run->payment_date ?? now();

        $rec = '1'; // データ区分
        $rec .= self::CATEGORY_CODE;
        $rec .= '0'; // コード区分（0=JIS）
        $rec .= $this->num($sender['consignor_code'] ?? '', 10);
        $rec .= $this->kana($sender['consignor_name'] ?? config('app.name'), 40);
        $rec .= $this->num($date->format('md'), 4); // 振込指定日 MMDD
        $rec .= $this->num($sender['bank_code'] ?? '', 4);
        $rec .= $this->kana($sender['bank_name'] ?? '', 15);
        $rec .= $this->num($sender['branch_code'] ?? '', 3);
        $rec .= $this->kana($sender['branch_name'] ?? '', 15);

        return $this->fit($rec);
    }

    private function dataRecord(Payslip $p): string
    {
        $ep = $p->user->employeePayroll;

        $rec = '2'; // データ区分
        $rec .= $this->num($ep->bank_code, 4);
        $rec .= $this->kana($ep->bank_name ?? '', 15);
        $rec .= $this->num($ep->branch_code, 3);
        $rec .= $this->kana($ep->branch_name ?? '', 15);
        $rec .= str_repeat(' ', 4); // 手形交換所番号
        $rec .= $this->accountType($ep->account_type);
        $rec .= $this->num($ep->account_number, 7);
        $rec .= $this->kana($ep->account_holder_kana ?? ($p->user->name ?? ''), 30);
        $rec .= $this->num((string) (int) $p->net_pay, 10);
        $rec .= '0'; // 新規コード
        $rec .= str_repeat(' ', 20); // 顧客コード1/2
        $rec .= '7'; // 振込区分（7=給与振込）
        $rec .= ' '; // 識別表示

        return $this->fit($rec);
    }

    private function trailerRecord(int $count, int $total): string
    {
        $rec = '8';
        $rec .= $this->num((string) $count, 6);
        $rec .= $this->num((string) $total, 12);

        return $this->fit($rec);
    }

    private function endRecord(): string
    {
        return $this->fit('9');
    }

    private function accountType(?string $type): string
    {
        return match ($type) {
            'checking' => '2', // 当座
            'savings' => '4',  // 貯蓄
            default => '1',    // 普通
        };
    }

    /** 数字を右詰めゼロ埋め（超過は末尾切り捨て） */
    private function num(string $value, int $length): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        $digits = substr($digits, 0, $length);

        return str_pad($digits, $length, '0', STR_PAD_LEFT);
    }

    /**
     * 半角カナ・英数へ寄せ、SJIS(SJIS-win)バイト列に変換して左詰めスペース埋め。
     * 固定長を保つため、切り詰め・パディングはすべてバイト単位で行う。
     * （半角カナは1バイトだが、変換しきれない漢字等は2バイトになるためバイト基準が必須）
     */
    private function kana(string $value, int $length): string
    {
        $half = mb_convert_kana($value, 'krns');
        $bytes = mb_convert_encoding($half, 'SJIS-win', 'UTF-8');

        // マルチバイト境界を壊さないよう、SJISとして安全に length バイトへ切り詰める
        $bytes = mb_strcut($bytes, 0, $length, 'SJIS-win');

        return $bytes . str_repeat(' ', max(0, $length - strlen($bytes)));
    }

    /** 120バイト固定長へ調整（バイト単位で切り詰め／スペース埋め） */
    private function fit(string $rec): string
    {
        if (strlen($rec) > self::RECORD_LENGTH) {
            return mb_strcut($rec, 0, self::RECORD_LENGTH, 'SJIS-win');
        }

        return $rec . str_repeat(' ', self::RECORD_LENGTH - strlen($rec));
    }
}
