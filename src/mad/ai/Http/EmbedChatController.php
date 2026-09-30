<?php

namespace Mad\Ai\Http;

use Illuminate\Http\Request;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Mad\Ai\AgentRunner;
use Mad\Ai\CodingPlanAgentRunner;
use Mad\Ai\ConfirmCoordinator;
use Mad\Ai\ConversationStore;
use Mad\Ai\MadAi;
use Mad\Ai\McpToolCaller;
use Mad\Ai\ProvenanceTracker;
use Mad\Ai\RenderToolRegistry;
use Mad\Ai\SseSink;
use Mad\Ai\SystemPromptAssembler;
use Mad\Ai\Tools\DbSchemaTool;
use Mad\Ai\Tools\ListWidgetsTool;
use Mad\Ai\Tools\McpDataTool;
use Mad\Ai\Tools\WidgetUpsertTool;
use Mad\Ai\UsageLog;
use Mad\Ai\WidgetStore;
use Mad\Mcp\McpCurrentUser;
use Mad\Mcp\McpManifest;
use Mad\Mcp\McpManifestLoader;
use Mad\Mcp\McpPermissionResolver;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * EmbedChatController — endpoint SSE do agente embed (POST /embed/v1/chat).
 *
 * A auth (McpManifestAuthMiddleware) já resolveu o Bearer → McpCurrentUser.
 * Aqui: lê {message, confirm, conversationId}; se veio confirm:{approved},
 * executa a escrita PENDENTE de forma determinística (sem chamar o modelo),
 * re-validando a permissão no piso/matriz; senão roda o agente (laravel/ai)
 * com as tools MCP in-process (McpDataTool) e emite o contrato SSE do iframe
 * (text_delta/tool_use_start|end/block/message_end/error/usage + [DONE]).
 *
 * O loop do agente NUNCA escreve: writes viram pendência (self-gate da
 * McpDataTool) → aprovação do usuário → execução aqui.
 *
 * F4: além das tools de dados (McpDataTool), o agente recebe as 15 render tools
 * (RenderToolRegistry: show_* + confirm_action) que emitem blocos visuais no SSE.
 * O refresh DETERMINÍSTICO de filtros de dashboard (sem LLM) fica para um slice
 * posterior; o dashboard já renderiza por ref e por bloco inline.
 */
final class EmbedChatController
{
    public function __invoke(Request $request): StreamedResponse
    {
        // Proxy de IA: app gerado SEM chave de IA → encaminha as mensagens pro
        // builder (Bearer MAD_PROJECT_TOKEN), que chama o modelo + debita no
        // developer e faz stream. A chave de IA fica SÓ no builder. Ligado por
        // config('mad.ai.proxy') (env MAD_AI_PROXY) + MAD_BUILDER_URL/TOKEN.
        // Coding Plan tem precedência e NÃO usa o proxy: o loop roda AQUI
        // (ramo local abaixo, CodingPlanAgentRunner) com o builder só de LLM.
        if (! (bool) config('mad.ai.coding_plan')
            && (bool) config('mad.ai.proxy')
            && (string) config('mad.builder.url') !== ''
            && (string) config('mad.builder.token') !== ''
        ) {
            return self::proxyToBuilder($request);
        }

        // --- entradas (ANTES do stream — request já parseado) ---
        $bearer = trim((string) $request->bearerToken());
        $sessionKey = hash('sha256', $bearer !== '' ? $bearer : 'anon');

        $payload = self::readPayload($request);
        $message = trim((string) ($payload['message'] ?? ''));
        $confirm = is_array($payload['confirm'] ?? null) ? $payload['confirm'] : null;
        $conversationId = trim((string) ($payload['conversationId'] ?? '')) ?: $sessionKey;

        // contexto de medição (best-effort)
        $usageUserId      = McpCurrentUser::id();
        $usageTokenId     = null;
        $usageTokenPrefix = null;
        $usageSystemSlug  = null;
        try {
            if ($bearer !== '') {
                $tok = \App\Models\Mcp\Token::where('token_hash', hash('sha256', $bearer))
                    ->where('active', '1')
                    ->first();
                if ($tok) {
                    $usageTokenId     = (int) $tok->id;
                    $usageTokenPrefix = (string) $tok->token_prefix;
                    $usageSystemSlug  = (string) $tok->app_slug;
                }
            }
        } catch (\Throwable) {
        }

        return new StreamedResponse(function () use (
            $sessionKey,
            $conversationId,
            $message,
            $confirm,
            $usageUserId,
            $usageTokenId,
            $usageTokenPrefix,
            $usageSystemSlug
        ): void {
            $sink = new SseSink();
            $sink->open();

            try {
                MadAi::boot();

                $manifest = self::manifest();
                $db       = $manifest?->database()
                    ?? (string) (config('mad.mcp.database') ?: config('mad.general.main_database', 'business'));

                // Tools MCP in-process (já filtradas por permissão via
                // shouldRegister sob o McpCurrentUser corrente).
                $caller = $manifest !== null ? new McpToolCaller($manifest) : null;

                $store    = new ConversationStore($db);
                $confirms = new ConfirmCoordinator();
                $userId   = $usageUserId !== null ? (int) $usageUserId : 0;

                $state      = $store->load($conversationId, $userId);
                $confirms->seed($state['pending']);
                $messages   = $state['messages'];
                $transcript = $state['transcript'];

                // Aprovação/cancelamento de escrita pendente: determinístico,
                // SEM chamar o modelo.
                if ($confirm !== null) {
                    self::resolveConfirm($confirm, $confirms, $caller, $sink, $store, $conversationId, $userId, $messages, $transcript);

                    return;
                }

                if ($message === '') {
                    $sink->messageEnd();
                    $sink->done();

                    return;
                }

                // --- enforcement de cota (pre-call) ---
                // Bloqueia somente quem JÁ passou do teto (o custo desta chamada
                // é desconhecido antes do modelo rodar). FAIL-OPEN: erro de infra
                // do serviço de cota não bloqueia o chat — só
                // QuotaExceededException barra.
                try {
                    (new \Mad\Usage\QuotaService())->assertWithinQuota(
                        $usageUserId,
                        array_map('intval', McpCurrentUser::groupIds()),
                        null,
                        $usageTokenId,
                        'embed_chat'
                    );
                } catch (\Mad\Usage\QuotaExceededException $q) {
                    $sink->error(self::quotaMessage($q));
                    $sink->done();

                    return; // não constrói tools, não chama o modelo
                } catch (\Throwable $e) {
                    error_log('[mad-usage] quota check failed (fail-open): ' . $e->getMessage());
                }

                $tools  = self::buildTools($caller, $sink, $confirms, $manifest, $db);
                $system = SystemPromptAssembler::assemble(
                    $manifest,
                    $caller?->allowedTools() ?? [],
                    (bool) config('mad.ai.widgets_enabled', true),
                );
                $history = self::toMessages($messages);

                // Data de hoje + fuso do app: vai junto com a mensagem (volátil —
                // fora do system cacheado e fora do histórico persistido).
                $context = SystemPromptAssembler::runtimeContext();

                // Coding Plan: mesmo loop, modelo servido pelo builder (MiniMax
                // da franquia do dono) — sem chave de IA local.
                $runner = (bool) config('mad.ai.coding_plan')
                    ? new CodingPlanAgentRunner($sink)
                    : new AgentRunner($sink);
                $result    = $runner->run($system, $history, $tools, $message, $context);
                $finalText = $result->text;

                UsageLog::record($result->usage, [
                    'userId'      => $usageUserId,
                    'tokenId'     => $usageTokenId,
                    'tokenPrefix' => $usageTokenPrefix,
                    'systemSlug'  => $usageSystemSlug,
                    'context'     => 'embed_chat',
                    'provider'    => MadAi::provider(),
                    'model'       => MadAi::model(),
                    'requestId'   => $sessionKey,
                    'status'      => $result->aborted ? 'aborted' : ($result->usage ? 'ok' : 'no_usage'),
                    'metadata'    => ['aborted' => $result->aborted],
                ], $db);

                if ($result->usage !== null && ! $sink->aborted()) {
                    $sink->usage([
                        'prompt'      => $result->usage->promptTokens,
                        'completion'  => $result->usage->completionTokens,
                        'total'       => $result->usage->promptTokens + $result->usage->completionTokens,
                        'cached'      => $result->usage->cacheReadInputTokens,
                        'cache_write' => $result->usage->cacheWriteInputTokens,
                    ]);
                }

                // persiste o turno (messages só-texto p/ o modelo; transcript rico p/ reabrir)
                $turnMsgs = [['role' => 'user', 'content' => $message]];
                if (trim($finalText) !== '') {
                    $turnMsgs[] = ['role' => 'assistant', 'content' => $finalText];
                }
                $turnTrans   = [['role' => 'user', 'text' => $message]];
                $turnTrans[] = ['role' => 'agent', 'items' => $sink->transcript()];

                $store->save(
                    $conversationId,
                    $userId,
                    array_merge($messages, $turnMsgs),
                    $confirms->all(),
                    null,
                    array_merge($transcript, $turnTrans),
                );

                $sink->messageEnd();
                $sink->done();
            } catch (\Throwable $e) {
                $detail = '';
                if ($e instanceof \Illuminate\Http\Client\RequestException) {
                    $detail = ' | provider body: ' . substr((string) $e->response->body(), 0, 2000);
                }
                error_log('[embed-chat] ' . $e->getMessage() . $detail);
                if (! $sink->aborted()) {
                    $sink->error('Desculpe, ocorreu um erro ao processar sua solicitacao.');
                }
                $sink->done();
            }
        }, 200, self::sseHeaders());
    }

    /**
     * @return array<int, \Laravel\Ai\Contracts\Tool>
     */
    private static function buildTools(?McpToolCaller $caller, SseSink $sink, ConfirmCoordinator $confirms, ?McpManifest $manifest, string $db): array
    {
        // Rastreia a última data-tool de leitura ok do turno — as render tools
        // anexam isso como `source` do bloco (salvar favorito) e indexam os
        // blocos por id (show_dashboard por ref).
        // enforce: bloco com dados só com dado consultado NESTE turno (anti-invenção)
        $trace = new ProvenanceTracker(enforce: true);

        $tools = [];

        if ($caller !== null) {
            foreach ($caller->allowedTools() as $name => $mcpTool) {
                $tools[] = new McpDataTool(
                    $mcpTool,
                    $name,
                    $caller->isWrite($name),
                    $caller->isDestructive($name),
                    $caller,
                    $sink,
                    $confirms,
                    $trace,
                );
            }
        }

        // Render tools (F4): show_* + confirm_action — emitem os blocos visuais
        // (table/bar/kpis/…) que o front renderiza. Sem elas o modelo despejava
        // os dados como markdown cru no texto. Independem do manifest (antes, app
        // sem manifesto perdia TODAS as tools de visualização); a proveniência
        // ($trace) barra bloco com dado que nenhuma consulta do turno devolveu.
        foreach (RenderToolRegistry::tools($sink, $confirms, $trace) as $renderTool) {
            $tools[] = $renderTool;
        }

        // ask_user: pergunta com opções clicáveis (follow chips) quando
        // falta um parâmetro-chave — melhor perguntar do que chutar.
        $tools[] = new \Mad\Ai\Tools\AskUserTool($sink);

        // suggest_next: próximos passos como chips no FIM da resposta —
        // no lugar da pergunta em prosa enterrada no texto.
        $tools[] = new \Mad\Ai\Tools\SuggestNextTool($sink);

        // Micro-BI: widgets salvos com SQL determinística (tela "Meus
        // Dashboards"). Independem do manifest (db_schema degrada pra
        // introspecção nativa quando ele falta). O preview/save devolvem ao
        // modelo a AMOSTRA das linhas reais (PII do manifest mascarada) e as
        // registram no $trace como prova de origem do turno.
        if ((bool) config('mad.ai.widgets_enabled', true)) {
            $widgetStore = new WidgetStore($db);
            $pii         = self::piiColumns($manifest);
            $tools[] = new DbSchemaTool($db, $manifest, $sink);
            // query_db: conferir/explorar SEM virar bloco na conversa
            $tools[] = new \Mad\Ai\Tools\DbQueryTool($db, $sink, $trace, $pii);
            $tools[] = new WidgetUpsertTool(false, $db, $sink, $widgetStore, $trace, $pii);
            $tools[] = new WidgetUpsertTool(true, $db, $sink, $widgetStore, $trace, $pii);
            $tools[] = new ListWidgetsTool($widgetStore);
            // save_dashboard: monta o dashboard persistente da tela "Meus
            // Dashboards" a partir dos widgets salvos — o usuário ajusta
            // visualmente depois (editor de grid).
            $tools[] = new \Mad\Ai\Tools\SaveDashboardTool($sink, $widgetStore, new \Mad\Ai\DashboardStore($db));
        }

        return $tools;
    }

    /**
     * Nomes das colunas marcadas pii no manifest (todas as tabelas) — a amostra
     * de linhas que o preview/save_widget devolve ao modelo sai mascarada nelas,
     * como no retorno das tools do MCP.
     *
     * @return list<string>
     */
    private static function piiColumns(?McpManifest $manifest): array
    {
        if ($manifest === null) {
            return [];
        }
        $out = [];
        foreach (array_keys((array) ($manifest->toArray()['fields'] ?? [])) as $table) {
            foreach ($manifest->piiColumnsFor((string) $table) as $col) {
                $out[$col] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Resolve uma confirmação: executa (aprovado) ou cancela. Determinístico,
     * sem chamar o modelo. RE-VALIDA a permissão no piso/matriz antes de
     * escrever — nunca confiar só no approved do front.
     *
     * @param array<string, mixed>                       $confirm
     * @param list<array{role: string, content: string}> $messages
     * @param list<array<string, mixed>>                 $transcript
     */
    private static function resolveConfirm(
        array $confirm,
        ConfirmCoordinator $confirms,
        ?McpToolCaller $caller,
        SseSink $sink,
        ConversationStore $store,
        string $conversationId,
        int $userId,
        array $messages,
        array $transcript,
    ): void {
        $saveAll = static function () use (&$messages, &$transcript, $store, $confirms, $conversationId, $userId, $sink): void {
            $items = $sink->transcript();
            if ($items !== []) {
                $transcript[] = ['role' => 'agent', 'items' => $items];
            }
            $store->save($conversationId, $userId, $messages, $confirms->all(), null, $transcript);
        };

        $id       = (string) ($confirm['id'] ?? '');
        $approved = ($confirm['approved'] ?? false) === true;
        $values   = is_array($confirm['values'] ?? null) ? $confirm['values'] : [];

        $pending = $id !== '' ? $confirms->resolve($id) : null;

        if (! $approved) {
            $sink->textDelta('Acao cancelada. Nada foi alterado.');
            $messages[] = ['role' => 'assistant', 'content' => 'Acao cancelada pelo usuario.'];
            $saveAll();
            $sink->messageEnd();
            $sink->done();

            return;
        }

        if ($pending === null || $caller === null) {
            $sink->error('Confirmacao expirada ou invalida. Refaca a solicitacao.');
            $saveAll();
            $sink->done();

            return;
        }

        $tool = $pending['tool'];
        $args = array_merge($pending['args'], $values);

        // Re-gate: revalida no piso/matriz sob o McpCurrentUser DESTE request.
        $spec = $caller->spec($tool);
        if ($spec === null || ! (new McpPermissionResolver())->canUseTool($caller->manifest(), $spec)) {
            $sink->error('Voce nao tem permissao para executar esta acao.');
            $saveAll();
            $sink->done();

            return;
        }

        $r = $caller->invoke($tool, $args, $sink);

        if ($r['ok']) {
            $sink->textDelta('Acao "' . $tool . '" executada com sucesso.');
            $messages[] = ['role' => 'assistant', 'content' => 'Acao ' . $tool . ' executada com sucesso.'];
        } else {
            $msg = is_array($r['result'])
                ? (string) ($r['result']['message'] ?? $r['result']['error'] ?? 'Falha desconhecida.')
                : 'Falha desconhecida.';
            $sink->textDelta('Nao foi possivel executar a acao: ' . $msg);
            $messages[] = ['role' => 'assistant', 'content' => 'Falha ao executar ' . $tool . ': ' . $msg];
        }

        $saveAll();
        $sink->messageEnd();
        $sink->done();
    }

    /** Mensagem amigável de limite atingido a partir do resultado do check(). */
    private static function quotaMessage(\Mad\Usage\QuotaExceededException $q): string
    {
        $periods = $q->result()['periods'] ?? [];
        $labels  = ['day' => 'diario', 'month' => 'mensal', 'total' => 'total'];

        foreach ($periods as $period => $info) {
            if (($info['action'] ?? '') === 'block' && ! empty($info['over'])) {
                $label = $labels[$period] ?? $period;

                return sprintf(
                    'Limite %s de uso de IA atingido (%s/%s tokens). Tente novamente mais tarde.',
                    $label,
                    number_format((int) ($info['used'] ?? 0), 0, ',', '.'),
                    number_format((int) ($info['max'] ?? 0), 0, ',', '.')
                );
            }
        }

        return 'Limite de uso de IA atingido. Tente novamente mais tarde.';
    }

    /** Manifest MCP é opcional no slice (chat funciona sem). */
    private static function manifest(): ?McpManifest
    {
        try {
            return McpManifestLoader::load();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<array{role: string, content: string}> $stored
     * @return array<int, object>
     */
    private static function toMessages(array $stored): array
    {
        $out = [];
        foreach ($stored as $m) {
            $content = (string) ($m['content'] ?? '');
            if ($content === '') {
                continue;
            }
            $out[] = ((string) ($m['role'] ?? 'user')) === 'assistant'
                ? new AssistantMessage($content)
                : new UserMessage($content);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function readPayload(Request $request): array
    {
        try {
            $json = $request->json()->all();
            if (is_array($json) && $json !== []) {
                return $json;
            }
        } catch (\Throwable) {
            // not JSON
        }

        return $request->all();
    }

    /**
     * Proxy de IA: encaminha a mensagem do embed pro builder e faz passthrough
     * do stream. O app NÃO lê chave de IA — só o token MadBuilder; o builder
     * autentica, chama o modelo (chave dele) e debita no developer.
     */
    private static function proxyToBuilder(Request $request): StreamedResponse
    {
        $url     = rtrim((string) config('mad.builder.url'), '/') . '/api/app/ai/chat';
        $token   = (string) config('mad.builder.token');
        $payload = self::readPayload($request);
        $message = trim((string) ($payload['message'] ?? ''));

        return new StreamedResponse(function () use ($url, $token, $message): void {
            try {
                $resp = \Illuminate\Support\Facades\Http::withToken($token)
                    ->withOptions(['stream' => true])
                    ->timeout(120)
                    ->post($url, ['messages' => [['role' => 'user', 'content' => $message]]]);

                // Upstream negou (403 de plano, 401, 5xx, ...): o corpo é JSON de
                // erro, não SSE. Fazer passthrough cru mandaria esse JSON pro
                // browser como se fosse um frame SSE — o widget não reconhece e a
                // mensagem do usuário "some". Traduz pro mesmo frame event:error
                // do catch abaixo (o embed já sabe renderizar).
                if (! $resp->successful()) {
                    $raw = (string) $resp->body();
                    $decoded = json_decode($raw, true);
                    $msg = is_array($decoded)
                        ? (string) ($decoded['error'] ?? $decoded['message'] ?? 'Recurso indisponível no seu plano')
                        : ($raw !== '' ? $raw : 'Recurso indisponível no seu plano');
                    echo 'event: error' . "\n" . 'data: ' . json_encode(['message' => $msg]) . "\n\n";
                    @flush();
                    return;
                }

                $body = $resp->toPsrResponse()->getBody();
                while (! $body->eof()) {
                    echo $body->read(8192);
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    @flush();
                }
            } catch (\Throwable $e) {
                echo 'event: error' . "\n" . 'data: ' . json_encode(['message' => $e->getMessage()]) . "\n\n";
                @flush();
            }
        }, 200, self::sseHeaders());
    }

    /** @return array<string, string> CORS sai nas rotas (iframe cross-origin). */
    private static function sseHeaders(): array
    {
        return [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }
}
