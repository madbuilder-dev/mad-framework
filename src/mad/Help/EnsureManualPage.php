<?php

namespace Mad\Help;

use Closure;
use Illuminate\Http\Request;

/**
 * Rota `/app/ajuda/{path}`: página que não está no índice do manual → 404.
 *
 * Fica num middleware (depois do `mad.auth`, que manda quem não entrou para o
 * login) porque a tela, ao montar, transforma qualquer exceção no cartão de
 * erro com HTTP 200 — e a página inexistente precisa responder 404 de verdade.
 */
final class EnsureManualPage
{
    public function handle(Request $request, Closure $next)
    {
        $path = (string) ($request->route('path') ?? '');
        if ((new ManualRepository())->find($path) === null) {
            abort(404);
        }

        return $next($request);
    }
}
