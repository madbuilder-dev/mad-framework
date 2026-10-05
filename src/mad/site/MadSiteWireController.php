<?php

namespace Mad\Site;

use Illuminate\Http\JsonResponse;
use Mad\Component\MadComponent;
use Mad\Component\MadComponentHandler;
use Mad\Registry\ControlRegistry;
use Mad\Security\ClassSource;
use Mad\Security\PermissionGate;
use Mad\Ui\MadForbidden;

/**
 * MadSiteWireController
 *
 * Porta reativa do site público (POST /public/_mad-wire).
 *
 * Recebe POST do mad-livewire.js (em mad-ui.js) com:
 *   - mad_state   (token encriptado do estado do componente)
 *   - mad_id      (identificador da instância no DOM)
 *   - mad_action  (método PHP a executar)
 *   - mad_model[] (valores de mad:model)
 *   - mad_params  (JSON com argumentos extras)
 *
 * CSRF: validado pelo `ValidateCsrfToken` nativo do Laravel (grupo `web`,
 * "CSRF unificado" — ver bootstrap/app.php), igual ao /app/_mad-wire do admin.
 * O layout do site injeta `<meta name="csrf-token">` e o mad-livewire.js lê e
 * envia como header `X-CSRF-TOKEN`; a sessão (mesmo anônima) já carrega um
 * token válido do Laravel.
 *
 * ── O PORTEIRO ─────────────────────────────────────────────────────────────
 * Esta é a única porta do sistema que aceita POST de quem NÃO está logado.
 * Sem porteiro, um visitante poderia colar o estado de uma tela interna e
 * mandar o servidor rodar um método dela.
 *
 * A decisão acontece ANTES de {@see MadComponentHandler::process()}, que
 * decripta e HIDRATA o componente: decriptar aqui (sem instanciar nada) é o
 * que garante que uma tela interna forjada nunca chega a existir.
 *
 * Duas faixas, nesta ordem:
 *
 *   1. Página de site ({@see MadSitePage}) → a ação precisa estar declarada em
 *      `publicActions()`. Allowlist explícita: método público não vira
 *      endpoint só por existir.
 *   2. Qualquer outra classe → só passa para quem ESTÁ logado e tem a permissão
 *      de programa/ação de sempre ({@see PermissionGate::canAccessWire()}), com
 *      a MESMA recusa das outras portas do ciclo reativo. Visitante anônimo é
 *      recusado aqui, sempre — e com a recusa GENÉRICA, que não conta a ele
 *      qual tela existe do outro lado.
 *
 * Estado ausente, ilegível ou adulterado = recusa (fail-closed).
 */
class MadSiteWireController
{
    public static function handle(): JsonResponse
    {
        if (($erro = self::guard($_POST)) !== null) {
            return $erro;
        }

        try {
            // Saída da ação (MadResponse::emit(), echo) vira op — ver
            // MadComponentHandler::processCapturingOutput().
            $result = MadComponentHandler::processCapturingOutput($_POST);
        } catch (\Throwable $e) {
            // JSON de erro (com `_exception` só sob APP_DEBUG) em vez da página
            // HTML do Laravel — o MadErrorModal do front sabe exibir.
            if (function_exists('report')) {
                report($e);
            }

            return new JsonResponse(
                MadComponentHandler::exceptionPayload($e, $_POST),
                500,
                ['X-Mad-Site-Wire' => '1', 'X-Mad-Wire-Error' => '1', 'Cache-Control' => 'no-store, private'],
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
            [
                'X-Mad-Site-Wire' => '1',
                'Cache-Control'   => 'no-store, private',
            ]
        );
    }

    /**
     * Decide se o POST pode seguir. Devolve a recusa (403) ou null para passar.
     *
     * @param array<string, mixed> $post
     */
    private static function guard(array $post): ?JsonResponse
    {
        $token = (string) ($post['mad_state'] ?? '');
        if ($token === '') {
            // Numa porta pública, POST sem estado é ruído — não há tela aberta
            // para devolver um erro amigável de estado.
            return self::recusa();
        }

        $decoded = MadComponent::_decryptState($token);
        if (! is_array($decoded) || empty($decoded['class'])) {
            // Token presente mas indecifrável = adulteração/forja (a assinatura
            // falhou) ou expiração. Fail-closed.
            return self::recusa();
        }

        $action = trim((string) ($post['mad_action'] ?? ''));

        // "É página de site?" pela herança LIDA do código, sem carregar a
        // classe: o estado pode trazer uma tela interna (de quem entrou e saiu),
        // e carregá-la aqui — antes de saber se o visitante pode alguma coisa
        // com ela — derrubava com 500 o pedido anônimo quando a tela tem erro
        // de compilação. Só a página de site provada é carregada, e ela é a
        // própria tela deste pedido.
        if (self::isSitePage((string) $decoded['class'])) {
            $class = self::resolveClass((string) $decoded['class']);
            if (! is_subclass_of($class, MadSitePage::class)) {
                return self::recusa();
            }

            $publicas = [];
            try {
                $publicas = (array) $class::publicActions();
            } catch (\Throwable) {
                $publicas = [];
            }

            return ($action !== '' && in_array($action, $publicas, true)) ? null : self::recusa();
        }

        // Não é página de site: esta porta não serve de atalho para o painel.
        //
        // Visitante ANÔNIMO recebe sempre a recusa genérica. A frase específica
        // ("Você não tem acesso a esta tela") confirmaria que a tela existe do
        // outro lado — e para quem nem entrou no sistema isso é informação
        // sobre o produto de outra pessoa, não ajuda em nada.
        if (! PermissionGate::isLogged()) {
            return self::recusa();
        }

        // Quem está logado continua valendo pela permissão de sempre — as três
        // portas do ciclo reativo respondem igual (ver WireActionPermissionTest).
        if (! PermissionGate::canAccessWire($post)) {
            return self::recusa($post);
        }

        return null;
    }

    /** A classe do estado é página de site? Lido do código ({@see ClassSource::inherits()}). */
    private static function isSitePage(string $class): bool
    {
        try {
            return ClassSource::inherits($class, MadSitePage::class);
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }

            return false;
        }
    }

    /** FQCN real da classe do estado (aceita o basename de um control). */
    private static function resolveClass(string $class): string
    {
        $class = ltrim(trim($class), '\\');
        if ($class === '' || class_exists($class)) {
            return $class;
        }

        if (class_exists(ControlRegistry::class)) {
            return ControlRegistry::resolve($class) ?? $class;
        }

        return $class;
    }

    /**
     * Recusa 403. Com o POST em mãos e usuário logado, repete a frase das
     * outras portas ("Sem permissão para excluir"); caso contrário diz só que
     * a ação não está disponível — o visitante anônimo não precisa saber que
     * tela existe do outro lado.
     *
     * @param array<string, mixed>|null $post
     */
    private static function recusa(?array $post = null): JsonResponse
    {
        $corpo = ['error' => 'forbidden'];

        if ($post !== null) {
            try {
                $corpo = MadForbidden::wirePayload(PermissionGate::deniedWireActionKey($post));
            } catch (\Throwable) {
                $corpo = ['error' => 'forbidden'];
            }
        }

        return new JsonResponse(
            $corpo,
            403,
            ['X-Mad-Site-Wire' => '1', 'Cache-Control' => 'no-store, private'],
        );
    }
}
