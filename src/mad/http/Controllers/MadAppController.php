<?php

namespace Mad\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mad\Component\MadComponent;
use Mad\Component\MadComponentHandler;
use Mad\Security\MadCsrf;
use Mad\Security\PermissionGate;
use Mad\Ui\MadForbidden;

/**
 * Port Laravel do AppRouteResolver (modo driver=web do mad-framework).
 *
 * Diferença única vs original: o fragmento é executado instanciando o
 * MadComponent direto (show()), em vez do dispatcher de aplicação legado —
 * não existe dispatcher legado neste projeto.
 */
class MadAppController
{
    /** POST /app/_mad-wire — ciclo reativo (mesma lógica do MadAppWireController). */
    public function wire(Request $request): JsonResponse
    {
        if (!MadCsrf::validateWire()) {
            return new JsonResponse(['error' => 'CSRF token mismatch'], 403);
        }

        if (!PermissionGate::canAccessWire($_POST)) {
            // Mensagem traduzida: o `mad-livewire.js` mostra `error` ao usuário
            // (toast/modal), então "Permission denied" chegava em inglês na tela.
            // Recusa de AÇÃO numa tela que abre diz o que foi recusado ("Sem
            // permissão para excluir"); recusa da TELA mantém o recado de
            // sempre — e as três portas do wire respondem igual.
            return new JsonResponse(
                MadForbidden::wirePayload(PermissionGate::deniedWireActionKey($_POST)),
                403
            );
        }

        try {
            // Com a saída capturada: um MadResponse::emit() (ou echo) na ação
            // vira op, em vez de sair antes do JSON e derrubar a resposta.
            $result = MadComponentHandler::processCapturingOutput($_POST);
        } catch (\Throwable $e) {
            // Sem este catch a exception subia pro handler do Laravel, que
            // devolvia a PÁGINA DE ERRO HTML para um POST que o front espera em
            // JSON — o mad-livewire.js engolia o corpo e o usuário via NADA.
            // Agora volta JSON com `_exception` (só com APP_DEBUG) e o
            // MadErrorModal abre o relatório completo na tela.
            if (function_exists('report')) {
                report($e);
            }

            return new JsonResponse(
                MadComponentHandler::exceptionPayload($e, $_POST),
                500,
                ['X-Mad-App-Wire' => '1', 'X-Mad-Wire-Error' => '1', 'Cache-Control' => 'no-store, private'],
                JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE
            );
        }

        // O handler pode carimbar o status (403 da permissão por ação, decidida
        // só depois de o componente estar hidratado). Sem isto uma recusa saía
        // como 422 — "dado inválido" — e quem monitora não via a negativa.
        // O carimbo é de transporte, não faz parte do corpo.
        $status = (int) ($result['status'] ?? (isset($result['error']) ? 422 : 200));
        unset($result['status']);

        return new JsonResponse(
            $result,
            $status,
            ['X-Mad-App-Wire' => '1', 'Cache-Control' => 'no-store, private'],
            JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * GET /app/docs/{slug}/{id} — página de DOCUMENTO (PDF), registrada por
     * MadRoutes::exposeDocument. A classe é PLANA (não é MadComponent): expõe
     * show(int $id) devolvendo a Response do PDF (ver DocumentGenerator do
     * MadBuilder). Mesmo gate de permissão do run(); não passa pelo shell.
     */
    public function document(Request $request)
    {
        $route = $request->route();
        $class = (string) ($route->parameter('class') ?? '');
        $id    = (string) ($route->parameter('id') ?? '');

        if ($class === '' || $id === '' || !class_exists($class) || !method_exists($class, 'show')) {
            abort(404);
        }

        if (class_exists('MadTrace')) {
            \MadTrace::setTransactionName("{$class}::show");
            \MadTrace::setTransactionType('web');
        }

        if (!PermissionGate::canAccess($class, 'show')) {
            if (!session('logged')) {
                // Documento público (`$public = true`) cuja porta recusou: vai
                // para a tela que ela indicar (o login do portal), não o do app.
                $target = PermissionGate::isPublicPage($class) ? PermissionGate::publicGuardDenial($class) : null;

                return redirect($target !== null && $target !== ''
                    ? \Mad\Routing\MadRoutes::urlFor($target)
                    : \Mad\Routing\MadRoutes::loginUrl());
            }

            return MadForbidden::response($request);
        }

        $result = (new $class())->show(ctype_digit($id) ? (int) $id : $id);
        if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
            return $result;
        }
        if (is_string($result) && $result !== '') {
            return new Response($result, 200, ['Content-Type' => 'application/pdf']);
        }

        abort(404);
    }

    /**
     * Rotas declaradas (MadRoutes) — shell na navegação direta, fragmento no AJAX.
     *
     * class/method vêm dos PARÂMETROS DA ROTA por NOME (defaults do MadRoutes
     * ou segmentos do catch-all) — nunca da assinatura: com rotas tipo
     * /usuarios/{id}/editar a injeção posicional do dispatcher embaralharia
     * {id} em $class.
     */
    public function run(Request $request)
    {
        $route  = $request->route();
        $class  = (string) ($route->parameter('class') ?? '');
        $method = $route->parameter('method');
        $method = is_string($method) && $method !== '' ? $method : null;

        if (!class_exists($class) || !is_subclass_of($class, MadComponent::class)) {
            abort(404);
        }

        // APM (MadTrace) — route real do programa ("TesteListagem::onSearch"),
        // não o genérico MadAppController@run do RouteMatched (last write wins).
        if (class_exists('MadTrace')) {
            \MadTrace::setTransactionName($method !== null ? "{$class}::{$method}" : $class);
            \MadTrace::setTransactionType('web');
        }

        if (!PermissionGate::canAccess($class, $method)) {
            // Não logado → manda pro login preservando UX; logado sem permissão → 403.
            if (!session('logged') && $class !== 'LoginForm') {
                return redirect(\Mad\Routing\MadRoutes::loginUrl());
            }

            return MadForbidden::response($request);
        }

        // Métodos PHP-ESTÁTICOS via ?static=1 — widgets JS consomem o echo
        // direto do método, sem shell e sem show() (ex.: FullCalendar monta
        // /app/TesteCalendario/getEvents?static=1 pra buscar o feed JSON).
        // ATENÇÃO: no engine legado static=1 significa apenas "sem template"
        // e o Mad.go SEMPRE manda static=1 — método de INSTÂNCIA cai no fluxo
        // normal de fragmento abaixo (nunca 404 por causa do static).
        if ($method !== null && (string) $request->input('static') === '1'
            && method_exists($class, $method)
            && (new \ReflectionMethod($class, $method))->isStatic()) {

            // CSRF em profundidade no dispatch estático. `static=1` roda fora do
            // gate de wire(); a rota aceita GET+POST e o SameSite=lax deixa passar
            // navegação GET top-level cross-site — o vetor de CSRF-write. Métodos
            // que MUTAM estado exigem POST (o VerifyCsrfToken do Laravel já valida
            // o token nos POST; o GET é que ficava aberto). Lista CURADA: cobre
            // verbos de escrita e os on*-mutantes conhecidos, sem tocar nos
            // read-only on* (onSearch/onFilter/onLoad/onColFilter/onSort/onChange*)
            // que widgets buscam por GET.
            $mutates = preg_match(
                '/^(save|store|create|update|delete|remove|destroy|persist|insert|import|apply|register|upload|submit|confirm|generate)/i',
                $method
            ) || preg_match(
                '/^on(Save|Store|Create|Update|Delete|Remove|Destroy|Import|Apply|Register|Upload|Submit|Confirm|Run|Persist|Send|Move|Copy|Approve|Reject|Archive|Generate|Insert)/i',
                $method
            );
            if (session('logged') && $mutates && !$request->isMethod('post')) {
                return new Response('405 — state-changing action requires POST', 405, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Allow'        => 'POST',
                ]);
            }

            // Reconstrói $_REQUEST da Request do Laravel: no SAPI web o
            // conteúdo é o mesmo (inócuo), mas em testes (Request::create) os
            // superglobals ficam vazios — ou pior, com sobras do teste anterior
            // no mesmo processo — e o método não veria os params certos.
            $_REQUEST = array_merge($request->query(), $request->post());
            $_REQUEST['class'] = $class;

            ob_start();
            try {
                $class::$method($_REQUEST);
            } finally {
                $out = ob_get_clean();
            }

            return new Response($out, 200, [
                'Content-Type'  => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        if ($this->isDirectNavigation($request)) {
            return $this->renderShell();
        }

        // Reconstrói $_REQUEST da Request do Laravel (mesma razão do branch
        // static=1: no SAPI é inócuo; em testes vem vazio/sujo).
        $_REQUEST = array_merge($request->query(), $request->post());

        $_REQUEST['class'] = $class;
        if ($method !== null && $method !== '') {
            $_REQUEST['method'] = $method;
        } else {
            unset($_REQUEST['method']);
        }

        // Params de rota declarada (ex.: {id} de /usuarios/{id}/editar) entram
        // no $_REQUEST — MadComponent::show() despacha o método com eles.
        foreach ($request->route()->parameters() as $k => $v) {
            if ($k !== 'class' && $k !== 'method' && is_scalar($v)) {
                $_REQUEST[$k] = (string) $v;
            }
        }

        ob_start();
        try {
            (new $class())->show($_REQUEST);
        } finally {
            $html = ob_get_clean();
        }

        // Identidade da tela no fragmento: no modo web a URL amigável não
        // carrega a classe, e o <mad-tab-bar> / breadcrumb do casco precisam
        // saber QUAL programa veio (aba por tela, rótulo). Só metadado — o
        // HTML segue igual. Title só quando o programa declara static $title.
        $headers = [
            'Content-Type'  => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'X-Mad-Class'   => class_basename($class),
        ];
        $title = trim((string) $class::getTitle());
        if ($title !== '' && preg_match('/^[^\r\n]{1,120}$/u', $title)) {
            // Header é ASCII/Latin-1 por contrato: acentos vão RFC 2047-free via rawurlencode.
            $headers['X-Mad-Title'] = rawurlencode($title);
        }

        return new Response($html, 200, $headers);
    }

    /** GET sem X-Mad-Partial = navegação direta do browser. */
    private function isDirectNavigation(Request $request): bool
    {
        return $request->isMethod('GET') && !$request->headers->has('X-Mad-Partial');
    }

    /**
     * Shell completo (casco do tema + menu) renderizado via Blade pelo
     * {@see \App\Http\Controllers\ShellController}. O conteúdo da tela é
     * auto-carregado pelo Mad.bootShell() via re-fetch com X-Mad-Partial.
     */
    private function renderShell(): Response
    {
        $shell = app(\App\Http\Controllers\ShellController::class);

        switch ($this->resolveShellKind()) {
            case 'iframe': return $shell->renderIframe();
            case 'public': return $shell->renderPublic();
            case 'layout': return $shell->renderLayout();
            default:       return $shell->renderLogin();
        }
    }

    /**
     * Qual casco renderizar — espelha a escolha do antigo BuilderTemplateParser::init():
     * logado + template=iframe → iframe; logado → layout; public_view → public;
     * tela que se declara pública (`$public = true`) → public; senão → login.
     */
    private function resolveShellKind(): string
    {
        if (session('logged')) {
            return (string) (request()->input('template') ?? '') === 'iframe' ? 'iframe' : 'layout';
        }

        $ini = mad_app_config();
        if (isset($ini['general']['public_view']) && $ini['general']['public_view'] == '1') {
            return 'public';
        }

        // Página pública (o portal do cliente do 4.0): o casco do login a
        // espremeria no cartão de login — uma listagem ficava com 400px. O
        // casco público dá a largura toda, sem o menu do app (o visitante só
        // enxerga o que é público).
        $class = (string) (request()->route()?->parameter('class') ?? '');
        if ($class !== '' && PermissionGate::isPublicPage($class)) {
            return 'public';
        }

        return 'login';
    }
}
