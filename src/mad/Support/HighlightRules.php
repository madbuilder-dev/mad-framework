<?php

namespace Mad\Support;

use Mad\Grid\GridAction;

/**
 * HighlightRules — o que é comum aos destaques por condição.
 *
 * A grade (`<mad-row-highlights>`, {@see \Mad\Grid\MadDataGrid}) e as opções
 * dos campos de banco (`<mad-option-highlights>`,
 * {@see \Mad\Form\OptionHighlights}) usam a MESMA regra:
 *
 *     ['when' => ['field' => 'status', 'op' => 'eq', 'value' => 'atrasada'], 'tone' => 'danger']
 *     ['when' => 'Classe::metodo', 'color' => '#fde68a']
 *
 * Aqui ficam a avaliação da condição (`when-*` e `display-condition`), o
 * saneamento do que é comum a toda regra (condição, tom, cor) e a escolha da
 * primeira regra que casa. O que é de cada lugar — a célula da grade, o selo
 * da opção — fica com o dono.
 */
final class HighlightRules
{
    /** Tons do destaque — tokens --mad-* (claro e escuro). */
    public const TONES = ['danger', 'warning', 'success', 'info'];

    /**
     * Avalia uma condição declarativa (`when-*`, `disabled-*`) contra uma linha.
     * Aceita tanto objeto de model (preferido — permite navegação ->) quanto array.
     */
    public static function evalCond(array|object $row, array $cond): bool
    {
        $field = $cond['field'] ?? '';
        if ($field === '') return true;

        if (is_object($row)) {
            try { $fv = (string)($row->{$field} ?? ''); }
            catch (\Throwable $e) { $fv = ''; }
        } else {
            $fv = (string)($row[$field] ?? '');
        }
        // Coluna CHAR(n) do PostgreSQL volta completada com espaços ('PB   '):
        // sem o trim, `when-value="PB"` nunca casava (fórum #118). Os dois
        // lados, como já fazia a comparação de grandeza ({@see cmpValues()}).
        $fv  = trim($fv);
        $val = $cond['value'] ?? '';
        $one = static fn (mixed $v): string => is_scalar($v) ? trim((string)$v) : '';

        return match ($cond['op'] ?? 'eq') {
            'eq'  => $fv === $one($val),
            'neq' => $fv !== $one($val),
            'in'  => in_array($fv, array_map($one, (array)$val), true),
            'nin' => !in_array($fv, array_map($one, (array)$val), true),
            // Comparação de grandeza (destaque "estoque abaixo de 10"): número
            // com número; senão texto — data ISO (AAAA-MM-DD) compara certo.
            // Campo vazio nunca casa (vazio não é "menor que 10").
            'gt', 'gte', 'lt', 'lte' => $fv !== '' && self::cmpOp($cond['op'], self::cmpValues($fv, (string)$val)),
            default => true,
        };
    }

    /** -1/0/1 entre o valor do campo e o da regra (número se os dois forem). */
    public static function cmpValues(string $a, string $b): int
    {
        $a = trim($a);
        $b = trim($b);
        if (is_numeric($a) && is_numeric($b)) {
            return (float)$a <=> (float)$b;
        }

        return strcmp($a, $b) <=> 0;
    }

    public static function cmpOp(string $op, int $cmp): bool
    {
        return match ($op) {
            'gt'  => $cmp > 0,
            'gte' => $cmp >= 0,
            'lt'  => $cmp < 0,
            'lte' => $cmp <= 0,
            default => false,
        };
    }

    /** Cor livre aceita: #hex, nome, rgb()/hsl()/oklch() ou var(--x). Qualquer outra coisa some. */
    public static function safeColor(string $c): string
    {
        $c = trim($c);

        return preg_match('/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{3,30}|(?:rgba?|hsla?|oklch)\([0-9.,%\s\/-]+\)|var\(--[\w-]+\))$/', $c) ? $c : '';
    }

    /**
     * O que toda regra tem: condição obrigatória (string 'Classe::metodo' ou só
     * o nome do método da tela; ou array when-*), tom conhecido, cor livre só se
     * for cor de verdade (vai para um atributo style). Sem tom nem cor vale o
     * aviso (amarelo); com cor, o tom sai.
     *
     * @param  list<string> $hosts classes onde procurar o método escrito só pelo nome
     * @return array{when: string|array, tone: string, color: string}|null null = regra sem condição
     */
    public static function normalizeRule(mixed $r, array $hosts = []): ?array
    {
        if (!is_array($r)) return null;
        $when = $r['when'] ?? null;
        if (is_string($when)) {
            $when = trim($when);
            if ($when === '') return null;
            $when = GridAction::hostCallable($when, $hosts);
        } elseif (!is_array($when) || trim((string) ($when['field'] ?? '')) === '') {
            return null;
        }
        $tone = strtolower(trim((string) ($r['tone'] ?? '')));
        if (!in_array($tone, self::TONES, true)) $tone = '';
        $color = self::safeColor((string) ($r['color'] ?? ''));
        if ($tone === '' && $color === '') $tone = 'warning';

        return [
            'when'  => $when,
            'tone'  => $color !== '' ? '' : $tone,
            'color' => $color,
        ];
    }

    /**
     * A condição casa? Array = when-*; string = método chamável
     * ({@see GridAction::callRowCallback()} decide o que ele recebe). Método que
     * não é chamável ou que lança não casa: o motivo vai para o log (uma vez por
     * nome) e quem desenha segue em frente.
     *
     * @param string $who quem está avaliando, para o log ('[MadGrid] destaque de linha')
     */
    public static function matches(mixed $when, ?object $record, array $row, string $who): bool
    {
        if (is_array($when)) {
            return self::evalCond($record ?? $row, $when);
        }

        return self::callableMatches($when, $record, $row, $who);
    }

    /** Só o lado `display-condition` de {@see matches()}. */
    public static function callableMatches(mixed $when, ?object $record, array $row, string $who): bool
    {
        if (!is_string($when) || !is_callable($when)) {
            static $warned = [];
            $key = is_string($when) ? $when : get_debug_type($when);
            if (!isset($warned[$who . '|' . $key])) {
                $warned[$who . '|' . $key] = true;
                error_log($who . ': display-condition "' . $key . '" não é chamável — a regra foi ignorada. Use um método public static da tela (só o nome) ou Classe::metodo.');
            }
            return false;
        }
        try {
            return (bool) GridAction::callRowCallback($when, $record, $row);
        } catch (\Throwable $e) {
            error_log($who . ': "' . $when . '" falhou — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * A primeira regra que casa, ou null.
     *
     * @param list<array{when: string|array}> $rules
     */
    public static function first(array $rules, ?object $record, array $row, string $who): ?array
    {
        foreach ($rules as $r) {
            if (self::matches($r['when'] ?? null, $record, $row, $who)) {
                return $r;
            }
        }

        return null;
    }
}
