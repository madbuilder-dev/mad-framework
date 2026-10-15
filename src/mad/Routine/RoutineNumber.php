<?php

namespace Mad\Routine;

/**
 * Aritmética decimal das rotinas sobre TEXTO: `33.33 + 33.33 + 33.34` tem de dar
 * `100.00`, não `99.99999999999999`. Usa bcmath quando a extensão existe;
 * sem ela, inteiros na escala do maior número de casas.
 *
 * @internal
 */
final class RoutineNumber
{
    private const MAX_SCALE = 10;

    /** Texto numérico normalizado ('1e3' → '1000', ' 2,5' não é número → '0'). */
    public static function norm(mixed $v): string
    {
        if ($v === null || $v === '' || is_bool($v)) {
            return $v === true ? '1' : '0';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            $s = rtrim(rtrim(sprintf('%.' . self::MAX_SCALE . 'F', $v), '0'), '.');

            return $s === '-0' || $s === '' ? '0' : $s;
        }
        $s = trim((string) $v);
        if (preg_match('/^[+-]?(\d+)(\.\d+)?$/', $s)) {
            return ltrim($s, '+');
        }
        if (preg_match('/^[+-]?\.\d+$/', $s)) {
            return str_replace('.', '0.', ltrim($s, '+'));
        }
        if (is_numeric($s)) {
            return self::norm((float) $s);
        }

        return '0';
    }

    public static function scale(string $n): int
    {
        $p = strpos($n, '.');

        return $p === false ? 0 : min(self::MAX_SCALE, strlen($n) - $p - 1);
    }

    public static function add(mixed $a, mixed $b): string
    {
        $a = self::norm($a);
        $b = self::norm($b);
        $scale = max(self::scale($a), self::scale($b));
        if (function_exists('bcadd')) {
            return self::trim(bcadd($a, $b, $scale), $scale);
        }

        return self::fromInt(self::toInt($a, $scale) + self::toInt($b, $scale), $scale);
    }

    public static function sub(mixed $a, mixed $b): string
    {
        return self::add($a, self::neg(self::norm($b)));
    }

    public static function neg(mixed $a): string
    {
        $a = self::norm($a);
        if (self::cmp($a, '0') === 0) {
            return '0';
        }

        return str_starts_with($a, '-') ? substr($a, 1) : '-' . $a;
    }

    public static function cmp(mixed $a, mixed $b): int
    {
        $a = self::norm($a);
        $b = self::norm($b);
        $scale = max(self::scale($a), self::scale($b));
        if (function_exists('bccomp')) {
            return bccomp($a, $b, $scale);
        }

        return self::toInt($a, $scale) <=> self::toInt($b, $scale);
    }

    /**
     * Parte `$index` (1..$parts) do rateio de `$total` com `$decimals` casas:
     * cada parte é o quociente truncado, e a ÚLTIMA leva a sobra.
     * 100 em 3 → 33.33, 33.33, 33.34.
     */
    public static function split(mixed $total, int $parts, int $index, int $decimals): string
    {
        $decimals = max(0, min(self::MAX_SCALE, $decimals));
        $cents    = self::toInt(self::norm($total), $decimals);
        $base     = intdiv($cents, $parts);
        $value    = $index === $parts ? $cents - $base * ($parts - 1) : $base;

        return self::fromInt($value, $decimals);
    }

    /** Arredonda para inteiro na escala (meio para longe do zero). */
    private static function toInt(string $n, int $scale): int
    {
        $neg = str_starts_with($n, '-');
        $n   = ltrim($n, '-');
        [$int, $frac] = array_pad(explode('.', $n, 2), 2, '');
        $digits = $frac . str_repeat('0', max(0, $scale - strlen($frac)));
        $kept   = substr($digits, 0, $scale);
        $next   = (int) ($digits[$scale] ?? '0');
        $value  = (int) ($int . $kept);
        if ($next >= 5) {
            $value++;
        }

        return $neg ? -$value : $value;
    }

    private static function fromInt(int $value, int $scale): string
    {
        $neg = $value < 0;
        $abs = (string) abs($value);
        if ($scale > 0) {
            $abs = str_pad($abs, $scale + 1, '0', STR_PAD_LEFT);
            $abs = substr($abs, 0, -$scale) . '.' . substr($abs, -$scale);
        }

        return ($neg && trim($abs, '0.') !== '' ? '-' : '') . $abs;
    }

    private static function trim(string $n, int $scale): string
    {
        // bcadd devolve "-0.00" em alguns casos
        return self::cmp($n === '' ? '0' : $n, '0') === 0 && $scale > 0 ? '0.' . str_repeat('0', $scale)
            : ($n === '-0' ? '0' : $n);
    }
}
