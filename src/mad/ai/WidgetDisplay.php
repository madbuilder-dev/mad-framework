<?php

namespace Mad\Ai;

/**
 * WidgetDisplay — overrides de exibição de um widget salvo (micro-BI).
 *
 * O usuário pode ajustar COMO o widget aparece sem tocar na SQL/map:
 *   - type   troca a visualização entre famílias de forma compatível
 *            (bar ↔ donut ↔ funnel ↔ progress; line ↔ area → bar/donut c/ 1 série)
 *   - format máscara de valor (moeda BRL, número, percentual, data/data-hora)
 *            aplicada aos campos textuais do bloco (kpis, células de tabela,
 *            list.right, detail.value, donut.centerValue)
 *
 * O override mora em spec_json.display (PATCH /embed/v1/widgets/{id}) e é
 * aplicado no render DEPOIS do WidgetBlockBuilder — determinístico, 0 IA.
 * Fail-safe: conversão impossível (ex.: multi-série → bar) mantém o bloco
 * original; formato só toca valores que casam com o tipo (numérico/ISO-date).
 */
final class WidgetDisplay
{
    /** Família label+valor — conversível entre si. */
    private const LABEL_VALUE = ['bar', 'donut', 'funnel', 'progress'];

    /** Família de séries no tempo. */
    private const SERIES = ['line', 'area'];

    private const FORMAT_KINDS = ['currency', 'number', 'percent', 'date', 'datetime'];

    /** Data/data-hora ISO (o que as SQLs devolvem) — evita strtotime() em texto livre. */
    private const ISO_DATE_RE = '/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/';

    /**
     * Tipos de visualização permitidos como override pra um type base.
     *
     * @return list<string>
     */
    public static function allowedTypes(string $baseType): array
    {
        if (in_array($baseType, self::LABEL_VALUE, true)) {
            return self::LABEL_VALUE;
        }
        if (in_array($baseType, self::SERIES, true)) {
            return ['line', 'area', 'bar', 'donut'];
        }

        return [];
    }

    /**
     * Valida/normaliza o display recebido no PATCH. Chaves fora do contrato
     * caem; display efetivamente vazio vira null (remove o override).
     *
     * @param  mixed $raw
     * @return array{type?: string, format?: array{kind: string, decimals?: int}}|null
     */
    public static function sanitize(mixed $raw, string $baseType): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $out = [];

        $type = $raw['type'] ?? null;
        if (is_string($type) && $type !== '' && $type !== $baseType
            && in_array($type, self::allowedTypes($baseType), true)) {
            $out['type'] = $type;
        }

        $format = $raw['format'] ?? null;
        if (is_array($format)) {
            $kind = $format['kind'] ?? null;
            if (is_string($kind) && in_array($kind, self::FORMAT_KINDS, true)) {
                $f = ['kind' => $kind];
                if (isset($format['decimals']) && is_numeric($format['decimals'])) {
                    $f['decimals'] = max(0, min(4, (int) $format['decimals']));
                }
                $out['format'] = $f;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * Aplica o override ao bloco já construído pelo WidgetBlockBuilder.
     *
     * @param  array<string, mixed>      $block
     * @param  array<string, mixed>|null $display
     * @return array<string, mixed>
     */
    public static function apply(array $block, ?array $display): array
    {
        if ($display === null || $display === []) {
            return $block;
        }

        try {
            $target = (string) ($display['type'] ?? '');
            if ($target !== '') {
                $block = self::convertType($block, $target);
            }

            $format = $display['format'] ?? null;
            if (is_array($format)) {
                $block = self::formatValues($block, $format);
            }
        } catch (\Throwable $e) {
            error_log('[widget-display] apply: ' . $e->getMessage());
        }

        return $block;
    }

    /**
     * @param  array<string, mixed> $block
     * @return array<string, mixed>
     */
    private static function convertType(array $block, string $target): array
    {
        $from = (string) ($block['type'] ?? '');
        if ($from === $target || ! in_array($target, self::allowedTypes($from), true)) {
            return $block;
        }

        // line ↔ area: mesmo shape (xLabels + series), só troca o type
        if (in_array($from, self::SERIES, true) && in_array($target, self::SERIES, true)) {
            $input = ['xLabels' => $block['xLabels'] ?? [], 'series' => $block['series'] ?? []];
            self::carry($input, $block, ['title', 'unit']);

            return BlockValidator::build($target, $input) ?? $block;
        }

        $pairs = self::labelValuePairs($block);
        if ($pairs === null) {
            return $block; // ex.: multi-série → bar não tem conversão fiel
        }

        $input = self::inputFromPairs($target, $pairs);
        self::carry($input, $block, ['title', 'unit']);

        return BlockValidator::build($target, $input) ?? $block;
    }

    /**
     * Extrai a lista label+valor do bloco de origem.
     *
     * @param  array<string, mixed> $block
     * @return list<array{label: string, value: float|int}>|null
     */
    private static function labelValuePairs(array $block): ?array
    {
        $rows = match ((string) ($block['type'] ?? '')) {
            'bar', 'donut' => $block['data'] ?? [],
            'funnel'       => $block['steps'] ?? [],
            'progress'     => $block['items'] ?? [],
            'line', 'area' => self::seriesPairs($block),
            default        => null,
        };
        if (! is_array($rows)) {
            return null;
        }

        $out = [];
        foreach ($rows as $r) {
            if (is_array($r) && isset($r['label'], $r['value']) && is_numeric($r['value'])) {
                $out[] = ['label' => (string) $r['label'], 'value' => $r['value'] + 0];
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * Série única → pares (xLabel, point). Multi-série não converte.
     *
     * @param  array<string, mixed> $block
     * @return list<array{label: string, value: float|int}>|null
     */
    private static function seriesPairs(array $block): ?array
    {
        $series = $block['series'] ?? [];
        $labels = $block['xLabels'] ?? [];
        if (! is_array($series) || count($series) !== 1 || ! is_array($labels)) {
            return null;
        }
        $points = $series[0]['points'] ?? [];
        if (! is_array($points)) {
            return null;
        }

        $out = [];
        foreach (array_slice($labels, 0, count($points)) as $i => $label) {
            $out[] = ['label' => (string) $label, 'value' => $points[$i] + 0];
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  list<array{label: string, value: float|int}> $pairs
     * @return array<string, mixed>
     */
    private static function inputFromPairs(string $target, array $pairs): array
    {
        return match ($target) {
            'funnel'   => ['steps' => $pairs],
            'progress' => ['items' => array_map(
                // barras proporcionais ao maior valor (progress exige um teto)
                fn (array $p) => $p + ['max' => max(array_column($pairs, 'value'))],
                $pairs,
            )],
            default    => ['data' => $pairs], // bar, donut
        };
    }

    /**
     * Copia chaves opcionais (title/unit) do bloco de origem pro input novo.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $block
     * @param list<string>         $keys
     */
    private static function carry(array &$input, array $block, array $keys): void
    {
        foreach ($keys as $k) {
            if (isset($block[$k]) && is_string($block[$k])) {
                $input[$k] = $block[$k];
            }
        }
    }

    /**
     * Máscara de valor nos campos textuais do bloco. Números pros desenhos
     * (barras, séries, gauge) ficam intactos — só o que é EXIBIDO como texto.
     *
     * @param  array<string, mixed> $block
     * @param  array<string, mixed> $format
     * @return array<string, mixed>
     */
    private static function formatValues(array $block, array $format): array
    {
        $fmt = fn (mixed $v): mixed => self::formatScalar($v, $format);

        switch ((string) ($block['type'] ?? '')) {
            case 'kpis':
                foreach ($block['items'] as &$it) {
                    $it['value'] = (string) $fmt($it['value']);
                }
                break;

            case 'donut':
                if (isset($block['centerValue'])) {
                    $block['centerValue'] = (string) $fmt($block['centerValue']);
                }
                break;

            case 'table':
                foreach ($block['rows'] as &$row) {
                    foreach ($row as $k => $v) {
                        if (is_scalar($v)) {
                            $row[$k] = $fmt($v);
                        }
                    }
                }
                break;

            case 'list':
                foreach ($block['items'] as &$it) {
                    if (isset($it['right'])) {
                        $it['right'] = (string) $fmt($it['right']);
                    }
                }
                break;

            case 'detail':
                foreach ($block['items'] as &$it) {
                    $it['value'] = (string) $fmt($it['value']);
                }
                break;
        }

        return $block;
    }

    /**
     * Formata UM valor se ele casa com o kind (numérico / ISO-date); senão
     * devolve intacto. Locale fixo pt-BR (1.234,56 · R$ · d/m/Y).
     */
    private static function formatScalar(mixed $v, array $format): mixed
    {
        $kind     = (string) ($format['kind'] ?? '');
        $decimals = isset($format['decimals']) && is_numeric($format['decimals']) ? (int) $format['decimals'] : null;
        // override "Número" da tela de dashboards: 2 casas por padrão (contrato antigo)
        if ($kind === 'number' && $decimals === null) {
            $decimals = 2;
        }

        return self::formatValue($v, $kind, $decimals);
    }

    /** Formatos que o map de um widget (e o show_kpis) pode declarar. */
    public const VALUE_FORMATS = ['currency', 'number', 'int', 'percent', 'date', 'datetime'];

    /**
     * Formatador ÚNICO de valor exibido pelo Copilot/widgets (pt-BR): o modelo
     * devolve o número cru e diz o formato; quem formata é o runtime — nunca a
     * SQL (o SQLite formata em inglês: "R$ 0.02 mi" no lugar de R$ 20.884).
     * Valor que não casa com o kind (texto, data não-ISO) volta intacto.
     *
     *   currency → "R$ 20.884,00"   number → "1.234,50" (inteiro: "1.234")
     *   int      → "1.235"          percent → "7,6%"
     *   date     → "03/09/2026"     datetime → "03/09/2026 10:00"
     */
    public static function formatValue(mixed $v, string $kind, ?int $decimals = null): mixed
    {
        if ($decimals !== null) {
            $decimals = max(0, min(4, $decimals));
        }

        if ($kind === 'date' || $kind === 'datetime') {
            if (is_string($v) && preg_match(self::ISO_DATE_RE, trim($v))) {
                $ts = strtotime(trim($v));
                if ($ts !== false) {
                    return date($kind === 'date' ? 'd/m/Y' : 'd/m/Y H:i', $ts);
                }
            }

            return $v;
        }

        if (is_bool($v) || ! is_numeric(is_string($v) ? trim($v) : $v)) {
            return $v;
        }
        $n = (is_string($v) ? trim($v) : $v) + 0;

        return match ($kind) {
            'currency' => 'R$ ' . number_format($n, $decimals ?? 2, ',', '.'),
            'number'   => number_format($n, $decimals ?? (fmod((float) $n, 1.0) !== 0.0 ? 2 : 0), ',', '.'),
            'int'      => number_format($n, 0, ',', '.'),
            'percent'  => number_format($n, $decimals ?? 0, ',', '.') . '%',
            default    => $v,
        };
    }
}
