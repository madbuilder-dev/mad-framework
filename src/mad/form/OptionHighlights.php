<?php

namespace Mad\Form;

use Illuminate\Database\Eloquent\Model;
use Mad\Component\MadRenderContext;
use Mad\Support\HighlightRules;

/**
 * OptionHighlights — destaque por condição nas opções dos campos de banco
 * (`<mad-option-highlights>`, framework#323).
 *
 * O mesmo contrato do destaque da grade (`<mad-row-highlights>`), avaliado
 * contra o REGISTRO de cada opção. Regra (prop `:option-highlights`):
 *
 *     ['when' => ['field' => 'status', 'op' => 'eq', 'value' => 'inativo'], 'tone' => 'danger', 'badge' => 'Inativo'],
 *     ['when' => 'estaInadimplente', 'tone' => 'danger', 'badge' => '{dias_atraso} dias', 'paint' => 'badge'],
 *
 * Vale a primeira que casar. O que sai daqui é a MARCA já calculada da opção
 * (`paint` + `tone` ou `color` + `badge`); a regra nunca vai ao DOM nem à
 * resposta dos serviços — nas recargas (cascata, busca) ela viaja no token
 * cifrado, com o `display-condition` já resolvido para `Classe::metodo`.
 *
 * Limite: `when-field` é coluna (ou atributo calculado) do próprio registro; o
 * avaliador da grade não percorre relação (`cliente->status`). Para olhar outra
 * tabela use `display-condition`.
 */
final class OptionHighlights
{
    /** O que a cor pinta: a linha da opção (padrão), só o selo ou só um ponto antes do texto. */
    public const PAINTS = ['row', 'badge', 'dot'];

    /**
     * Nome de cor aceito na cor por opção (`colors` do select, coluna do
     * `color-column`) além dos tons e de cor CSS: o vocabulário do `badge`.
     */
    private const COLOR_ALIASES = [
        'primary'   => 'var(--mad-primary)',
        'secondary' => 'var(--mad-text-muted)',
        'neutral'   => 'var(--mad-text-muted)',
        'muted'     => 'var(--mad-text-muted)',
        'default'   => 'var(--mad-text-muted)',
        'error'     => 'danger',
        // Cor pelo nome em português ("Aberto=azul, Concluído=verde").
        'azul'      => '#2563eb',
        'verde'     => '#16a34a',
        'vermelho'  => '#dc2626',
        'amarelo'   => '#eab308',
        'laranja'   => '#f97316',
        'roxo'      => '#9333ea',
        'rosa'      => '#db2777',
        'cinza'     => '#71717a',
        'preto'     => '#18181b',
    ];

    /** Tamanho máximo do texto do selo (o resto vira reticências). */
    private const BADGE_MAX = 40;

    private const WHO = '[OptionHighlights] destaque de opção';

    /**
     * Saneia as regras da prop: condição obrigatória, tom conhecido, cor só se
     * for cor de verdade, `paint` do par. `display-condition` escrito só com o
     * nome do método vira `Classe::metodo` da tela (`$hosts`) — é assim que ele
     * segue no token. Idempotente: regra já saneada volta igual.
     *
     * @param  list<string> $hosts
     * @return list<array{when: string|array, tone: string, color: string, badge: string, paint: string}>
     */
    public static function normalize(mixed $raw, array $hosts = []): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $r) {
            // Cor vinda de uma coluna do registro (`color-column` do combo).
            if (is_array($r) && array_key_exists('color_column', $r)) {
                $col = trim((string) $r['color_column']);
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col)) {
                    $out[] = self::colorColumnRule($col);
                }
                continue;
            }
            $rule = HighlightRules::normalizeRule($r, $hosts);
            if ($rule === null) {
                continue;
            }
            $badge = $r['badge'] ?? '';
            $badge = is_scalar($badge) && !is_bool($badge) ? trim((string) $badge) : '';
            $paint = strtolower(trim((string) ($r['paint'] ?? '')));
            $out[] = $rule + [
                'badge' => $badge,
                // Só o selo leva a cor: sem selo não há o que pintar, vale a linha.
                'paint' => self::paint($paint, $badge),
            ];
        }

        return $out;
    }

    /**
     * Regra da cor por opção lida de uma coluna do registro (`color-column`):
     * a coluna guarda o tom (`success`), o nome do selo (`primary`) ou a cor
     * (`#16a34a`). Vem depois das regras com condição — o destaque vence.
     *
     * @return array{color_column: string, when: string, tone: string, color: string, badge: string, paint: string}
     */
    public static function colorColumnRule(string $column): array
    {
        return ['color_column' => $column, 'when' => '', 'tone' => '', 'color' => '', 'badge' => '', 'paint' => 'dot'];
    }

    /** Regras do campo + a da `color-column` (quando há coluna válida). */
    public static function withColorColumn(array $rules, mixed $column): array
    {
        $column = is_scalar($column) ? trim((string) $column) : '';
        if ($column === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            return $rules;
        }

        return array_merge($rules, [self::colorColumnRule($column)]);
    }

    /**
     * A marca de uma cor por opção: ponto antes do texto, na lista e no valor
     * escolhido. Aceita tom (`success`), nome do selo (`primary`, `secondary`)
     * ou cor CSS (`#16a34a`, `green`, `var(--x)`). Vazio ou inválido: null.
     *
     * @return array{paint: string, tone?: string, color?: string}|null
     */
    public static function colorMark(mixed $value): ?array
    {
        $v = is_scalar($value) ? trim((string) $value) : '';
        if ($v === '') {
            return null;
        }
        $v = self::COLOR_ALIASES[strtolower($v)] ?? $v;
        if (in_array(strtolower($v), HighlightRules::TONES, true)) {
            return ['paint' => 'dot', 'tone' => strtolower($v)];
        }
        $color = HighlightRules::safeColor($v);

        return $color !== '' ? ['paint' => 'dot', 'color' => $color] : null;
    }

    /**
     * Marcas por chave de opção de uma lista fixa (`<mad-select-field>`):
     * prop `colors` ("aberto:info|concluido:success" ou array) e, sem ela para
     * a opção, a cor do par de selo nos itens (`['aberto' => ['Aberto', 'info']]`)
     * ou a chave `color` da lista de objetos.
     *
     * @param  array<array-key, mixed> $items  itens CRUS (antes do MadItems::normalize)
     * @return array<string, array>
     */
    public static function staticMarks(mixed $colors, array $items = []): array
    {
        $map = [];
        foreach ($items as $k => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (array_is_list($items) && isset($item['color'])) {
                $key = $item['value'] ?? $item['id'] ?? $item['key'] ?? null;
                if (is_scalar($key)) {
                    $map[(string) $key] = $item['color'];
                }
            } elseif (!array_is_list($items) && array_is_list($item) && isset($item[1]) && is_string($item[1])) {
                $map[(string) $k] = $item[1];
            }
        }
        if (is_string($colors)) {
            foreach (explode('|', $colors) as $pair) {
                $p = explode(':', $pair, 2);
                if (count($p) === 2 && trim($p[0]) !== '') {
                    $map[trim($p[0])] = trim($p[1]);
                }
            }
        } elseif (is_array($colors)) {
            foreach ($colors as $k => $c) {
                $map[(string) $k] = $c;
            }
        }

        $out = [];
        foreach ($map as $k => $c) {
            if (($mark = self::colorMark($c)) !== null) {
                $out[(string) $k] = $mark;
            }
        }

        return $out;
    }

    /** `paint` aceito: `badge` só com texto de selo; `dot`; o resto é `row`. */
    private static function paint(string $paint, string $badge): string
    {
        return match (true) {
            $paint === 'badge' && $badge !== '' => 'badge',
            $paint === 'dot' => 'dot',
            default => 'row',
        };
    }

    /**
     * Regras de um campo que está sendo desenhado: `display-condition` com só o
     * nome do método resolve na tela que hospeda o campo.
     */
    public static function forField(mixed $raw): array
    {
        return empty($raw) ? [] : self::normalize($raw, self::hosts());
    }

    /** A tela do render atual (onde mora o método do `display-condition`). */
    public static function hosts(): array
    {
        $component = MadRenderContext::getComponent();

        return $component !== null ? [get_class($component)] : [];
    }

    /**
     * A marca da opção: o que a primeira regra que casa manda desenhar.
     *
     * @return array{paint: string, tone?: string, color?: string, badge?: string}|null
     */
    public static function markFor(array $rules, object $record): ?array
    {
        if ($rules === []) {
            return null;
        }
        $row   = $record instanceof Model ? $record->getAttributes() : get_object_vars($record);
        $conds = array_values(array_filter($rules, static fn ($r) => !isset($r['color_column'])));
        $rule  = $conds !== [] ? HighlightRules::first($conds, $record, $row, self::WHO) : null;
        if ($rule === null) {
            // Sem destaque que case: a cor da coluna (`color-column`), se houver.
            foreach ($rules as $r) {
                if (isset($r['color_column'])) {
                    try {
                        $v = $record->{$r['color_column']} ?? ($row[$r['color_column']] ?? null);
                    } catch (\Throwable $e) {
                        $v = $row[$r['color_column']] ?? null;
                    }
                    if (($mark = self::colorMark($v)) !== null) {
                        return $mark;
                    }
                }
            }

            return null;
        }

        $badge = self::badgeText((string) ($rule['badge'] ?? ''), $record);
        $mark  = ['paint' => self::paint((string) ($rule['paint'] ?? 'row'), $badge)];
        if (($rule['color'] ?? '') !== '') {
            $mark['color'] = $rule['color'];
        } else {
            $mark['tone'] = $rule['tone'] ?: 'warning';
        }
        if ($badge !== '') {
            $mark['badge'] = $badge;
        }

        return $mark;
    }

    /**
     * Marcas das opções de uma consulta: devolve o coletor para o
     * {@see ModelOptionsLoader::itemsFromQuery()} (7º parâmetro), que enche
     * `$marks` (chave da opção => marca) com as opções que casaram. Sem regras,
     * null — a consulta roda exatamente como sempre rodou.
     *
     * @param array<int|string, array> $marks (saída)
     */
    public static function collector(array $rules, array &$marks): ?\Closure
    {
        if ($rules === []) {
            return null;
        }

        return static function (mixed $key, object $record) use ($rules, &$marks): void {
            $mark = self::markFor($rules, $record);
            if ($mark !== null && is_scalar($key)) {
                $marks[(string) $key] = $mark;
            }
        };
    }

    /**
     * Linha crua (stdClass de uma consulta `toBase()`/derived table) → registro
     * do model, sem consulta nova: é o que a regra recebe (atributo calculado,
     * relação). Sem model que resolva, a própria linha.
     *
     * @return \Closure(object): object
     */
    public static function hydrator(string $model, string $database = ''): \Closure
    {
        $proto = null;
        if ($model !== '') {
            try {
                $class = ModelOptionsLoader::resolveModelClass($model);
                $proto = $database !== '' ? $class::on($database)->getModel() : new $class();
            } catch (\Throwable $e) {
                $proto = null;
            }
        }

        return static function (object $row) use ($proto): object {
            if ($proto === null || $row instanceof Model) {
                return $row;
            }

            return $proto->newFromBuilder(get_object_vars($row));
        };
    }

    /**
     * Atributos `data-mad-hl-*` da `<option>` (com o espaço na frente), já
     * escapados. Sem marca, string vazia: a option sai como sempre saiu.
     */
    public static function attrs(?array $mark): string
    {
        if ($mark === null) {
            return '';
        }
        $e   = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $out = ' data-mad-hl="' . $e((string) ($mark['paint'] ?? 'row')) . '"';
        foreach (['tone', 'color', 'badge'] as $k) {
            if (($mark[$k] ?? '') !== '') {
                $out .= ' data-mad-hl-' . $k . '="' . $e((string) $mark[$k]) . '"';
            }
        }

        return $out;
    }

    /** Texto do selo: literal, ou máscara `{coluna}` lida do registro. É TEXTO: quem desenha escapa. */
    private static function badgeText(string $badge, object $record): string
    {
        if ($badge === '') {
            return '';
        }
        if (str_contains($badge, '{')) {
            try {
                $badge = ModelOptionsLoader::mask($record, $badge);
            } catch (\Throwable $e) {
                error_log(self::WHO . ': selo "' . $badge . '" falhou — ' . $e->getMessage());

                return '';
            }
        }
        $badge = trim(preg_replace('/\s+/u', ' ', $badge) ?? '');
        if (mb_strlen($badge) > self::BADGE_MAX) {
            $badge = rtrim(mb_substr($badge, 0, self::BADGE_MAX - 1)) . '…';
        }

        return $badge;
    }
}
