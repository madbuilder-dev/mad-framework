<?php

namespace Mad\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Mad\Ai\JsonSchemaBuilder;
use Mad\Ai\ProvenanceTracker;
use Mad\Ai\SseSink;
use Mad\Ai\WidgetBlockBuilder;
use Mad\Ai\WidgetStore;
use Mad\Mcp\McpCurrentUser;

/**
 * WidgetUpsertTool — preview_widget / save_widget (uma instância por modo).
 *
 * Recebe o WidgetSpec {type, sql, map, style} do modelo; o servidor valida a
 * SQL (WidgetSqlGuard), executa, mapeia (WidgetBlockBuilder) e EMITE o bloco
 * no SSE — o usuário vê o widget na hora no chat. Em save, persiste no
 * WidgetStore (privado por usuário); o widget vira insumo da tela "Meus
 * Dashboards" e a exibição lá re-executa a MESMA estrutura sem IA.
 *
 * map/style entram como JSON STRING (mesmo fallback do show_dashboard.block)
 * — o shape varia por tipo, documentado na description.
 */
final class WidgetUpsertTool implements Tool
{
    private const MAP_DOC = 'map JSON by type — '
        . 'bar|donut|funnel: {"labelCol","valueCol"} · '
        . 'line|area: {"xCol","series":[{"name","col","color?","dashed?"}]} · '
        . 'kpis: {"items":[{"label","col","format?","subCol?","deltaCol?","dir?","tone?"}]} (first row) · '
        . 'gauge: {"valueCol","maxCol?"} · '
        . 'table: {"columns":[{"key","label","align?","mono?","format?"}]} · '
        . 'list: {"titleCol","subCol?","rightCol?","rightFormat?"} · '
        . 'progress: {"labelCol","valueCol","maxCol?"} · '
        . 'timeline: {"titleCol","timeCol?","subCol?"} · '
        . 'format = currency|number|int|percent|date|datetime (the SYSTEM formats in pt-BR; SQL returns the RAW value).';

    /** Linhas da consulta que voltam ao modelo (amostra; o bloco na tela tem todas). */
    public const SAMPLE_ROWS = 25;

    /** Corte de cada valor na amostra (texto longo = observação, descrição). */
    private const SAMPLE_VALUE_CHARS = 120;

    /** Id do último bloco emitido (volta ao modelo p/ show_dashboard por ref). */
    private string $lastBlockId = '';

    /**
     * @param ProvenanceTracker|null $trace      prova de origem do turno (as linhas
     *                                           consultadas liberam os blocos show_*)
     * @param list<string>           $piiColumns colunas PII do manifest — mascaradas
     *                                           na amostra que vai ao modelo
     */
    public function __construct(
        private bool $save,
        private string $db,
        private SseSink $sink,
        private WidgetStore $store,
        private ?ProvenanceTracker $trace = null,
        private array $piiColumns = [],
    ) {
    }

    public function name(): string
    {
        return $this->save ? 'save_widget' : 'preview_widget';
    }

    public function description(): string
    {
        return ($this->save
            ? 'Validate, render AND SAVE a BI widget (persisted; it appears in the user\'s "Dashboards" screen and is re-executed deterministically there). '
            : 'Validate and render a BI widget WITHOUT saving (use to iterate until the user approves; then call save_widget). ')
            . 'The widget is a deterministic spec with ONE of two sources: (a) SQL (SELECT-only; call db_schema first) '
            . 'or (b) a read-only MCP TOOL call {tool, args, rows_path} — USE THIS for BI data (bi__* tools; SQL cannot '
            . 'reach the BI mart). Plus a column mapping over the resulting rows. '
            . 'This is also how you QUERY the database to answer a question: the block is shown to the user and the '
            . 'result returns the REAL rows (sample) for your answer. '
            . 'NEVER format numbers in SQL (no printf/concat "R$"/ROUND-to-text/"mi"): return raw values and set "format" in map. '
            . 'Dates: use the system markers :hoje, :agora, :inicio_mes, :inicio_proximo_mes, :inicio_mes_anterior, :inicio_ano '
            . '(filled at run time in the app timezone) instead of the database clock.';
    }

    public function schema(JsonSchema $schema): array
    {
        $spec = [
            'type'       => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Widget title shown on dashboards.'],
                'type'  => ['type' => 'string', 'enum' => WidgetBlockBuilder::TYPES],
                'sql'   => ['type' => 'string', 'description' => 'Source (a): ONE read-only SELECT (or WITH). No writes/DDL. Aliases become the columns referenced in map. May contain :placeholders declared in params (dashboard filters). Omit when using tool source.'],
                'tool'  => ['type' => 'string', 'description' => 'Source (b): read-only MCP tool name (e.g. bi__explorar, bi__receita). USE for BI data. Omit when using sql.'],
                'args'  => ['type' => 'string', 'description' => 'Tool-source args as JSON string (e.g. {"fato":"recebimentos","dimensao":"forma_pagamento","mes":"7","ano":"2026"}). Dashboard filter params with the SAME name override these at render time.'],
                'rows_path' => ['type' => 'string', 'description' => 'Dot-path to the rows inside the tool result (e.g. "result.linhas", "result.ranking", "result.distribuicao"). Map label=>value becomes rows {label,value}.'],
                'map'   => ['type' => 'string', 'description' => self::MAP_DOC],
                'style' => ['type' => 'string', 'description' => 'Optional style JSON: {"title","unit","horizontal","colors":[],"max","min","zones":[],"note","centerValue","centerLabel"}.'],
                'params' => ['type' => 'string', 'description' => 'Optional dashboard-filter defs JSON: [{"name","label","kind":"search|select","options":[…],"default":""}]. Each name MUST appear as :name in the sql, written so empty value = no filter (e.g. WHERE (:busca = \'\' OR nome LIKE \'%\' || :busca || \'%\')).'],
            ],
            'required'   => $this->save ? ['title', 'type', 'map'] : ['type', 'map'],
        ];
        if ($this->save) {
            $spec['properties']['widgetId'] = ['type' => 'string', 'description' => 'Existing widget id (wid-…) to UPDATE instead of creating.'];
        }

        return JsonSchemaBuilder::properties($schema, $spec);
    }

    public function handle(Request $request): string
    {
        $t0    = microtime(true);
        $input = $request->all();
        $title = trim((string) ($input['title'] ?? ''));
        $spec  = [
            'type'   => (string) ($input['type'] ?? ''),
            'sql'    => (string) ($input['sql'] ?? ''),
            'map'    => self::obj($input['map'] ?? null),
            'style'  => self::obj($input['style'] ?? null),
            'params' => self::obj($input['params'] ?? null),
        ];

        // Fonte TOOL (widgets de BI): tool+args+rows_path no lugar da SQL.
        $toolName = trim((string) ($input['tool'] ?? ''));
        if ($toolName !== '') {
            $spec['source'] = [
                'tool'      => $toolName,
                'args'      => self::obj($input['args'] ?? null),
                'rows_path' => trim((string) ($input['rows_path'] ?? '')),
            ];
        }
        if ($toolName === '' && trim($spec['sql']) === '') {
            $this->sink->toolUseStart($this->name(), ['type' => $spec['type']]);
            $this->sink->toolUseEnd($this->name(), ['type' => $spec['type']], 0, ['error' => 'sem fonte'], false);

            return 'erro: informe sql OU tool (fonte do widget).';
        }

        $params = ['type' => $spec['type'], 'title' => $title];
        $this->sink->toolUseStart($this->name(), $params);

        $built = WidgetBlockBuilder::build($spec, $this->db);
        $ms    = (int) round((microtime(true) - $t0) * 1000);

        if (! $built['ok']) {
            $err = (string) ($built['error'] ?? 'falha ao montar o widget');
            $this->sink->toolUseEnd($this->name(), $params, $ms, ['error' => $err], false);

            return 'erro: ' . $err;
        }

        $block       = $built['block'] ?? [];
        $block['id'] = 'blk-' . substr(md5(uniqid('', true)), 0, 12);
        $this->lastBlockId = (string) $block['id'];

        // As linhas REAIS da consulta: provam a origem dos blocos seguintes do
        // turno e voltam (amostra) ao modelo — sem elas ele comentava/re-exibia
        // o resultado de cabeça e inventava OS, clientes e técnicos.
        $rows   = is_array($built['rows'] ?? null) ? $built['rows'] : [];
        $sample  = $this->sample($rows);
        $summary = self::summarize($rows, $this->piiColumns);
        $this->trace?->recordRows($rows);
        $this->trace?->recordRows($sample['rows']); // forma mascarada que o modelo vê
        $this->trace?->recordBlock($block);         // referenciável num show_dashboard (ref: blk-…)

        if (! $this->save) {
            $this->sink->toolUseEnd($this->name(), $params, $ms, ['total' => $built['rowCount'] ?? 0]);
            $this->sink->block($block);

            return $this->result('preview ok', (string) ($block['type'] ?? $spec['type']), (int) ($built['rowCount'] ?? 0), $sample, $summary, null,
                'Se o usuário quiser guardar, chame save_widget com o MESMO spec + title.');
        }

        $userId = (int) (McpCurrentUser::id() ?? 0);
        if ($userId <= 0) {
            $this->sink->toolUseEnd($this->name(), $params, $ms, ['error' => 'sem usuário'], false);

            return 'erro: usuário não identificado — não é possível salvar.';
        }

        if ($title === '') {
            $this->sink->toolUseEnd($this->name(), $params, $ms, ['error' => 'sem título'], false);

            return 'erro: title é obrigatório em save_widget.';
        }

        $widgetId = $this->store->save(trim((string) ($input['widgetId'] ?? '')), $userId, $title, $spec);
        if ($widgetId === null) {
            $this->sink->toolUseEnd($this->name(), $params, $ms, ['error' => 'falha ao salvar'], false);

            return 'erro: não foi possível salvar o widget.';
        }

        $this->sink->toolUseEnd($this->name(), $params, $ms, ['total' => $built['rowCount'] ?? 0]);
        $this->sink->block($block);

        return $this->result('widget salvo', (string) ($block['type'] ?? $spec['type']), (int) ($built['rowCount'] ?? 0), $sample, $summary, $widgetId,
            'O widget já aparece na tela "Meus Dashboards" do sistema.');
    }

    /**
     * Retorno ao modelo: o que está na tela + AMOSTRA das linhas reais.
     *
     * @param array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool} $sample
     */
    private function result(string $status, string $blockType, int $rowCount, array $sample, array $summary, ?string $widgetId, string $next): string
    {
        $out = ['status' => $status];
        if ($widgetId !== null) {
            $out['widgetId'] = $widgetId;
        }
        $out += [
            'shown_to_user' => $blockType,
            'blockId'       => $this->lastBlockId,
            'rowCount'      => $rowCount,
            'columns'       => $sample['columns'],
            'rows'          => $sample['rows'],
            'truncated'     => $sample['truncated'],
        ] + $summary + [
            'note'          => 'Estes são os dados REAIS que o usuário já está vendo no bloco "' . $blockType . '"'
                . ($sample['truncated'] ? ' (amostra: ' . count($sample['rows']) . ' de ' . $rowCount . ' linhas)' : '')
                . '. Responda usando SOMENTE estes valores — não invente linhas, nomes, códigos nem números; contagens e '
                . 'totais vêm de "counts"/"stats" (calculados sobre TODAS as linhas), nunca de conta de cabeça — e NÃO repita '
                . 'este resultado com show_* (ele já está na tela). ' . ($rowCount === 0 ? 'A consulta não retornou linhas: diga isso. ' : '')
                . $next,
        ];

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            ?: ('{"status":"' . $status . '","rowCount":' . $rowCount . '}');
    }

    /**
     * Amostra limitada das linhas (colunas da 1ª linha, até SAMPLE_ROWS linhas,
     * valores longos cortados, colunas PII mascaradas como no MCP).
     *
     * @param list<array<string, mixed>> $rows
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool}
     */
    private function sample(array $rows): array
    {
        return self::sampleRows($rows, self::SAMPLE_ROWS, $this->piiColumns);
    }

    /**
     * Amostra de linhas para o MODELO (mesma regra no preview/save e no
     * query_db): colunas da 1ª linha, até $max linhas, valor longo cortado e
     * colunas PII mascaradas como no retorno das tools do MCP.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $piiColumns
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool}
     */
    /**
     * Resumo DETERMINÍSTICO de TODAS as linhas (não só da amostra): contagem por
     * valor das colunas categóricas (2–12 valores distintos: status, prioridade…)
     * e min/max/soma das numéricas. O modelo cita estes números em vez de contar
     * de cabeça — ele trocava "3 aguardando peça, 2 em atendimento" por "2 e 3".
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $piiColumns colunas PII não entram
     * @return array{counts?: array<string, array<string, int>>, stats?: array<string, array{min: float|int, max: float|int, sum: float|int}>}
     */
    public static function summarize(array $rows, array $piiColumns = []): array
    {
        if (count($rows) < 2 || count($rows) > 5000 || ! is_array($rows[0] ?? null)) {
            return [];
        }
        $pii    = array_flip(array_map('strtolower', $piiColumns));
        $counts = [];
        $stats  = [];
        foreach (array_keys($rows[0]) as $col) {
            $col = (string) $col;
            if (isset($pii[strtolower($col)])) {
                continue;
            }
            $vals    = array_column($rows, $col);
            $numeric = $vals !== [] && array_filter($vals, static fn ($v) => ! (is_int($v) || is_float($v)) && $v !== null) === [];
            if ($numeric) {
                $nums = array_values(array_filter($vals, static fn ($v) => $v !== null));
                if ($nums !== []) {
                    $stats[$col] = ['min' => min($nums), 'max' => max($nums), 'sum' => round(array_sum($nums), 2)];
                }
                continue;
            }
            $freq = [];
            foreach ($vals as $v) {
                if (is_string($v) && trim($v) !== '' && mb_strlen($v) <= 60) {
                    $freq[$v] = ($freq[$v] ?? 0) + 1;
                }
            }
            if (count($freq) >= 2 && count($freq) <= 12 && count($freq) < count($rows)) {
                arsort($freq);
                $counts[$col] = $freq;
            }
        }

        return array_filter(['counts' => $counts, 'stats' => $stats]);
    }

    public static function sampleRows(array $rows, int $max, array $piiColumns = []): array
    {
        $columns = is_array($rows[0] ?? null) ? array_map('strval', array_keys($rows[0])) : [];
        $pii     = array_flip(array_map('strtolower', $piiColumns));

        $out = [];
        foreach (array_slice($rows, 0, max(1, $max)) as $r) {
            if (! is_array($r)) {
                continue;
            }
            $row = [];
            foreach ($r as $k => $v) {
                if (is_string($v)) {
                    if (isset($pii[strtolower((string) $k)]) && $v !== '') {
                        $v = str_contains($v, '@') ? strstr($v, '@', true) . '@***' : '***';
                    } elseif (mb_strlen($v) > self::SAMPLE_VALUE_CHARS) {
                        $v = mb_substr($v, 0, self::SAMPLE_VALUE_CHARS) . '…';
                    }
                } elseif (! is_scalar($v) && $v !== null) {
                    $v = mb_substr((string) json_encode($v, JSON_UNESCAPED_UNICODE), 0, self::SAMPLE_VALUE_CHARS);
                }
                $row[(string) $k] = $v;
            }
            $out[] = $row;
        }

        return ['columns' => $columns, 'rows' => $out, 'truncated' => count($rows) > count($out)];
    }

    /** map/style chegam como JSON string (ou objeto, se o provider preservar). */
    private static function obj(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && trim($v) !== '') {
            $d = json_decode($v, true);

            return is_array($d) ? $d : [];
        }

        return [];
    }
}
