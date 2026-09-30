<?php

namespace Mad\Ai;

/**
 * ProvenanceTracker — rastreia, por turno, a ultima data-tool MCP de LEITURA
 * executada com sucesso, para anexar `source: {tool, args}` aos blocos emitidos
 * pelas render tools. E o elo do "salvar favorito": o front devolve {tool, args}
 * no save e o replay re-executa a tool deterministicamente, sem LLM.
 *
 * Tambem indexa os blocos emitidos no turno por id, habilitando o
 * show_dashboard por REFERENCIA (widgets {ref: "blk-..."}).
 *
 * Holder em memoria por request (padrao ConfirmCoordinator). Heuristica v1:
 * "ultima leitura ok antes da render call" — com multiplas fontes no mesmo
 * turno a atribuicao pode errar; limitacao documentada no contrato.
 */
final class ProvenanceTracker
{
    /** @var array{tool: string, args: array<string, mixed>}|null */
    private ?array $last = null;

    /** @var array<string, mixed>|null resultado (ja mascarado) da ultima leitura ok */
    private ?array $lastResult = null;

    /** @var list<array{tool: string, args: array<string, mixed>, result: array<string, mixed>|null}> todas as leituras ok do turno */
    private array $all = [];

    /**
     * Houve ALGUMA consulta ao banco neste turno (tool de dados do MCP ou SQL
     * de widget)? Bloco com dados sem nenhuma consulta = dado inventado.
     */
    private bool $hasData = false;

    /**
     * Valores (normalizados) que as consultas do turno devolveram — a prova de
     * origem do que um bloco mostra. Chave = variante normalizada (texto em
     * minúsculas, número canônico, data ISO), valor = true.
     *
     * @var array<string, true>
     */
    private array $known = [];

    /** Teto do índice de valores (proteção de memória em consulta grande). */
    private const MAX_KNOWN = 50000;

    /**
     * @param bool $enforce true = as render tools EXIGEM origem (bloco com dados
     *                      sem consulta no turno, ou com linhas que não batem com
     *                      nada consultado, é recusado). Ligado no chat do
     *                      Copilot (EmbedChatController); o Agent Console, cujas
     *                      system tools não registram leituras aqui, fica como era.
     */
    public function __construct(private bool $enforce = false)
    {
    }

    public function enforces(): bool
    {
        return $this->enforce;
    }

    /**
     * @param array<string, mixed>      $args
     * @param array<string, mixed>|null $result resultado da tool (memoria por request;
     *                                          permite compilar specs sem re-query)
     */
    public function recordData(string $tool, array $args, ?array $result = null): void
    {
        if ($tool === '') {
            return;
        }
        $this->last       = ['tool' => $tool, 'args' => $args];
        $this->lastResult = $result;
        $this->all[]      = ['tool' => $tool, 'args' => $args, 'result' => $result];
        $this->hasData    = true;
        if ($result !== null) {
            $this->index($result);
        }
    }

    /**
     * Linhas de uma consulta SQL do turno (preview_widget/save_widget). NÃO
     * vira `source` de favorito (não há tool MCP a re-executar) — só prova de
     * origem para os blocos que o modelo montar a partir delas.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function recordRows(array $rows): void
    {
        $this->hasData = true;
        $this->index($rows);
    }

    public function hasData(): bool
    {
        return $this->hasData;
    }

    /** O valor (ou uma forma equivalente dele) saiu de alguma consulta do turno? */
    public function knows(mixed $value): bool
    {
        foreach (self::variants($value) as $v) {
            if (isset($this->known[$v])) {
                return true;
            }
        }

        return false;
    }

    /** Indexa recursivamente os escalares de um resultado. */
    private function index(mixed $data, int $depth = 0): void
    {
        if ($depth > 6 || count($this->known) >= self::MAX_KNOWN) {
            return;
        }
        if (is_array($data)) {
            foreach ($data as $v) {
                $this->index($v, $depth + 1);
            }

            return;
        }
        foreach (self::variants($data) as $v) {
            $this->known[$v] = true;
        }
    }

    /**
     * Formas normalizadas de um valor — o modelo reformata o que mostra (data
     * ISO → dd/mm/aaaa, 473 → "R$ 473,00", "8.893,00"), e isso ainda é o MESMO
     * dado. Texto: minúsculo, espaços colapsados. Número: forma canônica.
     *
     * @return list<string>
     */
    public static function variants(mixed $value): array
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? ['n:' . self::canonicalNumber((float) $value)] : [];
        }
        if (! is_string($value)) {
            return [];
        }
        $t = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($t === '') {
            return [];
        }

        $out = ['s:' . mb_strtolower($t)];

        if (($n = self::parseNumber($t)) !== null) {
            $out[] = 'n:' . self::canonicalNumber($n);
        }

        // Data: ISO (Y-m-d[ H:i[:s]]) ou pt-BR (d/m/Y[ H:i]) → mesma chave
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/', $t, $m)) {
            $out[] = "d:{$m[1]}-{$m[2]}-{$m[3]}";
            if (isset($m[4])) {
                $out[] = "dt:{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}";
            }
        } elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})(?: (\d{2}):(\d{2}))?/', $t, $m)) {
            $out[] = "d:{$m[3]}-{$m[2]}-{$m[1]}";
            if (isset($m[4])) {
                $out[] = "dt:{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}";
            }
        }

        return $out;
    }

    /** Número de um texto cru ou formatado (pt-BR/en, com R$/%) — null se não for número. */
    private static function parseNumber(string $t): ?float
    {
        $s = trim(str_replace(['R$', '%', "\u{00a0}"], ['', '', ' '], $t));
        $s = str_replace(' ', '', $s);
        if ($s === '' || ! preg_match('/^[-+]?[\d.,]+$/', $s)) {
            return null;
        }
        if (is_numeric($s)) {
            return (float) $s;
        }
        // pt-BR: 1.234.567,89 · 8.893 · 473,00
        if (preg_match('/^[-+]?\d{1,3}(\.\d{3})+(,\d+)?$/', $s) || preg_match('/^[-+]?\d+,\d+$/', $s)) {
            return (float) str_replace(',', '.', str_replace('.', '', $s));
        }
        // en: 1,234,567.89
        if (preg_match('/^[-+]?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) {
            return (float) str_replace(',', '', $s);
        }

        return null;
    }

    private static function canonicalNumber(float $n): string
    {
        $r = round($n, 4);
        if ($r == 0.0) {
            return '0';
        }
        $s = rtrim(rtrim(number_format($r, 4, '.', ''), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }

    /** @return array{tool: string, args: array<string, mixed>}|null */
    public function lastSource(): ?array
    {
        return $this->last;
    }

    /** @return array<string, mixed>|null */
    public function lastResult(): ?array
    {
        return $this->lastResult;
    }

    /**
     * Todas as leituras ok do turno (ordem de execucao) — um dashboard pode
     * compor widgets de FONTES diferentes; o capture testa cada uma.
     *
     * @return list<array{tool: string, args: array<string, mixed>, result: array<string, mixed>|null}>
     */
    public function allData(): array
    {
        return $this->all;
    }

    /** @var array<string, array<string, mixed>> blocos emitidos no turno, por id */
    private array $blocks = [];

    /**
     * Registra um bloco emitido (com id/source ja anexados) — habilita o
     * show_dashboard por REFERENCIA: widgets {ref: blk-id} em vez do JSON
     * inteiro (modelo emite os widgets como tools normais, que aparecem na
     * hora, e a moldura so aponta).
     *
     * @param array<string, mixed> $block
     */
    public function recordBlock(array $block): void
    {
        $id = (string) ($block['id'] ?? '');
        if ($id !== '') {
            $this->blocks[$id] = $block;
        }
    }

    /** @return array<string, mixed>|null */
    public function blockById(string $id): ?array
    {
        return $this->blocks[$id] ?? null;
    }

    /** @return list<string> ids emitidos no turno (p/ mensagem de erro ao modelo) */
    public function blockIds(): array
    {
        return array_keys($this->blocks);
    }

    /**
     * Resultado da leitura correspondente a um source {tool, args} (match
     * exato) — permite compilar o spec do widget com a fonte CERTA.
     *
     * @param array{tool: string, args: array<string, mixed>} $source
     * @return array<string, mixed>|null
     */
    public function resultFor(array $source): ?array
    {
        foreach ($this->all as $d) {
            if ($d['tool'] === ($source['tool'] ?? null)
                && json_encode($d['args']) === json_encode($source['args'] ?? [])) {
                return $d['result'];
            }
        }

        return null;
    }
}
