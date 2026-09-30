<?php

namespace Mad\Web;

/**
 * Mad\Web\ErrorBag
 *
 * Collection simples de mensagens de erro indexadas por campo.
 * API similar ao Illuminate\Support\MessageBag do Laravel (subset).
 *
 * Formato interno: array<string, string|string[]>
 */
class ErrorBag
{
    /** @var array<string, array<int, string>> */
    private array $messages;

    /**
     * @param array<string, string|array<int, string>> $messages
     */
    public function __construct(array $messages = [])
    {
        $normalized = [];
        foreach ($messages as $field => $msg) {
            $normalized[$field] = is_array($msg) ? array_values($msg) : [(string) $msg];
        }
        $this->messages = $normalized;
    }

    /**
     * Se ha algum erro (opcionalmente pra um campo especifico).
     */
    public function has(?string $field = null): bool
    {
        if ($field === null) {
            return !empty($this->messages);
        }
        return isset($this->messages[$field]) && !empty($this->messages[$field]);
    }

    /**
     * Alias para has() sem argumento — usado em views como @if($errors->any()).
     */
    public function any(): bool
    {
        return !empty($this->messages);
    }

    /**
     * Retorna a PRIMEIRA mensagem para um campo (ou para qualquer campo se $field == null).
     */
    public function first(?string $field = null, string $default = ''): string
    {
        if ($field === null) {
            foreach ($this->messages as $msgs) {
                if (!empty($msgs)) return (string) $msgs[0];
            }
            return $default;
        }
        return $this->messages[$field][0] ?? $default;
    }

    /**
     * Retorna TODAS as mensagens de um campo (array). Se $field null, retorna todas
     * mensagens de todos os campos em um unico array flat.
     *
     * @return array<int, string>
     */
    public function get(?string $field = null): array
    {
        if ($field === null) {
            $flat = [];
            foreach ($this->messages as $msgs) {
                foreach ($msgs as $m) $flat[] = $m;
            }
            return $flat;
        }
        return $this->messages[$field] ?? [];
    }

    /**
     * Retorna o array interno completo (field => [msgs]).
     *
     * @return array<string, array<int, string>>
     */
    public function all(): array
    {
        return $this->messages;
    }

    /**
     * Retorna o array no formato plano field => primeira_mensagem.
     * Util pra flash messages simples.
     *
     * @return array<string, string>
     */
    public function toFlatArray(): array
    {
        $flat = [];
        foreach ($this->messages as $field => $msgs) {
            $flat[$field] = $msgs[0] ?? '';
        }
        return $flat;
    }

    /**
     * Conta total de mensagens.
     */
    public function count(): int
    {
        $c = 0;
        foreach ($this->messages as $msgs) $c += count($msgs);
        return $c;
    }

    public function isEmpty(): bool
    {
        return empty($this->messages);
    }
}
