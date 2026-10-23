<?php

namespace Mad\Database\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recusa de gravação que, num pedido de API, responde como a validação dela:
 * 422 com `{message, errors: {coluna: [texto]}}` — o formato da
 * ValidationException do Laravel (fw#534). Sem isso a rota da API que grava
 * com `create()`/`save()` devolvia 500 para um dado errado do corpo.
 *
 * Página comum (sem JSON): `render()` devolve null e vale o tratamento de
 * sempre. O formulário MAD e o MadComponentHandler capturam a exceção antes
 * e mostram o erro no campo — nada muda para eles.
 *
 * Quem usa declara a coluna em `errorField()`.
 */
trait AnswersAsFieldError
{
    /** Coluna em que o erro aparece (`errors.<coluna>`). */
    abstract public function errorField(): string;

    /** Chamado pelo Handler do Laravel ao renderizar a exceção. */
    public function render(Request $request): ?JsonResponse
    {
        if (! self::answersAsJson($request)) {
            return null;
        }

        return new JsonResponse([
            'message' => $this->getMessage(),
            'errors'  => [$this->errorField() => [$this->getMessage()]],
        ], 422);
    }

    /**
     * Respondida como 422 é dado errado de quem chamou, não falha do app: fica
     * fora do log, como a ValidationException. Fora disso (página, fila,
     * comando), `false` = o registro de sempre.
     */
    public function report(): ?bool
    {
        try {
            $request = app()->bound('request') ? app('request') : null;
        } catch (\Throwable) {
            $request = null;
        }

        return $request instanceof Request && self::answersAsJson($request) ? null : false;
    }

    /** API do app (`/api/...`) ou chamada que pede JSON. */
    private static function answersAsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }
}
