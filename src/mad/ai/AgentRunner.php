<?php

namespace Mad\Ai;

use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Streaming\Events\Error as AiError;
use Laravel\Ai\Streaming\Events\TextDelta;

/**
 * AgentRunner — roda um turno do agente e mapeia o stream do laravel/ai para o
 * contrato SSE do iframe.
 *
 * O laravel/ai roda o loop de tools internamente (gateway): a cada tool_use ele
 * chama tool->handle(). As nossas tools ja emitem block / tool_use_end como
 * efeito colateral. Aqui so iteramos os eventos e proxyamos:
 *   - TextDelta → sink->textDelta  (fonte do text_delta, 1:1)
 *   - Error     → sink->error
 *   - aborto do cliente → para a iteracao (corta custo de token)
 *
 * Retorna um AgentResult {text, usage, aborted}: o texto agregado (para o
 * historico), o consumo de tokens (StreamableAgentResponse->usage, combinado de
 * todas as iteracoes do tool-loop) e se houve abort. O usage so e lido apos a
 * iteracao completar — em abort fica null.
 */
final class AgentRunner
{
    public function __construct(private SseSink $sink)
    {
    }

    /**
     * @param array<int, object> $history  mensagens (UserMessage/AssistantMessage)
     * @param array<int, \Laravel\Ai\Contracts\Tool> $tools
     * @param string $context contexto volátil do turno (data de hoje/fuso) — vai
     *                        antes da pergunta nesta mensagem; o histórico
     *                        persistido guarda só a pergunta
     */
    public function run(string $system, array $history, array $tools, string $userMessage, string $context = ''): AgentResult
    {
        $agent   = new EmbedAgent($system, $history, $tools);
        $text    = '';
        $sink    = $this->sink;
        $aborted = false;

        AiCallCost::reset(); // custo do turno = soma das chamadas a partir daqui

        $prompt   = trim($context) !== '' ? $context . "\n\n" . $userMessage : $userMessage;
        $response = $agent->stream($prompt, provider: MadAi::provider(), model: MadAi::model());

        $errored = false;
        try {
            $response->each(function ($event) use (&$text, &$aborted, &$errored, $sink): bool {
                if ($sink->aborted()) {
                    $aborted = true;

                    return false; // interrompe a iteracao (corta custo de token)
                }

                if ($event instanceof TextDelta) {
                    $text .= $event->delta;
                    $sink->textDelta($event->delta);
                } elseif ($event instanceof AiError) {
                    $errored = true;
                    $sink->error((string) ($event->message ?? 'Erro no modelo de IA.'));
                }

                return true;
            });
        } catch (StreamErrorException $e) {
            // laravel/ai 1.0: depois do evento Error o passo termina em excecao.
            // O erro ja foi pro iframe pelo evento; deixar subir fazia o
            // controller somar um 2o erro generico e descartar o turno.
            if (! $errored) {
                $sink->error($e->getMessage() !== '' ? $e->getMessage() : 'Erro no modelo de IA.');
            }

            return new AgentResult($text, null, $aborted);
        }

        // ->usage so e populado quando a iteracao completa (getIterator combina o
        // usage de todos os StreamEnd). Em abort a iteracao para e a propriedade
        // tipada fica nao-inicializada — o ?? null e seguro (semantica isset).
        $usage = $aborted ? null : ($response->usage ?? null);

        return new AgentResult($text, $usage, $aborted);
    }
}
