<?php

namespace Mad\Security;

use Mad\Core\AppConfig;

/**
 * PermissionGate — fonte única de verdade da permissão de programa/ação.
 *
 * Centraliza a lógica de permissão de programa/ação (antes espalhada em
 * dispatcher + BuilderPermissionService). É usada por estes caminhos (todos
 * via mad_can_access() ou direto):
 *
 *   1. app/middleware/{AuthAdmin,ProgramPermission}Middleware — rotas /app/*
 *   2. SystemPermission::checkPermission — callback de filtro do menu (NotchMenu)
 *   3. views Blade — diretiva @canAccess / helper mad_can_access()
 *
 * Mantê-la única garante que "botão escondido ⇒ rota negada".
 *
 * Fonte da verdade da sessão (inalterada): TSession 'logged' / 'programs' /
 * 'programs_actions', populadas no login por ApplicationAuthenticationService.
 */
class PermissionGate
{
    /** Usuário autenticado? */
    public static function isLogged(): bool
    {
        return (bool) session('logged');
    }

    /** Lista de classes públicas do config/mad.php [permission] public_classes[]. */
    public static function publicClasses(): array
    {
        $ini = \Mad\Core\AppConfig::get();
        $list = $ini['permission']['public_classes'] ?? [];
        return is_array($list) ? $list : [];
    }

    /**
     * Classe é pública para acesso ANÔNIMO (sem login)? public_classes[] ou
     * tela que se declara pública ({@see isPublicPage()}) cuja porta
     * ({@see publicGuardDenial()}) deixa passar AGORA.
     *
     * IMPORTANTE: NÃO inclui [user_public_pages]. Isso espelha o gate original,
     * cujo gate de NÃO-LOGADO usa apenas `$public = in_array($class,
     * public_classes)` — as páginas mobile ([user_public_pages]) só liberam
     * para usuários LOGADOS (ver isMobilePublic + canAccess).
     *
     * A porta só roda quando a requisição é DA PRÓPRIA tela (abri-la, ou uma
     * ação dela) ou quando a classe já está carregada. Fora disso — o menu, a
     * busca, um botão `@canAccess`, que perguntam de dezenas de telas — rodar a
     * porta seria carregar TODAS elas, e uma única tela com erro de compilação
     * (fatal, sem catch) derrubaria todas as páginas do app. Ali a decisão é
     * pelo código: tela pública SEM porta (em lugar nenhum da linhagem) conta
     * como pública; com porta, ou sem como provar que não tem, não conta — e a
     * permissão de programa decide, como para qualquer tela. A porta roda de
     * verdade quando a requisição chegar à tela.
     *
     * @param  bool|null  $forRequest  a requisição é desta tela? null = pela rota atual
     */
    public static function isPublic(string $class, ?bool $forRequest = null): bool
    {
        if ($class === '') {
            return false;
        }
        if (in_array($class, self::publicClasses(), true)) {
            return true;
        }

        $decl = self::publicDeclaration($class);
        if ($decl === null) {
            return false;
        }

        if ($forRequest ?? self::isRequestTarget($class)) {
            return self::publicGuardDenial($class) === null;
        }
        if (class_exists($decl['fqcn'], false)) {
            // Já carregada: rodar a porta não carrega nada.
            return self::publicGuardDenial($class) === null;
        }

        return self::lineage($decl)['guard'] === false;
    }

    /**
     * A TELA se declara pública — a "página pública" do MadBuilder 4.0 (o
     * portal do cliente, o formulário aberto a visitantes):
     *
     *   protected static bool $public = true;
     *
     * Abre sem login do app, para anônimo e para logado, exatamente como um
     * public_classes[] — mas a marca mora na própria classe: viaja com a tela,
     * não depende de editar config/mad.php e vale com as rotas em cache.
     *
     * Opcionalmente a tela declara a sua PORTA, chamada em toda requisição
     * (abrir a tela e cada ação reativa dela) — ver {@see publicGuardDenial()}:
     *
     *   public static function publicGuard(array $params = []): ?string
     *   {
     *       return session('cliente_logado') ? null : 'LoginClienteForm';
     *   }
     *
     * Aceita o basename (`PainelClienteForm`) ou o FQCN. Só tela (MadComponent)
     * ou documento PDF (classe com `show($id)`, rota exposeDocument).
     *
     * A decisão LÊ o código da tela ({@see ClassSource}) e nunca carrega a
     * classe: é perguntada para cada item do menu. Vale só a declaração no
     * corpo da PRÓPRIA classe — herdada, comentada, dentro de texto ou com
     * valor calculado não conta.
     *
     * Com a marca, a tela também pode pedir um link na tela de login do app
     * (`protected static string $loginLink = 'Área do Cliente'`) — ver
     * {@see PublicLoginLinks}.
     */
    public static function isPublicPage(string $class): bool
    {
        return self::publicDeclaration($class) !== null;
    }

    /**
     * Declaração da tela pública (a marca, lida do código), ou null. Nunca
     * carrega a classe: a que já está carregada é conferida por reflection; a
     * que não está, pela linhagem declarada no código.
     *
     * @return array<string,mixed>|null
     */
    private static function publicDeclaration(string $class): ?array
    {
        $class = ltrim(trim($class, " \t\r\n"), '\\');
        if ($class === '') {
            return null;
        }

        try {
            // Filtro barato: arquivo que não cita `$public` nem é tokenizado.
            $decl = ClassSource::of($class, '$public');
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }

            return null;
        }
        if ($decl === null || $decl['kind'] !== 'class' || $decl['abstract']
            || ($decl['props']['public'] ?? null) !== true) {
            return null;
        }

        $page = class_exists($decl['fqcn'], false)
            ? self::isPageClass($decl['fqcn'])
            : self::lineage($decl)['page'];

        return $page === true ? $decl : null;
    }

    /**
     * Tela (MadComponent) ou documento (`show($id)` público de instância)?
     * Só para classe JÁ carregada — nada aqui dispara autoload.
     */
    private static function isPageClass(string $fqcn): bool
    {
        if (is_a($fqcn, \Mad\Component\MadComponent::class, true)) {
            return true;
        }

        try {
            $show = new \ReflectionMethod($fqcn, 'show');

            return $show->isPublic() && !$show->isStatic();
        } catch (\ReflectionException) {
            return false;
        }
    }

    /**
     * O que o CÓDIGO diz da tela, subindo pela classe-mãe e pelos traits
     * declarados — sem carregar nenhum deles:
     *
     *  - `page`:  é tela (chega em MadComponent) ou documento (`show` público de instância);
     *  - `guard`: declara `publicGuard()` em algum ponto da linhagem.
     *
     * null = não dá para saber sem carregar (arquivo não achado, linhagem longa
     * demais). Quem decide trata null como "não" para `page` e como "tem porta"
     * para `guard` — na dúvida, fecha.
     *
     * Classe-mãe do framework (`Mad\…`) encerra a subida: nenhuma declara porta,
     * e a marca numa classe cuja mãe é do framework é a de uma tela.
     *
     * @param  array<string,mixed>  $decl
     * @return array{page:?bool, guard:?bool}
     */
    private static function lineage(array $decl, int $depth = 0): array
    {
        $method = $decl['methods']['show'] ?? null;
        $page = is_array($method) && $method['public'] && !$method['static'];
        $guard = isset($decl['methods']['publicguard']);

        if ($depth > 12) {
            return ['page' => $page ?: null, 'guard' => $guard ?: null];
        }

        $sources = [];
        foreach ($decl['traits'] as $trait) {
            $sources[] = [$trait, 'trait'];
        }
        if ($decl['parent'] !== '') {
            $sources[] = [$decl['parent'], 'class'];
        }

        foreach ($sources as [$name, $kind]) {
            $up = self::ancestor($name, $kind, $depth);
            $page = self::either($page, $kind === 'class' ? $up['page'] : false);
            $guard = self::either($guard, $up['guard']);
        }

        return ['page' => $page, 'guard' => $guard];
    }

    /** @return array{page:?bool, guard:?bool} */
    private static function ancestor(string $name, string $kind, int $depth): array
    {
        if (strcasecmp($name, \Mad\Component\MadComponent::class) === 0) {
            return ['page' => true, 'guard' => false];
        }
        if (class_exists($name, false) || trait_exists($name, false)) {
            // Já carregada: a resposta exata, sem risco.
            return [
                'page'  => $kind === 'class' && self::isPageClass($name),
                'guard' => method_exists($name, 'publicGuard'),
            ];
        }
        if (str_starts_with($name, 'Mad\\')) {
            return ['page' => $kind === 'class', 'guard' => false];
        }

        $up = ClassSource::of($name);
        if ($up === null || $up['kind'] !== $kind) {
            return ['page' => null, 'guard' => null];
        }

        return self::lineage($up, $depth + 1);
    }

    /** OU de três estados: true vence; senão, desconhecido vence. */
    private static function either(?bool $a, ?bool $b): ?bool
    {
        if ($a === true || $b === true) {
            return true;
        }

        return $a === null || $b === null ? null : false;
    }

    /**
     * A requisição atual é da própria tela (a classe da rota)? É só aí que a
     * tela pública pode ser carregada para rodar a porta — ela vai ser
     * carregada de qualquer forma para abrir. O canal reativo não passa por
     * aqui: {@see canAccessWire()} já sabe que a classe do estado é o alvo.
     */
    private static function isRequestTarget(string $class): bool
    {
        if (!function_exists('request')) {
            return false;
        }

        try {
            $target = request()->route()?->parameter('class');
        } catch (\Throwable) {
            return false;
        }
        if (!is_string($target) || $target === '') {
            return false;
        }

        // O identificador de tela é o basename (único no app): compara por ele.
        $base = static function (string $name): string {
            $name = ltrim(trim($name), '\\');
            $pos = strrpos($name, '\\');

            return strtolower($pos === false ? $name : substr($name, $pos + 1));
        };

        return $base($target) === $base($class);
    }

    /**
     * Porta da tela pública: o `publicGuard()` dela, chamado a CADA requisição.
     *
     * No 4.0 a guarda da página ("se o cliente não entrou, volte para o login")
     * morava no construtor, que rodava em toda chamada — abrir a tela, buscar,
     * paginar, excluir. No 5.0 o `mount()` roda só na abertura; uma ação
     * reativa chega com o estado já montado e pularia a guarda. A porta fecha
     * esse buraco: quem não passa não abre a tela nem executa ação nenhuma dela.
     *
     * A porta recebe `array $params`: os parâmetros com que a tela vai ABRIR
     * (os mesmos que o `mount()` recebe — query, corpo, rota e `_forward_param_*`),
     * para conferir o registro pedido (`$params['key'] ?? $params['id']`) antes
     * de ele ser exibido. Numa ação reativa chega vazio: o registro já está no
     * estado cifrado, validado na abertura.
     *
     * Retorno do `publicGuard()`: `null`/`true` = segue; `false`/`''` = recusa
     * (vai para o login do app); nome de tela = recusa e manda o visitante para
     * ela. Porta que lança, que não é `public static` ou que manda para a
     * própria tela conta como recusa — na dúvida, fecha.
     *
     * @param  array<string,mixed>|null  $params  null = os da requisição atual
     * @return string|null  null = liberado; '' = recusado sem destino; senão a
     *                      tela para onde mandar o visitante
     */
    public static function publicGuardDenial(string $class, ?array $params = null): ?string
    {
        // Sem a marca não há porta — e nada é carregado (a decisão lê o código).
        $decl = self::publicDeclaration($class);
        if ($decl === null) {
            return '';
        }

        // Daqui em diante a classe É carregada: é a tela pública, e quem chega
        // aqui é a requisição dela (ver isPublic()). Marca numa classe que não é
        // tela nem documento fecha.
        $fqcn = self::componentClass($decl['fqcn']);
        if ($fqcn === null) {
            return '';
        }
        if (!method_exists($fqcn, 'publicGuard')) {
            return null;
        }

        try {
            $method = new \ReflectionMethod($fqcn, 'publicGuard');
            if (!$method->isStatic() || !$method->isPublic()) {
                return '';
            }
            $verdict = $fqcn::publicGuard($params ?? self::openingParams());
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }

            return '';
        }

        if ($verdict === null || $verdict === true) {
            return null;
        }
        if (!is_string($verdict)) {
            return '';
        }
        $target = trim($verdict);
        $self = \Mad\Registry\ControlRegistry::idFor($fqcn);

        return $target === $self || $target === $fqcn ? '' : $target;
    }

    /**
     * Parâmetros com que a tela vai abrir nesta requisição — a mesma montagem
     * de MadAppController::run() + MadComponent::show(): query e corpo, os
     * parâmetros da rota por cima e os `_forward_param_*` por baixo (sem o
     * prefixo). Vazio no canal reativo: ali quem manda é o estado cifrado.
     *
     * @return array<string,mixed>
     */
    private static function openingParams(): array
    {
        if (!function_exists('request')) {
            return [];
        }
        $request = request();
        if (str_contains($request->path(), '_mad-wire')) {
            return [];
        }

        $params = array_merge($request->query(), $request->post());
        foreach (($request->route()?->parameters() ?? []) as $key => $value) {
            if ($key !== 'class' && $key !== 'method' && is_scalar($value)) {
                $params[$key] = (string) $value;
            }
        }

        $forward = [];
        $clean = [];
        foreach ($params as $key => $value) {
            if (str_starts_with((string) $key, '_forward_param_')) {
                $forward[substr((string) $key, strlen('_forward_param_'))] = $value;
            } else {
                $clean[$key] = $value;
            }
        }

        return array_merge($forward, $clean);
    }

    /**
     * FQCN da tela (MadComponent) ou do DOCUMENTO (PDF) pelo basename ou FQCN;
     * null se não for nenhum dos dois.
     *
     * Documento gerado é classe simples com `show($id)`, servida por
     * MadRoutes::exposeDocument — a única rota que despacha classe que não é
     * tela (o `run()` recusa com 404 o que não é MadComponent, e o canal reativo
     * só hidrata componente). Ele abre sem login com a mesma marca e a mesma
     * porta das telas; a porta recebe o `id` da rota em `$params`.
     *
     * ⚠️ CARREGA a classe. Só {@see publicGuardDenial()} chama, e só para a tela
     * que se declarou pública e que esta requisição vai abrir — nunca para
     * montar menu (ver {@see isPublic()}).
     */
    private static function componentClass(string $class): ?string
    {
        if ($class === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $class)) {
            return null;
        }
        $fqcn = $class;
        if (!class_exists($fqcn) && class_exists(\Mad\Registry\ControlRegistry::class)) {
            $fqcn = \Mad\Registry\ControlRegistry::resolve($class) ?? '';
        }
        if ($fqcn === '' || !class_exists($fqcn)) {
            return null;
        }
        if (is_subclass_of($fqcn, \Mad\Component\MadComponent::class)) {
            return $fqcn;
        }

        try {
            $show = new \ReflectionMethod($fqcn, 'show');

            return $show->isPublic() && !$show->isStatic() ? $fqcn : null;
        } catch (\ReflectionException) {
            return null;
        }
    }

    /**
     * Classe está em [user_public_pages] (páginas públicas mobile)? No
     * gate original isso é o `$public_mobile`, usado SÓ no ramo de usuário logado.
     */
    public static function isMobilePublic(string $class): bool
    {
        if ($class === '') {
            return false;
        }
        $ini = \Mad\Core\AppConfig::get();
        $mobile = array_keys($ini['user_public_pages'] ?? []);
        return in_array($class, $mobile, true);
    }

    /**
     * Programas sempre liberados para usuários logados (serviços de sistema do
     * legado/Builder/Mad). Movido do getDefaultPermissions() legado
     * para cá — agora é a fonte canônica.
     */
    public static function defaultPermissions(): array
    {
        // Telas e serviços sempre liberados a todo usuário logado. Controls são
        // endereçados pelo BASENAME da classe (UserForm, MessageList, ...); os
        // serviços de sistema (legados/Mad*/Builder*/System*) NÃO são controls de
        // App\Control e mantêm seu nome próprio.
        return [
            // Controls universais (casco/top-bar: login, busca, notificações, mensageria).
            "LoginForm" => true,
            "EmptyPage" => true,
            "WelcomeView" => true,
            "SearchBox" => true,
            "NotificationCenter" => true,
            "NotificationView" => true,
            "NotificationList" => true,
            "HeaderMessageList" => true,   // feed do envelope (top-bar)
            "MessageList" => true,         // inbox/compose
            "MessageForm" => true,
            "MessageFormView" => true,
            "NewChatForm" => true,
            "NewChatGroupForm" => true,
            "SettingsForm" => true,
            "MyDashboards" => true, // tela "Meus Dashboards" (micro-BI, iframe React)
            // Serviços de sistema (não-controls).
            "SystemSupportForm" => true,
            "SearchInputBox" => true,
            "BuilderPageService" => true,
            "BuilderConfigForm" => true,
            // Builder*DiffForm/Update/ConfigList + SystemFrameworkUpdate REMOVIDOS
            // (remoção do legado): rotas-fantasma deletadas (classes nunca implementadas).
            "SystemPageService" => true,
            "SystemPageBatchUpdate" => true,
            "SystemPermissionUpdate" => true,
            "SystemMenuUpdate" => true,
            // Troca de unidade/empresa (top-bar, rota só-auth). Chave = BASENAME de
            // runtime (o legado "SystemChangeUnitForm" ficou stale após o rename e
            // nunca casava com o idFor — wire da troca era negado).
            "ChangeUnitForm" => true,
            "ChangeTenantForm" => true,
            // Modal de escolha de unidade após trocar de empresa pela combo do header
            // (teleportado por ChangeTenantForm::onSwitchTenant; wire do confirm precisa
            // passar aqui — não é um "programa" da sessão).
            "SwitchUnitForm" => true,
            "BuilderService" => true,
            "MadSeekGrid" => true,
            // O grid do <mad-seek> roda com a classe completa (sem alias global).
            "Mad\\Seek\\MadSeekGrid" => true,
            "MadAutoFillService" => true,
            "MadDbComboService" => true,
            "MadDbSearchService" => true,
            "MadDbEntryService" => true,
            "MadQuickRegisterService" => true, // cadastro inline no-results dos selects (auth = token MadStateCrypt por request)
            // Helpers de campo (cep/cnpj) — universais a todo logado, como os db-*.
            // (A auth real é o token MadStateCrypt cifrado em cada request.)
            // Vale TAMBÉM para a auto-criação de cidade/estado (MadLocationResolver):
            // o cliente só controla o dígito consultado e QUAL token pronto replay —
            // model/colunas/mapa de criação viajam selados (AES-256-GCM) e o universo
            // de escrita é fechado (27 UFs + ~5570 municípios, dedupe pelo match).
            // O create exige um lookup pago bem-sucedido antes, então o rate limit
            // do billing upstream já limita a taxa — flipar isto p/ false quebraria
            // o CEP de todo app existente por permissão explícita ausente.
            "MadCepService" => true,
            "MadCnpjService" => true,
            "Mad\\Grid\\MadGrid" => true,
        ];
    }

    /**
     * Decisão central de acesso a um programa (classe) e, opcionalmente, ação
     * (método). Semântica da decisão:
     *
     *   - classe pública                        → permite
     *   - não logado                            → só LoginForm
     *   - logado, classe não está nos programas → nega
     *   - logado, "Visualizar" desmarcado       → nega (a tela inteira)
     *   - logado, ação explicitamente == false  → nega
     *   - caso contrário                         → permite
     *
     * A ação é comparada de DUAS formas (ver {@see ActionVocab}): pela chave do
     * vocabulário que a tela de Perfis marca (`onDelete` → `delete`) e pelo
     * nome cru do método — apps publicados antes desta versão gravaram as ações
     * como `[{"action":"onSave"}]` e continuam valendo.
     *
     * `onSave`/`onSaveDraft` NÃO são decididos aqui: salvar é incluir ou editar
     * conforme haja registro aberto, e isso só se sabe depois de o componente
     * estar hidratado — ver {@see canRunAction()}.
     *
     * @param string      $class  Nome do controller/programa.
     * @param string|null $method Ação (método). Null = só checa programa.
     */
    public static function canAccess(string $class, ?string $method = null): bool
    {
        return self::decide($class, $method, null);
    }

    /**
     * {@see canAccess()} dizendo se a requisição é DESTA tela — o canal
     * reativo sabe (a classe vem do estado cifrado); os demais descobrem pela
     * rota ({@see isRequestTarget()}). Só na requisição da própria tela a porta
     * de uma tela pública pode carregar a classe.
     */
    private static function decide(string $class, ?string $method, ?bool $forRequest): bool
    {
        if ($class === '') {
            return false;
        }

        // Normaliza p/ o IDENTIFICADOR de runtime (o BASENAME: UserForm / LoginForm) —
        // a chave usada em public_classes / defaultPermissions / session('programs'). O
        // wire manda get_class() = App\Control\<Dominio>\<Nome>; idFor() devolve o
        // basename (sem isso, ação via wire = 401). Serviço/basename já pronto volta igual.
        if (class_exists(\Mad\Registry\ControlRegistry::class)) {
            $class = \Mad\Registry\ControlRegistry::idFor($class);
        }

        // public_classes[] libera anônimo E logado (engine: $public).
        if (self::isPublic($class, $forRequest)) {
            return true;
        }

        if (!self::isLogged()) {
            // engine NÃO-LOGADO: `$class == 'LoginForm' || $public`.
            // ($public já tratado acima; mobile NÃO libera anônimo.)
            return $class === 'LoginForm';
        }

        // Logado: [user_public_pages] libera (engine: $public_mobile).
        if (self::isMobilePublic($class)) {
            return true;
        }

        // session('programs')/('programs_actions') vêm CRUS de mad_iam_program.controller
        // (User::getPrograms) — as chaves já são o BASENAME do control (UserForm), igual
        // ao $class normalizado acima por idFor().
        $programs = (array) session('programs');
        $programs = array_merge($programs, self::defaultPermissions());
        $programs_actions = (array) session('programs_actions');

        if (!isset($programs[$class])) {
            // Herança de permissão: um form declarado como resource (resourceI18n)
            // NÃO é um "programa" próprio — herda a permissão da sua LIST. Espelha
            // o fallback do AppRouteResolver::run(), mas aqui no gate central, de
            // modo que o canal reativo (canAccessWire → este método) também herde:
            // antes o form de resource ABRIA (run tinha o fallback) mas o onSave/
            // onEdit via wire era negado ("Permissão negada").
            $listClass = \Mad\Routing\MadRoutes::resourceFormList($class);
            if ($listClass !== null && $listClass !== $class && isset($programs[$listClass])) {
                return true;
            }
            return false;
        }

        $acoes = (is_array($programs_actions) && isset($programs_actions[$class]) && is_array($programs_actions[$class]))
            ? $programs_actions[$class]
            : [];

        // "Visualizar" é a permissão de ABRIR a tela (D5): desmarcada, nada do
        // programa passa — nem a navegação direta, nem o canal reativo.
        if (self::deniedIn($acoes, 'view')) {
            return false;
        }

        if ($method !== null && $method !== '') {
            // Compat: chave gravada com o nome do método (apps publicados).
            if (self::deniedIn($acoes, $method)) {
                return false;
            }
            // Vocabulário: onDelete/onMadGridDelete → delete, onExport* → export…
            $chave = ActionVocab::staticKey($method);
            if ($chave !== null && self::deniedIn($acoes, $chave)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fase CONTEXTUAL do gate — roda no canal reativo depois de o componente
     * estar hidratado, que é a única hora em que dá para saber se um "Salvar" é
     * inclusão (registro novo) ou edição (registro aberto).
     *
     * Decide SÓ por ação: quem não pode abrir a tela já foi recusado antes, por
     * {@see canAccess()}/{@see canAccessWire()}.
     *
     * O dono da permissão é a TELA. O `<mad-grid>` declarativo roda como
     * `Mad\Grid\MadGrid` (liberado a todo usuário logado, como todo serviço do
     * framework), então sem isto a exclusão embutida dele não passava por
     * permissão nenhuma; o componente informa os donos reais por `_permOwners()`.
     *
     * Um grid pode ter DOIS donos — a tela que o contém e a classe de ações
     * configurada nele. Basta um dizer não: negação não é revogada por um
     * segundo dono que simplesmente não conhece aquela ação.
     *
     * Ação que o perfil não declara continua liberada — a plataforma nunca
     * inventa restrição que o usuário não pediu.
     */
    public static function canRunAction(object $component, string $method): bool
    {
        return self::deniedActionKey($component, $method) === null;
    }

    /**
     * A MESMA decisão de {@see canRunAction()}, dizendo QUAL chave foi negada —
     * `delete`, `edit`, ou o nome do método numa ação própria (`onAprovar`).
     *
     * Existe porque a recusa precisa dizer o que foi recusado: enquanto o 403
     * do canal reativo repetia a mensagem de TELA ("Você não tem acesso a esta
     * tela"), quem clicava em Excluir numa tela que ABRE normalmente recebia um
     * recado que contradizia o que estava vendo. Com a chave na mão, o corpo do
     * 403 vira "Sem permissão para excluir" — a mesma frase da dica do botão
     * cinza ({@see ActionGuard}), que é o outro lado do mesmo contrato.
     *
     * @return string|null  null = a ação está liberada
     */
    public static function deniedActionKey(object $component, string $method): ?string
    {
        if ($method === '') {
            return null;
        }

        $chave = ActionVocab::keyFor($method, self::isNewRecord($component));

        foreach (self::ownersOf($component) as $class) {
            // Compat: chave gravada com o nome do método (apps publicados). O
            // rótulo vem do vocabulário quando ele conhece o método — "Sem
            // permissão para excluir" diz mais do que "para on delete".
            if (!self::allows($class, $method)) {
                return $chave ?? $method;
            }
            if ($chave !== null && !self::allows($class, $chave)) {
                return $chave;
            }
        }

        return null;
    }

    /**
     * Classes cujas permissões valem para as ações deste componente.
     *
     * O caso comum é uma só: a própria classe do componente. Componentes que
     * executam ações "de outra pessoa" — hoje só o `<mad-grid>` declarativo —
     * declaram os donos reais.
     *
     * @return list<string>
     */
    private static function ownersOf(object $component): array
    {
        foreach (['_permOwners', '_permOwner'] as $metodo) {
            if (!method_exists($component, $metodo)) {
                continue;
            }
            try {
                $donos = array_values(array_filter(
                    array_map(
                        fn ($d) => is_string($d) ? trim($d) : '',
                        (array) $component->$metodo()
                    ),
                    fn ($d) => $d !== ''
                ));
            } catch (\Throwable $e) {
                continue; // dono indisponível → tenta o próximo / cai na classe
            }

            if ($donos !== []) {
                return array_values(array_unique($donos));
            }
        }

        return [get_class($component)];
    }

    /**
     * A chave está liberada para a classe? Consulta PURA da sessão, usada tanto
     * pelo gate quanto pelos botões (um botão escondido tem que corresponder a
     * uma rota recusada, e vice-versa).
     *
     * Só nega o que está explicitamente marcado como negado: chave ausente, ou
     * programa sem nenhuma marcação, é liberado.
     */
    public static function allows(string $class, string $key): bool
    {
        if ($class === '' || $key === '') {
            return true;
        }

        if (class_exists(\Mad\Registry\ControlRegistry::class)) {
            $class = \Mad\Registry\ControlRegistry::idFor($class);
        }

        $programs_actions = session('programs_actions');
        if (!is_array($programs_actions)
            || !isset($programs_actions[$class])
            || !is_array($programs_actions[$class])) {
            return true;
        }

        return !self::deniedIn($programs_actions[$class], $key);
    }

    /**
     * A tela está sem registro carregado (= um cadastro novo)?
     *
     * Devolve null quando não dá para saber — aí o método contextual fica sem
     * chave e passa, que é o comportamento de sempre.
     */
    private static function isNewRecord(object $component): ?bool
    {
        if (!method_exists($component, '_recordId')) {
            return null;
        }

        try {
            $id = $component->_recordId();
        } catch (\Throwable $e) {
            return null;
        }

        return $id === null || $id === '';
    }

    /** A chave existe no mapa de ações E está explicitamente negada? */
    private static function deniedIn(array $acoes, string $key): bool
    {
        return isset($acoes[$key]) && $acoes[$key] == false;
    }

    /**
     * Gate de permissão para o ciclo reativo (wire). A classe alvo vem do
     * estado criptografado (mad_state) e a AÇÃO real do mad_action — não da
     * URL. Usado pelo MadAppWireController (rota /app/_mad-wire) e pelo
     * AppRouteResolver (catch-all /app/{class}/wire?static=1), garantindo que
     * AMBAS as portas do wire em modo web apliquem a permissão por ação.
     *
     * Retorna true quando permitido OU quando não há contexto suficiente
     * (estado ausente/inválido) — nesse caso o MadComponentHandler trata o
     * estado inválido.
     */
    public static function canAccessWire(array $post): bool
    {
        $token = $post['mad_state'] ?? null;
        if (!$token) {
            return true; // handler retornará erro de estado
        }

        $decoded = \Mad\Component\MadComponent::_decryptState((string) $token);
        if (!is_array($decoded) || empty($decoded['class'])) {
            // Token PRESENTE mas indecifrável = adulteração/forja (tag GCM falhou)
            // OU expirado. Fail-CLOSED: nega. (Estado AUSENTE cai no ramo acima e
            // deixa o handler renderizar o erro amigável de estado.) Antes isto
            // retornava true — um primitivo de authz fail-open latente.
            return false;
        }

        $class  = (string) $decoded['class'];
        $action = (string) ($post['mad_action'] ?? '');

        // A classe do estado é a tela desta requisição: a porta dela roda.
        return self::decide($class, $action !== '' ? $action : null, true);
    }

    /**
     * Quando {@see canAccessWire()} recusa, foi por AÇÃO ou por PROGRAMA?
     *
     * Devolve a chave da ação negada (`delete`, `export`, `onAprovar`) quando a
     * tela em si ABRE para esta pessoa e só o método é que não passa; devolve
     * null quando a recusa é da tela inteira (programa ausente, "Visualizar"
     * desmarcado) — aí o recado certo continua sendo o de sempre.
     *
     * Sem esta distinção as três portas do canal reativo diziam coisas
     * diferentes para a mesma recusa: o `/app/_mad-wire` (que passa pelo gate
     * estático) respondia "Você não tem acesso a esta tela" ao Excluir negado,
     * enquanto as outras duas — que caem direto na fase contextual — já
     * respondiam "Sem permissão para excluir".
     */
    public static function deniedWireActionKey(array $post): ?string
    {
        $token = $post['mad_state'] ?? null;
        if (!$token) {
            return null;
        }

        $decoded = \Mad\Component\MadComponent::_decryptState((string) $token);
        if (!is_array($decoded) || empty($decoded['class'])) {
            return null; // token forjado/expirado: recusa de acesso, não de ação
        }

        $class  = (string) $decoded['class'];
        $action = (string) ($post['mad_action'] ?? '');
        if ($class === '' || $action === '') {
            return null;
        }

        // A tela abre, mas o método não passa ⇒ a recusa é da AÇÃO.
        if (!self::decide($class, null, true) || self::decide($class, $action, true)) {
            return null;
        }

        return ActionVocab::staticKey($action) ?? $action;
    }
}
