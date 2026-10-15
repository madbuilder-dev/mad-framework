<?php

namespace Mad\Routine;

/**
 * O que uma execução de rotina fez. `message()` é o aviso que o botão mostra:
 * "3 registros criados, 2 desfeitos." / "Nada a fazer."
 */
final class RoutineResult
{
    public function __construct(
        public string $runId = '',
        public int $created = 0,
        public int $updated = 0,
        public int $accumulated = 0,
        public int $reversed = 0,
    ) {
    }

    public function message(): string
    {
        $parts = [];
        foreach (['created' => $this->created, 'updated' => $this->updated,
                  'accumulated' => $this->accumulated, 'reversed' => $this->reversed] as $kind => $n) {
            if ($n <= 0) {
                continue;
            }
            // O primeiro item leva o substantivo ("2 registros criados"); os
            // seguintes, não ("1 atualizado"). Criar não tem forma curta: é
            // sempre o primeiro quando existe.
            $form = $parts === [] || $kind === 'created' ? $kind . '_first' : $kind;
            $parts[] = RoutineText::get($form . ($n === 1 ? '_one' : '_many'), ['n' => $n]);
        }

        return $parts === [] ? RoutineText::get('nothing') : implode(', ', $parts) . '.';
    }

    /** @return array{run_id: string, created: int, updated: int, accumulated: int, reversed: int} */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId, 'created' => $this->created, 'updated' => $this->updated,
            'accumulated' => $this->accumulated, 'reversed' => $this->reversed,
        ];
    }
}
