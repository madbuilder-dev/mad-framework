<?php

namespace Mad\Ai;

/**
 * WidgetBlockBuilder — materializa um WidgetSpec num Block do contrato do front.
 *
 * spec = { type, sql, map, style } (persistido em mad_ai_widget.spec_json).
 * Fluxo: WidgetSqlGuard::run(sql) → rows → mapeia (map/style) para o INPUT da
 * render tool correspondente → BlockValidator::build(type, input) (o MESMO
 * validador das render tools — o bloco que sai daqui obedece byte-a-byte o
 * contrato que o BlockRenderer do iframe espera). Zero IA.
 *
 * Mapeamentos por tipo (map):
 *   bar|donut|funnel  labelCol, valueCol, colorCol?          style: title, unit,
 *                     (cores: style.colors[] cicla por item)         horizontal (bar),
 *                                                                    centerValue/centerLabel (donut)
 *   line|area         xCol, series[{name, col, color?, dashed?, area?}]   style: title, unit
 *   kpis              items[{label, col, subCol?|sub?, deltaCol?, dir?, tone?}] (1ª row)
 *   gauge             valueCol (1ª row)                      style: max, min, unit, label, zones[]
 *   table             columns[{key, label, align?, mono?, strong?, format?}]  style: title, note
 *   list              titleCol, subCol?, rightCol?, rightFormat?   style: title
 *   progress          labelCol, valueCol, maxCol?            style: title, max?
 *   timeline          titleCol, timeCol?, subCol?            style: title
 *
 * Formatação de número/moeda/data é do RUNTIME, não da SQL: a SQL devolve o
 * valor cru e o map declara `format` (kpis.items[], table.columns[],
 * list.rightFormat) — currency|number|int|percent|date|datetime, em pt-BR
 * (WidgetDisplay::formatValue). SQL formatando número saía em inglês
 * ("R$ 0.02 mi" no lugar de R$ 20.884).
 *
 * Marcadores de data do sistema (:hoje, :inicio_mes…, ver dateParams()) são
 * preenchidos a cada execução no fuso do app — "este mês" continua certo no
 * widget salvo nos dias seguintes.
 */
final class WidgetBlockBuilder
{
    public const TYPES = ['kpis', 'bar', 'line', 'area', 'donut', 'funnel', 'gauge', 'table', 'list', 'progress', 'timeline'];

    /** Marcadores de data que o SISTEMA preenche na SQL (nunca viram filtro). */
    public const DATE_PARAMS = ['hoje', 'agora', 'amanha', 'inicio_mes', 'inicio_proximo_mes', 'inicio_mes_anterior', 'inicio_ano', 'inicio_proximo_ano'];

    /**
     * Valores dos marcadores de data, no fuso do APP (mad.general.timezone —
     * o `app.timezone` do esqueleto é UTC, e o `date('now')` do SQLite também:
     * depois das 21h "hoje" virava amanhã). Datas 'Y-m-d' e agora 'Y-m-d H:i:s':
     * comparam certo com colunas date/datetime em sqlite/mysql/pgsql. Use em
     * intervalo semiaberto: col >= :inicio_mes AND col < :inicio_proximo_mes.
     *
     * @return array<string, string>
     */
    public static function dateParams(?\DateTimeInterface $now = null): array
    {
        $tz  = (string) (config('mad.general.timezone') ?: config('app.timezone') ?: 'UTC');
        $now = $now !== null
            ? \Illuminate\Support\Carbon::instance($now)->setTimezone($tz)
            : \Illuminate\Support\Carbon::now($tz);
        $month = $now->copy()->startOfMonth();

        return [
            'hoje'                => $now->format('Y-m-d'),
            'agora'               => $now->format('Y-m-d H:i:s'),
            'amanha'              => $now->copy()->addDay()->format('Y-m-d'),
            'inicio_mes'          => $month->format('Y-m-d'),
            'inicio_proximo_mes'  => $month->copy()->addMonthNoOverflow()->format('Y-m-d'),
            'inicio_mes_anterior' => $month->copy()->subMonthNoOverflow()->format('Y-m-d'),
            'inicio_ano'          => $now->copy()->startOfYear()->format('Y-m-d'),
            'inicio_proximo_ano'  => $now->copy()->startOfYear()->addYear()->format('Y-m-d'),
        ];
    }

    /**
     * Defs de filtro do widget (spec.params), sanitizadas.
     *
     * @param  array<string, mixed> $spec
     * @return list<array{name: string, label: string, kind: string, options: list<string>, default: string}>
     */
    /**
     * SUGESTÕES de filtro derivadas do próprio spec — o usuário clica em vez
     * de digitar: fonte TOOL → cada arg vira candidato (default = valor
     * atual); fonte SQL → cada :placeholder. Heurísticas de UX: mes → select
     * 1-12, uf → select das 27 UFs, labels bonitos. Params já declarados
     * ficam de fora.
     *
     * @param  array<string, mixed> $spec
     * @return list<array{name: string, label: string, kind: string, options: list<string>, default: string}>
     */
    /** Nomes de filtro CONSUMIDOS pela fonte (placeholders da SQL / args da tool). */
    private static function consumedNames(array $spec): array
    {
        $out = [];
        $source = is_array($spec['source'] ?? null) ? $spec['source'] : null;
        if ($source !== null && is_array($source['args'] ?? null)) {
            $out = array_merge($out, array_map('strval', array_keys($source['args'])));
        }
        $sql = (string) ($spec['sql'] ?? '');
        if ($sql !== '' && preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $m)) {
            $out = array_merge($out, $m[1]);
        }

        return array_values(array_unique($out));
    }

    /** Rótulo de UX pra um nome técnico de filtro/coluna. */
    private static function prettyLabel(string $name): string
    {
        $labels = [
            'mes' => 'Mês', 'ano' => 'Ano', 'uf' => 'UF', 'regiao' => 'Região',
            'cidade' => 'Cidade', 'unidade' => 'Unidade', 'por' => 'Dimensão',
            'dimensao' => 'Dimensão', 'dimensao2' => 'Dimensão 2', 'metrica' => 'Métrica',
            'fato' => 'Fato', 'top' => 'Top N', 'limite' => 'Limite', 'meses' => 'Meses',
            'filtros' => 'Filtros', 'busca' => 'Busca', 'status' => 'Status', 'termo' => 'Termo',
            'forma_pagamento' => 'Forma de pagamento', 'pilar' => 'Pilar', 'grupo' => 'Grupo',
            'canal' => 'Canal', 'origem' => 'Origem', 'evento' => 'Evento',
            'tipo_cliente' => 'Tipo de cliente', 'competencia' => 'Competência',
        ];

        return $labels[$name] ?? ucfirst(str_replace('_', ' ', $name));
    }

    /**
     * Sugestões extraídas dos DADOS renderizados: coluna textual com 2-30
     * valores distintos vira candidato SELECT com as opções REAIS (as fatias
     * do donut, as categorias do bar…) — aplicadas pelo SLICER de rows.
     *
     * @param  list<array<string, mixed>> $rows
     * @return list<array{name: string, label: string, kind: string, options: list<string>, default: string}>
     */
    public static function suggestedParamsFromRows(array $rows): array
    {
        $first = $rows[0] ?? null;
        if (! is_array($first)) {
            return [];
        }
        $out = [];
        foreach (array_keys($first) as $col) {
            $col = (string) $col;
            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                continue;
            }
            $vals = [];
            foreach ($rows as $r) {
                $v = $r[$col] ?? null;
                if (! is_string($v)) {
                    continue 2; // coluna não-textual (métricas) não vira filtro
                }
                $v = trim($v);
                if ($v !== '') {
                    $vals[$v] = true;
                }
            }
            $n = count($vals);
            if ($n < 2 || $n > 30) {
                continue;
            }
            $options = array_keys($vals);
            sort($options, SORT_NATURAL | SORT_FLAG_CASE);
            $out[] = [
                'name'    => $col,
                'label'   => self::prettyLabel($col),
                'kind'    => 'select',
                'options' => $options,
                'default' => '',
            ];
        }

        return $out;
    }

    public static function suggestedParams(array $spec): array
    {
        $declared = array_column(self::params($spec), 'name');
        $cands    = [];

        $source = is_array($spec['source'] ?? null) ? $spec['source'] : null;
        if ($source !== null && is_array($source['args'] ?? null)) {
            foreach ($source['args'] as $name => $value) {
                if (is_string($name) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) && (is_scalar($value) || $value === null)) {
                    $cands[$name] = (string) ($value ?? '');
                }
            }
        }
        $sql = (string) ($spec['sql'] ?? '');
        if ($sql !== '' && preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $m)) {
            foreach ($m[1] as $name) {
                // marcador de data do sistema (:inicio_mes…) não é filtro do usuário
                if (in_array($name, self::DATE_PARAMS, true)) {
                    continue;
                }
                $cands[$name] ??= '';
            }
        }

        $ufs =['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

        $out = [];
        foreach ($cands as $name => $default) {
            if (in_array($name, $declared, true)) {
                continue;
            }
            $kind = 'search';
            $options = [];
            if ($name === 'mes') {
                $kind = 'select';
                $options = array_map('strval', range(1, 12));
            } elseif ($name === 'uf') {
                $kind = 'select';
                $options = $ufs;
            }
            $out[] = [
                'name'    => $name,
                'label'   => self::prettyLabel($name),
                'kind'    => $kind,
                'options' => $options,
                'default' => $default,
            ];
        }

        return array_slice($out, 0, 12);
    }

    public static function params(array $spec): array
    {
        $out = [];
        foreach ((array) ($spec['params'] ?? []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $name = (string) ($p['name'] ?? '');
            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
                continue;
            }
            $kind    = ($p['kind'] ?? '') === 'select' ? 'select' : 'search';
            $options = [];
            foreach ((array) ($p['options'] ?? []) as $o) {
                if (is_scalar($o) && (string) $o !== '') {
                    $options[] = (string) $o;
                }
            }
            $out[] = [
                'name'    => $name,
                'label'   => (string) ($p['label'] ?? $name),
                'kind'    => $kind,
                'options' => $options,
                'default' => (string) ($p['default'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>  $spec
     * @param array<string, string> $filterValues valores dos filtros do dashboard (name => valor)
     * @return array{ok: bool, block?: array<string, mixed>, error?: string, rowCount?: int}
     */
    public static function build(array $spec, string $db, array $filterValues = []): array
    {
        $type = (string) ($spec['type'] ?? '');
        if (! in_array($type, self::TYPES, true)) {
            return ['ok' => false, 'error' => "Tipo de widget inválido: \"{$type}\". Use: " . implode(', ', self::TYPES) . '.'];
        }

        $sql = (string) ($spec['sql'] ?? '');

        // Filtros: defaults das defs + valores recebidos (SÓ nomes declarados);
        // placeholders são trocados por valores QUOTED antes do guard rodar.
        $values = [];
        foreach (self::params($spec) as $p) {
            $values[$p['name']] = $p['default'];
        }
        foreach ($filterValues as $k => $v) {
            if (array_key_exists($k, $values) && is_scalar($v)) {
                $values[$k] = (string) $v;
            }
        }

        // ── Hook de filtro GLOBAL (app-level, mad.ai.widget_global_filter_hook):
        // filtros do dashboard que a fonte NÃO consome (nem arg, nem placeholder)
        // são oferecidos ao app ANTES de rodar a fonte — o app pode traduzi-los
        // em escopo de dados (ex.: unidade → whereIn unidade_id nas queries de
        // BI), re-agregando de verdade o que o slicer de rows não alcança.
        // SEMPRE chamado (filtros vazios = reset — estado estático vaza entre
        // requests sob Octane). Falha do hook não derruba o render (fica o
        // comportamento antigo: fonte sem escopo + slicer).
        $consumed = self::consumedNames($spec);
        $hook     = (string) config('mad.ai.widget_global_filter_hook', '');
        if ($hook !== '' && class_exists($hook) && method_exists($hook, 'apply')) {
            $extras = [];
            foreach ($values as $name => $value) {
                if ($value !== '' && ! in_array($name, $consumed, true)) {
                    $extras[$name] = $value;
                }
            }
            try {
                $hook::apply($extras);
            } catch (\Throwable $e) {
                error_log('[WidgetBlockBuilder] widget_global_filter_hook: ' . $e->getMessage());
            }
        }

        // ── Fonte TOOL (widgets de BI): em vez de SQL, o spec guarda uma
        // chamada determinística de tool MCP {tool, args, rows_path}. Roda
        // sob o Bearer do REQUEST (permissão piso/matriz + tenant do token
        // valem no render) — dado vivo, sem IA, sem SQL cru contra o mart.
        $source = is_array($spec['source'] ?? null) ? $spec['source'] : null;
        if ($source !== null && (string) ($source['tool'] ?? '') !== '') {
            $toolRun = self::runToolSource($source, $values);
            if (! $toolRun['ok']) {
                return ['ok' => false, 'error' => $toolRun['error']];
            }
            $rows = $toolRun['rows'];
        } else {
            if (str_contains($sql, ':')) {
                // + marcadores de data do sistema (filtro declarado homônimo vence)
                $sql = WidgetSqlGuard::interpolate($db, $sql, $values + self::dateParams());
            }

            $run = WidgetSqlGuard::run($db, $sql);
            if (! $run['ok']) {
                return ['ok' => false, 'error' => $run['error'] ?? 'Falha ao executar a SQL.'];
            }

            $rows = $run['rows'] ?? [];
        }
        // SLICER: filtro cujo nome não é placeholder/arg da fonte mas É coluna
        // do resultado filtra as ROWS por igualdade (case-insensitive) — é o
        // que faz "forma_pagamento=PIX" funcionar num widget agregado.
        foreach ($values as $name => $value) {
            if ($value === '' || in_array($name, $consumed, true)) {
                continue;
            }
            $first = $rows[0] ?? null;
            if (! is_array($first) || ! array_key_exists($name, $first)) {
                continue;
            }
            $needle = mb_strtolower(trim((string) $value));
            $rows   = array_values(array_filter(
                $rows,
                static fn ($r) => mb_strtolower(trim((string) ($r[$name] ?? ''))) === $needle
            ));
        }

        $map   = is_array($spec['map'] ?? null) ? $spec['map'] : [];
        $style = is_array($spec['style'] ?? null) ? $spec['style'] : [];

        if ($rows === [] && ! in_array($type, ['table', 'list', 'timeline'], true)) {
            // Com filtro aplicado, zero linha é resultado legítimo (busca sem
            // match) — devolve um callout "sem resultados" em vez de erro.
            if (self::params($spec) !== []) {
                return [
                    'ok'       => true,
                    'block'    => ['type' => 'callout', 'intent' => 'info', 'text' => 'Sem resultados para o filtro aplicado.'],
                    'rowCount' => 0,
                ];
            }

            // Zero linha é RESPOSTA ("nenhuma venda este mês"), não erro: com
            // "ajuste a consulta" o modelo reescrevia a SQL até esgotar as
            // rodadas e o turno acabava sem resposta. Estado vazio honesto.
            return self::emptyResult();
        }

        $input = self::inputFor($type, $rows, $map, $style);
        if (isset($input['__error'])) {
            return ['ok' => false, 'error' => (string) $input['__error']];
        }

        $block = BlockValidator::build($type, $input);
        if ($block === null) {
            if ($rows === []) {
                return self::emptyResult(); // tabela/lista sem linha e sem colunas declaradas
            }

            // As colunas REAIS do resultado vão no erro: sem elas o modelo chutava
            // outro map/type às cegas.
            $cols = is_array($rows[0] ?? null) ? implode(', ', array_map('strval', array_keys($rows[0]))) : '';

            return ['ok' => false, 'error' => 'O mapeamento não produziu um bloco válido — confira map/style.'
                . ($cols !== '' ? " Colunas do resultado da SQL: {$cols}." : '')];
        }

        return ['ok' => true, 'block' => $block, 'rowCount' => count($rows), 'rows' => $rows];
    }

    /**
     * Executa a fonte TOOL do widget e normaliza o resultado em ROWS
     * (mesmo insumo do caminho SQL — o map funciona igual):
     *   - lista de objetos → como está;
     *   - mapa label=>escalar (ex. distribuicao) → [{label, value}];
     *   - escalar → [{value}].
     * Filtros do dashboard (values) SOBRESCREVEM os args homônimos — é o que
     * torna o widget de tool filtrável (mes/ano/uf…) na tela.
     *
     * @param array<string, mixed>  $source {tool, args?, rows_path?}
     * @param array<string, string> $values filtros declarados (name => valor)
     * @return array{ok: bool, error?: string, rows?: list<array<string, mixed>>}
     */
    private static function runToolSource(array $source, array $values): array
    {
        $tool = (string) $source['tool'];
        try {
            $caller = new \Mad\Ai\McpToolCaller();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Manifest MCP indisponível: ' . $e->getMessage()];
        }
        if (! $caller->has($tool)) {
            return ['ok' => false, 'error' => "Tool '{$tool}' indisponível ou sem permissão para este usuário."];
        }
        if ($caller->isWrite($tool)) {
            return ['ok' => false, 'error' => 'Widget de tool só aceita tools de LEITURA.'];
        }

        $args = is_array($source['args'] ?? null) ? $source['args'] : [];
        foreach ($values as $k => $v) {
            if ($v !== '') {
                $args[$k] = $v;
            }
        }

        $out = $caller->invoke($tool, $args);
        if (! ($out['ok'] ?? false)) {
            $msg = is_array($out['result'] ?? null) ? (string) ($out['result']['error'] ?? '') : '';

            return ['ok' => false, 'error' => 'A tool falhou' . ($msg !== '' ? ": {$msg}" : '.')];
        }

        $data = $out['result'];
        foreach (array_filter(explode('.', (string) ($source['rows_path'] ?? ''))) as $seg) {
            if (! is_array($data) || ! array_key_exists($seg, $data)) {
                return ['ok' => false, 'error' => "rows_path '{$source['rows_path']}' não existe no resultado da tool."];
            }
            $data = $data[$seg];
        }

        if (is_array($data) && array_is_list($data)) {
            $rows = array_values(array_filter($data, 'is_array'));
        } elseif (is_array($data)) {
            $scalars = array_filter($data, 'is_scalar');
            $rows = count($scalars) === count($data)
                ? array_map(fn ($k, $v) => ['label' => (string) $k, 'value' => $v], array_keys($data), $data)
                : [$data]; // objeto único (ex. resumo) vira 1 row
        } elseif (is_scalar($data)) {
            $rows = [['value' => $data]];
        } else {
            $rows = [];
        }

        return ['ok' => true, 'rows' => $rows];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $map
     * @param array<string, mixed>       $style
     * @return array<string, mixed>
     */
    private static function inputFor(string $type, array $rows, array $map, array $style): array
    {
        switch ($type) {
            case 'bar':
            case 'donut':
            case 'funnel':
                $labelCol = self::col($map, 'labelCol');
                $valueCol = self::col($map, 'valueCol');
                if ($labelCol === null || $valueCol === null) {
                    return ['__error' => "map.labelCol e map.valueCol são obrigatórios para {$type}."];
                }
                $colors   = self::colorList($style);
                $colorCol = self::col($map, 'colorCol');
                $data     = [];
                foreach ($rows as $i => $r) {
                    $item = [
                        'label' => self::s($r[$labelCol] ?? null),
                        'value' => $r[$valueCol] ?? null,
                    ];
                    $color = $colorCol !== null ? self::s($r[$colorCol] ?? null) : null;
                    if ($color === null && $colors !== []) {
                        $color = $colors[$i % count($colors)];
                    }
                    if ($color !== null) {
                        $item['color'] = $color;
                    }
                    $data[] = $item;
                }
                $input = [
                    $type === 'funnel' ? 'steps' : 'data' => $data,
                    'title' => self::s($style['title'] ?? null),
                    'unit'  => self::s($style['unit'] ?? null),
                ];
                if ($type === 'bar') {
                    $input['horizontal'] = ($style['horizontal'] ?? null) === true;
                }
                if ($type === 'donut') {
                    $input['centerValue'] = self::s($style['centerValue'] ?? null);
                    $input['centerLabel'] = self::s($style['centerLabel'] ?? null);
                }
                return $input;

            case 'line':
            case 'area':
                $xCol = self::col($map, 'xCol');
                $sers = is_array($map['series'] ?? null) ? $map['series'] : [];
                if ($xCol === null || $sers === []) {
                    return ['__error' => "map.xCol e map.series[{name, col}] são obrigatórios para {$type}."];
                }
                $xLabels = [];
                foreach ($rows as $r) {
                    $xLabels[] = self::s($r[$xCol] ?? null) ?? '';
                }
                $series = [];
                foreach ($sers as $s) {
                    if (! is_array($s)) {
                        continue;
                    }
                    $col = self::col($s, 'col');
                    if ($col === null) {
                        continue;
                    }
                    $points = [];
                    foreach ($rows as $r) {
                        $points[] = $r[$col] ?? 0;
                    }
                    $serie = [
                        'name'   => self::s($s['name'] ?? null) ?? $col,
                        'points' => $points,
                    ];
                    if (($c = self::s($s['color'] ?? null)) !== null) {
                        $serie['color'] = $c;
                    }
                    if (($s['dashed'] ?? null) === true) {
                        $serie['dashed'] = true;
                    }
                    if (($s['area'] ?? null) === true) {
                        $serie['area'] = true;
                    }
                    $series[] = $serie;
                }
                return [
                    'xLabels' => $xLabels,
                    'series'  => $series,
                    'title'   => self::s($style['title'] ?? null),
                    'unit'    => self::s($style['unit'] ?? null),
                ];

            case 'kpis':
                $defs = is_array($map['items'] ?? null) ? $map['items'] : [];
                if ($defs === []) {
                    return ['__error' => 'map.items[{label, col}] é obrigatório para kpis.'];
                }
                $row   = $rows[0] ?? [];
                $items = [];
                foreach ($defs as $d) {
                    if (! is_array($d)) {
                        continue;
                    }
                    $col = self::col($d, 'col');
                    $val = $col !== null ? ($row[$col] ?? null) : ($d['value'] ?? null);
                    // O RUNTIME formata (pt-BR), não a SQL: item.format (ou
                    // map.format) = currency|number|int|percent|date|datetime
                    // → "R$ 20.884,00", "7,6%"… Vale também p/ string numérica
                    // (DECIMAL do MySQL/Postgres chega "20884.00"). Sem format,
                    // número cru vira pt-BR: 66983.6 → "66.983,60".
                    $fmt = self::fmt($d['format'] ?? ($map['format'] ?? null));
                    // SUM/AVG sobre conjunto vazio = NULL: o KPI é "zero", não
                    // bloco inválido (o card sumia e o modelo reescrevia a SQL).
                    if ($val === null || $val === '') {
                        $val = $fmt !== null && ! in_array($fmt, ['date', 'datetime'], true) ? 0 : '—';
                    }
                    if ($fmt !== null) {
                        $val = WidgetDisplay::formatValue($val, $fmt, self::decimals($d));
                    } elseif (is_numeric($val) && ! is_string($val)) {
                        $dec = fmod((float) $val, 1.0) !== 0.0 ? 2 : 0;
                        $val = number_format((float) $val, $dec, ',', '.');
                    }
                    $it  = [
                        'label' => self::s($d['label'] ?? null) ?? ($col ?? ''),
                        'value' => self::s($val),
                    ];
                    $subCol = self::col($d, 'subCol');
                    $sub    = $subCol !== null ? self::s($row[$subCol] ?? null) : self::s($d['sub'] ?? null);
                    if ($sub !== null) {
                        $it['sub'] = $sub;
                    }
                    $deltaCol = self::col($d, 'deltaCol');
                    $delta    = $deltaCol !== null ? self::s($row[$deltaCol] ?? null) : self::s($d['delta'] ?? null);
                    if ($delta !== null) {
                        $it['delta'] = $delta;
                    }
                    foreach (['dir', 'tone'] as $k) {
                        if (isset($d[$k]) && is_string($d[$k])) {
                            $it[$k] = $d[$k];
                        }
                    }
                    $items[] = $it;
                }
                return ['items' => $items];

            case 'gauge':
                $valueCol = self::col($map, 'valueCol');
                if ($valueCol === null) {
                    return ['__error' => 'map.valueCol é obrigatório para gauge.'];
                }
                $row   = $rows[0] ?? [];
                $input = [
                    'value' => $row[$valueCol] ?? null,
                    'max'   => $style['max'] ?? null,
                    'min'   => $style['min'] ?? null,
                    'title' => self::s($style['title'] ?? null),
                    'unit'  => self::s($style['unit'] ?? null),
                    'label' => self::s($style['label'] ?? null),
                ];
                $maxCol = self::col($map, 'maxCol');
                if ($maxCol !== null && isset($row[$maxCol])) {
                    $input['max'] = $row[$maxCol];
                }
                if (is_array($style['zones'] ?? null)) {
                    $input['zones'] = $style['zones'];
                }
                return $input;

            case 'table':
                $cols = is_array($map['columns'] ?? null) ? $map['columns'] : [];
                if ($cols === []) {
                    // fallback: todas as colunas da 1ª row viram colunas
                    foreach (array_keys($rows[0] ?? []) as $k) {
                        $cols[] = ['key' => $k, 'label' => $k];
                    }
                }
                $outRows = [];
                foreach ($rows as $r) {
                    $row = [];
                    foreach ($cols as $c) {
                        $k = is_array($c) ? self::col($c, 'key') : null;
                        if ($k !== null) {
                            // columns[].format: o runtime formata (pt-BR); sem
                            // format a célula sai como veio (id 1019 não vira "1.019")
                            $v   = $r[$k] ?? null;
                            $fmt = self::fmt($c['format'] ?? null);
                            if ($fmt !== null) {
                                $v = WidgetDisplay::formatValue($v, $fmt, self::decimals($c));
                            }
                            $row[$k] = self::s($v) ?? '';
                        }
                    }
                    $outRows[] = $row;
                }
                // `format` é instrução do runtime, não do contrato do bloco
                $cols = array_map(static function ($c) {
                    if (is_array($c)) {
                        unset($c['format'], $c['decimals']);
                    }

                    return $c;
                }, $cols);
                return [
                    'columns' => $cols,
                    'rows'    => $outRows,
                    'title'   => self::s($style['title'] ?? null),
                    'note'    => self::s($style['note'] ?? null),
                ];

            case 'list':
                $titleCol = self::col($map, 'titleCol');
                if ($titleCol === null) {
                    return ['__error' => 'map.titleCol é obrigatório para list.'];
                }
                $subCol   = self::col($map, 'subCol');
                $rightCol = self::col($map, 'rightCol');
                $rightFmt = self::fmt($map['rightFormat'] ?? null);
                $items    = [];
                foreach ($rows as $r) {
                    $it = ['title' => self::s($r[$titleCol] ?? null) ?? ''];
                    if ($subCol !== null && ($v = self::s($r[$subCol] ?? null)) !== null) {
                        $it['sub'] = $v;
                    }
                    $right = $rightCol !== null ? ($r[$rightCol] ?? null) : null;
                    if ($right !== null && $rightFmt !== null) {
                        $right = WidgetDisplay::formatValue($right, $rightFmt);
                    }
                    if ($rightCol !== null && ($v = self::s($right)) !== null) {
                        $it['right'] = $v;
                    }
                    $items[] = $it;
                }
                return ['items' => $items, 'title' => self::s($style['title'] ?? null)];

            case 'progress':
                $labelCol = self::col($map, 'labelCol');
                $valueCol = self::col($map, 'valueCol');
                if ($labelCol === null || $valueCol === null) {
                    return ['__error' => 'map.labelCol e map.valueCol são obrigatórios para progress.'];
                }
                $maxCol = self::col($map, 'maxCol');
                $items  = [];
                foreach ($rows as $r) {
                    $it = [
                        'label' => self::s($r[$labelCol] ?? null) ?? '',
                        'value' => $r[$valueCol] ?? null,
                    ];
                    if ($maxCol !== null && isset($r[$maxCol])) {
                        $it['max'] = $r[$maxCol];
                    } elseif (isset($style['max'])) {
                        $it['max'] = $style['max'];
                    }
                    $items[] = $it;
                }
                return ['items' => $items, 'title' => self::s($style['title'] ?? null)];

            case 'timeline':
                $titleCol = self::col($map, 'titleCol');
                if ($titleCol === null) {
                    return ['__error' => 'map.titleCol é obrigatório para timeline.'];
                }
                $timeCol = self::col($map, 'timeCol');
                $subCol  = self::col($map, 'subCol');
                $items   = [];
                foreach ($rows as $r) {
                    $it = ['title' => self::s($r[$titleCol] ?? null) ?? ''];
                    if ($timeCol !== null && ($v = self::s($r[$timeCol] ?? null)) !== null) {
                        $it['time'] = $v;
                    }
                    if ($subCol !== null && ($v = self::s($r[$subCol] ?? null)) !== null) {
                        $it['sub'] = $v;
                    }
                    $items[] = $it;
                }
                return ['items' => $items, 'title' => self::s($style['title'] ?? null)];
        }

        return ['__error' => 'Tipo não mapeado.'];
    }

    /**
     * Consulta que não devolveu linhas: bloco informativo + rowCount 0 (o
     * modelo recebe "0 linhas" e diz isso ao usuário, em vez de reescrever a SQL).
     *
     * @return array{ok: true, block: array<string, string>, rowCount: 0, rows: array{}}
     */
    private static function emptyResult(): array
    {
        return [
            'ok'       => true,
            'block'    => ['type' => 'callout', 'intent' => 'info', 'text' => 'Nenhum registro encontrado para esta consulta.'],
            'rowCount' => 0,
            'rows'     => [],
        ];
    }

    /** Formato declarado no map (currency|number|int|percent|date|datetime) ou null. */
    private static function fmt(mixed $v): ?string
    {
        $v = is_string($v) ? strtolower(trim($v)) : '';
        $v = ['money' => 'currency', 'moeda' => 'currency', 'integer' => 'int', 'numero' => 'number', 'pct' => 'percent', 'data' => 'date'][$v] ?? $v;

        return in_array($v, WidgetDisplay::VALUE_FORMATS, true) ? $v : null;
    }

    /** Casas decimais explícitas do item/coluna (0-4) ou null (default do formato). */
    private static function decimals(array $arr): ?int
    {
        return isset($arr['decimals']) && is_numeric($arr['decimals']) ? (int) $arr['decimals'] : null;
    }

    /** Nome de coluna não-vazio ou null. */
    private static function col(array $arr, string $key): ?string
    {
        $v = $arr[$key] ?? null;

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    /** Escalar → string (null para vazio/não-escalar). */
    private static function s(mixed $v): ?string
    {
        if ($v === null || is_array($v) || is_object($v)) {
            return null;
        }
        $s = (string) $v;

        return $s === '' ? null : $s;
    }

    /** @return list<string> */
    private static function colorList(array $style): array
    {
        $out = [];
        foreach ((array) ($style['colors'] ?? []) as $c) {
            if (is_string($c) && $c !== '') {
                $out[] = $c;
            }
        }

        return $out;
    }
}
