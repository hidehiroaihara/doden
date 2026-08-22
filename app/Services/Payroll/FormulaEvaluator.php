<?php

namespace App\Services\Payroll;

use RuntimeException;

/**
 * カスタム計算式（支給項目 se13）のトークン列を評価する再帰下降パーサ。
 *
 * トークン形式（custom_formula JSON）:
 *   {"t":"ref","kind":"basis|pay|attendance","code":"hourly2"}
 *   {"t":"num","value":1.25}
 *   {"t":"op","value":"+|-|*|/"}
 *   {"t":"cmp","value":"<=|>=|!=|<|>|="}
 *   {"t":"fn","value":"ROUND|ROUNDUP|ROUNDDOWN|IF"}
 *   {"t":"paren","value":"(|)"}
 *   {"t":"comma"}
 *
 * 対応: 四則演算(+ - * /)・括弧・比較(<= >= != < > =, 真1/偽0)・
 *       ROUND/ROUNDUP/ROUNDDOWN(値,桁数)・IF(条件,真,偽)。
 * eval() は使用せず安全に評価する。
 */
class FormulaEvaluator
{
    /** @var array<int, array<string, mixed>> */
    private array $tokens = [];

    private int $pos = 0;

    /**
     * 解決コンテキスト。
     * ['basis' => [code => float], 'pay' => [code => float], 'attendance' => [code => float]]
     *
     * @var array<string, array<string, float>>
     */
    private array $context = [];

    /**
     * トークン列を評価して数値を返す。
     *
     * @param array<int, array<string, mixed>> $tokens
     * @param array<string, array<string, float>> $context
     */
    public function evaluate(array $tokens, array $context): float
    {
        $this->tokens = array_values($tokens);
        $this->context = $context;
        $this->pos = 0;

        if (empty($this->tokens)) {
            return 0.0;
        }

        $value = $this->parseExpression();

        if ($this->pos < count($this->tokens)) {
            throw new RuntimeException('カスタム計算式の解析に失敗しました（余分なトークン）');
        }

        return $value;
    }

    // ---- パーサ ------------------------------------------------------------

    private function parseExpression(): float
    {
        // comparison （最も低い優先度）
        $left = $this->parseAdditive();

        $token = $this->peek();
        if ($token !== null && $token['t'] === 'cmp') {
            $this->next();
            $right = $this->parseAdditive();

            return $this->compare((string) $token['value'], $left, $right) ? 1.0 : 0.0;
        }

        return $left;
    }

    private function parseAdditive(): float
    {
        $value = $this->parseMultiplicative();

        while (($token = $this->peek()) !== null && $token['t'] === 'op' && in_array($token['value'], ['+', '-'], true)) {
            $this->next();
            $right = $this->parseMultiplicative();
            $value = $token['value'] === '+' ? $value + $right : $value - $right;
        }

        return $value;
    }

    private function parseMultiplicative(): float
    {
        $value = $this->parseUnary();

        while (($token = $this->peek()) !== null && $token['t'] === 'op' && in_array($token['value'], ['*', '/'], true)) {
            $this->next();
            $right = $this->parseUnary();
            if ($token['value'] === '*') {
                $value *= $right;
            } else {
                $value = $right != 0.0 ? $value / $right : 0.0;
            }
        }

        return $value;
    }

    private function parseUnary(): float
    {
        $token = $this->peek();
        if ($token !== null && $token['t'] === 'op' && $token['value'] === '-') {
            $this->next();

            return -$this->parseUnary();
        }
        if ($token !== null && $token['t'] === 'op' && $token['value'] === '+') {
            $this->next();

            return $this->parseUnary();
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): float
    {
        $token = $this->peek();
        if ($token === null) {
            throw new RuntimeException('カスタム計算式が途中で終了しています');
        }

        switch ($token['t']) {
            case 'num':
                $this->next();

                return (float) $token['value'];

            case 'ref':
                $this->next();

                return $this->resolveRef((string) ($token['kind'] ?? ''), (string) ($token['code'] ?? ''));

            case 'paren':
                if ($token['value'] === '(') {
                    $this->next();
                    $value = $this->parseExpression();
                    $this->expect('paren', ')');

                    return $value;
                }
                throw new RuntimeException('予期しない閉じ括弧です');

            case 'fn':
                return $this->parseFunction((string) $token['value']);

            default:
                throw new RuntimeException("予期しないトークンです: {$token['t']}");
        }
    }

    private function parseFunction(string $fn): float
    {
        $this->next(); // consume fn
        $this->expect('paren', '(');

        $args = [$this->parseExpression()];
        while (($token = $this->peek()) !== null && $token['t'] === 'comma') {
            $this->next();
            $args[] = $this->parseExpression();
        }
        $this->expect('paren', ')');

        return $this->applyFunction($fn, $args);
    }

    /**
     * @param array<int, float> $args
     */
    private function applyFunction(string $fn, array $args): float
    {
        switch (strtoupper($fn)) {
            case 'ROUND':
                return $this->roundAt($args[0] ?? 0.0, (int) ($args[1] ?? 0), 'round');
            case 'ROUNDUP':
                return $this->roundAt($args[0] ?? 0.0, (int) ($args[1] ?? 0), 'ceil');
            case 'ROUNDDOWN':
                return $this->roundAt($args[0] ?? 0.0, (int) ($args[1] ?? 0), 'floor');
            case 'IF':
                $cond = $args[0] ?? 0.0;

                return $cond != 0.0 ? ($args[1] ?? 0.0) : ($args[2] ?? 0.0);
            default:
                throw new RuntimeException("未対応の関数です: {$fn}");
        }
    }

    // ---- ヘルパ ------------------------------------------------------------

    private function resolveRef(string $kind, string $code): float
    {
        return (float) ($this->context[$kind][$code] ?? 0.0);
    }

    private function compare(string $op, float $a, float $b): bool
    {
        return match ($op) {
            '=' => $a == $b,
            '!=' => $a != $b,
            '<' => $a < $b,
            '>' => $a > $b,
            '<=' => $a <= $b,
            '>=' => $a >= $b,
            default => throw new RuntimeException("未対応の比較演算子です: {$op}"),
        };
    }

    private function roundAt(float $value, int $digits, string $mode): float
    {
        $factor = 10 ** $digits;
        $scaled = $value * $factor;
        $result = match ($mode) {
            'ceil' => ceil($scaled),
            'floor' => floor($scaled),
            default => round($scaled),
        };

        return $result / $factor;
    }

    /** @return array<string, mixed>|null */
    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function next(): void
    {
        $this->pos++;
    }

    private function expect(string $type, ?string $value = null): void
    {
        $token = $this->peek();
        if ($token === null || $token['t'] !== $type || ($value !== null && ($token['value'] ?? null) !== $value)) {
            $expected = $value !== null ? "{$type}({$value})" : $type;
            throw new RuntimeException("カスタム計算式の解析に失敗しました（{$expected} が必要です）");
        }
        $this->next();
    }
}
