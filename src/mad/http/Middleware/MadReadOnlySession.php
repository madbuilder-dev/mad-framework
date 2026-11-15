<?php

namespace Mad\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\NullSessionHandler;

/**
 * Sessão SÓ DE LEITURA para a requisição (`mad.session.readonly`).
 *
 * O `StartSession` do Laravel lê a sessão inteira no começo da requisição e, no
 * fim, grava a sessão inteira de volta — sem conferir o que mudou. Duas
 * requisições paralelas da mesma sessão, então, se atropelam: a que termina por
 * último apaga o que a outra gravou no meio.
 *
 * É o que acontecia ao abrir qualquer tela do app: o Echo dispara
 * `/broadcasting/auth` (correio/chat) e `/broadcasting/auth-notifications` junto
 * com o carregamento. Elas leem a sessão antes de a tela gravar a dela (config
 * da grade, filtros lembrados, estado de calendário/kanban/agenda…) e gravam a
 * cópia velha depois — a chave da tela some. Visto no 1731 (14/11/2026): salvar
 * uma empresa no drawer deixou a linha em branco porque a config da listagem
 * tinha sumido da sessão.
 *
 * Essas pontes só LEEM a sessão (quem é o usuário, para assinar o canal) e não
 * têm nada a guardar. Com este middleware a requisição continua lendo tudo
 * normalmente (`session('userid')`, CSRF, `mad.auth`), mas nada dela volta ao
 * armazenamento: o handler da sessão vira um `NullSessionHandler` antes do
 * controller, então nem o salvar do fim do `StartSession` nem um `save()`
 * explícito no meio sobrescrevem o que outra requisição gravou.
 *
 * O que fica de fora de propósito:
 * - Escrita que precisa valer (logout forçado de usuário desativado, troca de
 *   tenant inválido) acontece ANTES, nos middlewares do grupo `web` e no
 *   `mad.auth`, que salvam explicitamente quando precisam — e toda requisição
 *   seguinte repete a checagem.
 * - A requisição não renova o "último acesso" da sessão: ponte de broadcasting
 *   não é atividade do usuário.
 *
 * Por que não `->block()` do Laravel: o bloqueio só serializa requisições que
 * TAMBÉM pedem o bloqueio. Para valer, a tela e as pontes teriam de bloquear —
 * toda abertura de tela passaria a esperar as pontes (e vice-versa), e o app
 * ficaria dependente de um cache com trava atômica. As pontes não precisam
 * gravar nada; não gravar resolve sem fila.
 *
 * Use só em rota que não precisa persistir nada na sessão.
 */
class MadReadOnlySession
{
    /** Atributo da requisição com o handler original, devolvido no terminate(). */
    public const ORIGINAL_HANDLER = '_mad_session_original_handler';

    public function handle(Request $request, Closure $next)
    {
        if ($request->hasSession()) {
            $session = $request->session();
            $request->attributes->set(self::ORIGINAL_HANDLER, $session->getHandler());
            $session->setHandler(new NullSessionHandler());
        }

        return $next($request);
    }

    /**
     * Devolve o handler original depois que a resposta saiu (o `StartSession` já
     * "salvou" no nulo). Em worker que atende várias requisições no mesmo
     * processo (Octane, FrankenPHP), o objeto da sessão pode ser reaproveitado:
     * sem isto a requisição seguinte herdaria o handler nulo e não gravaria nada.
     */
    public function terminate(Request $request, $response): void
    {
        $original = $request->attributes->get(self::ORIGINAL_HANDLER);
        if ($original !== null && $request->hasSession()) {
            $request->session()->setHandler($original);
        }
    }
}
