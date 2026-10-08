<?php
namespace Mad\Grid;

/**
 * A fórmula de uma coluna calculada (`evaluate`) escrita como expressão SQL.
 *
 * A coluna calculada é resolvida em PHP, linha a linha, depois da consulta
 * (MadDataGrid::_computeEvaluate). Duas coisas precisam da MESMA conta no
 * banco: o total do rodapé sobre o resultado inteiro (somar em PHP exigiria
 * carregar todas as linhas) e a ordenação pela coluna (ORDER BY de uma coluna
 * que só existe em PHP derrubava a listagem).
 *
 * A tradução só existe quando ela dá o mesmo número que o PHP dá:
 *
 *  - `{campo}` vira o valor numérico da coluna, com nulo = 0 — quem decide se
 *    o campo é uma coluna numérica da tabela é o chamador (`$column`); o que
 *    ele recusar (caminho de relação, texto, atributo calculado no Model)
 *    recusa a fórmula inteira;
 *  - a conta é em ponto flutuante, como no PHP: sem isto `7 / 2` daria 3 no
 *    PostgreSQL e no SQLite (divisão inteira) e `1 / 3` daria 0,3333 no MySQL
 *    (quatro casas);
 *  - divisão por zero vale 0 para a fórmula INTEIRA, como no PHP: o divisor
 *    passa por NULLIF, o nulo se propaga e o COALESCE de fora devolve 0.
 *
 * Tudo o que o PHP aceitaria com outro sentido (`**`, `--`, número colado no
 * placeholder, literal octal) não é traduzido: devolve null, e o chamador
 * fica com a conta em PHP.
 */
final class GridEvaluateSql
{
    /** @var list<array{0:string, 1:string}> [tipo, texto] — tipos: num, col, op, (, ) */
    private array $tokens = [];
    private int $pos = 0;
    /** @var callable(string): ?string */
    private $column;

    /**
     * @param callable(string): ?string $column nome do placeholder → SQL do valor numérico
     *                                          (já em ponto flutuante e com nulo = 0), ou null
     * @return string|null a expressão, pronta para SELECT/ORDER BY; null = sem tradução fiel
     */
    public static function compile(string $expr, callable $column): ?string
    {
        $self = new self();
        $self->column = $column;
        if (!$self->tokenize($expr) || $self->tokens === []) {
            return null;
        }

        $sql = $self->expression();
        if ($sql === null || $self->pos !== count($self->tokens)) {
            return null;
        }

        return 'COALESCE(' . $sql . ', 0)';
    }

    /** Os nomes entre chaves da fórmula, na ordem em que aparecem. */
    public static function placeholders(string $expr): array
    {
        preg_match_all('/\{([^}]+)\}/', $expr, $m);

        return array_values(array_unique(array_map('trim', $m[1])));
    }

    private function tokenize(string $expr): bool
    {
        $parts = preg_split('/(\{[^}]+\})/', $expr, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($parts as $part) {
            if ($part[0] === '{') {
                $this->tokens[] = ['col', trim(substr($part, 1, -1))];
                continue;
            }

            // O mesmo filtro do PHP: o que não é dígito, ponto, operador,
            // parêntese ou espaço some antes da conta.
            $text = (string) preg_replace('/[^0-9.+\-*\/() ]/', '', $part);
            // `--`, `++` e `**` são outros operadores no PHP.
            if (preg_match('/--|\+\+|\*\*/', $text)) {
                return false;
            }

            $len = strlen($text);
            for ($i = 0; $i < $len;) {
                $ch = $text[$i];
                if ($ch === ' ') {
                    // O espaço separa: `1 2` não é 12.
                    $this->tokens[] = ['sp', ' '];
                    $i++;
                    continue;
                }
                if ($ch === '(' || $ch === ')') {
                    $this->tokens[] = [$ch, $ch];
                    $i++;
                    continue;
                }
                if (strpos('+-*/', $ch) !== false) {
                    $this->tokens[] = ['op', $ch];
                    $i++;
                    continue;
                }
                if (!preg_match('/\G(?:\d+(?:\.\d+)?|\.\d+)/', $text, $m, 0, $i)) {
                    return false;   // ponto solto: no PHP é concatenação
                }
                $num = $m[0];
                $i  += strlen($num);
                // `1.5.2`, `5.` e literal com zero à esquerda (octal no PHP).
                if (($i < $len && $text[$i] === '.') || preg_match('/^0\d/', $num)) {
                    return false;
                }
                $this->tokens[] = ['num', $num[0] === '.' ? '0' . $num : $num];
            }
        }

        // Dois valores encostados (`{a}5`, `2{b}`, `{a}{b}`) viram um número
        // só no PHP; separados por espaço, são erro de sintaxe. Nenhum dos
        // dois casos tem tradução.
        $clean = [];
        $prev  = null;
        foreach ($this->tokens as $tok) {
            $isValue = $tok[0] === 'num' || $tok[0] === 'col';
            if ($tok[0] === 'sp') {
                if ($prev !== null) {
                    $prev = $prev === 'value' ? 'value-sp' : $prev;
                }
                continue;
            }
            if ($isValue && ($prev === 'value' || $prev === 'value-sp')) {
                return false;
            }
            $prev    = $isValue ? 'value' : 'other';
            $clean[] = $tok;
        }
        $this->tokens = $clean;

        return true;
    }

    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function isOp(string ...$ops): bool
    {
        $t = $this->peek();

        return $t !== null && $t[0] === 'op' && in_array($t[1], $ops, true);
    }

    /** soma := termo (('+' | '-') termo)* */
    private function expression(): ?string
    {
        $left = $this->term();
        while ($left !== null && $this->isOp('+', '-')) {
            $op    = $this->tokens[$this->pos++][1];
            $right = $this->term();
            if ($right === null) {
                return null;
            }
            $left = '(' . $left . ' ' . $op . ' ' . $right . ')';
        }

        return $left;
    }

    /** termo := fator (('*' | '/') fator)* */
    private function term(): ?string
    {
        $left = $this->factor();
        while ($left !== null && $this->isOp('*', '/')) {
            $op    = $this->tokens[$this->pos++][1];
            $right = $this->factor();
            if ($right === null) {
                return null;
            }
            $left = $op === '/'
                ? '(' . $left . ' / NULLIF(' . $right . ', 0))'
                : '(' . $left . ' * ' . $right . ')';
        }

        return $left;
    }

    /** fator := número | {campo} | '(' soma ')' | ('+' | '-') fator */
    private function factor(): ?string
    {
        $t = $this->peek();
        if ($t === null) {
            return null;
        }

        if ($t[0] === 'op' && ($t[1] === '-' || $t[1] === '+')) {
            $this->pos++;
            $inner = $this->factor();
            if ($inner === null) {
                return null;
            }

            return $t[1] === '-' ? '(-' . $inner . ')' : $inner;
        }

        if ($t[0] === 'num') {
            $this->pos++;

            // `e0`: literal em ponto flutuante nos quatro bancos — `2 / 4`
            // com literais inteiros seria divisão inteira.
            return $t[1] . 'e0';
        }

        if ($t[0] === 'col') {
            $this->pos++;
            $sql = ($this->column)($t[1]);

            return is_string($sql) && $sql !== '' ? $sql : null;
        }

        if ($t[0] === '(') {
            $this->pos++;
            $inner = $this->expression();
            $close = $this->peek();
            if ($inner === null || $close === null || $close[0] !== ')') {
                return null;
            }
            $this->pos++;

            return $inner;
        }

        return null;
    }
}
