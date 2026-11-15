<?php

namespace Mad\Ai\Http;

use Illuminate\Http\Request;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Mad\Ai\AgentRunner;
use Mad\Ai\ChatDashboards;
use Mad\Ai\ChatStorage;
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
 * McpDataTool) → aprovação do usuário → execução aqui. O desfecho da
 * aprovação sai num evento `confirm_result {id, ok, message?}` antes do
 * message_end/error (fw#564).
 *
 * Dois bancos: o de NEGÓCIO (manifest) roda as tools do MCP e as SQL dos
 * widgets; o estado do chat (conversa, pendências, widgets/dashboards salvos)
 * fica na conexão do {@see ChatStorage}.
 *
 * F4: além das tools de dados (McpDataTool), o agente recebe as 15 render tools
 * (RenderToolRegistry: show_* + confirm_action) que emitem blocos visuais no SSE.
 *
 * Filtro do dashboard do chat: `dashboardAction {dashboardId, values}` (sem
 * message). Widgets de SQL/tool salvos no registro da conversa são reexecutados
 * aqui, sem o modelo; senão vira um turno sintético (ChatDashboards).
 *
 * O modelo do turno vem de um de três lugares — Coding Plan (franquia do
 * dono), proxy da plataforma (app sem chave de IA, MAD_AI_PROXY) ou provider
 * local —, mas o loop é sempre este: tools, permissão, histórico e
 * confirmação de escrita rodam AQUI, no app.
 */
final class EmbedChatController
{
    /** A pendência do cartão deste turno não foi guardada (CHAT_STORAGE_UNAVAILABLE). */
    private const STORAGE_SAVE_FAILED = 'Não consegui guardar este pedido de confirmação, então ele não pode ser confirmado. Nada foi alterado. Peça de novo em instantes; se o erro continuar, avise o administrador do sistema.';

    /** A conversa não pôde ser lida no turno da aprovação (CHAT_STORAGE_UNAVAILABLE). */
    private const STORAGE_READ_FAILED = 'Não consegui ler o pedido de confirmação guardado nesta conversa. Nada foi alterado. Peça de novo em instantes; se o erro continuar, avise o administrador do sistema.';

    public function __invoke(Request $request): StreamedResponse
    {
        // --- entradas (ANTES do stream — request já parseado) ---
        $bearer = trim((string) $request->bearerToken());
        $sessionKey = hash('sha256', $bearer !== '' ? $bearer : 'anon');

        $payload = self::readPayload($request);
        $message = trim((string) ($payload['message'] ?? ''));
        $confirm = is_array($payload['confirm'] ?? null) ? $payload['confirm'] : null;
        $dashAction = is_array($payload['dashboardAction'] ?? null) ? $payload['dashboardAction'] : null;
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
            $dashAction,
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
                $chatDb   = ChatStorage::connection($db);

                // Tools MCP in-process (já filtradas por permissão via
                // shouldRegister sob o McpCurrentUser corrente).
                $caller = $manifest !== null ? new McpToolCaller($manifest) : null;

                $store    = new ConversationStore($chatDb);
                $confirms = new ConfirmCoordinator();
                $userId   = $usageUserId !== null ? (int) $usageUserId : 0;

                $state      = $store->load($conversationId, $userId);
                $confirms->seed($state['pending']);
                $messages   = $state['messages'];
                $transcript = $state['transcript'];
                $dashboards = (array) ($state['dashboards'] ?? []);

                // Aprovação/cancelamento de escrita pendente: determinístico,
                // SEM chamar o modelo.
                if ($confirm !== null) {
                    // Depois da escrita aprovada, o modelo escreve o resumo a
                    // partir do retorno da acao (numero, total, link...) numa
                    // chamada SEM tools: nao reexecuta nem relê a tabela.
                    $summarize = static function (string $prompt, array $history) use (
                        $manifest, $caller, $sink, $db, $sessionKey,
                        $usageUserId, $usageTokenId, $usageTokenPrefix, $usageSystemSlug
                    ): ?string {
                        try {
                            (new \Mad\Usage\QuotaService())->assertWithinQuota(
                                $usageUserId,
                                array_map('intval', McpCurrentUser::groupIds()),
                                null,
                                $usageTokenId,
                                'embed_chat'
                            );
                        } catch (\Mad\Usage\QuotaExceededException) {
                            return null; // escrita ja feita: resumo deterministico
                        } catch (\Throwable $e) {
                            error_log('[mad-usage] quota check failed (fail-open): ' . $e->getMessage());
                        }

                        $system = SystemPromptAssembler::assemble(
                            $manifest,
                            $caller?->allowedTools() ?? [],
                            (bool) config('mad.ai.widgets_enabled', true),
                            array_keys($manifest?->forms() ?? []),
                            self::helpAvailable(),
                        );
                        $runner = match (true) {
                            (bool) config('mad.ai.coding_plan') => new CodingPlanAgentRunner($sink),
                            self::proxyEnabled()                => CodingPlanAgentRunner::viaProxy($sink),
                            default                             => new AgentRunner($sink),
                        };
                        // Falha do resumo (402, timeout, quadro de erro) NUNCA vira
                        // `error` no chat: a escrita já foi feita e o usuário
                        // pedia de novo, duplicando. Cai no resumo determinístico.
                        [$result, $swallowed] = $sink->withoutErrors(
                            static fn () => $runner->run($system, self::toMessages($history), [], $prompt, SystemPromptAssembler::runtimeContext())
                        );

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
                            'metadata'    => ['aborted' => $result->aborted, 'confirm_summary' => true],
                        ], $db);

                        if ($swallowed !== []) {
                            throw new \RuntimeException('resumo sem modelo: ' . $swallowed[0]);
                        }

                        return $result->aborted ? null : $result->text;
                    };

                    self::resolveConfirm($confirm, $confirms, $caller, $sink, $store, $conversationId, $userId, $messages, $transcript, $state['error'], $summarize);

                    return;
                }

                // Filtro/busca do dashboard do chat. Turno sem bolha do usuário.
                $hiddenTurn = false;
                if ($dashAction !== null && $message === '') {
                    $dashId = (string) ($dashAction['dashboardId'] ?? '');
                    $entry  = is_array($dashboards[$dashId] ?? null) ? $dashboards[$dashId] : null;
                    if ($entry === null) {
                        $sink->textDelta('Não encontrei este painel na conversa. Peça para montá-lo de novo.');
                        $sink->messageEnd();
                        $sink->done();

                        return;
                    }
                    $values = ChatDashboards::cleanValues((array) $entry['block'], $dashAction['values'] ?? []);
                    $fresh  = ChatDashboards::canRefreshLocally($entry, $values)
                        ? ChatDashboards::refresh($entry, $values, $db)
                        : null;
                    if ($fresh !== null) {
                        $sink->block($fresh);
                        $entry['block'] = $fresh;
                        ChatDashboards::replaceIn($transcript, $fresh);
                        $store->save(
                            $conversationId,
                            $userId,
                            $messages,
                            $confirms->all(),
                            null,
                            $transcript,
                            ChatDashboards::merge($dashboards, [$dashId => $entry]),
                        );
                        $sink->messageEnd();
                        $sink->done();

                        return;
                    }
                    $message    = ChatDashboards::refreshMessage($entry, $values);
                    $hiddenTurn = true;
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
                    $sink->error(self::quotaMessage($q), 'USAGE_QUOTA_REACHED', self::quotaResetAt($q));
                    $sink->done();

                    return; // não constrói tools, não chama o modelo
                } catch (\Throwable $e) {
                    error_log('[mad-usage] quota check failed (fail-open): ' . $e->getMessage());
                }

                // enforce: bloco com dados só com dado consultado NESTE turno (anti-invenção)
                $trace    = new ProvenanceTracker(enforce: true);
                // "Abrir no sistema": tabelas com tela de cadastro no manifesto
                $linkable = array_keys($manifest?->forms() ?? []);
                $tools    = self::buildTools($caller, $sink, $confirms, $manifest, $db, $trace, $linkable, $chatDb);
                $system   = SystemPromptAssembler::assemble(
                    $manifest,
                    $caller?->allowedTools() ?? [],
                    (bool) config('mad.ai.widgets_enabled', true),
                    $linkable,
                    self::helpAvailable(),
                );
                $history = self::toMessages($messages);

                // Data de hoje + fuso do app: vai junto com a mensagem (volátil —
                // fora do system cacheado e fora do histórico persistido).
                $context = SystemPromptAssembler::runtimeContext();

                // Coding Plan: mesmo loop, modelo servido pelo builder (MiniMax
                // da franquia do dono) — sem chave de IA local. Proxy (app sem
                // chave de IA): mesmo loop, modelo pela chave da plataforma, que
                // debita no developer. Antes o proxy repassava à plataforma só a
                // frase do usuário: sem system, tools, histórico nem confirmação.
                $runner = match (true) {
                    (bool) config('mad.ai.coding_plan') => new CodingPlanAgentRunner($sink),
                    self::proxyEnabled()                => CodingPlanAgentRunner::viaProxy($sink),
                    default                             => new AgentRunner($sink),
                };
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
                        'prompt'      => $result->usage->inputTokens,
                        'completion'  => $result->usage->outputTokens,
                        'total'       => $result->usage->inputTokens + $result->usage->outputTokens,
                        'cached'      => $result->usage->cacheReadInputTokens,
                        'cache_write' => $result->usage->cacheWriteInputTokens,
                    ]);
                }

                // persiste o turno (messages só-texto p/ o modelo; transcript rico p/ reabrir)
                $turnMsgs = [['role' => 'user', 'content' => $message]];
                if (trim($finalText) !== '') {
                    $turnMsgs[] = ['role' => 'assistant', 'content' => $finalText];
                }
                // Transcript como a tela mostra: refresh de dashboard sem bolha
                // do usuário, dashboard reemitido troca o antigo no lugar e os
                // widgets absorvidos pela moldura não se repetem soltos.
                $turnTrans = $hiddenTurn ? [] : [['role' => 'user', 'text' => $message]];
                $items     = ChatDashboards::fold($transcript, $sink->transcript());
                if ($items !== []) {
                    $turnTrans[] = ['role' => 'agent', 'items' => $items];
                }

                $saved = $store->save(
                    $conversationId,
                    $userId,
                    array_merge($messages, $turnMsgs),
                    $confirms->all(),
                    null,
                    array_merge($transcript, $turnTrans),
                    ChatDashboards::merge($dashboards, $trace->dashboards()),
                );

                // Cartão de confirmação deste turno que não foi guardado não
                // pode ficar na tela como se valesse: o Confirmar ia voltar
                // "expirada". Diz já o que houve (fw#564).
                if (! $saved && array_diff_key($confirms->all(), $state['pending']) !== []) {
                    $sink->error(self::STORAGE_SAVE_FAILED, 'CHAT_STORAGE_UNAVAILABLE');
                    $sink->done();

                    return;
                }

                $sink->messageEnd();
                $sink->done();
            } catch (\Mad\Ai\MadAiException $e) {
                // Sem IA (sem Mad Coding Plan, franquia acabou, nada configurado):
                // a mensagem já é para o usuário — nunca o "erro genérico".
                error_log('[embed-chat] ' . $e->codigo() . ': ' . $e->getMessage());
                if (! $sink->aborted()) {
                    $sink->error($e->getMessage(), $e->chatCode(), $e->resetAt());
                }
                $sink->done();
            } catch (\Throwable $e) {
                $detail = '';
                if ($e instanceof \Illuminate\Http\Client\RequestException) {
                    $detail = ' | provider body: ' . substr((string) $e->response->body(), 0, 2000);
                }
                error_log('[embed-chat] ' . $e->getMessage() . $detail);
                if (! $sink->aborted()) {
                    $sink->error('Desculpe, ocorreu um erro ao processar sua solicitação.');
                }
                $sink->done();
            }
        }, 200, self::sseHeaders());
    }

    /**
     * @return array<int, \Laravel\Ai\Contracts\Tool>
     */
    private static function buildTools(?McpToolCaller $caller, SseSink $sink, ConfirmCoordinator $confirms, ?McpManifest $manifest, string $db, ?ProvenanceTracker $trace = null, array $linkable = [], ?string $chatDb = null): array
    {
        $chatDb ??= ChatStorage::connection($db);

        // enforce: bloco com dados só com dado consultado NESTE turno (anti-invenção)
        $trace ??= new ProvenanceTracker(enforce: true);

        // $trace rastreia a última data-tool de leitura ok do turno — as render
        // tools anexam isso como `source` do bloco (salvar favorito), indexam os
        // blocos por id (show_dashboard por ref) e guardam o spec dos widgets
        // (filtro do dashboard sem IA).
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
        foreach (RenderToolRegistry::tools($sink, $confirms, $trace, $linkable) as $renderTool) {
            $tools[] = $renderTool;
        }

        // ask_user: pergunta com opções clicáveis (follow chips) quando
        // falta um parâmetro-chave — melhor perguntar do que chutar.
        $tools[] = new \Mad\Ai\Tools\AskUserTool($sink);

        // suggest_next: próximos passos como chips no FIM da resposta —
        // no lugar da pergunta em prosa enterrada no texto.
        $tools[] = new \Mad\Ai\Tools\SuggestNextTool($sink);

        // Manual do sistema (#227): help_search/help_get leem a Ajuda publicada
        // (resources/manual) para responder "como faço…?". Sem LLM e sem HTTP;
        // valem para todo usuário logado (o manual é o mesmo para todos), por
        // isso não passam pelo gate do MCP. App sem manual: não entram.
        if (self::helpAvailable()) {
            $tools[] = new \Mad\Ai\Tools\HelpSearchTool($sink);
            $tools[] = new \Mad\Ai\Tools\HelpGetTool($sink);
        }

        // Micro-BI: widgets salvos com SQL determinística (tela "Meus
        // Dashboards"). Independem do manifest (db_schema degrada pra
        // introspecção nativa quando ele falta). O preview/save devolvem ao
        // modelo a AMOSTRA das linhas reais (PII do manifest mascarada) e as
        // registram no $trace como prova de origem do turno.
        if ((bool) config('mad.ai.widgets_enabled', true)) {
            $widgetStore = new WidgetStore($chatDb);
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
            $tools[] = new \Mad\Ai\Tools\SaveDashboardTool($sink, $widgetStore, new \Mad\Ai\DashboardStore($chatDb));
        }

        return $tools;
    }

    /** Há manual do sistema publicado (as tools help_* entram)? Nunca lança. */
    private static function helpAvailable(): bool
    {
        try {
            return (new \Mad\Help\ManualRepository())->exists();
        } catch (\Throwable) {
            return false;
        }
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
     * escrever — nunca confiar só no approved do front. Aprovada, o desfecho
     * sai em `confirm_result` antes do message_end/error.
     *
     * $storageFailed = a conversa não pôde ser lida: pendência ausente aí não
     * é "expirada", é armazenamento fora do ar (CHAT_STORAGE_UNAVAILABLE).
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
        bool $storageFailed = false,
        ?\Closure $summarize = null,
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
            $sink->textDelta('Ação cancelada. Nada foi alterado.');
            $messages[] = ['role' => 'assistant', 'content' => 'Ação cancelada pelo usuário.'];
            $saveAll();
            $sink->messageEnd();
            $sink->done();

            return;
        }

        if ($pending === null || $caller === null) {
            [$text, $code] = $pending === null && $storageFailed
                ? [self::STORAGE_READ_FAILED, 'CHAT_STORAGE_UNAVAILABLE']
                : ['Esta confirmação expirou ou não vale mais. Peça a ação de novo.', 'CONFIRM_EXPIRED'];
            $sink->confirmResult($id, false, $text);
            $sink->error($text, $code);
            $saveAll();
            $sink->done();

            return;
        }

        $tool = $pending['tool'];
        // So os campos que o cartao declarou editaveis, no tipo declarado: o
        // cliente nao injeta argumento novo nem troca o registro alvo. Edicao
        // que nao entra na escrita (valor invalido, campo que nao e argumento
        // de topo) RECUSA a escrita: nunca grava o valor velho calado.
        [$args, $rejected] = ConfirmCoordinator::applyEdits($pending['args'], $pending['editable'] ?? [], $values);
        if ($rejected !== []) {
            $text = 'Não consegui aplicar o que você mudou em: ' . implode(', ', $rejected)
                . '. Nada foi gravado. Peça a ação de novo com o valor certo.';
            error_log('[mad-ai] confirm ' . $tool . ': edicao recusada em ' . implode(', ', $rejected) . ' — escrita nao executada');
            $sink->confirmResult($id, false, $text);
            $sink->textDelta($text);
            $messages[] = ['role' => 'assistant', 'content' => 'Ação ' . $tool . ' NÃO executada: edição do cartão inválida em ' . implode(', ', $rejected) . '.'];
            $saveAll();
            $sink->messageEnd();
            $sink->done();

            return;
        }

        // Re-gate: revalida no piso/matriz sob o McpCurrentUser DESTE request.
        $spec = $caller->spec($tool);
        if ($spec === null || ! (new McpPermissionResolver())->canUseTool($caller->manifest(), $spec)) {
            $sink->confirmResult($id, false, 'Você não tem permissão para executar esta ação.');
            $sink->error('Você não tem permissão para executar esta ação.');
            $saveAll();
            $sink->done();

            return;
        }

        // Execucao APROVADA no cartao: unico caminho em que uma escrita roda.
        $r = $caller->invoke($tool, $args, $sink, confirmed: true);

        if ($r['ok']) {
            $sink->confirmResult($id, true);

            // O retorno da acao entra no historico (o modelo do proximo turno
            // sabe numero/total sem reler a tabela) e vira o resumo ao usuario.
            // Acao custom devolve {ok, result: <retorno do metodo>}: o que
            // interessa ao usuario e o retorno do metodo.
            $payload = is_array($r['result']) && array_key_exists('result', $r['result']) && ($r['result']['ok'] ?? null) === true
                ? $r['result']['result']
                : $r['result'];
            $json   = self::resultJson($payload);
            $record = '[resultado da acao ' . $tool . '] ' . $json;
            $text   = null;
            $tried  = false;
            if ($summarize !== null) {
                $tried = true;
                try {
                    // Exceção do modelo também não vira `error`: a escrita valeu.
                    [$text] = $sink->withoutErrors(
                        static fn () => $summarize(self::summaryPrompt($tool, $json, (string) ($spec['description'] ?? '')), $messages)
                    );
                } catch (\Throwable $e) {
                    error_log('[embed-chat] resumo da acao ' . $tool . ' falhou (vai o deterministico): ' . $e->getMessage());
                    $text = null;
                }
            }
            if ($text === null || trim($text) === '') {
                $text = self::fallbackSummary($tool, $payload);
                // separa de um texto parcial que o modelo tenha soltado antes de falhar
                $sink->textDelta(($tried ? "\n\n" : '') . $text);
            }
            $messages[] = ['role' => 'assistant', 'content' => $record . "\n\n" . trim($text)];
        } else {
            $msg = is_array($r['result'])
                ? (string) ($r['result']['message'] ?? $r['result']['error'] ?? 'Falha desconhecida.')
                : 'Falha desconhecida.';
            $sink->confirmResult($id, false, 'Não foi possível executar a ação: ' . $msg);
            $sink->textDelta('Não foi possível executar a ação: ' . $msg);
            $messages[] = ['role' => 'assistant', 'content' => 'Falha ao executar ' . $tool . ': ' . $msg];
        }

        $saveAll();
        $sink->messageEnd();
        $sink->done();
    }

    /** Teto do retorno da acao guardado no historico / mandado ao modelo. */
    private const RESULT_MAX = 4000;

    /** Retorno da acao como JSON compacto (cortado no teto). */
    private static function resultJson(mixed $result): string
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $json = is_string($json) ? $json : 'null';

        return mb_strlen($json) > self::RESULT_MAX ? mb_substr($json, 0, self::RESULT_MAX) . '…' : $json;
    }

    /** Pedido ao modelo do resumo depois da escrita aprovada. */
    private static function summaryPrompt(string $tool, string $json, string $description): string
    {
        return 'O usuário APROVOU no cartão a ação "' . $tool . '" e ela foi executada com sucesso.'
            . ($description !== '' ? "\nDescrição da ação: " . $description : '')
            . "\nRetorno da ação (JSON): " . $json
            . "\n\nEscreva agora ao usuario, em poucas linhas, o resumo do que foi feito usando SO esse retorno"
            . ' (número, total, parcelas, link, o que a descrição da ação pedir). Não chame ferramentas,'
            . ' não consulte os dados de novo e não peça nova confirmação.';
    }

    /**
     * Resumo sem modelo (cota, sem IA, falha): sucesso + campos simples do
     * retorno com rótulo legível (`pedido_id` → "Pedido 12", `valor_total` →
     * "Valor total 40"). Sem formato por tabela: só o nome da chave.
     */
    private static function fallbackSummary(string $tool, mixed $result): string
    {
        $text = 'Ação executada com sucesso.';
        if (is_scalar($result) && ! is_bool($result) && (string) $result !== '') {
            return $text . ' Retorno: ' . mb_substr((string) $result, 0, 300);
        }
        if (! is_array($result)) {
            return $text;
        }
        $parts = [];
        foreach ($result as $k => $v) {
            if (is_string($k) && $k !== '' && (is_scalar($v) || $v === null) && count($parts) < 8) {
                $value   = is_bool($v) ? ($v ? 'sim' : 'não') : ($v === null ? '—' : mb_substr((string) $v, 0, 120));
                $parts[] = self::fieldLabel($k) . ' ' . $value;
            }
        }

        return $parts !== [] ? $text . ' ' . implode(' · ', $parts) . '.' : $text;
    }

    /** `pedido_id` → "Pedido", `valor_total` → "Valor total", `numeroNota` → "Numero nota". */
    private static function fieldLabel(string $key): string
    {
        $k = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key;
        $k = preg_replace('/^id_|_id$/i', '', strtolower($k)) ?? $k;
        $k = trim(str_replace(['_', '-'], ' ', $k));
        if ($k === '') {
            $k = $key;
        }

        return mb_strtoupper(mb_substr($k, 0, 1)) . mb_substr($k, 1);
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

    /**
     * Quando o chat volta (ISO 8601): a última janela estourada a zerar. null
     * quando uma delas é `total` (não zera sozinha) ou não dá para saber.
     */
    private static function quotaResetAt(\Mad\Usage\QuotaExceededException $q): ?string
    {
        $latest = null;
        foreach ($q->result()['periods'] ?? [] as $period => $info) {
            if (($info['action'] ?? '') !== 'block' || empty($info['over'])) {
                continue;
            }
            $at = \Mad\Usage\Period::nextReset((string) $period, $info['resetDay'] ?? null);
            if ($at === null) {
                return null;
            }
            $latest = max($latest ?? 0, $at);
        }

        return $latest !== null ? date('c', $latest) : null;
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
     * Proxy de IA ligado: app gerado SEM chave de IA (MAD_AI_PROXY) com o
     * vínculo do builder (MAD_BUILDER_URL + MAD_PROJECT_TOKEN). A chave de IA
     * fica SÓ no builder. O Coding Plan tem precedência (testado antes).
     */
    private static function proxyEnabled(): bool
    {
        return (bool) config('mad.ai.proxy')
            && (string) config('mad.builder.url') !== ''
            && (string) config('mad.builder.token') !== '';
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
