<?php

namespace Mad\Http\Middleware;

use Mad\Core\AppConfig;
use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mad\Component\MadComponent;
use Mad\Routing\MadRoutes;
use Mad\Security\ClassSource;
use Mad\Security\PermissionGate;
use Mad\Site\MadSitePage;
use Illuminate\Support\Facades\Auth;

/**
 * Porta de autenticação do app interno (port Laravel-nativo do
 * AuthAdminMiddleware original). Exige usuário logado, exceto classes
 * públicas (public_classes[] / LoginForm / public_entry) e telas que se
 * declaram públicas (`protected static bool $public = true`, com a porta
 * `publicGuard()` opcional — ver PermissionGate::isPublicPage).
 *
 * Permissão de PROGRAMA/AÇÃO é dos próximos estágios (MadProgramPermission
 * nas rotas de conteúdo; PermissionGate::canAccessWire no endpoint reativo).
 *
 * Ao negar: wire/XHR → JSON 401 com redirect; conteúdo → fragmento que
 * redireciona o browser pro login.
 */
class MadAuthenticate
{
    public function handle(Request $request, Closure $next)
    {
        if (PermissionGate::isLogged()) {
            $this->bridgeGuard();
            $this->refreshStalePermissions();

            // Senha vencida NO MEIO da sessão: o guard já derrubou a sessão, e
            // daqui pra frente este request é o de um não-logado — mesma
            // resposta (HTML que navega pro login, ou 401+redirect no wire).
            if ($this->enforcePasswordPolicy()) {
                return $this->deny($request);
            }

            return $next($request);
        }

        $class = $this->resolveClass($request);

        // Página do SITE PÚBLICO: existe justamente para quem não está logado
        // (a home, os planos, o contato). Liberada aqui pelo TIPO — sem isso,
        // uma rota de site que caísse neste middleware devolveria o desvio para
        // o login e o visitante nunca veria o produto. A checagem usa o FQCN
        // ANTES da normalização para basename (é a herança que decide), e a
        // herança é LIDA do código: ver isPublicSitePage().
        if ($class !== '' && $this->isPublicSitePage($class)) {
            return $next($request);
        }

        // O wire manda o get_class() do componente (FQCN App\Control\<Dominio>\<Nome>);
        // normaliza p/ o BASENAME (LoginForm / UserForm) antes das checagens literais
        // abaixo — senão o login (e toda ação reativa de tela pública) daria 401.
        if ($class !== '' && class_exists(\Mad\Registry\ControlRegistry::class)) {
            $class = \Mad\Registry\ControlRegistry::idFor($class);
        }

        // Tela que se declara pública (`protected static bool $public = true`,
        // a "página pública" do 4.0): abre sem login. Com porta própria
        // (`publicGuard()`) que recusa, o visitante vai para a tela indicada
        // por ela — o login do portal —, não para o login do app. A decisão
        // final continua no PermissionGate (canAccess/canAccessWire usam a
        // classe do estado cifrado, não a da query string).
        if ($class !== '' && PermissionGate::isPublicPage($class)) {
            $target = PermissionGate::publicGuardDenial($class);

            return $target === null ? $next($request) : $this->deny($request, $target);
        }

        if ($class !== '' && (
            $class === 'LoginForm'
            || in_array($class, PermissionGate::publicClasses(), true)
            || $class === $this->publicEntry()
        )) {
            return $next($request);
        }

        return $this->deny($request);
    }

    /**
     * A classe alvo é uma página do site público (Mad\Site\MadSitePage)?
     *
     * Decide pela herança DECLARADA no código ({@see ClassSource::inherits()}),
     * sem carregar a classe. Aqui passa TODO pedido de quem não está logado,
     * para qualquer tela — e antes de saber se ela abre. Com `class_exists` a
     * tela pedida era carregada neste ponto: uma tela com erro de compilação
     * (fatal, sem catch) devolvia 500 ao visitante anônimo em vez do desvio
     * para o login. Na dúvida (herança não provada), não é página de site e o
     * pedido segue o fluxo normal de login.
     */
    private function isPublicSitePage(string $class): bool
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

    /** Classe alvo: route default/param {class}, ou a do mad_state (wire). */
    /**
     * Ponte sessão → guard do Laravel.
     *
     * A identidade do app mora na sessão (`logged`, `userid`, `username`…,
     * gravadas pelo LoginForm); o guard do Laravel nunca recebia
     * `Auth::login()`, então `auth()->id()`, `auth()->user()`,
     * `Auth::check()` e `request()->user()` eram null em TODO request —
     * tela e ação reativa. Código gerado com `created_by = auth()->id()`
     * gravava NULL em silêncio, e a sessão #bW0nBn (11/set/2026) gastou
     * seis rodadas culpando a action do PDV por um "guard que não propaga".
     *
     * Aqui, com a sessão logada, o guard recebe o usuário da sessão SÓ
     * para este request (`setUser`, sem `login()` — nada é gravado na
     * sessão do Laravel, e o logout do app continua sendo o que apaga
     * `logged`). Prefere o modelo real do provider (`config/auth.php` →
     * `App\Models\Iam\User`); provider quebrado (AUTH_MODEL inexistente,
     * tabela ausente) cai num `GenericUser` com os dados da sessão — pior
     * do que o Eloquent, melhor do que null. Nunca lança: a ponte é
     * cortesia, não porta.
     */
    private function bridgeGuard(): void
    {
        $userId = (int) session('userid');
        if ($userId <= 0) {
            return;
        }

        try {
            $guard = Auth::guard();
            if ($guard->check()) {
                return; // app que já faz Auth::login() por conta própria
            }

            $user = null;
            if (method_exists($guard, 'getProvider')) {
                try {
                    $user = $guard->getProvider()->retrieveById($userId);
                } catch (\Throwable) {
                    $user = null;
                }
            }

            $guard->setUser($user ?? new GenericUser([
                'id'        => $userId,
                'name'      => (string) session('username', ''),
                'login'     => (string) session('login', ''),
                'email'     => (string) session('usermail', ''),
                'unit_id'   => session('userunitid'),
                'tenant_id' => session('tenant_id'),
            ]));
        } catch (\Throwable) {
            // guard exótico sem setUser/provider: segue sem ponte.
        }
    }

    /**
     * Recalcula a permissão da sessão quando a licença mudou POR FORA dela.
     *
     * `session('programs')`/`session('unit_modules')` são calculados no login.
     * Quando o cliente paga um upgrade, quem escreve na licença é outro
     * processo (webhook do provedor, "marcar como paga" do dono) e a sessão
     * dele continua com o menu de antes — o módulo pago só apareceria depois de
     * sair e entrar. O serviço do APP compara um carimbo de revisão (uma
     * leitura de cache) e recarrega só quando mudou.
     *
     * Ponte por `class_exists` sobre o NOME: o pacote não depende de classe do
     * app. App gerado com framework anterior ao serviço simplesmente não tem a
     * classe e este método é um no-op. try/catch porque um recálculo de menu
     * não pode derrubar request nenhum.
     */
    private function refreshStalePermissions(): void
    {
        $refresher = 'App\\Service\\Iam\\SessionPermissionRefresher';

        if (! class_exists($refresher) || ! method_exists($refresher, 'refreshIfStale')) {
            return;
        }

        try {
            $refresher::refreshIfStale();
        } catch (\Throwable) {
            // otimização de sessão: falha aqui nunca vira falha de request
        }
    }

    /**
     * Expira a sessão quando a senha vence DURANTE ela.
     *
     * O modal do login cobre quem entra com a senha vencida; uma sessão de
     * "lembrar de mim" dura até 30 dias sem passar por lá, então sem esta
     * checagem a validade nunca alcançaria quem marcou a caixa. O serviço do
     * APP decide (no máximo uma consulta por hora por sessão) e devolve `true`
     * quando já derrubou a sessão — aqui só resta responder como se responde a
     * um não-logado.
     *
     * Ponte por `class_exists` sobre o NOME, igual ao refreshStalePermissions:
     * o pacote não depende de classe do app, e app gerado com framework
     * anterior ao serviço simplesmente não tem a classe. try/catch porque uma
     * política de senha não pode derrubar request nenhum — na dúvida, deixa
     * passar (o login ainda vai cobrar a troca na próxima entrada).
     */
    private function enforcePasswordPolicy(): bool
    {
        $guard = 'App\\Service\\Iam\\PasswordExpirationGuard';

        if (! class_exists($guard) || ! method_exists($guard, 'enforceIfStale')) {
            return false;
        }

        try {
            return (bool) $guard::enforceIfStale();
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveClass(Request $request): string
    {
        $class = (string) ($request->route('class') ?? $request->get('class', ''));
        if ($class !== '') {
            return $class;
        }

        $token = $_POST['mad_state'] ?? null;
        if ($token) {
            $decoded = MadComponent::_decryptState((string) $token);
            if (is_array($decoded) && !empty($decoded['class'])) {
                return (string) $decoded['class'];
            }
        }

        return '';
    }

    private function publicEntry(): string
    {
        $ini = AppConfig::get();
        if (($ini['general']['public_view'] ?? '0') === '1') {
            return (string) ($ini['general']['public_entry'] ?? '');
        }

        return '';
    }

    /** @param  string|null  $target  tela para onde mandar (porta da tela pública); vazio = login do app */
    private function deny(Request $request, ?string $target = null)
    {
        $loginUrl = $target !== null && $target !== '' ? MadRoutes::urlFor($target) : MadRoutes::loginUrl();

        if ($this->isWire($request)) {
            return new JsonResponse(['error' => 'Permission denied', 'redirect' => $loginUrl], 401);
        }

        $url = htmlspecialchars($loginUrl, ENT_QUOTES);

        return new Response(
            '<script>window.location.href="' . $url . '";</script>',
            401,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    private function isWire(Request $request): bool
    {
        if (str_contains($request->path(), '_mad-wire')) {
            return true;
        }

        return $request->ajax() && str_contains((string) $request->header('Accept'), 'application/json');
    }
}
