<?php

namespace Mad\Routine;

use Illuminate\Database\Eloquent\Model;

/**
 * O que uma execução de rotina recebe: o registro de origem (travado para
 * escrita durante a execução; null em rotina sem origem), os parâmetros de quem
 * chamou e o identificador da execução (o mesmo gravado no livro).
 */
final class RoutineContext
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public readonly ?Model $origin,
        public readonly array $params,
        public readonly string $runId,
    ) {
    }
}
