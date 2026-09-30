<?php

namespace Mad\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mad\Security\PermissionGate;
use Mad\Ui\MadForbidden;

/**
 * Permissão de PROGRAMA nas rotas de conteúdo (port Laravel-nativo do
 * ProgramPermissionMiddleware original). A classe alvo vem dos defaults da
 * rota declarada (MadRoutes::screen/resource/expose) — gate precoce; a
 * checagem autoritativa continua no MadAppController::run (canAccess) e no
 * canal reativo (canAccessWire).
 */
class MadProgramPermission
{
    public function handle(Request $request, Closure $next)
    {
        $class  = (string) ($request->route('class') ?? '');
        $method = $request->route('method');
        $method = is_string($method) && $method !== '' ? $method : null;

        if ($class === '' || PermissionGate::canAccess($class, $method)) {
            return $next($request);
        }

        // Recusa que o USUÁRIO entende: traduzida, no visual do kit e com
        // caminho de volta. Era `<h1>403 — Permission denied</h1>` — HTML cru,
        // em inglês, sem casco e sem saída, num app pt-BR.
        //
        // Navegação direta de quem ESTÁ logado devolve o casco do app: o
        // `Mad.bootShell()` refaz o pedido com `X-Mad-Partial`, cai aqui de
        // novo e injeta o fragmento no conteúdo — a recusa aparece dentro do
        // app, com menu, em vez de substituir a página inteira. O status
        // continua 403 nos dois caminhos.
        return MadForbidden::response($request);
    }
}
