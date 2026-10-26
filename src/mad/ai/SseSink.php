<?php

namespace Mad\Ai;

/**
 * SseSink — emissor do contrato SSE do iframe (envelope {type, data}).
 *
 * Cada frame: `data: {json}\n\n`. Terminador: `data: [DONE]\n\n`. Heartbeat:
 * `: ping\n\n`. Da flush a cada emit. Marca abort quando o cliente desconecta
 * (connection_aborted) para o loop poder parar e nao gastar tokens.
 *
 * Tipos de evento (LiveTransport.ts): text_delta, tool_use_end, block,
 * message_end, error, usage (opcional — contagem de tokens do turno),
 * confirm_result (desfecho do cartão de confirmação, no turno da aprovação).
 */
final class SseSink
{
    private bool $aborted = false;

    /**
     * Transcript do turno NA ORDEM emitida — itens {k:'text'|'tool'|'block'}.
     * Persistido em mad_ai_conversation.transcript_json para o historico
     * reabrir fiel (textos segmentados + tools + blocos), nao so o texto.
     *
     * @var list<array<string, mixed>>
     */
    private array $transcript = [];

    private string $textBuf = '';

    /** Drena buffers de saida abertos (init.php/runtime legado) — chamar no inicio do stream. */
    public function open(): void
    {
        // Em testes o PHPUnit/streamedContent gerencia os buffers — drenar
        // aqui mataria o buffer do próprio harness.
        if (function_exists('app') && app()->runningUnitTests()) {
            return;
        }

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
    }

    public function emit(string $type, array $data): void
    {
        if ($this->aborted) {
            return;
        }

        echo 'data: ' . json_encode(['type' => $type, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        $this->flush();

        if (connection_aborted() === 1) {
            $this->aborted = true;
        }
    }

    public function textDelta(string $text): void
    {
        if ($text !== '') {
            $this->textBuf .= $text;
            $this->emit('text_delta', ['text' => $text]);
        }
    }

    /** Card "executando…" no tool-inspector — emitido ANTES da tool rodar
     *  (o tool_use_end com o resultado substitui o card no front). */
    public function toolUseStart(string $tool, array $params, ?string $label = null): void
    {
        $this->emit('tool_use_start', array_filter([
            'tool'    => $tool,
            'label'   => $label,
            'params'  => $params,
            'running' => true,
        ], static fn ($v) => $v !== null));
    }

    /**
     * UM card no tool-inspector por chamada MCP. `$label` = nome legível da
     * tool do manifest (cartão "Consultando setores · 3 registros").
     */
    public function toolUseEnd(string $tool, array $params, int $ms, mixed $result, bool $ok = true, ?string $label = null): void
    {
        $this->flushText();
        // Resultado NAO vai pro transcript (rows inteiras inflariam a conversa)
        // — so o total, p/ o card do historico mostrar "N registros".
        $total = is_array($result) && isset($result['total']) && is_numeric($result['total'])
            ? (int) $result['total'] : null;
        $this->transcript[] = array_filter(
            ['k' => 'tool', 'tool' => $tool, 'label' => $label, 'params' => $params, 'ms' => $ms, 'ok' => $ok, 'total' => $total],
            static fn ($v) => $v !== null
        );

        $this->emit('tool_use_end', array_filter([
            'tool'   => $tool,
            'label'  => $label,
            'params' => $params,
            'ms'     => $ms,
            'result' => $result,
            'ok'     => $ok,
        ], static fn ($v) => $v !== null));
    }

    /** $block JA contem seu proprio campo 'type' (kpis/bar/...). */
    public function block(array $block): void
    {
        $this->flushText();
        $this->transcript[] = ['k' => 'block', 'b' => $block];
        $this->emit('block', $block);
    }

    /** @var array<int, array<string, mixed>> chips pendentes (AskUserTool) p/ o próximo message_end */
    private array $pendingFollow = [];

    /** Registra chips de resposta rápida — saem no message_end do turno (ask_user). */
    public function setFollow(array $chips): void
    {
        $this->pendingFollow = $chips;
    }

    /** @param array{follow?: array<int, array<string, mixed>>} $data */
    public function messageEnd(array $data = []): void
    {
        if ($this->pendingFollow !== [] && ! isset($data['follow'])) {
            $data['follow'] = $this->pendingFollow;
            $this->pendingFollow = [];
        }
        $this->emit('message_end', $data);
    }

    /**
     * Erro do turno. `$code`/`$resetAt` dizem ao chat embutido o MOTIVO (chat
     * desligado, plano, cota — e quando volta) sem depender do texto; sem
     * motivo conhecido o frame é só `{message}`, como sempre foi.
     */
    public function error(string $message, ?string $code = null, ?string $resetAt = null): void
    {
        $this->emit('error', ['message' => $message]
            + ($code !== null && $code !== '' ? ['code' => $code] : [])
            + ($resetAt !== null && $resetAt !== '' ? ['reset_at' => $resetAt] : []));
    }

    /**
     * Desfecho de uma confirmação aprovada (fw#564): `ok` só quando a escrita
     * rodou; `message` diz ao usuário o motivo da falha. Sai antes do
     * message_end/error do turno, para o cartão mostrar o resultado real.
     */
    public function confirmResult(string $id, bool $ok, ?string $message = null): void
    {
        $this->emit('confirm_result', ['id' => $id, 'ok' => $ok]
            + ($message !== null && $message !== '' ? ['message' => $message] : []));
    }

    /** Evento opcional p/ o cliente exibir a contagem de tokens do turno. */
    public function usage(array $data): void
    {
        $this->emit('usage', $data);
    }

    public function ping(): void
    {
        if ($this->aborted) {
            return;
        }
        echo ": ping\n\n";
        $this->flush();
    }

    public function done(): void
    {
        if ($this->aborted) {
            return;
        }
        echo "data: [DONE]\n\n";
        $this->flush();
    }

    /**
     * Itens do turno na ordem emitida (fecha o buffer de texto pendente).
     *
     * @return list<array<string, mixed>>
     */
    public function transcript(): array
    {
        $this->flushText();

        return $this->transcript;
    }

    private function flushText(): void
    {
        if (trim($this->textBuf) !== '') {
            $this->transcript[] = ['k' => 'text', 't' => $this->textBuf];
        }
        $this->textBuf = '';
    }

    public function aborted(): bool
    {
        if (! $this->aborted && connection_aborted() === 1) {
            $this->aborted = true;
        }

        return $this->aborted;
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
