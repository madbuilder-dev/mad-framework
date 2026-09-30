<?php

namespace Mad\Ai;

use Mad\Ai\Tools\RenderTool;

/**
 * RenderToolRegistry — as 15 render tools (espelho de embed/contract/tools.ts +
 * block-schemas.ts). Cada entrada: name → blockType, description, input_schema.
 *
 * O input_schema e o spec JSON-Schema que o JsonSchemaBuilder converte para o
 * schema do laravel/ai. NAO divergir do contrato do front (validate.ts revalida
 * pelos VALORES — as `description` dos campos so guiam o modelo, nao afetam o
 * front). Mantemos descricoes ENXUTAS (custo de token por request): so onde
 * agregam (formato de valor, semantica de campo). Paleta de cores e explicada no
 * RENDER_SYSTEM_PROMPT, entao `color` nao precisa de description por campo.
 *
 * Extensao server-only: confirm_action ganha `args` (JSON string) para o modelo
 * informar o payload real da escrita — usada na execucao apos a confirmacao; o
 * front nunca a renderiza (validate.ts a descarta).
 */
final class RenderToolRegistry
{
    private const TONE = ['type' => 'string', 'enum' => ['pos', 'warn', 'neg', 'neutral']];

    private const COLOR = ['type' => 'string'];

    /**
     * Constroi as instancias de RenderTool ligadas ao sink/confirms.
     *
     * @return array<int, RenderTool>
     */
    public static function tools(
        SseSink $sink,
        ?ConfirmCoordinator $confirms = null,
        ?ProvenanceTracker $trace = null,
    ): array {
        $defs = self::definitions();
        $out = [];
        foreach ($defs as $name => $def) {
            $out[] = new RenderTool($name, $def['blockType'], $def['description'], $def['schema'], $sink, $confirms, $trace);
        }

        return $out;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::definitions());
    }

    public static function isRenderTool(string $name): bool
    {
        return array_key_exists($name, self::definitions());
    }

    /**
     * @return array<string, array{blockType: string, description: string, schema: array<string, mixed>}>
     */
    private static function definitions(): array
    {
        $badgeItem = ['type' => 'object', 'properties' => ['label' => ['type' => 'string'], 'tone' => self::TONE], 'required' => ['label']];

        return [
            'show_kpis' => [
                'blockType'   => 'kpis',
                'description' => 'Show a 2×N grid of KPI stat cards (label, big value, optional delta arrow). Use for headline metrics / summaries.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'items' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'maxItems' => 6,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'label'  => ['type' => 'string'],
                                    'value'  => ['type' => 'string', 'description' => 'O valor EXATO retornado pela consulta. Número cru ("20884.5") + format → o sistema formata em pt-BR; texto já formatado só se não houver format.'],
                                    'format' => ['type' => 'string', 'enum' => ['currency', 'number', 'int', 'percent'], 'description' => 'Formato do value numérico (o sistema formata: R$ 20.884,50 · 1.234 · 7,6%).'],
                                    'sub'    => ['type' => 'string'],
                                    'delta' => ['type' => 'string', 'description' => 'Pré-formatado: "+8%", "-0,6 pp".'],
                                    'dir'   => ['type' => 'string', 'enum' => ['up', 'down']],
                                    'tone'  => self::TONE,
                                ],
                                'required'   => ['label', 'value'],
                            ],
                        ],
                    ],
                    'required'   => ['items'],
                ],
            ],

            'show_bar_chart' => [
                'blockType'   => 'bar',
                'description' => 'Show a bar chart. Vertical for time buckets / categories; set horizontal=true for a ranking (top-N).',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title'      => ['type' => 'string'],
                        'unit'       => ['type' => 'string'],
                        'horizontal' => ['type' => 'boolean'],
                        'data'       => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => ['label' => ['type' => 'string'], 'value' => ['type' => 'number'], 'color' => self::COLOR],
                                'required'   => ['label', 'value'],
                            ],
                        ],
                    ],
                    'required'   => ['data'],
                ],
            ],

            'show_bars_chart' => [
                'blockType'   => 'bars',
                'description' => 'Show a MULTI-SERIES bar chart per category: grouped (compare 2-4 metrics side by side) or stacked=true (composition per category). Use instead of two separate bar charts.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title'      => ['type' => 'string'],
                        'unit'       => ['type' => 'string'],
                        'stacked'    => ['type' => 'boolean', 'description' => 'true → séries EMPILHADAS (composição); ausente → lado a lado.'],
                        'horizontal' => ['type' => 'boolean'],
                        'categories' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
                        'series'     => [
                            'type'     => 'array',
                            'minItems' => 2,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'name'   => ['type' => 'string'],
                                    'color'  => self::COLOR,
                                    'values' => ['type' => 'array', 'items' => ['type' => 'number'], 'description' => 'Um valor por categoria, na MESMA ordem.'],
                                ],
                                'required'   => ['name', 'values'],
                            ],
                        ],
                    ],
                    'required'   => ['categories', 'series'],
                ],
            ],

            'show_scatter' => [
                'blockType'   => 'scatter',
                'description' => 'Show a scatter plot (correlation between two metrics, one point per entity; optional size = third metric). E.g. orçado × vendido por unidade.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title'  => ['type' => 'string'],
                        'xLabel' => ['type' => 'string'],
                        'yLabel' => ['type' => 'string'],
                        'points' => [
                            'type'     => 'array',
                            'minItems' => 3,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'x'     => ['type' => 'number'],
                                    'y'     => ['type' => 'number'],
                                    'label' => ['type' => 'string'],
                                    'size'  => ['type' => 'number'],
                                ],
                                'required'   => ['x', 'y'],
                            ],
                        ],
                    ],
                    'required'   => ['points'],
                ],
            ],

            'show_heatmap' => [
                'blockType'   => 'heatmap',
                'description' => 'Show a heatmap matrix (x × y with color intensity). E.g. canal × mês, unidade × forma de pagamento.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title'   => ['type' => 'string'],
                        'unit'    => ['type' => 'string'],
                        'xLabels' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
                        'yLabels' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
                        'values'  => [
                            'type'        => 'array',
                            'description' => 'Matriz values[y][x] — uma linha por yLabel, um número por xLabel.',
                            'items'       => ['type' => 'array', 'items' => ['type' => 'number']],
                        ],
                    ],
                    'required'   => ['xLabels', 'yLabels', 'values'],
                ],
            ],

            'show_combo_chart' => [
                'blockType'   => 'combo',
                'description' => 'Show bars + overlaid line(s) on the same categories. Set dualAxis=true when the line is a rate/% and bars are volume.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title'      => ['type' => 'string'],
                        'unit'       => ['type' => 'string'],
                        'dualAxis'   => ['type' => 'boolean'],
                        'categories' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
                        'bars'       => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'name'   => ['type' => 'string'],
                                    'color'  => self::COLOR,
                                    'values' => ['type' => 'array', 'items' => ['type' => 'number']],
                                ],
                                'required'   => ['name', 'values'],
                            ],
                        ],
                        'lines'      => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'name'   => ['type' => 'string'],
                                    'color'  => self::COLOR,
                                    'points' => ['type' => 'array', 'items' => ['type' => 'number'], 'description' => 'Um valor por categoria, na MESMA ordem.'],
                                    'dashed' => ['type' => 'boolean'],
                                ],
                                'required'   => ['name', 'points'],
                            ],
                        ],
                    ],
                    'required'   => ['categories', 'bars', 'lines'],
                ],
            ],

            'show_map_br' => [
                'blockType'   => 'map_br',
                'description' => 'Show a Brazil UF tile map (choropleth by value per state). Use for anything "por estado/UF/região".',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'unit'  => ['type' => 'string'],
                        'data'  => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'uf'    => ['type' => 'string', 'description' => 'Sigla da UF (2 letras): SP, MG, BA…'],
                                    'value' => ['type' => 'number'],
                                ],
                                'required'   => ['uf', 'value'],
                            ],
                        ],
                    ],
                    'required'   => ['data'],
                ],
            ],

            'show_line_chart' => [
                'blockType'   => 'line',
                'description' => 'Show a multi-series line chart for time series (e.g. previsto × realizado, trends). Use dashed for forecast, area for the main line.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title'   => ['type' => 'string'],
                        'unit'    => ['type' => 'string'],
                        'xLabels' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'series'  => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'name'   => ['type' => 'string'],
                                    'color'  => self::COLOR,
                                    'points' => ['type' => 'array', 'items' => ['type' => 'number']],
                                    'dashed' => ['type' => 'boolean'],
                                    'area'   => ['type' => 'boolean'],
                                ],
                                'required'   => ['name', 'points'],
                            ],
                        ],
                    ],
                    'required'   => ['xLabels', 'series'],
                ],
            ],

            'show_donut_chart' => [
                'blockType'   => 'donut',
                'description' => 'Show a donut for composition / share of a whole (e.g. aging buckets, % split).',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title'       => ['type' => 'string'],
                        'centerValue' => ['type' => 'string'],
                        'centerLabel' => ['type' => 'string'],
                        'data'        => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => ['label' => ['type' => 'string'], 'value' => ['type' => 'number'], 'color' => self::COLOR],
                                'required'   => ['label', 'value'],
                            ],
                        ],
                    ],
                    'required'   => ['data'],
                ],
            ],

            'show_area_chart' => [
                'blockType'   => 'area',
                'description' => 'Show a STACKED area chart — composition over time (how parts of a total evolve, e.g. receita por produto por mês).',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title'   => ['type' => 'string'],
                        'unit'    => ['type' => 'string'],
                        'xLabels' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'series'  => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => ['name' => ['type' => 'string'], 'color' => self::COLOR, 'points' => ['type' => 'array', 'items' => ['type' => 'number']]],
                                'required'   => ['name', 'points'],
                            ],
                        ],
                    ],
                    'required'   => ['xLabels', 'series'],
                ],
            ],

            'show_gauge' => [
                'blockType'   => 'gauge',
                'description' => 'Show a radial gauge — one value vs a max, with optional threshold zones (meta atingida, inadimplência vs teto, utilização).',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'value' => ['type' => 'number'],
                        'max'   => ['type' => 'number'],
                        'min'   => ['type' => 'number'],
                        'unit'  => ['type' => 'string'],
                        'label' => ['type' => 'string'],
                        'zones' => [
                            'type'        => 'array',
                            'description' => 'Arco usa o tom da 1ª zona com upTo ≥ value.',
                            'items'       => ['type' => 'object', 'properties' => ['upTo' => ['type' => 'number'], 'tone' => self::TONE], 'required' => ['upTo', 'tone']],
                        ],
                    ],
                    'required'   => ['value', 'max'],
                ],
            ],

            'show_funnel' => [
                'blockType'   => 'funnel',
                'description' => 'Show a funnel — ordered stages with conversion drop-off (vendas, pipeline, conversão).',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'unit'  => ['type' => 'string'],
                        'steps' => [
                            'type'     => 'array',
                            'minItems' => 2,
                            'items'    => ['type' => 'object', 'properties' => ['label' => ['type' => 'string'], 'value' => ['type' => 'number'], 'color' => self::COLOR], 'required' => ['label', 'value']],
                        ],
                    ],
                    'required'   => ['steps'],
                ],
            ],

            'show_table' => [
                'blockType'   => 'table',
                'description' => 'Show a tabular dataset of records. Use a "badge"-type column for status. Add `note` when the MCP masked PII. '
                    . 'Para tabela AGRUPADA com quebras/subtotais/Total: groupBy = key da coluna que agrupa (vira banda) e totals = keys somadas — células dessas colunas em NUMBER CRU (o renderer soma e formata pt-BR; não emita linhas de subtotal).',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title'   => ['type' => 'string'],
                        'note'    => ['type' => 'string', 'description' => 'Nota de PII/dados sob a tabela.'],
                        'columns' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'key'    => ['type' => 'string'],
                                    'label'  => ['type' => 'string'],
                                    'align'  => ['type' => 'string', 'enum' => ['left', 'right']],
                                    'mono'   => ['type' => 'boolean'],
                                    'strong' => ['type' => 'boolean'],
                                    'type'   => ['type' => 'string', 'enum' => ['badge'], 'description' => 'badge → célula = {label,tone}.'],
                                ],
                                'required'   => ['key', 'label'],
                            ],
                        ],
                        'rows'    => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Objeto por column.key; célula badge = {label,tone}.'],
                        'groupBy' => ['type' => 'string', 'description' => 'Key da coluna que AGRUPA as linhas (vira banda com contagem; some do corpo). Linhas na ordem desejada.'],
                        'totals'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Keys de colunas SOMADAS por grupo + Total geral. Células em number cru.'],
                        'grandTotal' => ['type' => 'boolean', 'description' => 'false → sem linha Total geral (default true com totals).'],
                    ],
                    'required'   => ['columns', 'rows'],
                ],
            ],

            'show_list' => [
                'blockType'   => 'list',
                'description' => 'Show a list of record cards (title + sub + right value + optional status badge). Good for contacts / ranked items.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'items' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => ['title' => ['type' => 'string'], 'sub' => ['type' => 'string'], 'right' => ['type' => 'string'], 'badge' => $badgeItem],
                                'required'   => ['title'],
                            ],
                        ],
                    ],
                    'required'   => ['items'],
                ],
            ],

            'show_badges' => [
                'blockType'   => 'badges',
                'description' => 'Show a row of small status pills. Good for audit results / quick flags after an action.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => ['items' => ['type' => 'array', 'minItems' => 1, 'items' => $badgeItem]],
                    'required'   => ['items'],
                ],
            ],

            'show_progress' => [
                'blockType'   => 'progress',
                'description' => 'Show labeled progress bars (value/max) — goal completion, budget usage per category.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'items' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'label' => ['type' => 'string'],
                                    'value' => ['type' => 'number', 'description' => 'com max → value/max; sem max → percent 0–100.'],
                                    'max'   => ['type' => 'number'],
                                    'sub'   => ['type' => 'string'],
                                    'tone'  => self::TONE,
                                ],
                                'required'   => ['label', 'value'],
                            ],
                        ],
                    ],
                    'required'   => ['items'],
                ],
            ],

            'show_timeline' => [
                'blockType'   => 'timeline',
                'description' => 'Show a vertical event timeline — status history, audit trail, order pipeline.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'items' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => ['title' => ['type' => 'string'], 'time' => ['type' => 'string'], 'sub' => ['type' => 'string'], 'tone' => self::TONE],
                                'required'   => ['title'],
                            ],
                        ],
                    ],
                    'required'   => ['items'],
                ],
            ],

            'show_callout' => [
                'blockType'   => 'callout',
                'description' => 'Show a highlighted insight/alert box to flag attention (risco, recomendação, aviso). intent: info|pos|warn|neg.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'intent' => ['type' => 'string', 'enum' => ['info', 'pos', 'warn', 'neg']],
                        'title'  => ['type' => 'string'],
                        'text'   => ['type' => 'string', 'description' => 'Aceita **negrito**.'],
                    ],
                    'required'   => ['text'],
                ],
            ],

            'show_detail' => [
                'blockType'   => 'detail',
                'description' => 'Show a key-value card for a SINGLE record (a read_<entidade> result). For many rows use show_table.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'items' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => ['label' => ['type' => 'string'], 'value' => ['type' => 'string'], 'tone' => self::TONE],
                                'required'   => ['label', 'value'],
                            ],
                        ],
                    ],
                    'required'   => ['items'],
                ],
            ],

            'show_dashboard' => [
                'blockType'   => 'dashboard',
                'description' => 'Frame a FULL dashboard (titled grid + optional search/filter bar) from widgets ALREADY emitted this turn. PREFERRED flow: call the show_* tools normally (each returns "rendered (id: blk-…)"), then call this with widgets: [{ref: "<that id>", span}]. Inline {"block": "<JSON string>"} is the fallback. On a filter refresh, re-run the data tools and re-emit with the SAME dashboard id.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'id'      => ['type' => 'string', 'description' => 'Id estável (ex: "dash-vendas-3f2"). REUSE o MESMO id no refresh — a UI substitui in-place.'],
                        'title'   => ['type' => 'string'],
                        'desc'    => ['type' => 'string'],
                        'filters' => [
                            'type'        => 'array',
                            'description' => 'Barra de busca/filtros. Os valores voltam num turno dashboardAction; re-execute as tools com eles.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'key'     => ['type' => 'string', 'description' => 'Campo filtrado nas tools MCP.'],
                                    'label'   => ['type' => 'string'],
                                    'kind'    => ['type' => 'string', 'enum' => ['search', 'select']],
                                    'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'value'   => ['type' => 'string', 'description' => 'Valor aplicado (ecoar no refresh).'],
                                ],
                                'required'   => ['key', 'label', 'kind'],
                            ],
                        ],
                        'widgets' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'maxItems' => 8,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'span'  => ['type' => 'number', 'enum' => [1, 2], 'description' => '2 = linha inteira.'],
                                    'ref'   => ['type' => 'string', 'description' => 'PREFERIDO: id de bloco ja emitido neste turno (retornado pela tool show_*: "rendered (id: blk-…)").'],
                                    'block' => ['type' => 'string', 'description' => 'Fallback: STRING JSON do bloco aninhado {"type":"<kind>", ...campos do input da tool show_<kind>}.'],
                                ],
                            ],
                        ],
                    ],
                    'required'   => ['id', 'widgets'],
                ],
            ],

            'confirm_action' => [
                'blockType'   => 'confirm',
                'description' => 'Render a confirmation card for a WRITE/destructive MCP tool. Call this INSTEAD of executing the write; only run the write after the user confirms. Put the real write payload as a JSON string in "args".',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'id'           => ['type' => 'string'],
                        'tool'         => ['type' => 'string', 'description' => 'Tool MCP de escrita que roda ao confirmar (ex: "unidades.del_unit").'],
                        'title'        => ['type' => 'string'],
                        'desc'         => ['type' => 'string'],
                        'danger'       => ['type' => 'boolean'],
                        'fields'       => [
                            'type'  => 'array',
                            'items' => ['type' => 'object', 'properties' => ['k' => ['type' => 'string'], 'v' => ['type' => 'string']], 'required' => ['k', 'v']],
                        ],
                        'confirmLabel' => ['type' => 'string'],
                        'args'         => ['type' => 'string', 'description' => 'JSON do payload real da escrita (ex: {"id":5}); usado no servidor, nao exibido.'],
                    ],
                    'required'   => ['tool', 'title', 'fields'],
                ],
            ],
        ];
    }
}
