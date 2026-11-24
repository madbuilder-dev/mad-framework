<?php

namespace Mad\Form;

/**
 * Condição `*-when` do campo (`visible-when="{tipo_pessoa} == 'F'"`) avaliada
 * no SERVIDOR, com os valores que o formulário enviou.
 *
 * No navegador quem avalia é o `_madWhen` do mad-ui.js: `{campo}` vira o valor
 * atual do campo (sempre texto) e o resto é JavaScript. Aqui vale só o pedaço
 * que o construtor de condições do Studio escreve e o que se digita à mão no
 * mesmo estilo: `{campo}`, texto entre aspas, número, `true`/`false`/`null`,
 * `==` `!=` `===` `!==` `<` `>` `<=` `>=`, `&&` `||` `!` e parênteses, com a
 * comparação frouxa do JavaScript (`'2' == 2`).
 *
 * Qualquer outra coisa (função, `.length`, campo que o formulário não mandou,
 * valor que não é texto) dá `null`: "não sei". Quem pergunta trata o "não sei"
 * como campo À VISTA — a validação continua valendo, como antes. Nunca executa
 * código: é um analisador próprio, sem eval.
 *
 * Fórum #402: "Validar documento" recusava o CPF escondido pela própria
 * condição (pessoa jurídica) — ver MadForm::_declaredDocumentErrors().
 */
final class WhenCondition
{
    /** Expressão do `x-mad-when:<kind>` que o compilador pôs no `attrs` do campo ('' sem ela). */
    public static function fromAttrs(string $attrs, string $kind = 'visible'): string
    {
        if ($attrs === '' || !preg_match('/\bx-mad-when:' . preg_quote($kind, '/') . '="([^"]*)"/', $attrs, $m)) {
            return '';
        }

        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * true/false pela condição; null quando não dá para saber.
     *
     * @param array<string, mixed> $values valores enviados pelo formulário
     */
    public static function evaluate(string $expr, array $values): ?bool
    {
        $expr = trim($expr);
        if ($expr === '' || strlen($expr) > 2000) {
            return null;
        }
        $tokens = self::tokens($expr);
        if ($tokens === null) {
            return null;
        }
        $p = new class($tokens, $values) {
            private int $i = 0;

            /** @param list<array{0:string,1:mixed}> $t @param array<string,mixed> $v */
            public function __construct(private array $t, private array $v) {}

            public function run(): mixed
            {
                $r = $this->or();
                if ($this->i !== count($this->t)) {
                    throw new \UnexpectedValueException('sobra');
                }

                return $r;
            }

            private function peek(): ?string
            {
                return $this->t[$this->i][0] ?? null;
            }

            private function or(): mixed
            {
                $l = $this->and();
                while ($this->peek() === '||') {
                    $this->i++;
                    $r = $this->and();
                    $l = WhenCondition::truthy($l) ? $l : $r;
                }

                return $l;
            }

            private function and(): mixed
            {
                $l = $this->eq();
                while ($this->peek() === '&&') {
                    $this->i++;
                    $r = $this->eq();
                    $l = WhenCondition::truthy($l) ? $r : $l;
                }

                return $l;
            }

            private function eq(): mixed
            {
                $l = $this->rel();
                while (in_array($op = $this->peek(), ['==', '!=', '===', '!=='], true)) {
                    $this->i++;
                    $r = $this->rel();
                    $l = match ($op) {
                        '=='  => WhenCondition::looseEq($l, $r),
                        '!='  => !WhenCondition::looseEq($l, $r),
                        '===' => WhenCondition::strictEq($l, $r),
                        '!==' => !WhenCondition::strictEq($l, $r),
                    };
                }

                return $l;
            }

            private function rel(): mixed
            {
                $l = $this->unary();
                while (in_array($op = $this->peek(), ['<', '>', '<=', '>='], true)) {
                    $this->i++;
                    $l = WhenCondition::compare($l, $this->unary(), $op);
                }

                return $l;
            }

            private function unary(): mixed
            {
                if ($this->peek() === '!') {
                    $this->i++;

                    return !WhenCondition::truthy($this->unary());
                }
                if ($this->peek() === '-') {
                    $this->i++;
                    $n = WhenCondition::num($this->unary());

                    return is_nan($n) ? NAN : -$n;
                }

                return $this->primary();
            }

            private function primary(): mixed
            {
                $tok = $this->t[$this->i] ?? null;
                if ($tok === null) {
                    throw new \UnexpectedValueException('fim');
                }
                $this->i++;
                switch ($tok[0]) {
                    case '(':
                        $r = $this->or();
                        if ($this->peek() !== ')') {
                            throw new \UnexpectedValueException('parêntese');
                        }
                        $this->i++;

                        return $r;
                    case 'field':
                        if (!array_key_exists($tok[1], $this->v)) {
                            throw new \UnexpectedValueException('campo fora do envio');
                        }
                        $val = $this->v[$tok[1]];
                        if ($val === null) {
                            return '';
                        }
                        if (is_bool($val)) {
                            return $val ? '1' : '0';
                        }
                        if (is_int($val) || is_float($val) || is_string($val)) {
                            return (string) $val;
                        }
                        throw new \UnexpectedValueException('valor que não é texto');
                    case 'str':
                    case 'num':
                        return $tok[1];
                    case 'lit':
                        return match ($tok[1]) { 'true' => true, 'false' => false, default => null };
                }
                throw new \UnexpectedValueException('token');
            }
        };

        try {
            return self::truthy($p->run());
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /** @return list<array{0:string,1:mixed}>|null */
    private static function tokens(string $s): ?array
    {
        $out = [];
        $n = strlen($s);
        for ($i = 0; $i < $n;) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '{') {
                if (!preg_match('/\G\{([A-Za-z_]\w*)\}/', $s, $m, 0, $i)) {
                    return null;
                }
                $out[] = ['field', $m[1]];
                $i += strlen($m[0]);
                continue;
            }
            if ($c === "'" || $c === '"') {
                $buf = '';
                for ($j = $i + 1; $j < $n && $s[$j] !== $c; $j++) {
                    if ($s[$j] === '\\') {
                        $j++;
                        if ($j >= $n) {
                            return null;
                        }
                        $buf .= match ($s[$j]) { 'n' => "\n", 't' => "\t", default => $s[$j] };
                        continue;
                    }
                    $buf .= $s[$j];
                }
                if ($j >= $n) {
                    return null;
                }
                $out[] = ['str', $buf];
                $i = $j + 1;
                continue;
            }
            if (preg_match('/\G(?:\d+(?:\.\d*)?|\.\d+)/', $s, $m, 0, $i)) {
                $out[] = ['num', (float) $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G(?:true|false|null)\b/', $s, $m, 0, $i)) {
                $out[] = ['lit', $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G(?:===|!==|==|!=|<=|>=|&&|\|\||[<>!()\-])/', $s, $m, 0, $i)) {
                $out[] = [$m[0], null];
                $i += strlen($m[0]);
                continue;
            }

            return null;
        }

        return $out;
    }

    /** Verdadeiro à moda do JavaScript. */
    public static function truthy(mixed $v): bool
    {
        if (is_float($v)) {
            return !is_nan($v) && $v != 0.0;
        }

        return !($v === null || $v === false || $v === '');
    }

    /** Número à moda do `Number()` do JavaScript (texto vazio = 0; texto que não é número = NaN). */
    public static function num(mixed $v): float
    {
        if (is_float($v)) {
            return $v;
        }
        if ($v === true) {
            return 1.0;
        }
        if ($v === false || $v === null) {
            return 0.0;
        }
        $t = trim((string) $v);
        if ($t === '') {
            return 0.0;
        }

        return is_numeric($t) ? (float) $t : NAN;
    }

    public static function strictEq(mixed $a, mixed $b): bool
    {
        if (is_float($a) && is_float($b)) {
            return $a == $b;
        }

        return gettype($a) === gettype($b) && $a === $b;
    }

    /** `==` do JavaScript para texto, número, booleano e null. */
    public static function looseEq(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (gettype($a) === gettype($b)) {
            return self::strictEq($a, $b);
        }
        $x = self::num($a);
        $y = self::num($b);

        return !is_nan($x) && !is_nan($y) && $x == $y;
    }

    public static function compare(mixed $a, mixed $b, string $op): bool
    {
        if (is_string($a) && is_string($b)) {
            $c = strcmp($a, $b);
        } else {
            $x = self::num($a);
            $y = self::num($b);
            if (is_nan($x) || is_nan($y)) {
                return false;
            }
            $c = $x <=> $y;
        }

        return match ($op) { '<' => $c < 0, '>' => $c > 0, '<=' => $c <= 0, default => $c >= 0 };
    }
}
