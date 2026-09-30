<?php

namespace Mad\Ai;

/**
 * ConfirmCoordinator — guarda as escritas pendentes de confirmacao por sessao.
 *
 * Fluxo: no turno N a render tool confirm_action (ou o self-gate de uma write
 * tool) registra {tool, args} sob um id e emite o bloco confirm. O controller
 * persiste isto no ConversationStore. No turno N+1, com confirm:{id,approved},
 * o controller resolve o id e executa a escrita de forma deterministica.
 *
 * Holder em memoria — a persistencia entre requests e feita pelo controller
 * (seed na carga, all() no save).
 */
final class ConfirmCoordinator
{
    /** @var array<string, array{tool: string, args: array<string, mixed>}> */
    private array $pending = [];

    /** Semeia a partir do estado persistido. */
    public function seed(array $pending): void
    {
        foreach ($pending as $id => $p) {
            if (is_array($p) && isset($p['tool'])) {
                $this->pending[(string) $id] = [
                    'tool' => (string) $p['tool'],
                    'args' => is_array($p['args'] ?? null) ? $p['args'] : [],
                ];
            }
        }
    }

    public function newId(): string
    {
        return 'confirm-' . substr(md5(uniqid('', true)), 0, 12);
    }

    /** @param array<string, mixed> $args */
    public function stash(string $id, string $tool, array $args): void
    {
        if ($id === '' || $tool === '') {
            return;
        }
        $this->pending[$id] = ['tool' => $tool, 'args' => $args];
    }

    /** @return array{tool: string, args: array<string, mixed>}|null */
    public function resolve(string $id): ?array
    {
        $p = $this->pending[$id] ?? null;
        if ($p !== null) {
            unset($this->pending[$id]);
        }

        return $p;
    }

    /** @return array<string, array{tool: string, args: array<string, mixed>}> */
    public function all(): array
    {
        return $this->pending;
    }
}
