<?php

namespace Mad\Routing;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Mad\Http\Controllers\MadAppController;
use Mad\Security\ClassSource;
use Mad\Site\SiteRegistry;

/**
 * MadRoutes — registrador de rotas do app interno (port Laravel-nativo do
 * AppRouteResolver do mad-framework original).
 *
 * MODELO ALLOWLIST: não existe catch-all — toda tela precisa de uma linha
 * declarada no routes/web.php do app:
 *
 *   MadRoutes::expose('login', 'LoginForm');                              // /{slug}/{method?}  GET+POST
 *   MadRoutes::screen('welcome', 'WelcomeView');                          // /{slug}            GET
 *   MadRoutes::resource('users', 'UserList', 'UserForm');                 // /{slug}, /{slug}/{new}, /{slug}/{id}/{edit}
 *   MadRoutes::exposeClass('MadDbSearchService');                         // /{Classe}/{method?} (serviços AJAX por nome)
 *
 * Slugs vêm de lang/{locale}/routes.php (grupo `routes`), registrados pra
 * TODOS os locales suportados (inbound); o mapa outbound (classe → rota
 * amigável, consumido por toFriendlyUrl) usa o locale ATIVO.
 *
 * toFriendlyUrl() assa URLs legadas (index.php?class=X&method=Y) na rota
 * amigável — consumido por MadAction, MadResponse, FieldListAction e pelo
 * rewriteFriendlyHrefs do shell. O mapa NÃO é exportado pro client
 * (enumeração da superfície admin).
 */
class MadRoutes
{
    /** Locales suportados pra registro inbound. */
    private const I18N_LOCALES = ['pt-BR', 'en', 'es'];

    /** Mapa outbound classe → ['kind','path'|'new','edit','editParam']. */
    private static array $routeMap = [];

    /** Mapa outbound "Classe@metodo" → path amigável (exposeMethod). */
    private static array $methodRoutes = [];

    /** Form de resource → list da qual herda permissão. */
    private static array $resourceForms = [];

    /**
     * Mapa outbound das páginas PÚBLICAS (exposeSite): classe → caminho na RAIZ.
     *
     * Separado do $routeMap de propósito: aquele guarda caminhos relativos ao
     * prefixo do app (`/app`), que o urlFor() prefixa com RoutingDriver::appBase().
     * Página de site mora na raiz do domínio — misturar os dois faria
     * `urlFor(HomePage::class)` devolver `/app/` em vez de `/`.
     */
    private static array $siteRoutes = [];

    /**
     * URIs já registradas por este registrador (`app/<slug>`, `app/<Classe>`).
     * Impede o alias pelo nome da classe de tomar o caminho de um slug.
     *
     * @var array<string, true>
     */
    private static array $uris = [];

    // ─── Registro ─────────────────────────────────────────────────────────────

    /** Tela única (show): GET /{slug} por locale. */
    public static function screen(string $key, string $class, ?string $method = null, array $opts = []): void
    {
        foreach (self::slugsFor($key, $opts) as $slug) {
            self::$uris['app/' . $slug] = true;
            Route::get('/app/' . $slug, [MadAppController::class, 'run'])
                ->defaults('class', $class)
                ->defaults('method', $method);
        }

        if (($slug = self::seg($key, 'slug', null)) !== null) {
            // screen() registra só GET /{slug} (sem {method?}) → não aceita método no slug.
            self::$routeMap[$class] = ['kind' => 'list', 'path' => '/' . $slug, 'methodRoute' => false];
            // Mesma tela pelo NOME da classe (Mad.go('Classe')), só a abertura.
            self::classAlias($class, ['GET'], null, $method);
        }
    }

    /**
     * Classe com método dinâmico: GET+POST /{slug}/{method?} por locale.
     *
     * $opts['permissionFrom'] = 'ListClass' → a tela NÃO exige programa próprio:
     * herda a permissão da classe indicada (mesmo mecanismo dos forms de
     * resource()). Use p/ telas auxiliares de uma tela principal (ex.: FolderForm
     * do GED, auxiliar de DocumentList) — sem isso, um banco sem o programa
     * seedado nega o acesso SILENCIOSAMENTE (o botão "não funciona").
     */
    public static function expose(string $key, string $class, array $opts = []): void
    {
        foreach (self::slugsFor($key, $opts) as $slug) {
            self::$uris['app/' . $slug] = true;
            Route::match(['get', 'post'], '/app/' . $slug . '/{method?}', [MadAppController::class, 'run'])
                ->where('method', '[A-Za-z][A-Za-z0-9_]*')
                ->defaults('class', $class);
        }

        if (!empty($opts['permissionFrom'])) {
            self::$resourceForms[$class] = (string) $opts['permissionFrom'];
        }

        if (($slug = self::seg($key, 'slug', null)) !== null) {
            // expose() registra GET+POST /{slug}/{method?} → aceita método no slug.
            self::$routeMap[$class] = ['kind' => 'list', 'path' => '/' . $slug, 'methodRoute' => true];
            // Mesma rota pelo NOME da classe: Mad.go('Classe') sem URL amigável.
            self::classAlias($class, ['GET', 'POST'], '[A-Za-z][A-Za-z0-9_]*');
        }
    }

    /**
     * Página de DOCUMENTO (PDF): GET /app/docs/{slug}/{id} → (new $class)->show($id).
     *
     *   MadRoutes::exposeDocument('OrcamentoPdf', 'OrcamentoPdf');
     *   // inbound:  /app/docs/orcamento-pdf/7          → OrcamentoPdf::show(7)
     *   // outbound: urlFor('OrcamentoPdf','show',['id'=>7]) → /app/docs/orcamento-pdf/7
     *
     * Existe porque a classe de documento é PLANA (não estende MadComponent):
     * registrada por expose() ela cairia em MadAppController::run, que só
     * despacha MadComponent — 404 seco, e a página ficava sem URL nenhuma
     * (o docblock do gerador prometia `/docs/{Ctrl}/{id}` que ninguém registrava).
     *
     * Só GET, id obrigatório: documento não tem "new" nem métodos on*. Permissão
     * pelo mesmo gate das outras telas (mad.permission + canAccess no dispatch);
     * $opts['permissionFrom'] herda da tela dona, igual ao expose().
     */
    public static function exposeDocument(string $key, string $class, array $opts = []): void
    {
        foreach (self::slugsFor($key, $opts) as $slug) {
            Route::get('/app/docs/' . $slug . '/{id}', [MadAppController::class, 'document'])
                ->where('id', '[A-Za-z0-9_-]+')
                ->defaults('class', $class)
                ->defaults('method', 'show');
        }

        if (!empty($opts['permissionFrom'])) {
            self::$resourceForms[$class] = (string) $opts['permissionFrom'];
        }

        if (($slug = self::seg($key, 'slug', null)) !== null) {
            // kind=form reaproveita a interpolação de `:id` do urlFor (edit/editParam):
            // urlFor(Doc, 'show', ['id' => N]) → /docs/{slug}/N. Sem id não há documento.
            self::$routeMap[$class] = [
                'kind'      => 'form',
                'new'       => '/docs/' . $slug,
                'edit'      => '/docs/' . $slug . '/:id',
                'editParam' => 'id',
            ];
        }
    }

    /**
     * Página PÚBLICA do site: GET /{path} — na RAIZ, sem login, sem `/app`.
     * Mais POST no mesmo endereço, atendido só pela página que declara
     * `MadSitePage::acceptsPost()` (formulário do visitante).
     *
     *   MadRoutes::exposeSite('',             'HomePage',      ['home' => true]);
     *   MadRoutes::exposeSite('planos',       'PlanosPage',    ['nav' => ['label' => 'Planos', 'order' => 2]]);
     *   MadRoutes::exposeSite('blog/{slug}',  'BlogPostPage',  ['noindex' => true]);
     *
     * Diferenças para o expose() do painel, todas de propósito:
     *   - sem `{method?}`: página pública não expõe método pela URL (o que o
     *     visitante pode disparar está em `MadSitePage::publicActions()`);
     *   - sem prefixo `/app` e sem `mad.auth`: é a cara do produto, aberta;
     *   - o caminho NÃO é traduzido por locale — o endereço do site é um só,
     *     escolhido por quem publicou (é o que vai no anúncio e no buscador).
     *
     * `$opts`: `home` (bool, a inicial), `nav` (`['label' =>, 'order' =>]`, o
     * item de menu), `noindex` (bool, fora dos buscadores), `kind` (string, o
     * tipo da página — page/blog-index/blog-post/signup/legal).
     *
     * Nome da rota: `site.home` (caminho vazio) ou `site.<caminho>`. O nome vai
     * no array de ação (não em `->name()` encadeado) porque só assim ele entra
     * no índice de nomes na hora — e o `routes/web.php` pergunta
     * `Route::has('site.home')` LOGO DEPOIS, para decidir se a raiz continua
     * indo ao login.
     */
    public static function exposeSite(string $path, string $class, array $opts = []): void
    {
        $path = trim(trim($path), '/');
        $fqcn = self::resolveSiteClass($class);
        $name = $path === '' ? 'site.home' : 'site.' . self::siteRouteSlug($path);
        $uri  = $path === '' ? '/' : '/' . $path;

        $route = Route::get($uri, ['as' => $name, 'uses' => $fqcn . '@showPage']);

        // Envio de formulário do visitante (pedido, orçamento, agendamento):
        // POST no MESMO endereço, mesma ação. Existe para TODA página porque a
        // pergunta "esta página aceita envio?" (`MadSitePage::acceptsPost()`)
        // exige carregar a classe — e registrar rota não pode carregar página
        // (ver resolveSiteClass). Página que não pediu responde 405 no
        // showPage(), como antes. SEM nome de propósito: o nome é do GET (links,
        // `Route::has`), e dois registros com o mesmo nome quebram o
        // `route:cache`. Limitador próprio (`site-post`, declarado no
        // MadServiceProvider): não divide o contador com o contato e o cadastro.
        $post = Route::post($uri, ['uses' => $fqcn . '@showPage'])
            ->middleware('throttle:site-post');

        // Slug de conteúdo é minúsculo com hífen — a mesma forma que o gerador
        // de endereços produz. Sem a restrição, `/blog/QUALQUER%20COISA` casava
        // e a página respondia 404 só lá dentro.
        if (str_contains($path, '{slug}')) {
            $route->where('slug', '[a-z0-9-]+');
            $post->where('slug', '[a-z0-9-]+');
        }

        SiteRegistry::register($path, $fqcn, $opts + ['name' => $name]);

        self::$siteRoutes[$fqcn] = $uri;
    }

    /**
     * UM método de uma classe num caminho PRÓPRIO: GET+POST /app/{path}.
     *
     *   MadRoutes::exposeMethod('clientes/aprovar', 'ClienteList', 'onAprovar');
     *   // inbound:  /app/clientes/aprovar  → ClienteList::onAprovar
     *   // outbound: urlFor('ClienteList','onAprovar') → /app/clientes/aprovar
     *
     * Complementa o expose(), NÃO o substitui: `/app/{slug}/onAprovar` continua
     * respondendo (o expose registra `{method?}`), então URL já publicada não
     * quebra — a amigável entra como caminho ADICIONAL, e só o outbound muda.
     *
     * ⚠️ ORDEM IMPORTA. Laravel é first-match-wins e o `{method?}` do expose
     * casa um segmento qualquer: registrado DEPOIS do expose da mesma classe,
     * `/app/clientes/aprovar` cairia no expose com `method=aprovar` — que não é
     * o nome real do método (`onAprovar`) e daria 404 no dispatch. Registre as
     * linhas de exposeMethod ANTES das de expose.
     *
     * Caminho literal, sem locale: o slug amigável de método é definido uma vez
     * por projeto (é único project-wide), diferente do slug de PÁGINA, que é
     * traduzido em lang/{locale}/routes.php.
     */
    public static function exposeMethod(string $path, string $class, string $method): void
    {
        $path   = trim($path, '/');
        $method = self::normalizeMethodName($method);
        if ($path === '' || $method === '') {
            return;
        }

        Route::match(['get', 'post'], '/app/' . $path, [MadAppController::class, 'run'])
            ->defaults('class', $class)
            ->defaults('method', $method);

        self::$methodRoutes[$class . '@' . $method] = '/' . $path;
    }

    /** CRUD: GET /{slug} (list), /{slug}/{new} (form), /{slug}/{id}/{edit} (form::onEdit). */
    public static function resource(string $key, string $listClass, ?string $formClass = null, array $opts = []): void
    {
        $listMethod = $opts['listMethod'] ?? null;
        $editMethod = $opts['editMethod'] ?? 'onEdit';
        $editParam  = $opts['editParam']  ?? 'id';

        if ($formClass) {
            self::$resourceForms[$formClass] = $listClass; // herança de permissão
        }

        $seen = [];
        foreach ($opts['locales'] ?? self::I18N_LOCALES as $loc) {
            $slug = self::seg($key, 'slug', $loc);
            if ($slug === null || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            self::$uris['app/' . $slug] = true;

            Route::get('/app/' . $slug, [MadAppController::class, 'run'])
                ->defaults('class', $listClass)
                ->defaults('method', $listMethod);

            if ($formClass) {
                $new  = self::seg($key, 'new',  $loc) ?? 'novo';
                $edit = self::seg($key, 'edit', $loc) ?? 'editar';
                Route::get('/app/' . $slug . '/' . $new, [MadAppController::class, 'run'])
                    ->defaults('class', $formClass);
                Route::get('/app/' . $slug . '/{' . $editParam . '}/' . $edit, [MadAppController::class, 'run'])
                    ->defaults('class', $formClass)
                    ->defaults('method', $editMethod);
            }
        }

        // Outbound — locale ativo.
        $slug = self::seg($key, 'slug', null);
        if ($slug === null) {
            return;
        }

        self::$routeMap[$listClass] = ['kind' => 'list', 'path' => '/' . $slug, 'methodRoute' => false]; // list = GET /{slug} (sem {method?})
        // Pelo NOME da classe (Mad.go('Classe')): só a abertura, como o slug.
        self::classAlias($listClass, ['GET'], null, $listMethod);
        if ($formClass) {
            self::classAlias($formClass, ['GET'], null, null, [$editMethod => $editMethod]);
        }

        if ($formClass) {
            $new  = self::seg($key, 'new',  null) ?? 'novo';
            $edit = self::seg($key, 'edit', null) ?? 'editar';
            self::$routeMap[$formClass] = [
                'kind'      => 'form',
                'new'       => '/' . $slug . '/' . $new,
                'edit'      => '/' . $slug . '/:' . $editParam . '/' . $edit,
                'editParam' => $editParam,
            ];
        }
    }

    /**
     * Serviço AJAX pelo NOME da classe: GET+POST /{Classe}/{method?} (sem slug/mapa).
     * Retorna a Route pra permitir encadear middleware (ex.: uploader → status mapper).
     */
    public static function exposeClass(string $class): \Illuminate\Routing\Route
    {
        self::$uris['app/' . $class] = true;

        return Route::match(['get', 'post'], '/app/' . $class . '/{method?}', [MadAppController::class, 'run'])
            ->where('method', '[A-Za-z][A-Za-z0-9_]*')
            ->defaults('class', $class);
    }

    /**
     * Serviço AJAX com SLUG amigável em inglês: GET+POST /app/services/{slug}/{method?}.
     *
     * A URL não vaza o nome da classe interna — mas o dispatch é idêntico ao
     * exposeClass (o MadAppController lê a classe de ->defaults('class'), não da
     * URL; a PermissionGate também é keyada pela classe). Por isso o $class aqui
     * deve ser o MESMO nome usado antes na allowlist (ex.: 'MadDbSearchService',
     * 'MadDbComboService') — assim gate/compat continuam intactos.
     *
     * Popula o mapa outbound também: toFriendlyUrl('...?class='.$class.'...')
     * passa a render /services/{slug}/{method} (ex.: o endpoint do quick-register
     * montado server-side por MadNoResultsHelper vira amigável sem tocar no PHP).
     */
    public static function exposeService(string $slug, string $class): void
    {
        Route::match(['get', 'post'], '/app/services/' . $slug . '/{method?}', [MadAppController::class, 'run'])
            ->where('method', '[A-Za-z][A-Za-z0-9_]*')
            ->defaults('class', $class);

        self::$routeMap[$class] = ['kind' => 'list', 'path' => '/services/' . $slug, 'methodRoute' => true];
    }

    // ─── Consultas ────────────────────────────────────────────────────────────

    /** List da qual um form de resource herda permissão (PermissionGate). */
    public static function resourceFormList(string $formClass): ?string
    {
        return self::$resourceForms[$formClass] ?? null;
    }

    /**
     * URL amigável de um endpoint declarado — API DIRETA (classe + método +
     * query params). É o jeito certo de montar URL nova em PHP:
     *
     *   MadRoutes::urlFor('LoginForm', 'onLogout', ['static' => 1])
     *   // → /app/login/onLogout?static=1
     *
     * NÃO construa string 'index.php?class=...' só pra passar pelo
     * toFriendlyUrl() — ele existe pra converter URL legada JÁ EXISTENTE
     * (menus/actions assadas em dados), e delega aqui.
     *
     * Classe sem entrada no mapa → fallback /app/{class}[/{method}] (no modo
     * allowlist isso só resolve se houver exposeClass declarado — senão 404,
     * por design). Fora do driver web devolve a URL do dispatcher legado.
     */
    public static function urlFor(string $class, ?string $method = null, array $params = []): string
    {
        $method = self::normalizeMethodName($method);

        // Página pública do site: mora na raiz, fora do prefixo do painel.
        if (isset(self::$siteRoutes[$class])) {
            $qs = http_build_query($params);

            return RoutingDriver::basePath() . self::$siteRoutes[$class] . ($qs !== '' ? '?' . $qs : '');
        }

        $base  = RoutingDriver::appBase();
        $entry = self::$routeMap[$class] ?? null;
        $path  = null;

        // Rota amigável declarada para ESTE método (exposeMethod) tem
        // precedência sobre o mapa por classe: foi escolhida explicitamente
        // para o par (classe, método), enquanto o $routeMap só conhece a classe.
        // Params continuam na query string — exposeMethod registra caminho
        // literal, sem placeholder de id como o `edit` do resource().
        if ($method !== '' && isset(self::$methodRoutes[$class . '@' . $method])) {
            $path = self::$methodRoutes[$class . '@' . $method];
        } elseif ($entry && ($entry['kind'] ?? '') === 'list') {
            if ($method !== '' && $method !== 'show' && !($entry['methodRoute'] ?? false)) {
                // Classe registrada por screen()/resource-list: a rota é só
                // GET /{slug} (SEM {method?}). Um método (feed estático tipo
                // getEvents, ou sub-endpoint) vive na rota por NOME DE CLASSE
                // (exposeClass: /app/<Classe>/<metodo>) — slug+metodo daria 404.
                $path = '/' . rawurlencode($class) . '/' . rawurlencode($method);
            } else {
                // expose() (methodRoute=true) aceita /{slug}/{method?}; ou show.
                $path = $entry['path'];
                if ($method !== '' && $method !== 'show') {
                    $path .= '/' . rawurlencode($method);
                }
            }
        } elseif ($entry && ($entry['kind'] ?? '') === 'form') {
            $editParam = $entry['editParam'] ?? 'id';
            $id        = $params[$editParam] ?? null;
            if ($method === 'onEdit' || ($id !== null && $id !== '')) {
                unset($params[$editParam]);
                $path = str_replace(':' . $editParam, rawurlencode((string) $id), (string) $entry['edit']);
            } else {
                $path = (string) $entry['new'];
            }
        }

        if ($path === null) {
            $path = '/' . $class . ($method !== '' && $method !== 'show' ? '/' . $method : '');
        }

        $qs = http_build_query($params);

        return $base . $path . ($qs !== '' ? '?' . $qs : '');
    }

    /**
     * Converte URL LEGADA (index.php?class=X&method=Y&...) na rota amigável.
     * Só pra URLs que já existem como string (menu.xml, actions assadas em
     * dados); pra montar URL nova em código use urlFor().
     */
    public static function toFriendlyUrl(string $url): string
    {
        $q = strpos($url, '?');
        if ($q === false) {
            return $url;
        }

        parse_str(substr($url, $q + 1), $params);
        $class = (string) ($params['class'] ?? '');
        if ($class === '') {
            return $url;
        }

        $method = (string) ($params['method'] ?? '');
        unset($params['class'], $params['method']);

        return self::urlFor($class, $method !== '' ? $method : null, $params);
    }

    /** Rota amigável do login (deny dos middlewares). Slug igual nos 3 idiomas. */
    public static function loginUrl(): string
    {
        return RoutingDriver::appBase() . '/' . (self::seg('login', 'slug', null) ?? 'login');
    }

    // ─── Internos ─────────────────────────────────────────────────────────────

    /**
     * FQCN da página de site. O gerador escreve o BASENAME do control
     * (`HomePage`), que é o identificador de endereçamento do projeto — o
     * índice de controls devolve a classe real.
     *
     * Achado SEM carregar a página ({@see ClassSource::locate()}: o índice de
     * controls, a tela plana, o mapa do Composer). Isto roda ao registrar as
     * rotas, ou seja, em TODO pedido: com `class_exists` a página era carregada
     * ali, e uma página de site com erro de compilação (fatal, sem catch)
     * derrubava o app inteiro — login e telas internas incluídos — em vez de
     * ficar fora do ar sozinha, quando alguém a abrisse.
     */
    private static function resolveSiteClass(string $class): string
    {
        $class = ltrim(trim($class), '\\');
        if ($class === '') {
            return $class;
        }

        try {
            $where = ClassSource::locate($class);
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }
            $where = null;
        }

        return $where['fqcn'] ?? $class;
    }

    /** Caminho de site → sufixo do nome da rota (`blog/{slug}` → `blog-slug`). */
    private static function siteRouteSlug(string $path): string
    {
        $slug = strtolower(trim($path, '/'));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * Nome de método pronto para virar segmento de URL / chave de mapa.
     *
     * Parênteses vazios (`onShow()`, forma que o studio gravava) NUNCA podem
     * chegar no rawurlencode: virariam `onShow%28%29`, que a constraint do
     * expose() (`[A-Za-z][A-Za-z0-9_]*`) rejeita com 404. Rede de segurança
     * para quem monta URL sem passar pelo MadAction (ex.: friendlyTreeNavUrl).
     */
    private static function normalizeMethodName(?string $method): string
    {
        if ($method === null || trim($method) === '') {
            return '';
        }

        return (string) preg_replace('/\(\s*\)$/', '', trim($method));
    }

    /**
     * A MESMA tela também pelo NOME da classe: `/app/<Classe>[/<metodo>]`.
     *
     * Quem abre sem URL amigável — `Mad.go('ContaPagarList')` escrito à mão ou
     * vindo de código importado do 4.0 — cai na forma genérica
     * `/app/<Classe>/<metodo>`, que no modelo allowlist só existia com
     * exposeClass(): a tela estava declarada (slug `contapagarlist`) e mesmo
     * assim dava 404.
     *
     * Não abre superfície nova: só ganha o alias a classe JÁ declarada por
     * expose()/screen()/resource(), com os mesmos verbos e dentro do mesmo
     * grupo de middleware (autenticação + permissão) da declaração — e o
     * MadAppController lê a classe do default da rota, nunca da URL.
     *
     * @param list<string>          $verbs
     * @param string|null           $methods regex do {method?} (expose) ou null =
     *                                       só a abertura (/<Classe> e /<Classe>/show)
     * @param array<string, string> $fixed   segmento de método com rota própria => método
     */
    private static function classAlias(string $class, array $verbs, ?string $methods, ?string $defaultMethod = null, array $fixed = []): void
    {
        // FQCN (com `\\`) ou nome fora do padrão de classe não vira segmento de URL.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)) {
            return;
        }
        $uri = 'app/' . $class;
        // Slug idêntico ao nome da classe, ou alias/exposeClass já registrado.
        if (isset(self::$uris[$uri])) {
            return;
        }
        self::$uris[$uri] = true;

        if ($methods !== null) {
            Route::match($verbs, '/' . $uri . '/{method?}', [MadAppController::class, 'run'])
                ->where('method', $methods)
                ->defaults('class', $class);

            return;
        }

        // `/show` é o que o Mad.go manda sem método: abre como o slug (com o
        // método de entrada declarado, não um "show" literal).
        foreach (['' => $defaultMethod, 'show' => $defaultMethod] + $fixed as $segment => $method) {
            Route::match($verbs, '/' . $uri . ($segment !== '' ? '/' . $segment : ''), [MadAppController::class, 'run'])
                ->defaults('class', $class)
                ->defaults('method', $method);
        }
    }

    /** Slugs únicos da chave em todos os locales (inbound). */
    private static function slugsFor(string $key, array $opts): array
    {
        $seen = [];
        foreach ($opts['locales'] ?? self::I18N_LOCALES as $loc) {
            $slug = self::seg($key, 'slug', $loc);
            if ($slug !== null) {
                $seen[$slug] = true;
            }
        }

        return array_keys($seen);
    }

    /** Segmento traduzido routes.{key}.{seg} no locale (null = ativo); null se ausente. */
    private static function seg(string $key, string $seg, ?string $locale): ?string
    {
        $tkey  = "routes.{$key}.{$seg}";
        $value = Lang::get($tkey, [], $locale);
        if (!is_string($value) || $value === '' || $value === $tkey) {
            return null;
        }

        return trim($value, '/');
    }
}
