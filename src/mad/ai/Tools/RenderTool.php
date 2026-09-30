<?php

namespace Mad\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Mad\Ai\BlockValidator;
use Mad\Ai\ConfirmCoordinator;
use Mad\Ai\JsonSchemaBuilder;
use Mad\Ai\ProvenanceTracker;
use Mad\Ai\SseSink;
use Mad\Ai\WidgetDisplay;

/**
 * RenderTool — tool generica de visualizacao (uma instancia por tipo de bloco).
 *
 * Quando o modelo a chama, valida o input (BlockValidator, espelho do
 * validate.ts), emite um evento SSE `block` e devolve um ack curto ao modelo.
 * NAO toca no banco. `name()` dinamico e lido pelo ToolNameResolver do laravel/ai.
 *
 * `confirm_action` e especial: registra a pendencia {tool, args} e emite o bloco
 * confirm, mas NAO executa a escrita — a execucao real acontece no turno seguinte
 * (aprovado), deterministica, no controller.
 *
 * Provenance: blocos data-bearing ganham um id estavel (blk-…) + source (ultima
 * data-tool de leitura ok do turno) — habilita "salvar favorito" e o
 * show_dashboard por REFERENCIA (widgets {ref: blk-…}). O refresh deterministico
 * de filtros (DashboardRegistry/RenderSpecCompiler) fica para um slice posterior;
 * o dashboard ja renderiza por ref e por bloco inline.
 */
final class RenderTool implements Tool
{
    /** Tipos sem dado de origem — nao recebem id/source de proveniencia.
     *  Dashboard agrega N fontes (favorito e por widget) e gerencia o proprio id. */
    private const NON_DATA_TYPES = ['confirm', 'callout', 'badges', 'dashboard'];

    /** @param array<string, mixed> $schemaSpec */
    public function __construct(
        private string $toolName,
        private string $blockType,
        private string $desc,
        private array $schemaSpec,
        private SseSink $sink,
        private ?ConfirmCoordinator $confirms = null,
        private ?ProvenanceTracker $trace = null,
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return $this->desc;
    }

    public function schema(JsonSchema $schema): array
    {
        return JsonSchemaBuilder::properties($schema, $this->schemaSpec);
    }

    public function handle(Request $request): string
    {
        $input = $request->all();

        if ($this->blockType === 'confirm') {
            return $this->handleConfirm($input);
        }

        // Dashboard por REFERENCIA: widgets {ref: "blk-..."} apontam blocos ja
        // emitidos no turno — resolve para o bloco completo antes de validar.
        if ($this->blockType === 'dashboard') {
            $input = $this->resolveWidgetRefs($input);
            if (is_string($input)) {
                return $input; // erro p/ o modelo (refs invalidas)
            }
        }

        // Proveniencia OBRIGATORIA: bloco com dados (tabela, grafico, KPI…) so
        // sai se alguma consulta rodou neste turno. Sem isso o modelo "exibia"
        // OS, clientes e tecnicos que nao existem (dado inventado ao lado do real).
        $dataBlock = ! in_array($this->blockType, self::NON_DATA_TYPES, true);
        if ($dataBlock && $this->trace !== null && $this->trace->enforces() && ! $this->trace->hasData()) {
            error_log('[embed-render] ' . $this->toolName . ' recusado: nenhuma consulta no turno');

            return 'erro: nenhum dado foi consultado neste turno — um bloco com dados só pode mostrar o resultado de uma consulta. '
                . 'Consulte primeiro (db_schema + preview_widget, que já exibe o resultado ao usuário, ou uma tool de dados) '
                . 'e use SOMENTE os valores retornados. Nunca preencha com exemplos.';
        }

        // KPI com valor numérico cru + format: o SISTEMA formata (pt-BR).
        if ($this->blockType === 'kpis') {
            $input = self::formatKpiInput($input);
        }

        $block = BlockValidator::build($this->blockType, $input);
        if ($block === null) {
            // Diagnostico: o input que o modelo mandou (truncado) — sem isso a
            // rejeicao e invisivel (so o modelo ve o erro e re-tenta as cegas).
            error_log('[embed-render] input invalido p/ ' . $this->toolName . ': '
                . substr(json_encode($input, JSON_UNESCAPED_UNICODE) ?: '', 0, 2000));

            return 'erro: input invalido para ' . $this->toolName . ' — revise os campos obrigatorios.'
                . ($this->blockType === 'dashboard'
                    ? ' Lembre: widgets e um ARRAY de {span?, block}; cada block e uma STRING JSON {"type":"kpis",...} no formato da tool show_* correspondente.'
                    : '');
        }

        // Linhas/rotulos que nao batem com NADA do que as consultas do turno
        // devolveram = inventados. Maioria inventada derruba o bloco inteiro.
        if ($dataBlock && $this->trace !== null && $this->trace->enforces()) {
            [$checked, $unsourced] = $this->unsourcedEntries($block);
            if ($unsourced !== [] && count($unsourced) * 2 >= $checked) {
                $examples = implode('; ', array_map(
                    static fn (string $e): string => '"' . mb_substr($e, 0, 80) . '"',
                    array_slice($unsourced, 0, 3)
                ));
                error_log('[embed-render] ' . $this->toolName . ' recusado: ' . count($unsourced) . '/' . $checked . ' sem origem — ' . $examples);

                return 'erro: bloco recusado — ' . count($unsourced) . ' de ' . $checked . ' linha(s) não batem com nenhum dado '
                    . 'consultado neste turno (ex.: ' . $examples . '). Mostre SÓ valores que vieram das consultas, '
                    . 'copiados exatamente — nunca exemplos inventados. Se precisa de outro dado, consulte o banco primeiro '
                    . '(preview_widget já exibe o resultado da SQL).';
            }
        }

        // Proveniencia (favoritos): id estavel + fonte (ultima data-tool de
        // leitura ok do turno) em blocos data-bearing. validate.ts repassa.
        if ($dataBlock) {
            if (! isset($block['id'])) {
                $block['id'] = 'blk-' . substr(md5(uniqid('', true)), 0, 12);
            }
            $src = $this->trace?->lastSource();
            if ($src !== null) {
                $block['source'] = $src;
            }
            // habilita show_dashboard por ref (widgets {ref: blk-…})
            $this->trace?->recordBlock($block);
        }

        $this->sink->block($block);

        // O id volta ao modelo: e ele que permite referenciar o bloco num
        // show_dashboard posterior (widgets: [{ref: "<id>"}]).
        return isset($block['id']) ? 'rendered (id: ' . $block['id'] . ')' : 'rendered';
    }

    /**
     * Resolve widgets {ref} para o bloco completo emitido neste turno.
     * Devolve o input ajustado, ou uma STRING de erro para o modelo quando
     * ha refs e nenhuma resolve.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|string
     */
    private function resolveWidgetRefs(array $input)
    {
        $widgets = $input['widgets'] ?? null;
        if (is_string($widgets)) {
            $decoded = json_decode($widgets, true);
            $widgets = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($widgets)) {
            return $input;
        }

        $badRefs  = [];
        $resolved = [];
        foreach ($widgets as $w) {
            if (is_string($w)) {
                $decoded = json_decode($w, true);
                $w       = is_array($decoded) ? $decoded : $w;
            }
            $ref = is_array($w) ? ($w['ref'] ?? null) : null;
            if (is_string($ref) && $ref !== '') {
                $blk = $this->trace?->blockById($ref);
                if ($blk === null) {
                    $badRefs[] = $ref;
                    continue;
                }
                $w['block'] = $blk;
                unset($w['ref']);
            }
            $resolved[] = $w;
        }

        if ($resolved === [] && $badRefs !== []) {
            $ids = $this->trace?->blockIds() ?? [];

            return 'erro: nenhuma ref valida em show_dashboard (' . implode(', ', $badRefs) . ').'
                . ' Ids emitidos neste turno: ' . ($ids !== [] ? implode(', ', $ids) : '(nenhum)')
                . '. Emita os widgets com as tools show_* primeiro e use os ids retornados.';
        }

        $input['widgets'] = $resolved;

        return $input;
    }

    /**
     * KPI: `value` numérico (número ou string numérica) + `format` → texto
     * pt-BR pelo formatador do sistema. Sem format, número cru (int/float)
     * também vira pt-BR; string pré-formatada passa como veio.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function formatKpiInput(array $input): array
    {
        if (! is_array($input['items'] ?? null)) {
            return $input;
        }
        foreach ($input['items'] as $i => $it) {
            if (! is_array($it)) {
                continue;
            }
            $v    = $it['value'] ?? null;
            $kind = is_string($it['format'] ?? null) ? strtolower(trim((string) $it['format'])) : '';
            if ($kind !== '' && in_array($kind, WidgetDisplay::VALUE_FORMATS, true)) {
                $v = WidgetDisplay::formatValue($v, $kind);
            } elseif (is_int($v) || is_float($v)) {
                $v = WidgetDisplay::formatValue($v, 'number');
            }
            if (is_int($v) || is_float($v)) {
                $v = (string) $v;
            }
            $input['items'][$i]['value'] = $v;
            unset($input['items'][$i]['format']);
        }

        return $input;
    }

    /**
     * Entradas do bloco que carregam DADO de registro (linha de tabela, item
     * de lista/timeline/detalhe, rótulo de gráfico) e que não batem com nada
     * que as consultas do turno devolveram. Só texto é conferido (nome, código,
     * status…): número e data o modelo pode ter calculado/reformatado.
     *
     * @param array<string, mixed> $block
     * @return array{0: int, 1: list<string>} [entradas conferidas, exemplos sem origem]
     */
    private function unsourcedEntries(array $block): array
    {
        $entries = match ($this->blockType) {
            'table'    => array_map(
                static fn ($r) => is_array($r) ? array_map(static fn ($c) => is_array($c) ? ($c['label'] ?? null) : $c, array_values($r)) : [],
                (array) ($block['rows'] ?? [])
            ),
            'list'     => array_map(static fn ($i) => [$i['title'] ?? null, $i['sub'] ?? null, $i['right'] ?? null], (array) ($block['items'] ?? [])),
            'timeline' => array_map(static fn ($i) => [$i['title'] ?? null, $i['sub'] ?? null], (array) ($block['items'] ?? [])),
            'detail'   => array_map(static fn ($i) => [$i['value'] ?? null], (array) ($block['items'] ?? [])),
            'bar', 'donut', 'progress' => array_map(static fn ($d) => [$d['label'] ?? null], (array) ($block['data'] ?? $block['items'] ?? [])),
            'funnel'   => array_map(static fn ($d) => [$d['label'] ?? null], (array) ($block['steps'] ?? [])),
            default    => [],
        };

        $checked   = 0;
        $unsourced = [];
        foreach ($entries as $cells) {
            $texts = array_values(array_filter($cells, [self::class, 'isRecordText']));
            if ($texts === []) {
                continue; // só número/data: não dá pra provar nem negar
            }
            $checked++;
            $ok = false;
            foreach ($texts as $t) {
                if ($this->trace?->knows($t)) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok) {
                $unsourced[] = implode(' · ', array_slice($texts, 0, 2));
            }
        }

        return [$checked, $unsourced];
    }

    /** Texto de registro (tem letra; não é número, moeda, % nem data). */
    private static function isRecordText(mixed $v): bool
    {
        if (! is_string($v)) {
            return false;
        }
        $t = trim($v);
        if (mb_strlen($t) < 2 || ! preg_match('/\p{L}/u', $t)) {
            return false;
        }
        foreach (ProvenanceTracker::variants($t) as $variant) {
            if (! str_starts_with($variant, 's:')) {
                return false; // parseou como número/data
            }
        }

        return true;
    }

    /** @param array<string, mixed> $input */
    private function handleConfirm(array $input): string
    {
        $block = BlockValidator::build('confirm', $input);
        if ($block === null) {
            return 'erro: confirm_action exige "tool" e "title".';
        }

        // args (server-only): payload real da escrita. Aceita JSON string ou array.
        $args = [];
        $raw = $input['args'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $args = is_array($decoded) ? $decoded : [];
        } elseif (is_array($raw)) {
            $args = $raw;
        }

        if ($this->confirms !== null) {
            $this->confirms->stash((string) $block['id'], (string) $block['tool'], $args);
        }

        $this->sink->block($block);

        return 'confirm exibido ao usuario; NAO execute a escrita agora — aguarde a confirmacao.';
    }
}
