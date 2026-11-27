<?php

declare(strict_types=1);

namespace Mad\Form;

/**
 * Analisador descendente da expressão (ver DateFormula). Avalia enquanto lê:
 * número, {campo} como número, + - * /, parênteses, round/max/min/abs/floor/ceil
 * e as funções de data com argumentos simples. Qualquer outra coisa lança — o
 * DateFormula devolve null ("não sei").
 *
 * @internal
 */
final class DateFormulaParser
{
    private int $i = 0;

    /**
     * @param list<array{0: string, 1: mixed}> $t
     * @param array<string, mixed>             $values
     * @param array<string, string>            $masks
     */
    public function __construct(private array $t, private array $values, private array $masks, private bool $numbersOnly = false)
    {
    }

    /** @return array{kind: string, value?: mixed} */
    public function parse(): array
    {
        $r = $this->sum();
        if ($this->i !== count($this->t)) {
            throw new \RuntimeException('sobra');
        }

        return $r;
    }

    /** @return array{kind: string, value?: mixed} */
    private function sum(): array
    {
        $l = $this->product();
        while ($this->isOp('+') || $this->isOp('-')) {
            $op = $this->t[$this->i++][1];
            $r = $this->product();
            $l = self::num($this->n($l) + ($op === '+' ? 1 : -1) * $this->n($r));
        }

        return $l;
    }

    /** @return array{kind: string, value?: mixed} */
    private function product(): array
    {
        $l = $this->unary();
        while ($this->isOp('*') || $this->isOp('/')) {
            $op = $this->t[$this->i++][1];
            $a = $this->n($l);
            $b = $this->n($this->unary());
            if ($op === '*') {
                $l = self::num($a * $b);
            } else {
                // 1/0 no navegador = Infinity → campo vazio; 0/0 = NaN.
                $l = self::num($b == 0.0 ? ($a == 0.0 || is_nan($a) ? NAN : INF) : $a / $b);
            }
        }

        return $l;
    }

    /** @return array{kind: string, value?: mixed} */
    private function unary(): array
    {
        if ($this->isOp('-')) {
            $this->i++;

            return self::num(-$this->n($this->unary()));
        }
        if ($this->isOp('+')) {
            $this->i++;

            return self::num($this->n($this->unary()));
        }

        return $this->primary();
    }

    /** @return array{kind: string, value?: mixed} */
    private function primary(): array
    {
        $tok = $this->t[$this->i] ?? null;
        if ($tok === null) {
            throw new \RuntimeException('fim');
        }
        if ($tok[0] === 'num') {
            $this->i++;

            return self::num((float) $tok[1]);
        }
        if ($tok[0] === 'field') {
            $this->i++;

            return self::num(DateFormula::toNumber($this->values[$tok[1]] ?? ''));
        }
        if ($this->isOp('(')) {
            $this->i++;
            $r = $this->sum();
            $this->expect(')');

            return $r;
        }
        if ($tok[0] === 'id') {
            $name = (string) $tok[1];
            $this->i++;
            $this->expect('(');
            if (isset(DateFormula::FUNCTIONS[$name])) {
                // Campo calculado: só as que dão número (como no navegador).
                if ($this->numbersOnly && !in_array($name, DateFormula::NUMBER_FUNCTIONS, true)) {
                    throw new \RuntimeException('função de data no campo calculado');
                }

                return $this->dateCall($name);
            }
            if (DateFormula::isMath($name)) {
                return $this->mathCall(strtolower($name));
            }
        }

        throw new \RuntimeException('ficha');
    }

    /** @return array{kind: string, value?: mixed} */
    private function dateCall(string $name): array
    {
        $kinds = DateFormula::FUNCTIONS[$name];
        $args = [];
        foreach ($kinds as $k => $kind) {
            if ($k > 0) {
                $this->expect(',');
            }
            $args[] = $kind === 'd' ? $this->dateArg() : $this->countArg();
        }
        $this->expect(')');

        if (in_array(null, $args, true)) {
            return ['kind' => 'nothing'];
        }
        switch ($name) {
            case 'addDays':
            case 'subDays':
                [$d, $hasTime] = $args[0];
                $r = DateFormula::addDays($d, $name === 'subDays' ? -$args[1] : $args[1]);

                return $r === null ? ['kind' => 'nothing'] : ['kind' => $hasTime ? 'datetime' : 'date', 'value' => $r];
            case 'addMonths':
            case 'subMonths':
                [$d, $hasTime] = $args[0];
                $r = DateFormula::addMonths($d, $name === 'subMonths' ? -$args[1] : $args[1]);

                return $r === null ? ['kind' => 'nothing'] : ['kind' => $hasTime ? 'datetime' : 'date', 'value' => $r];
            case 'diffDays':
                $n = DateFormula::diffDays($args[0][0], $args[1][0]);

                return $n === null ? ['kind' => 'nothing'] : self::num((float) $n);
            default: // diffBusinessDays
                $n = DateFormula::diffBusinessDays($args[0][0], $args[1][0]);

                return $n === null ? ['kind' => 'nothing'] : self::num((float) $n);
        }
    }

    /**
     * `{campo}`, today() ou now(). null = vazio/ilegível.
     *
     * @return array{0: \DateTimeImmutable, 1: bool}|null
     */
    private function dateArg(): ?array
    {
        $tok = $this->t[$this->i] ?? null;
        if ($tok !== null && $tok[0] === 'field') {
            $this->i++;
            $name = (string) $tok[1];

            return DateFormula::parseDate($this->values[$name] ?? null, (string) ($this->masks[$name] ?? ''));
        }
        if ($tok !== null && $tok[0] === 'id' && in_array($tok[1], ['today', 'now'], true)) {
            $this->i++;
            $this->expect('(');
            $this->expect(')');
            $now = DateFormula::now();

            return $tok[1] === 'today' ? [$now->setTime(0, 0, 0), false] : [$now, true];
        }

        throw new \RuntimeException('argumento de data');
    }

    /** `{campo}` ou inteiro (com sinal). null = vazio/ilegível. */
    private function countArg(): ?int
    {
        $tok = $this->t[$this->i] ?? null;
        if ($tok !== null && $tok[0] === 'field') {
            $this->i++;

            return DateFormula::count($this->values[(string) $tok[1]] ?? null);
        }
        $sign = 1;
        if ($this->isOp('-') || $this->isOp('+')) {
            $sign = $this->t[$this->i][1] === '-' ? -1 : 1;
            $this->i++;
            $tok = $this->t[$this->i] ?? null;
        }
        if ($tok !== null && $tok[0] === 'num' && floor((float) $tok[1]) == $tok[1]) {
            $this->i++;

            return $sign * (int) $tok[1];
        }

        throw new \RuntimeException('argumento de quantidade');
    }

    /** @return array{kind: string, value?: mixed} */
    private function mathCall(string $name): array
    {
        $args = [];
        if (!$this->isOp(')')) {
            $args[] = $this->n($this->sum());
            while ($this->isOp(',')) {
                $this->i++;
                $args[] = $this->n($this->sum());
            }
        }
        $this->expect(')');
        if ($args === []) {
            throw new \RuntimeException('sem argumento');
        }
        foreach ($args as $a) {
            // NaN contamina, como no Math.* do navegador.
            if (is_nan($a)) {
                return self::num(NAN);
            }
        }

        return match ($name) {
            'max'   => self::num(max($args)),
            'min'   => self::num(min($args)),
            'abs'   => self::num(abs($args[0])),
            'floor' => self::num(floor($args[0])),
            'ceil'  => self::num(ceil($args[0])),
            // round(x, casas) como o _madRound: meio para cima (1.005 → 1.01).
            default => self::num(self::round($args[0], (int) ($args[1] ?? 2))),
        };
    }

    private static function round(float $v, int $decimals): float
    {
        if (!is_finite($v)) {
            return $v;
        }
        $f = 10 ** $decimals;

        return floor(($v + PHP_FLOAT_EPSILON) * $f + 0.5) / $f;
    }

    /** Valor numérico de um resultado: data no meio de uma conta = NaN (vazio). */
    private function n(array $r): float
    {
        if ($r['kind'] === 'number') {
            return (float) $r['value'];
        }

        return NAN;
    }

    /** @return array{kind: string, value: float} */
    private static function num(float $v): array
    {
        return ['kind' => 'number', 'value' => $v];
    }

    private function isOp(string $op): bool
    {
        $tok = $this->t[$this->i] ?? null;

        return $tok !== null && $tok[0] === 'op' && $tok[1] === $op;
    }

    private function expect(string $op): void
    {
        if (!$this->isOp($op)) {
            throw new \RuntimeException('esperava ' . $op);
        }
        $this->i++;
    }
}
