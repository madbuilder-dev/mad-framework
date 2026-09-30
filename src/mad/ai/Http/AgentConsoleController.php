<?php

namespace Mad\Ai\Http;

use Illuminate\Http\Request;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Mad\Ai\AgentRunner;
use Mad\Ai\ConfirmCoordinator;
use Mad\Ai\Console\AgentConsolePromptAssembler;
use Mad\Ai\Console\SystemToolExecutor;
use Mad\Ai\Console\SystemToolRegistry;
use Mad\Ai\Console\ToolAuditStore;
use Mad\Ai\ConversationStore;
use Mad\Ai\MadAi;
use Mad\Ai\ProvenanceTracker;
use Mad\Ai\RenderToolRegistry;
use Mad\Ai\SseSink;
use Mad\Ai\UsageLog;
use Mad\Mcp\McpCurrentUser;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AgentConsoleController — endpoint SSE do Mad Agent Command Center
 * (POST /agent-console/v1/chat). SEPARADO do EmbedChatController, mas reusa o
 * mesmo motor (AgentRunner/ConfirmCoordinator/ConversationStore/SseSink/MadAi).
 *
 * Diferenças do embed:
 *   - tools = SystemToolRegistry (operam o sistema) + RenderToolRegistry (visual);
 *     SEM tools de dados MCP.
 *   - resolveConfirm RE-CHECA admin e executa via SystemToolExecutor
 *     (backup→mutate→verify→rollback), nunca confiando no `approved` do front.
 *   - admin-only é imposto pelo AgentConsoleAdminMiddleware (rota) + re-check aqui.
 */
final class AgentConsoleController
{
    public function __invoke(Request $request): StreamedResponse
    {
        $bearer     = trim((string) $request->bearerToken());
        $sessionKey = hash('sha256', $bearer !== '' ? $bearer : 'anon');

        $payload        = self::readPayload($request);
        $message        = trim((string) ($payload['message'] ?? ''));
        $confirm        = is_array($payload['confirm'] ?? null) ? $payload['confirm'] : null;
        $conversationId = trim((string) ($payload['conversationId'] ?? '')) ?: $sessionKey;

        $usageUserId      = McpCurrentUser::id();
        $login            = (string) (session('login') ?: '');
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
            $login,
            $usageUserId,
            $usageTokenId,
            $usageTokenPrefix,
            $usageSystemSlug
        ): void {
            $sink = new SseSink();
            $sink->open();

            try {
                MadAi::boot();

                $db = (string) (config('mad.mcp.database') ?: config('mad.general.main_database', 'business'));

                $store    = new ConversationStore($db);
                $confirms = new ConfirmCoordinator();
                $audit    = new ToolAuditStore($db);
                $registry = new SystemToolRegistry();
                $userId   = $usageUserId !== null ? (int) $usageUserId : 0;

                $state      = $store->load($conversationId, $userId);
                $confirms->seed($state['pending']);
                $messages   = $state['messages'];
                $transcript = $state['transcript'];

                // Aprovação/cancelamento de ação pendente: determinístico, sem modelo.
                if ($confirm !== null) {
                    self::resolveConfirm(
                        $confirm,
                        $confirms,
                        new SystemToolExecutor($registry, $audit),
                        $sink,
                        $store,
                        $conversationId,
                        $userId,
                        $login,
                        $messages,
                        $transcript,
                    );

                    return;
                }

                if ($message === '') {
                    $sink->messageEnd();
                    $sink->done();

                    return;
                }

                // Cota (pre-call) — mesma política fail-open do embed.
                try {
                    (new \Mad\Usage\QuotaService())->assertWithinQuota(
                        $usageUserId,
                        array_map('intval', McpCurrentUser::groupIds()),
                        null,
                        $usageTokenId,
                        'agent_console'
                    );
                } catch (\Mad\Usage\QuotaExceededException $q) {
                    $sink->error('Limite de uso de IA atingido. Tente novamente mais tarde.');
                    $sink->done();

                    return;
                } catch (\Throwable $e) {
                    error_log('[mad-usage] quota check (fail-open): ' . $e->getMessage());
                }

                $tools = self::buildTools($registry, $sink, $confirms, $audit, $conversationId, $userId, $login);
                $system  = AgentConsolePromptAssembler::assemble($registry);
                $history = self::toMessages($messages);

                $result    = (new AgentRunner($sink))->run($system, $history, $tools, $message);
                $finalText = $result->text;

                UsageLog::record($result->usage, [
                    'userId'      => $usageUserId,
                    'tokenId'     => $usageTokenId,
                    'tokenPrefix' => $usageTokenPrefix,
                    'systemSlug'  => $usageSystemSlug,
                    'context'     => 'agent_console',
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
                error_log('[agent-console] ' . $e->getMessage());
                if (! $sink->aborted()) {
                    $sink->error('Desculpe, ocorreu um erro ao processar sua solicitacao.');
                }
                $sink->done();
            }
        }, 200, self::sseHeaders());
    }

    /**
     * Tools do loop: system tools (operam o sistema) + render tools (visual).
     *
     * @return array<int, \Laravel\Ai\Contracts\Tool>
     */
    private static function buildTools(
        SystemToolRegistry $registry,
        SseSink $sink,
        ConfirmCoordinator $confirms,
        ToolAuditStore $audit,
        string $conversationId,
        int $userId,
        string $login,
    ): array {
        $tools = $registry->systemTools($sink, $confirms, $audit, $conversationId, $userId, $login);

        $trace = new ProvenanceTracker();
        foreach (RenderToolRegistry::tools($sink, $confirms, $trace) as $renderTool) {
            $tools[] = $renderTool;
        }

        return $tools;
    }

    /**
     * Resolve uma confirmação: executa (aprovado) ou cancela. Determinístico, sem
     * modelo. RE-CHECA admin antes de qualquer mutação — nunca confiar no front.
     *
     * @param array<string, mixed>                       $confirm
     * @param list<array{role: string, content: string}> $messages
     * @param list<array<string, mixed>>                 $transcript
     */
    private static function resolveConfirm(
        array $confirm,
        ConfirmCoordinator $confirms,
        SystemToolExecutor $executor,
        SseSink $sink,
        ConversationStore $store,
        string $conversationId,
        int $userId,
        string $login,
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

        // Re-gate admin: só admin executa a ação. Defesa contra approved forjado.
        if ((string) session('login') !== 'admin') {
            $sink->error('Voce nao tem permissao para executar esta acao (admin requerido).');
            $saveAll();
            $sink->done();

            return;
        }

        if ($pending === null) {
            $sink->error('Confirmacao expirada ou invalida. Refaca a solicitacao.');
            $saveAll();
            $sink->done();

            return;
        }

        $tool = $pending['tool'];
        $args = array_merge($pending['args'], $values);

        $outcome = $executor->execute($tool, $args, $sink, $id);

        if ($outcome->ok) {
            $sink->textDelta('Acao "' . $tool . '" executada com sucesso. ' . $outcome->message);
            $messages[] = ['role' => 'assistant', 'content' => 'Acao ' . $tool . ' executada: ' . $outcome->message];
        } else {
            $sink->textDelta('Nao foi possivel executar a acao: ' . $outcome->message);
            $messages[] = ['role' => 'assistant', 'content' => 'Falha ao executar ' . $tool . ': ' . $outcome->message];
        }

        $saveAll();
        $sink->messageEnd();
        $sink->done();
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

    /** @return array<string, string> */
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
