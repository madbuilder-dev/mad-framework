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
    /** O que a cor pinta: a linha da opção (padrão) ou só o selo. */
    public const PAINTS = ['row', 'badge'];

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
                'paint' => $paint === 'badge' && $badge !== '' ? 'badge' : 'row',
            ];
        }

        return $out;
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
        $row  = $record instanceof Model ? $record->getAttributes() : get_object_vars($record);
        $rule = HighlightRules::first($rules, $record, $row, self::WHO);
        if ($rule === null) {
            return null;
        }

        $badge = self::badgeText((string) ($rule['badge'] ?? ''), $record);
        $mark  = ['paint' => ($rule['paint'] ?? 'row') === 'badge' && $badge !== '' ? 'badge' : 'row'];
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
