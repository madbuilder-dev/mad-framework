<?php

namespace Mad\Ai;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * CodingPlanAgentRunner — o MESMO turno do AgentRunner, mas com o modelo
 * servido pelo Mad Coding Plan (MiniMax pela franquia do DONO) em vez do
 * laravel/ai com chave local.
 *
 * Arquitetura: o AGENT LOOP roda AQUI, no app do cliente — tools, system
 * prompt do manifest, histórico e render tools são os mesmos do modo
 * in-process. O MadBuilder entra só como API intermediária de LLM:
 *
 *   1. mint: POST {MAD_BUILDER_URL}/api/embed-llm/session (Bearer
 *      MAD_PROJECT_TOKEN) → embed JWT curto + endpoint do stream (Node).
 *      Cacheado por processo até perto do exp.
 *   2. por rodada: POST {stream} {messages, tools} (Bearer JWT) → SSE
 *      (text_delta / tool_use_start|delta / usage / error). O system viaja
 *      como mensagem role:system — o Node a converte no param `system`.
 *   3. cada tool_use é executado IN-PROCESS via $tool->handle() (o mesmo
 *      objeto Tool do AgentRunner: McpDataTool emite cards e aplica
 *      permissão/row-scope/confirm; render tools emitem blocos) e o
 *      resultado volta como tool_result na rodada seguinte.
 *
 * Fail-fast: mint/stream indisponível → sink->error (não cai em provider
 * local — este app não tem chave de IA).
 */
final class CodingPlanAgentRunner
{
    /** Fallback do teto de rodadas modelo↔tools por turno (config mad.ai.max_steps). */
    private const MAX_ROUNDS = 8;

    /** Teto efetivo: config do app (fluxos de construção de dashboard passam de 8). */
    private static function maxRounds(): int
    {
        return max(2, (int) (config('mad.ai.max_steps') ?: self::MAX_ROUNDS));
    }

    // ── Guards do harness (portados do agent loop do MadBuilder) ────────────

    /**
     * "Anunciou e parou": texto termina prometendo trabalho ("vou buscar…")
     * mas o turno veio SEM tool call — padrão de modelo fraco visto em
     * produção (MiniMax/GLM). Casa no FINAL do texto (últimos ~280 chars).
     */
    private const CONTINUE_INTENT_RE =
        '/\b(let me|let\'?s|i\'?ll|i will|i need to|now i|next|going to|deixa eu|vou|agora vou|em seguida|pr[óo]ximo passo|preciso)\b/iu';

    /**
     * "ainda"/"falta" só anunciam trabalho seguidos (em até duas palavras) de
     * verbo de ação: "falta criar o widget", "ainda vou ajustar". Soltos no
     * regex de cima casavam relato — "vale conferir se o serviço ainda não
     * foi lançado", "parada por falta de peça" — e o nudge de narração fazia
     * o modelo escrever ao usuário "Você está certo — fechei o turno errado".
     * Espelho do PENDING_REMAINDER_RE do agente do Studio (pendingWorkGuard).
     */
    private const PENDING_REMAINDER_RE =
        '/(?<!\p{L})(?:ainda|falta(?:m|ria|riam)?)\s+(?:(?!n[ãa]o(?!\p{L}))[\p{L}\p{N}_-]+\s+){0,2}'
        . '(?:vou|vamos|irei|preciso|tenho que|'
        . '(?!(?:similar|particular|popular|regular|singular|familiar|auxiliar|lugar|mulher|prazer|par|mar|bar)(?!\p{L}))\p{L}{2,}(?:ar|er|ir)(?:-(?:lo|la|los|las))?|'
        . '\p{L}{2,}(?:arei|erei|irei|aremos|eremos|iremos))(?!\p{L})/iu';

    /**
     * Bail seco no ÚLTIMO parágrafo ("não consigo continuar", "parando por
     * aqui", "vou verificar depois") — uma tentativa de desbloqueio antes de
     * aceitar o fim. Subconjunto do bailDetector do mad-agent.
     */
    private const BAIL_RES = [
        '/^(?:n[ãa]o (?:consigo|[ée] poss[íi]vel|foi poss[íi]vel)|i (?:can(?:\'?t|not)|am unable to)) (?:prosseguir|continuar|avan[çc]ar|completar|concluir|resolver|proceed|continue|complete|fix)\b/iu',
        '/^(?:desisto|desisti|vou desistir|giving up|a tarefa n[ãa]o [ée] acion[áa]vel)\b/iu',
        '/^(?:parando por aqui|paro por aqui|parei por aqui|stopping here)(?:\.|,|;|$| )/iu',
        '/^(?:vou|irei) (?:verificar|checar|conferir|olhar|voltar|retomar|tentar)(?: \S+){0,4} (?:mais tarde|depois|em breve|later)\b/iu',
    ];

    /**
     * Dialeto XML de tool-call que o MiniMax às vezes VAZA como texto em vez
     * do bloco nativo (visto em produção no mad-agent, 30/jul/2026). Tudo a
     * partir do primeiro marcador é scaffolding — nunca prosa.
     */
    private const LEAK_MARKERS = ['<tool_call>', '<invoke name=', '<invoke  name=', ']<]minimax[>', '<function_calls>'];

    // Os nudges NUNCA mandam "emitir show_* com o que você tem": em modelo
    // fraco isso virava bloco com dado INVENTADO (OS, clientes e técnicos que
    // não existem) ao lado do resultado real. O caminho é sempre consultar.
    private const NUDGE_NARRATED =
        'Você descreveu os próximos passos mas NÃO emitiu nenhuma tool call neste turno. NÃO narre — AJA: chame AGORA as tools de consulta (db_schema, preview_widget ou a tool de dados) para executar o que você anunciou. Só responda em texto puro quando a resposta estiver REALMENTE completa.';

    private const NUDGE_NARRATED_2 =
        'ÚLTIMA CHANCE — você narrou de novo em vez de executar. Responda SOMENTE com as tool calls de consulta, NENHUM texto. Se terminar sem tool calls, o turno encerra com a promessa não cumprida visível ao usuário.';

    private const NUDGE_EMPTY =
        'Você processou os tool_results mas não retornou texto nem novas tool calls. Responda AGORA ao pedido do usuário em 1-3 frases usando SOMENTE os valores que as consultas devolveram (sem inventar nada); se ainda falta dado, consulte-o antes.';

    private const NUDGE_BAIL =
        'Não desista nem adie: se algo bloqueou, diga CONCRETAMENTE o que falta e responda o que JÁ dá com os dados consultados — ou faça a consulta que falta (db_schema/preview_widget). Nunca preencha com dado inventado. Adiar sem entregar não é uma opção.';

    private const NUDGE_STUCK =
        'Você está repetindo a MESMA tool com os MESMOS argumentos. Pare de chamar tools e responda ao usuário com o que já tem; se o dado veio vazio, diga isso claramente.';

    private const NUDGE_LEAK =
        'Sua última resposta continha a sintaxe XML interna de tool call como TEXTO — isso não executa nada. Emita a tool call NATIVAMENTE (bloco tool_use), nunca como texto.';

    /**
     * Rodada "fantasma": o provedor cobrou saída que não chegou em bloco
     * nenhum. Medido no MiniMax-M3 (28/09/2026, lab do Copilot): depois de um
     * resultado de tool o modelo escreve o anúncio ("Agora listo as OS
     * atrasadas:") e gera a chamada da próxima tool (~300 tokens), mas a
     * resposta volta com `stop_reason: end_turn`, SÓ o bloco de texto e
     * `output_tokens` ~10× o texto — a chamada foi descartada pelo provedor.
     * Sem tool_use, o loop tratava como fim legítimo: turno mudo.
     */
    private const NUDGE_DROPPED =
        'Sua última resposta chegou incompleta: o que você gerou depois do texto (a chamada de ferramenta) se perdeu no caminho e NADA foi executado. Emita AGORA essa chamada como tool call nativa, sem repetir o texto que o usuário já viu. Se não precisava de ferramenta, responda ao usuário com o resultado usando só os dados já consultados.';

    /** Mensagem visível quando nem as novas tentativas trazem a chamada perdida. */
    private const DROPPED_GAVE_UP =
        'A resposta do modelo chegou incompleta e não consegui concluir esta consulta. Tente perguntar de novo.';

    /**
     * Saída não entregue a partir da qual a rodada é fantasma. Uma chamada de
     * tool perdida custa 200–450 tokens; o texto que chega é estimado com
     * folga (2 caracteres por token — português/inglês ficam em ~3,3), então
     * resposta legítima nunca sobra acima disto (medido: sobra ≤ 0).
     */
    private const DROPPED_MIN_TOKENS = 64;

    /** Novas tentativas por turno depois de uma rodada fantasma. */
    private const MAX_DROPPED_RETRIES = 2;

    /** Resultado de tool no contexto do modelo (o card do front mostra o cheio). */
    private const MAX_TOOL_RESULT_CHARS = 40000;

    /** @var array{token: string, endpoint: string, exp: int}|null cache por worker */
    private static ?array $session = null;

    /**
     * @param (\Closure(string $endpoint, string $token, string $body, \Closure(string): bool $feed): array{0: bool, 1: int, 2: string})|null $stream
     *        transporte da rodada (testes); null = cURL no stream do builder.
     *        Recebe os bytes do SSE por $feed (false = parar) e devolve [ok, status HTTP, erro].
     */
    public function __construct(private SseSink $sink, private ?\Closure $stream = null)
    {
    }

    /**
     * @param array<int, object> $history mensagens laravel/ai (UserMessage/AssistantMessage)
     * @param array<int, Tool>   $tools
     * @param string             $context contexto volátil do turno (data de hoje/fuso —
     *                                    SystemPromptAssembler::runtimeContext) — vai
     *                                    junto com a mensagem do usuário, não no system
     */
    public function run(string $system, array $history, array $tools, string $userMessage, string $context = ''): AgentResult
    {
        $sess = self::mintSession();
        if ($sess === null) {
            $this->sink->error('Mad Coding Plan indisponível (falha ao autenticar no builder).');

            return new AgentResult('', null, false);
        }

        $byName = [];
        $defs   = [];
        foreach ($tools as $tool) {
            $name          = ToolNameResolver::resolve($tool);
            $byName[$name] = $tool;

            $schema      = $tool->schema(new JsonSchemaTypeFactory());
            $inputSchema = ['type' => 'object', 'properties' => (object) []];
            if (filled($schema)) {
                $schemaArray = (new ObjectSchema($schema))->toSchema();
                $inputSchema['properties'] = (object) ($schemaArray['properties'] ?? []);
                $inputSchema['required']   = $schemaArray['required'] ?? [];
            }
            $defs[] = ['name' => $name, 'description' => (string) $tool->description(), 'input_schema' => $inputSchema];
        }

        $messages = self::buildMessages($system, $history, $userMessage, $context);

        $finalText = '';
        // Soma das rodadas; vira TextUsage (readonly no laravel/ai 1.0) no fim.
        $usage     = ['in' => 0, 'out' => 0, 'cache_read' => 0, 'cache_write' => 0];
        $aborted   = false;

        // Estado dos guards (um turno): nudges são one-shot/escalonados pra
        // nunca virarem loop de nudge (lição do mad-agent).
        $narrationNudges = 0;
        $emptyNudged     = false;
        $bailNudged      = false;
        $leakNudged      = false;
        $stuckNudged     = false;
        $droppedRetries  = 0;
        $lastSignature   = '';

        $maxRounds = self::maxRounds();
        for ($round = 0; $round < $maxRounds; $round++) {
            $leaked = false;
            $turn = $this->streamRound($sess, $messages, $defs, $usage, $aborted, $leaked);
            if ($turn === null) {
                // erro já emitido no sink
                return new AgentResult($finalText, null, $aborted);
            }
            [$text, $uses, $dropped] = $turn;
            $finalText .= $text;

            $content = [];
            if (trim($text) !== '') {
                $content[] = ['type' => 'text', 'text' => $text];
            }
            foreach ($uses as $u) {
                // input vazio TEM que serializar como {} (dict), nunca [] —
                // o wire anthropic (MiniMax incluso) rejeita lista com 400.
                $content[] = [
                    'type'  => 'tool_use',
                    'id'    => $u['id'],
                    'name'  => $u['name'],
                    'input' => $u['input'] === [] ? (object) [] : $u['input'],
                ];
            }
            if ($content !== []) {
                $messages[] = ['role' => 'assistant', 'content' => $content];
            }

            if ($aborted) {
                break;
            }

            if ($uses === []) {
                // Turno sem ação — decide entre fim legítimo e falha nudgeável.
                $nudge = null;
                if ($leaked && ! $leakNudged) {
                    $leakNudged = true;
                    $nudge = self::NUDGE_LEAK;
                } elseif ($dropped > 0 && $droppedRetries < self::MAX_DROPPED_RETRIES) {
                    // O provedor cobrou uma chamada que não entregou: não é fim
                    // de turno, é transmissão perdida — pede de novo, com o
                    // motivo certo (o texto do anúncio já está no histórico).
                    $droppedRetries++;
                    $nudge = self::NUDGE_DROPPED;
                } elseif (trim($text) === '' && ! $emptyNudged && $round > 0) {
                    $emptyNudged = true;
                    $nudge = self::NUDGE_EMPTY;
                } elseif (trim($text) !== '' && self::matchesBail($text) && ! $bailNudged) {
                    $bailNudged = true;
                    $nudge = self::NUDGE_BAIL;
                } elseif (
                    trim($text) !== ''
                    && $narrationNudges < 2
                    && self::announcesPendingWork($text)
                ) {
                    $nudge = $narrationNudges === 0 ? self::NUDGE_NARRATED : self::NUDGE_NARRATED_2;
                    $narrationNudges++;
                }

                if ($nudge !== null && $round < $maxRounds - 1) {
                    \error_log('[coding-plan] guard nudge r' . $round . ($dropped > 0 ? " ({$dropped} tokens não entregues)" : '') . ': ' . mb_substr($nudge, 0, 60));
                    $messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => $nudge]]];
                    continue;
                }
                if ($dropped > 0) {
                    // Nem as novas tentativas trouxeram a chamada: o turno
                    // acaba, mas nunca calado (o usuário ficava olhando o
                    // anúncio "agora listo…" sem saber que parou).
                    \error_log('[coding-plan] rodada sem entrega, tentativas esgotadas r' . $round . " ({$dropped} tokens)");
                    $this->sink->error(self::DROPPED_GAVE_UP);
                }
                break; // fim legítimo (ou nudges esgotados)
            }

            // Stuck-loop: mesma(s) tool(s) com os MESMOS args da rodada anterior.
            $signature = json_encode(array_map(fn ($u) => [$u['name'], $u['input']], $uses));
            if ($signature === $lastSignature && ! $stuckNudged && $round < $maxRounds - 1) {
                $stuckNudged = true;
                // Executa mesmo assim (resultados vão pro contexto) mas avisa.
                \error_log('[coding-plan] guard stuck-loop r' . $round);
            }
            $repeated = $signature === $lastSignature;
            $lastSignature = $signature;

            // ask_user encerra o turno por contrato: pergunta + chips já
            // emitidos; rodada extra só queimaria tokens (e o guard de turno
            // vazio acusaria falso-positivo).
            $askedUser = array_filter($uses, fn ($u) => $u['name'] === 'ask_user') !== [];

            // Executa cada tool IN-PROCESS. Os cards/blocos saem pelo próprio
            // handle() (McpDataTool→McpToolCaller->invoke(sink) / RenderTool).
            $results = [];
            foreach ($uses as $u) {
                $tool = $byName[$u['name']] ?? null;
                if ($tool === null) {
                    $out = 'Tool desconhecida: ' . $u['name'];
                } else {
                    try {
                        $out = (string) $tool->handle(new \Laravel\Ai\Tools\Request($u['input']));
                    } catch (\Throwable $e) {
                        \error_log('[coding-plan] tool ' . $u['name'] . ': ' . $e->getMessage());
                        $out = json_encode(['error' => 'Falha ao executar a ferramenta.']);
                    }
                }
                if (mb_strlen($out) > self::MAX_TOOL_RESULT_CHARS) {
                    $out = mb_substr($out, 0, self::MAX_TOOL_RESULT_CHARS) . '…(truncado)';
                }
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $u['id'], 'content' => $out];
            }
            if ($repeated && $stuckNudged) {
                $results[] = ['type' => 'text', 'text' => self::NUDGE_STUCK];
            }
            $messages[] = ['role' => 'user', 'content' => $results];

            if ($askedUser) {
                break; // pergunta + chips no ar; a resposta chega como turno novo
            }
        }

        $hasUsage = $usage['in'] > 0 || $usage['out'] > 0;
        // Nomeados: o construtor do TextUsage poe cache READ antes do WRITE.
        $total = new TextUsage(
            inputTokens: $usage['in'],
            outputTokens: $usage['out'],
            cacheReadInputTokens: $usage['cache_read'],
            cacheWriteInputTokens: $usage['cache_write'],
        );

        return new AgentResult($finalText, $hasUsage && ! $aborted ? $total : null, $aborted);
    }

    /**
     * Mensagens do turno no wire anthropic. O system vai como mensagem
     * role:system — o Node do builder a converte no param `system` (com prompt
     * caching). O contexto volátil (data de hoje) entra como um bloco de texto
     * ANTES da pergunta, na mensagem do usuário: o histórico persistido guarda
     * só a pergunta, e o prefixo cacheado (system) não muda a cada minuto.
     *
     * @param array<int, object> $history
     * @return list<array<string, mixed>>
     */
    public static function buildMessages(string $system, array $history, string $userMessage, string $context = ''): array
    {
        $messages = [];
        if (trim($system) !== '') {
            $messages[] = ['role' => 'system', 'content' => [['type' => 'text', 'text' => $system]]];
        }
        foreach ($history as $m) {
            $role = $m instanceof \Laravel\Ai\Messages\AssistantMessage ? 'assistant' : 'user';
            $text = (string) ($m->content ?? '');
            if ($text !== '') {
                $messages[] = ['role' => $role, 'content' => [['type' => 'text', 'text' => $text]]];
            }
        }

        $turn = [];
        if (trim($context) !== '') {
            $turn[] = ['type' => 'text', 'text' => $context];
        }
        $turn[]     = ['type' => 'text', 'text' => $userMessage];
        $messages[] = ['role' => 'user', 'content' => $turn];

        return $messages;
    }

    /** O fim do texto (~280 chars) promete trabalho que não veio? */
    public static function announcesPendingWork(string $text): bool
    {
        $tail = mb_substr($text, -280);

        return preg_match(self::CONTINUE_INTENT_RE, $tail) === 1
            || preg_match(self::PENDING_REMAINDER_RE, $tail) === 1;
    }

    /** Último parágrafo não-vazio casa algum padrão de bail? */
    private static function matchesBail(string $text): bool
    {
        $paras = array_values(array_filter(array_map('trim', preg_split('/\n{2,}|\n/', $text))));
        $last  = $paras !== [] ? end($paras) : '';
        if ($last === '') {
            return false;
        }
        foreach (self::BAIL_RES as $re) {
            if (preg_match($re, $last) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tokens de saída que o provedor cobrou numa rodada e não entregou em bloco
     * nenhum (0 = nada perdido). O entregue é estimado com folga (2 caracteres
     * por token) e a sobra só conta quando passa de DROPPED_MIN_TOKENS E é a
     * maior parte da saída — resposta longa com tabela/emoji nunca dispara.
     * Sem `completion_tokens` na rodada (stream antigo), não há como medir: 0.
     */
    public static function undeliveredTokens(?int $completionTokens, int $deliveredChars): int
    {
        if ($completionTokens === null || $completionTokens <= 0) {
            return 0;
        }
        $delivered = (int) ceil(max(0, $deliveredChars) / 2);
        $lost      = $completionTokens - $delivered;

        return $lost >= self::DROPPED_MIN_TOKENS && $completionTokens >= 2 * $delivered ? $lost : 0;
    }

    /**
     * Uma rodada modelo→stream. Emite text_delta no sink ao vivo; devolve
     * [textoAgregado, tool_uses[{id,name,input}], tokensNãoEntregues] ou null
     * em erro (já sinalizado).
     *
     * @param array{token: string, endpoint: string, exp: int} $sess
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $defs
     * @return array{0: string, 1: list<array{id: string, name: string, input: array<string, mixed>}>, 2: int}|null
     */
    private function streamRound(array $sess, array $messages, array $defs, array &$usage, bool &$aborted, bool &$leaked): ?array
    {
        $body = json_encode(
            ['messages' => $messages] + ($defs !== [] ? ['tools' => $defs] : []),
            JSON_UNESCAPED_UNICODE
        );

        $text    = '';
        $pending = [];   // id => {id, name, json}
        $order   = [];   // ordem de chegada dos tool_use
        $failed  = null;
        $buffer  = '';
        $sink    = $this->sink;

        $muted = false;

        // Contabilidade da rodada: saída cobrada × saída que chegou em bloco
        // (texto limpo, JSON de tool, raciocínio). Raciocínio cifrado não tem
        // tamanho — sem ele não dá para medir, então a rodada não é julgada.
        $roundOut       = null;
        $deliveredChars = 0;
        $unmeasurable   = false;

        $onFrame = function (array $ev) use (&$text, &$pending, &$order, &$failed, &$usage, $sink, &$aborted, &$leaked, &$muted, &$roundOut, &$deliveredChars, &$unmeasurable): void {
            $type = (string) ($ev['type'] ?? '');
            $d    = (array) ($ev['data'] ?? []);
            if ($type === 'text_delta') {
                $delta = (string) ($d['text'] ?? '');
                if (! $muted) {
                    // Scrub do dialeto XML de tool call vazado como texto
                    // (MiniMax): do primeiro marcador em diante é scaffolding,
                    // não prosa — corta o visível e sinaliza pro guard.
                    $cut = null;
                    foreach (self::LEAK_MARKERS as $marker) {
                        $pos = strpos($delta, $marker);
                        if ($pos !== false && ($cut === null || $pos < $cut)) {
                            $cut = $pos;
                        }
                    }
                    if ($cut !== null) {
                        $muted  = true;
                        $leaked = true;
                        $delta  = substr($delta, 0, $cut);
                        \error_log('[coding-plan] tool-call XML vazado como texto — stream mutado');
                    }
                    if ($delta !== '') {
                        $text .= $delta; // histórico fica com o texto LIMPO (pós-scrub)
                        $deliveredChars += mb_strlen($delta);
                        $sink->textDelta($delta);
                    }
                }
            } elseif ($type === 'tool_use_start') {
                $id           = (string) ($d['id'] ?? '');
                $pending[$id] = ['id' => $id, 'name' => (string) ($d['name'] ?? ''), 'json' => ''];
                $order[]      = $id;
            } elseif ($type === 'tool_use_delta') {
                $id = (string) ($d['id'] ?? '');
                if (isset($pending[$id])) {
                    $pending[$id]['json'] .= (string) ($d['input_json'] ?? '');
                    $deliveredChars       += strlen((string) ($d['input_json'] ?? ''));
                }
            } elseif ($type === 'thinking_block') {
                if (! empty($d['redacted'])) {
                    $unmeasurable = true;
                } else {
                    $deliveredChars += mb_strlen((string) ($d['thinking'] ?? ''));
                }
            } elseif ($type === 'usage') {
                $roundOut                      = ($roundOut ?? 0) + (int) ($d['completion_tokens'] ?? 0);
                // `prompt_tokens` do agente ja inclui o cache (mesma semantica do TextUsage).
                $usage['in']          += (int) ($d['prompt_tokens'] ?? 0);
                $usage['out']         += (int) ($d['completion_tokens'] ?? 0);
                $usage['cache_read']  += (int) ($d['cache_read_input_tokens'] ?? 0);
                $usage['cache_write'] += (int) ($d['cache_creation_input_tokens'] ?? 0);
            } elseif ($type === 'error') {
                $failed = (string) ($d['message'] ?? 'Erro no modelo de IA.');
            }
            if ($sink->aborted()) {
                $aborted = true;
            }
        };

        $feed = function (string $chunk) use (&$buffer, $onFrame, &$aborted): bool {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
                if (! str_starts_with($line, 'data: ') || $line === 'data: [DONE]') {
                    continue;
                }
                $ev = json_decode(substr($line, 6), true);
                if (is_array($ev)) {
                    $onFrame($ev);
                }
            }

            return ! $aborted; // false = aborta o transporte (cliente saiu)
        };

        [$okCurl, $status, $err] = $this->stream !== null
            ? ($this->stream)($sess['endpoint'], $sess['token'], (string) $body, $feed)
            : self::curlStream($sess['endpoint'], $sess['token'], (string) $body, $feed);

        if ($aborted) {
            return [$text, [], 0];
        }
        if ($failed !== null) {
            $this->sink->error($failed);

            return null;
        }
        if ($okCurl === false || $status >= 400) {
            self::$session = null; // JWT pode ter expirado — próximo turno re-minta
            $this->sink->error($status === 401
                ? 'Sessão do Mad Coding Plan expirou — tente novamente.'
                : ('Stream do Mad Coding Plan falhou' . ($err !== '' ? " ({$err})" : " (HTTP {$status})") . '.'));

            return null;
        }

        $uses = [];
        foreach ($order as $id) {
            $u = $pending[$id];
            if ($u['id'] === '' || $u['name'] === '') {
                continue;
            }
            $input  = json_decode($u['json'] !== '' ? $u['json'] : '{}', true);
            $uses[] = ['id' => $u['id'], 'name' => $u['name'], 'input' => is_array($input) ? $input : []];
        }

        // Só rodada SEM tool importa: com tool o loop segue de qualquer jeito.
        $dropped = $uses === [] && ! $unmeasurable ? self::undeliveredTokens($roundOut, $deliveredChars) : 0;

        return [$text, $uses, $dropped];
    }

    /**
     * Transporte padrão: POST no stream do builder (Node do embed) com os bytes
     * do SSE entregues a $feed conforme chegam.
     *
     * @param \Closure(string): bool $feed
     * @return array{0: bool, 1: int, 2: string} [ok, status HTTP, erro do cURL]
     */
    private static function curlStream(string $endpoint, string $token, string $body, \Closure $feed): array
    {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: text/event-stream',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 15,
            // Watchdog de stream PARADO (não só de duração): <1 byte/s por
            // 90s = upstream travou — aborta com erro limpo em vez de
            // segurar o turno os 300s inteiros ("ficou preso").
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME  => 90,
            CURLOPT_WRITEFUNCTION  => fn ($ch, string $chunk): int => $feed($chunk) ? strlen($chunk) : 0, // 0 = aborta o curl
        ]);
        $ok     = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        return [$ok !== false, $status, $err];
    }

    /**
     * Troca o MAD_PROJECT_TOKEN por um embed JWT curto no builder (cache por
     * worker até 60s antes do exp). Endpoint do stream: o que a nuvem devolver,
     * senão config mad.ai.embed_stream_url.
     *
     * @return array{token: string, endpoint: string, exp: int}|null
     */
    private static function mintSession(): ?array
    {
        if (self::$session !== null && self::$session['exp'] - time() > 60) {
            return self::$session;
        }

        $builderUrl   = rtrim((string) config('mad.builder.url'), '/');
        $projectToken = (string) config('mad.builder.token');
        if ($builderUrl === '' || $projectToken === '') {
            return null;
        }

        try {
            $resp = Http::withToken($projectToken)
                ->timeout(15)
                ->post($builderUrl . '/api/embed-llm/session', [
                    'end_user_id' => (string) (session('userid') ?: ''),
                ]);
        } catch (\Throwable $e) {
            \error_log('[coding-plan] mint: ' . $e->getMessage());

            return null;
        }
        if (! $resp->successful()) {
            \error_log('[coding-plan] mint HTTP ' . $resp->status() . ': ' . mb_substr((string) $resp->body(), 0, 200));

            return null;
        }

        $data  = (array) $resp->json();
        $token = (string) ($data['token'] ?? '');
        if ($token === '') {
            return null;
        }
        $endpoint = (string) ($data['agent_endpoint'] ?? '');
        if ($endpoint === '') {
            $endpoint = (string) config('mad.ai.embed_stream_url', '');
        }
        if ($endpoint === '') {
            return null;
        }

        return self::$session = [
            'token'    => $token,
            'endpoint' => $endpoint,
            'exp'      => time() + (int) ($data['expires_in'] ?? 900),
        ];
    }
}
