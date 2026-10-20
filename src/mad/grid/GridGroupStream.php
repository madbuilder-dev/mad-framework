<?php
namespace Mad\Grid;

/**
 * A quebra (`group-by`), o saldo acumulado (`running`) e a linha de detalhe
 * (`row-detail`) de um relatório, linha a linha — sem precisar do resultado
 * inteiro na memória.
 *
 * Hoje a grade carrega tudo e roda, sobre o conjunto, `_resolveGroupLabels()`,
 * `_applyRunning()`, `_applyRowDetail()` e `_flattenGroups()`. Este motor faz o
 * mesmo em fluxo: recebe as linhas em lotes (janelas), na ordem de exibição, e
 * devolve os MESMOS itens do `_flattenGroups()` — `group` (cabeçalho, com
 * rótulo, contagem e totais do grupo inteiro), `row` e `group-total` (rodapé).
 * O que precisa atravessar as janelas (grupo aberto, saldo de cada coluna,
 * contador de linhas, rótulos já vistos) sai em `state()` e volta pelo
 * construtor na janela seguinte.
 *
 * Contagem e totais de cada grupo vêm de um GridGroupTotals calculado antes,
 * numa passada do próprio motor em modo de coleta (`collect()`): o cabeçalho
 * precisa deles antes de as linhas do grupo chegarem.
 *
 * Pré-condição: cada grupo chega CONTÍGUO (a consulta ordena pelas chaves da
 * quebra antes de tudo). A coleta confere: grupo que reaparece depois de
 * fechado deixa `contiguous()` falso e quem chama volta à carga inteira.
 *
 * Máscara, delta e saldo inicial são funções de quem chama (o MadDataGrid passa
 * os `_renderMask/_runningDelta/_runningStart` dele): uma fonte só, inclusive
 * para a subclasse que sobrescreve.
 */
final class GridGroupStream
{
    /** @var GridColumn[] */
    private array $columns;
    /** @var GridColumn[] colunas `running`, na ordem das colunas */
    private array $running = [];
    /** @var string[] */
    private array $fields;
    /** @var string[] */
    private array $masks;
    private bool $groupTotal;
    private string $groupTotalLabel;
    private string $rowDetail;
    private \Closure $mask;
    private \Closure $delta;
    private \Closure $start;
    private ?GridGroupTotals $map;
    private ?GridGroupTotals $collecting = null;
    private bool $keepLastRow = false;

    // ── Estado que atravessa as janelas ──────────────────────────────────
    /** @var array<int, array{value:string, label:string, totalLabel:?string}> nível => grupo aberto */
    private array $open = [];
    /** @var array<string, string> `campo:valor` => rótulo (cache do _resolveGroupLabels) */
    private array $labels = [];
    /** @var array<string, array{acc:?float, key:?string}> fieldKey => saldo corrente */
    private array $acc = [];
    private int $delivered = 0;
    private ?array $lastRow = null;
    /** @var array<string, true> grupos já fechados (só na coleta, para a contiguidade) */
    private array $closed = [];
    private bool $contiguous = true;

    /**
     * @param GridColumn[] $columns   as colunas efetivas da grade
     * @param string[]     $fields    campos de quebra já normalizados (_groupFields)
     * @param string[]     $masks     máscara por nível (_groupMasks)
     * @param \Closure     $mask      fn(string $mask, array $row): string
     * @param \Closure     $delta     fn(GridColumn $col, array $row): float
     * @param \Closure     $start     fn(GridColumn $col, array $row): float
     * @param GridGroupTotals|null $map contagem e totais (null só na coleta)
     * @param array        $state     o `state()` da janela anterior
     */
    public function __construct(
        array $columns,
        array $fields,
        array $masks,
        bool $groupTotal,
        string $groupTotalLabel,
        string $rowDetail,
        \Closure $mask,
        \Closure $delta,
        \Closure $start,
        ?GridGroupTotals $map = null,
        array $state = []
    ) {
        $this->columns = array_values(array_filter($columns, fn ($c) => $c instanceof GridColumn));
        foreach ($this->columns as $col) {
            if ($col->isRunning()) {
                $this->running[] = $col;
            }
            if ($col->hasTransform) {
                $this->keepLastRow = true;
            }
        }
        $this->fields          = array_values(array_map('strval', $fields));
        $this->masks           = array_values($masks);
        $this->groupTotal      = $groupTotal;
        $this->groupTotalLabel = $groupTotalLabel;
        $this->rowDetail       = $rowDetail;
        $this->mask            = $mask;
        $this->delta           = $delta;
        $this->start           = $start;
        $this->map             = $map;

        $this->open      = $state['open'] ?? [];
        $this->labels    = $state['labels'] ?? [];
        $this->acc       = $state['acc'] ?? [];
        $this->delivered = (int) ($state['delivered'] ?? 0);
        $this->lastRow   = $state['lastRow'] ?? null;
    }

    /**
     * Modo de coleta: em vez de itens, acumula contagem e totais por grupo no
     * mapa devolvido. As linhas passam pelo mesmo saldo acumulado da tela, então
     * o "último saldo" de cada grupo sai exato.
     */
    public function collect(): GridGroupTotals
    {
        return $this->collecting ??= new GridGroupTotals($this->columns);
    }

    /** Todos os grupos chegaram inteiros e em sequência? (só faz sentido na coleta) */
    public function contiguous(): bool
    {
        return $this->contiguous;
    }

    /** Linhas já processadas, somando as janelas anteriores. */
    public function delivered(): int
    {
        return $this->delivered;
    }

    /**
     * O próximo lote de linhas (normalizadas, com a fórmula aplicada), na ordem
     * de exibição. Devolve os itens no formato do `_flattenGroups()`; na coleta,
     * [] (o resultado fica no `collect()`).
     *
     * @param array<int, array> $rows
     * @return array<int, array>
     */
    public function push(array $rows): array
    {
        $items = [];
        $depth = count($this->fields);

        foreach ($rows as $row) {
            // Chave de cada nível e o rótulo dos grupos que abrem nesta linha:
            // os rótulos leem a linha ANTES do saldo, como o _resolveGroupLabels
            // de hoje (que roda antes do _postProcessRows).
            $keys = [];
            foreach ($this->fields as $i => $field) {
                $keys[$i] = (string) (GridRenderHelpers::rowValue($row, $field) ?? '');
            }
            $changed = $this->firstChangedLevel($keys);
            $opening = [];
            if ($changed !== null) {
                $items = array_merge($items, $this->closeFrom($changed));
                for ($lvl = $changed; $lvl < $depth; $lvl++) {
                    // Na coleta o rótulo não é usado: só a identidade do grupo.
                    $opening[$lvl] = $this->collecting === null ? $this->labelFor($lvl, $keys[$lvl], $row) : '';
                }
            }

            $row = $this->applyRunning($row, $keys);
            if ($this->rowDetail !== '' && $this->collecting === null) {
                $row['__detail'] = ($this->mask)($this->rowDetail, $row);
            }

            foreach ($opening as $lvl => $label) {
                $path = array_slice($keys, 0, $lvl + 1);
                $this->open[$lvl] = [
                    'value'      => $keys[$lvl],
                    'label'      => $label,
                    // Rótulo do subtotal: contra a 1ª linha do grupo já com o
                    // saldo — no _flattenGroups ele lê `$groupRows[0]` de $_rows.
                    'totalLabel' => $this->groupTotalLabel !== '' && $this->collecting === null
                        ? ($this->mask)(str_replace('{group}', $label, $this->groupTotalLabel), $row)
                        : null,
                ];
                if ($this->collecting !== null) {
                    if (isset($this->closed[GridGroupTotals::pathKey($path)])) {
                        $this->contiguous = false;
                    }
                    continue;
                }
                $items[] = [
                    'type'   => 'group',
                    'level'  => $lvl,
                    'label'  => $label,
                    'field'  => $this->fields[$lvl],
                    'value'  => $keys[$lvl],
                    'count'  => $this->map?->count($path) ?? 0,
                    'totals' => $this->groupTotal && $this->map !== null ? $this->map->totals($path) : [],
                ];
            }

            $this->delivered++;
            if ($this->collecting !== null) {
                $this->collecting->add($keys, $row);
            } else {
                $items[] = ['type' => 'row', 'level' => $depth, 'data' => $row];
            }
            if ($this->keepLastRow) {
                $last = $row;
                unset($last['__record']);
                $this->lastRow = $last;
            }
        }

        return $items;
    }

    /**
     * Fim dos dados: fecha os grupos abertos (rodapés de subtotal).
     *
     * @return array<int, array>
     */
    public function finish(): array
    {
        return $this->closeFrom(0);
    }

    /** O que a próxima janela precisa para continuar de onde esta parou. */
    public function state(): array
    {
        return array_filter([
            'open'      => $this->open,
            'labels'    => $this->labels,
            'acc'       => $this->acc,
            'delivered' => $this->delivered,
            'lastRow'   => $this->lastRow,
        ], fn ($v) => $v !== [] && $v !== null && $v !== 0);
    }

    /** A linha anterior (o `$lastRow` dos transforms de coluna), entre janelas. */
    public function lastRow(): ?array
    {
        return $this->lastRow;
    }

    /** Nível mais raso cuja chave mudou (ou que ainda não estava aberto); null = mesmo grupo. */
    private function firstChangedLevel(array $keys): ?int
    {
        foreach ($keys as $lvl => $key) {
            if (!isset($this->open[$lvl]) || $this->open[$lvl]['value'] !== $key) {
                return $lvl;
            }
        }

        return null;
    }

    /**
     * Fecha os níveis >= $level, do mais fundo para o mais raso — a ordem em que
     * o `_flattenGroups()` emite os rodapés.
     *
     * @return array<int, array>
     */
    private function closeFrom(int $level): array
    {
        $items = [];
        $levels = array_keys($this->open);
        rsort($levels);
        foreach ($levels as $lvl) {
            if ($lvl < $level) {
                break;
            }
            $g  = $this->open[$lvl];
            $gp = [];
            for ($i = 0; $i <= $lvl; $i++) {
                $gp[] = $this->open[$i]['value'] ?? '';
            }
            unset($this->open[$lvl]);
            if ($this->collecting !== null) {
                $this->closed[GridGroupTotals::pathKey($gp)] = true;
                continue;
            }
            if (!$this->groupTotal) {
                continue;
            }
            $total = [
                'type'   => 'group-total',
                'level'  => $lvl,
                'label'  => $g['label'],
                'totals' => $this->map !== null ? $this->map->totals($gp) : [],
            ];
            if ($g['totalLabel'] !== null) {
                $total['totalLabel'] = $g['totalLabel'];
            }
            $items[] = $total;
        }

        return $items;
    }

    /**
     * Rótulo do grupo, com a regra do `_flattenGroups()` + `_resolveGroupLabels()`:
     * máscara com `{…}` resolvida contra a 1ª linha que tem aquele valor naquele
     * campo (cache por `campo:valor`, não por caminho); máscara sem chaves troca
     * os tokens pelo valor; sem máscara, o rótulo padrão do grão.
     */
    private function labelFor(int $level, string $value, array $row): string
    {
        $field = $this->fields[$level];
        $mask  = (string) ($this->masks[$level] ?? '');
        if ($mask !== '' && strpos($mask, '{') !== false) {
            $cacheKey = $field . ':' . $value;
            if (!isset($this->labels[$cacheKey])) {
                $label = ($this->mask)($mask, $row);
                // Nível 0 nunca reaparece (grupos contíguos): o rótulo vive no
                // grupo aberto e não precisa ficar no estado.
                if ($level === 0) {
                    return $label;
                }
                $this->labels[$cacheKey] = $label;
            }

            return $this->labels[$cacheKey];
        }

        $shown = GridRenderHelpers::groupDefaultLabel($field, $value);

        return $mask !== '' ? str_replace(GridRenderHelpers::groupMaskTokens($field), $shown, $mask) : $shown;
    }

    /**
     * O `_applyRunning()` de hoje, uma linha por vez: delta idempotente
     * (`__run_src_*`), recomeça no saldo inicial quando a chave muda até o nível
     * do `running-reset`, acumula. O saldo corrente fica no estado — a 1ª linha
     * de cada janela continua o saldo da anterior.
     */
    private function applyRunning(array $row, array $keys): array
    {
        foreach ($this->running as $col) {
            $srcKey = '__run_src_' . $col->fieldKey;
            if (array_key_exists($srcKey, $row)) {
                $delta = (float) $row[$srcKey];
            } else {
                $delta        = (float) ($this->delta)($col, $row);
                $row[$srcKey] = $delta;
            }

            $level = self::resetLevel($col->runningReset);
            if ($level === null || $this->fields === []) {
                $level = null;   // sem quebra não há o que zerar
            }
            $gkey = $level === null ? '' : implode("\x1F", array_slice($keys, 0, min($level, count($keys) - 1) + 1));

            $st = $this->acc[$col->fieldKey] ?? ['acc' => null, 'key' => null];
            if ($st['acc'] === null || $gkey !== $st['key']) {
                $st = ['acc' => (float) ($this->start)($col, $row), 'key' => $gkey];
            }
            $st['acc'] += $delta;
            $this->acc[$col->fieldKey] = $st;
            $row[$col->field] = $st['acc'];
        }

        return $row;
    }

    /**
     * Nível de quebra em que o saldo recomeça — o `_runningResetLevel()` de hoje.
     * '' / 'none' = nunca · 'group' = nível 0 · 'group:N' = nível N.
     */
    public static function resetLevel(string $reset): ?int
    {
        $reset = strtolower(trim($reset));
        if ($reset === '' || $reset === 'none') return null;
        if ($reset === 'group') return 0;
        if (preg_match('/^group:(\d+)$/', $reset, $m)) return (int) $m[1];

        return null;
    }
}
