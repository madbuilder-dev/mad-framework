<?php

namespace Mad;

use App\Http\Middleware\MadApiTenantMiddleware;
use App\Models\Log\Sql;
use HarryGulliford\Firebird\Connectors\FirebirdConnector;
use HarryGulliford\Firebird\FirebirdConnection;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Mad\Console\GridPurgeExportsCommand;
use Mad\Console\RestDriverInstallCommand;
use Mad\Console\TraceFlushCommand;
use Mad\Console\TraceInfraCommand;
use Mad\Console\WebhookCommand;
use Mad\Console\WebhookTickCommand;
use Mad\Core\AppConfig;
use Mad\Database\MadPostgresConnection;
use Mad\Http\Controllers\GridExportDownloadController;
use Mad\Http\Controllers\MadAppController;
use Mad\Http\Controllers\MadBlobDownloadController;
use Mad\Http\Controllers\MadDownloadController;
use Mad\Http\Middleware\MadAuthenticate;
use Mad\Http\Middleware\MadProgramPermission;
use Mad\Http\RequestId;
use Mad\Http\TransactionId;
use Mad\I18n\MadLang;
use Mad\Rest\RestDriver;
use Mad\Rest\RestDriverSigner;
use Mad\Util\MadSqlCollector;
use Mad\View\MadBlade;
use Mad\Web\BladeDirectives;
use Mad\Webhook\WebhookController;
use Mad\Webhook\WebhookDefinition;
use Mad\Webhook\WebhookProcessor;
use Mad\Webhook\WebhookRegistry;
use Yajra\Oci8\Connectors\OracleConnector;
use Yajra\Oci8\Oci8Connection;

/**
 * MadServiceProvider — boot completo da camada MAD num app Laravel.
 *
 * Registra: motor de views (MadBlade sobre illuminate/view), caminho dos
 * configs de banco legados (TConnection), tradutores, o endpoint reativo
 * do MadWire (POST /app/_mad-wire) e os assets publicáveis.
 *
 * O app consome via auto-discovery (extra.laravel.providers do composer.json
 * do package) — zero código MAD no AppServiceProvider.
 */
class MadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Antes de qualquer coisa: um control endereçado com namespace errado
        // não pode matar a request (ver ControlNamespaceFallback). Só age depois
        // que o PSR-4 falhou, então não intercepta nada que já resolva.
        \Mad\Registry\ControlNamespaceFallback::register();

        // Defaults de config do pacote (chaves novas p/ apps que só atualizam o
        // vendor). Nível raiz apenas; skip quando config:cache — ver o arquivo.
        $this->mergeConfigFrom(__DIR__ . '/../../config/mad-defaults.php', 'mad');

        $this->registerTrace();
        $this->registerDatabaseDrivers();

        if ($this->app->runningInConsole()) {
            $this->commands([
                TraceFlushCommand::class,
                TraceInfraCommand::class,
                GridPurgeExportsCommand::class,
                RestDriverInstallCommand::class,
            ]);
        }

        // Fora do gate de console de propósito: a plataforma (e a Central de
        // Comando) chamam `Artisan::call('mad:webhook', …)` de dentro de uma
        // requisição web. `commands()` só registra um callback, não custa nada
        // a quem nunca sobe o Artisan.
        $this->commands([
            WebhookCommand::class,
            WebhookTickCommand::class,
            \Mad\Console\RoutineInstallCommand::class,
        ]);
    }

    /**
     * Envio acima do `post_max_size` do PHP: o Laravel recusa a requisição
     * (413, ValidatePostSize) antes de ela chegar à tela, com a página de erro
     * dele. Nas chamadas feitas pela tela a resposta passa a ser o aviso em
     * JSON — o tamanho enviado, o limite e "nada foi salvo" —, que o
     * mad-livewire.js mostra como aviso (MadComponentHandler::oversizedPostPayload).
     */
    private function bootOversizedPostNotice(): void
    {
        try {
            $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        } catch (\Throwable) {
            return;
        }
        if (!method_exists($handler, 'renderable')) {
            return;
        }

        $handler->renderable(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, $request) {
            if (!$request->ajax() && !$request->expectsJson()) {
                return null;
            }
            $payload = \Mad\Component\MadComponentHandler::oversizedPostPayload();
            if ($payload === null) {
                return null;
            }
            $status = (int) $payload['status'];
            unset($payload['status']);

            return new \Illuminate\Http\JsonResponse(
                $payload,
                $status,
                ['Cache-Control' => 'no-store, private'],
                \Illuminate\Http\JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE
            );
        });
    }

    public function boot(): void
    {
        $this->bootOversizedPostNotice();
        $this->bootPaths();
        $this->bootBlade();
        $this->bootDatabase();
        $this->bootTransactionCorrelation();
        $this->bootTranslators();
        $this->bootRoutes();
        $this->bootSitePostLimiter();
        $this->bootRestDriver();
        $this->bootPublishing();
        $this->bootTrace();
        $this->bootSchedulePause();
        $this->bootWebhooks();
        $this->bootRoutines();
        $this->bootVibePreview();
        self::applyTrustedProxies(config('mad.trusted_proxies', env('TRUSTED_PROXIES')));
    }

    /**
     * Modo Vibe (só no Teste Online — a plataforma escreve as chaves no `.env`
     * do container). Sem as chaves, nada é registrado: o app fica idêntico.
     */
    private function bootVibePreview(): void
    {
        if (\Mad\Support\VibePreview::agentBridge()) {
            // GLOBAL e na FRENTE de todos: vê a resposta que o handler de
            // exceção renderizou (404 sem rota, 419, 500) e a de manutenção.
            $this->app->make(\Illuminate\Contracts\Http\Kernel::class)
                ->prependMiddleware(\Mad\Http\Middleware\InjectAgentBridge::class);
        }

        if (\Mad\Support\VibePreview::previewLoginSecret() !== '') {
            // SSO do preview. GET (fora do CSRF) e FORA do log de requisições:
            // o token na query não pode ir parar no banco.
            Route::middleware(['web'])
                ->withoutMiddleware([\App\Http\Middleware\LogRequest::class])
                ->get('/__mad/preview-login', \Mad\Http\Controllers\PreviewLoginController::class)
                ->name('mad.preview-login');
        }
    }

    /**
     * Proxies confiáveis lidos DEPOIS do `.env`.
     *
     * O `bootstrap/app.php` lê `env('TRUSTED_PROXIES')` dentro do
     * `withMiddleware()`, que roda quando o kernel HTTP é montado — antes de o
     * `.env` ser carregado. Ali só vale variável do PROCESSO: no app publicado
     * por SSH, ZIP ou MadCloud a linha do `.env` era ignorada, e atrás de
     * nginx/Traefik todo visitante aparecia com o IP do proxy (o limite de
     * tentativas do login de um trancava o de todos). Aqui o `.env` já foi lido
     * (e `config:cache` também serve, pela chave `mad.trusted_proxies`).
     *
     * Num CONTAINER (Teste Online, MadCloud, hospedagem com Traefik) o proxy
     * conecta pela rede do Docker, não pelo loopback, e o app via o gateway
     * (172.18.0.1) como o endereço de todo mundo — inclusive na prova da
     * decisão do workflow, o IP que vai para o registro de aprovação (fw#470).
     * Lá, vazio, só loopback (`127.0.0.1,::1`, o padrão que o MadBuilder grava
     * no `.env` para o nginx do mesmo servidor) e `*` confiam nas sub-redes do
     * Docker ligadas ao container ({@see containerNetworks()}) mais o loopback.
     * O `*` do Laravel sozinho confia só no vizinho imediato: a cadeia chega
     * `<visitante>, 172.18.0.1` (o proxy do host entra pela ponte do Docker) e
     * o app parava no gateway (fw#470, reaberta).
     *
     * NÃO confia nas redes privadas inteiras nem em qualquer vizinho: quem
     * chega de IP privado (VPN, intranet, app hospedado na empresa) ou direto
     * de IP público não forja o X-Forwarded-For que vai para a prova da
     * decisão. Outro container na MESMA rede do Docker forja: no Teste Online a
     * rede dos apps é compartilhada (laboratório); em produção cada app tem a
     * própria. Sem a tabela de rotas, vazio/loopback caem nas redes privadas
     * ({@see CONTAINER_PROXIES}) e `*` fica como era.
     *
     * Lista com outro endereço é escolha de quem configurou e vale como está.
     * Fora de container, vazio = não mexe no que o `bootstrap/app.php` já
     * aplicou (não confia em ninguém). `none` = não confia em ninguém, nem no
     * container.
     *
     * @param  bool|null  $inContainer  null = detecta ({@see runningInContainer()})
     * @param  list<string>|null  $networks  null = lê ({@see containerNetworks()})
     */
    public static function applyTrustedProxies(mixed $raw, ?bool $inContainer = null, ?array $networks = null): void
    {
        $raw = trim((string) $raw);
        if (strtolower($raw) === 'none') {
            \Illuminate\Http\Middleware\TrustProxies::at(['none']);

            return;
        }
        $list = $raw === '' || $raw === '*' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));
        $containerDefault = $raw === '*' || array_diff($list, ['127.0.0.1', '::1']) === [];
        if ($containerDefault && ($inContainer ?? self::runningInContainer())) {
            $networks ??= self::containerNetworks();
            if ($networks !== []) {
                \Illuminate\Http\Middleware\TrustProxies::at([...$networks, '127.0.0.1', '::1']);

                return;
            }
            if ($raw !== '*') {
                \Illuminate\Http\Middleware\TrustProxies::at(self::CONTAINER_PROXIES);

                return;
            }
        }
        if ($raw === '') {
            return;
        }
        \Illuminate\Http\Middleware\TrustProxies::at($raw === '*' ? '*' : $list);
    }

    /**
     * Sub-redes ligadas ao container, pela tabela de rotas (`/proc/net/route`),
     * em CIDR. Um container em duas redes (o app numa, o Traefik noutra) recebe
     * o proxy pela segunda, que não é a do gateway padrão: as duas entram. A
     * rota padrão (0.0.0.0/0) nunca. Vazio quando a tabela não é legível.
     *
     * @return list<string>
     */
    public static function containerNetworks(?string $routeTable = null): array
    {
        $routeTable ??= @is_readable('/proc/net/route') ? (string) @file_get_contents('/proc/net/route') : '';
        $out = [];
        foreach (array_slice(preg_split('/\R/', trim($routeTable)) ?: [], 1) as $line) {
            $c = preg_split('/\s+/', trim($line)) ?: [];
            if (count($c) < 8 || ! ctype_xdigit($c[1].$c[2].$c[7])) {
                continue;
            }
            [$dest, $gateway, $mask] = [self::routeIp($c[1]), self::routeIp($c[2]), self::routeIp($c[7])];
            $bits = substr_count(decbin((int) ip2long($mask)), '1');
            if ($gateway === '0.0.0.0' && $dest !== '0.0.0.0' && $bits > 0) {
                $out[] = $dest.'/'.$bits;
            }
        }

        return array_values(array_unique($out));
    }

    /** Endereço da tabela de rotas do kernel: hexadecimal, bytes em ordem inversa. */
    private static function routeIp(string $hex): string
    {
        return implode('.', array_reverse(array_map('hexdec', str_split(str_pad($hex, 8, '0', STR_PAD_LEFT), 2))));
    }

    /** Reserva do container sem tabela de rotas legível: redes privadas e loopback. */
    public const CONTAINER_PROXIES = [
        '127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7',
    ];

    /**
     * O app roda dentro de um container (Docker ou Podman)? Com `open_basedir`
     * (Teste Online, hospedagem que tranca o PHP na pasta do app) o caminho fora
     * dela dá aviso, e no boot o Laravel faz do aviso uma exceção: o app não
     * subia. Fora do alcance = não é container, e `*` fica como era.
     */
    public static function runningInContainer(): bool
    {
        return @is_file('/.dockerenv') || @is_file('/run/.containerenv');
    }

    /**
     * Rotinas (`app/config/routines.json`, Mad\Routine): a exclusão do registro
     * de origem desfaz o que a rotina gerou (`on_delete`). O desfazer tem de
     * rodar ANTES do guard de integridade que o model gerado registra no
     * `booted()` (filho existe = recusa): listener ESPECÍFICO por origem, pelo
     * nome da classe (nada é carregado no boot), registrado agora e no
     * `eloquent.booting` de cada model; o curinga fica de reserva. Sem o
     * arquivo, nada é registrado nem consultado. O gatilho "ao salvar" mora no
     * `MadForm::save()`.
     */
    private function bootRoutines(): void
    {
        $events = $this->app['events'];
        \Mad\Routine\RoutineTriggers::listen($events);
        $events->listen('eloquent.booting: *', [\Mad\Routine\RoutineTriggers::class, 'booting']);
        $events->listen('eloquent.deleting: *', [\Mad\Routine\RoutineTriggers::class, 'deleting']);
    }

    /**
     * Webhooks recebidos — `POST /api/webhooks/{slug}` e
     * `POST /api/webhooks/{slug}/{key}` ({@see WebhookController}).
     *
     * Uma rota FIXA do framework atende todos os webhooks declarados em
     * `app/config/webhooks.json`: nenhuma rota é gerada por webhook, então o
     * `routes/api.php` do projeto não tem como derrubar o recurso.
     *
     * Tudo dentro do `booted`, que roda depois de TODOS os providers (os
     * arquivos de rota do app inclusive):
     *
     *  - a rota entra por último na coleção, e o Laravel casa a primeira que
     *    serve — uma rota do próprio projeto em `/api/webhooks/*` continua
     *    vencendo;
     *  - o limitador só é declarado se o app não declarou o seu
     *    (`RateLimiter::for('mad-webhook', …)` no app vence).
     *
     * Limitador NOMEADO, por slug + IP, com o limite da definição: o numérico
     * (`throttle:N,M`) contaria todos os webhooks no mesmo balde e não saberia
     * o limite de cada um. Slug desconhecido divide um balde só por IP, para
     * que sondar endereços não crie uma chave de cache por tentativa.
     *
     * Sem grupo `web`: sem sessão, sem cookie e sem CSRF. O `where()` nos dois
     * segmentos faz o que não tem forma de slug/chave nem chegar ao controller.
     */
    private function bootWebhooks(): void
    {
        $this->app->booted(static function (): void {
            if (RateLimiter::limiter('mad-webhook') === null) {
                RateLimiter::for('mad-webhook', static function (Request $request) {
                    $slug = (string) $request->route('slug');
                    $definition = WebhookRegistry::find($slug);

                    return Limit::perMinute($definition?->rateLimit ?? 60)
                        ->by('mad-webhook|' . ($definition !== null ? $slug : '?') . '|' . $request->ip());
                });
            }

            Route::post('/api/webhooks/{slug}/{key?}', WebhookController::class)
                ->where('slug', WebhookDefinition::SLUG_PATTERN)
                ->where('key', WebhookDefinition::KEY_PATTERN)
                ->middleware('throttle:mad-webhook')
                ->name('mad.webhook');
        });

        // Batimento do worker da fila: é por ele que o modo `auto` sabe se há
        // quem tire o job da fila. Dispara a cada volta do `queue:work`; a
        // gravação no cache é limitada a uma a cada 30 s.
        Event::listen(Looping::class, static fn () => WebhookProcessor::beat());

        // Manutenção a cada minuto (falhas com recuo, entregas paradas, limpeza).
        // Pulada enquanto o app não tem nenhum webhook definido.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('mad:webhook:tick')
                ->everyMinute()
                ->withoutOverlapping(10)
                ->skip(static fn () => ! WebhookRegistry::hasDefinitions());
        });
    }

    /**
     * Pause de tarefas agendadas (cron-respeitante). O Laravel NÃO tem pause nativo;
     * a Central de Comando pausa gravando em `mad_sys_schedule_override` (active=0).
     * Aqui adicionamos um ->skip() a CADA evento do Schedule consultando essa tabela
     * — então `schedule:run` (o cron) pula as pausadas DE VERDADE. O nome da tarefa =
     * o comando artisan (mesma extração da Central de Comando, p/ a chave casar).
     */
    private function bootSchedulePause(): void
    {
        // O Schedule do Laravel 11 carrega os eventos do routes/console.php LAZY
        // (defineConsoleSchedule, na resolução DENTRO do comando) — então boot()/
        // callAfterResolving veem 0 eventos. O hook certo é o CommandStarting dos
        // comandos de schedule: aí resolvemos o Schedule (a factory carrega os eventos)
        // e injetamos o ->skip; é o MESMO singleton que o comando usa em seguida.
        // SEM gate de console: precisa valer tanto pro CRON (CLI `schedule:run`) quanto
        // pro botão "Executar agendador" da Central (web → Artisan::call('schedule:run')).
        // O listener só age em comando de schedule, então não pesa no GET da aba.
        // Pausa = linha em mad_sys_schedule_override com active=0.
        Event::listen(CommandStarting::class, function ($event) {
            if (! in_array($event->command, ['schedule:run', 'schedule:work', 'schedule:test', 'schedule:list'], true)) {
                return;
            }
            try {
                $schedule = $this->app->make(Schedule::class);
                $conn = (string) (config('mad.schedule.connection') ?: 'iam');
                foreach ($schedule->events() as $sevent) {
                    $cmd = (string) ($sevent->command ?? '');
                    $name = preg_match('/\bartisan[\'"]?\s+(.+)$/s', $cmd, $m)
                        ? trim($m[1])
                        : (method_exists($sevent, 'getSummaryForDisplay') ? trim((string) $sevent->getSummaryForDisplay()) : trim($cmd));
                    if ($name === '') {
                        continue;
                    }
                    $sevent->skip(function () use ($conn, $name) {
                        try {
                            return DB::connection($conn)
                                ->table('mad_sys_schedule_override')
                                ->where('task_name', $name)->where('active', 0)->exists();
                        } catch (\Throwable $e) {
                            return false; // sem tabela/conn → não pausa (fail-open)
                        }
                    });
                }
            } catch (\Throwable $e) {
                // sem Schedule/console routes → nada a pausar
            }
        });
    }

    /**
     * Limite de envios de formulário às páginas do site (`throttle:site-post`
     * na rota POST de `MadRoutes::exposeSite()`): 20 por minuto por IP.
     *
     * Limitador NOMEADO pelo mesmo motivo do `site-lead`/`site-signup` do app:
     * o limite numérico (`throttle:N,M`) conta por origem sem olhar o endereço
     * e dividiria o contador com o contato e o cadastro do site.
     *
     * Só é declarado se o app não declarou o seu: o `booted` roda depois de
     * TODOS os providers (o AppServiceProvider inclusive), então quem quer
     * outro limite define `RateLimiter::for('site-post', …)` no app e vence.
     * Sem definição nenhuma a rota POST quebraria — o Laravel recusa
     * limitador inexistente.
     */
    private function bootSitePostLimiter(): void
    {
        $this->app->booted(static function (): void {
            if (RateLimiter::limiter('site-post') !== null) {
                return;
            }

            RateLimiter::for('site-post', static fn (Request $request) => Limit::perMinute(20)
                ->by('site-post|' . $request->ip()));
        });
    }

    /**
     * Driver REST — endpoint serviço-a-serviço que o MadBuilder Database Manager
     * usa p/ operar o banco do app gerado sem abrir porta/VPN. Fail-closed:
     * as rotas só existem com mad.rest_driver.enabled (env MAD_REST_DRIVER_ENABLED).
     * Autenticação 100% HMAC (RestDriverHmac), sem sessão — rate-limit por chave.
     */
    private function bootRestDriver(): void
    {
        RateLimiter::for('mad-rest-driver', function (Request $request) {
            $keyId = (string) $request->header(RestDriverSigner::HEADER_KEY, $request->ip());

            return Limit::perMinute(RestDriver::rateLimit())->by('mad-rd:'.$keyId);
        });

        if (! RestDriver::enabled()) {
            return;
        }

        RestDriver::registerRoutes();
    }

    /**
     * MadTrace (erros + APM) — install o mais cedo possível dentro do ciclo
     * de providers. NÃO registra set_error_handler/set_exception_handler: o
     * HandleExceptions do Laravel é dono deles (exceptions chegam via
     * reportable(), wired em bootTrace; fatais via shutdown handler próprio).
     */
    private function registerTrace(): void
    {
        if (! class_exists('MadTrace')) {
            return;
        }

        $cfg = (array) config('mad.trace', []);
        if (empty($cfg['enabled'])) {
            return;
        }
        unset($cfg['enabled'], $cfg['db_listen'], $cfg['sql_log']);

        \MadTrace::install($cfg);
    }

    /**
     * Registra resolvers/connectors dos drivers fora do core do Illuminate
     * (Oracle via yajra/laravel-oci8, Firebird via harrygulliford/laravel-firebird).
     * Guard por class_exists: sem o pacote é no-op — os drivers nativos
     * (sqlite/mysql/pgsql/sqlsrv) seguem normalmente. Registra no container do
     * app para que uma conexão com dbinfo oracle/firebird resolva via
     * DB::connection() nativo.
     *
     * pgsql ganha a MadPostgresConnection (bool/int sob PgBouncer — ver a
     * classe). Só se o app não tiver registrado resolver próprio.
     */
    private function registerDatabaseDrivers(): void
    {
        if (Connection::getResolver('pgsql') === null) {
            Connection::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
                return new MadPostgresConnection($connection, $database, $prefix, $config);
            });
        }

        if (class_exists('Yajra\\Oci8\\Oci8Connection')
            && class_exists('Yajra\\Oci8\\Connectors\\OracleConnector')) {
            Connection::resolverFor('oracle', function ($connection, $database, $prefix, $config) {
                return new Oci8Connection($connection, $database, $prefix, $config);
            });
            $this->app->bind('db.connector.oracle', fn () => new OracleConnector);
        }

        if (class_exists('HarryGulliford\\Firebird\\FirebirdConnection')
            && class_exists('HarryGulliford\\Firebird\\Connectors\\FirebirdConnector')) {
            Connection::resolverFor('firebird', function ($connection, $database, $prefix, $config) {
                return new FirebirdConnection($connection, $database, $prefix, $config);
            });
            $this->app->bind('db.connector.firebird', fn () => new FirebirdConnector);
        }
    }

    /** PATH = raiz do app (BACKLOG F1-06: migrar call sites pra base_path()). */
    private function bootPaths(): void
    {
        if (! defined('PATH')) {
            define('PATH', base_path());
        }
    }

    private function bootBlade(): void
    {
        MadBlade::configure([
            'views' => resource_path('views'),
            'cache' => storage_path('framework/mad-blade'),
        ]);

        BladeDirectives::register();
        Site\BladeDirectives::register();
    }

    private function bootDatabase(): void
    {
        $this->ensureSqliteDatabases();
    }

    /**
     * Id de correlação por transação (Mad\Http\TransactionId) via eventos
     * NATIVOS do Illuminate — substitui o getUniqId() do helper de transação legado.
     * Sempre ligado (independe de mad.trace): o ChangeLogService usa o id p/
     * agrupar diffs da mesma transação na coluna transaction_id. Push/pop são
     * balanceados (cada beginTransaction casa com commit OU rollback, inclusive
     * SAVEPOINTs aninhados).
     */
    private function bootTransactionCorrelation(): void
    {
        Event::listen(TransactionBeginning::class, fn () => TransactionId::push());
        Event::listen(TransactionCommitted::class, fn () => TransactionId::pop());
        Event::listen(TransactionRolledBack::class, fn () => TransactionId::pop());
    }

    /**
     * Garante que o arquivo sqlite de cada conexão MAD exista antes do primeiro
     * connect. O SQLiteConnector nativo do Laravel lança
     * SQLiteDatabaseDoesNotExistException quando o arquivo não existe — o caminho
     * legado criava o arquivo sob demanda (touch). Sem isto, um clone novo / CI
     * (mad.sqlite é gitignored) quebra em qualquer query MAD.
     * Best-effort: se o FS for read-only, o connect adiante dá o erro claro do
     * próprio Laravel. ':memory:' e conexões não-sqlite são ignoradas.
     */
    private function ensureSqliteDatabases(): void
    {
        foreach ((array) config('mad.app_connections', []) as $name) {
            $cfg = config("database.connections.{$name}");
            if (! is_array($cfg) || ($cfg['driver'] ?? null) !== 'sqlite') {
                continue;
            }
            $path = (string) ($cfg['database'] ?? '');
            if ($path === '' || str_contains($path, ':memory:') || is_file($path)) {
                continue;
            }
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                @touch($path);
            }
        }
    }

    private function bootTranslators(): void
    {
        $ini = AppConfig::get();
        $lang = $ini['general']['language'] ?? 'pt';

        MadLang::setLocale($lang);
        if (! defined('LANG')) {
            define('LANG', $lang);
        }
    }

    /** Endpoint reativo do MadWire — mesmo path do RoutingDriver::wireEndpoint(). */
    private function bootRoutes(): void
    {
        // Aliases pros middlewares do framework (usáveis como 'mad.auth' etc).
        $router = $this->app['router'];
        $router->aliasMiddleware('mad.auth', MadAuthenticate::class);
        $router->aliasMiddleware('mad.permission', MadProgramPermission::class);
        // API pública stateless: auth por token Bearer + escopo tenant/unit num
        // passo só (classe app-level, como os models IAM que BelongsToTenant referencia).
        $router->aliasMiddleware('mad.api', MadApiTenantMiddleware::class);

        // Wire: só Auth (com passe anônimo pra classes públicas via mad_state);
        // permissão de AÇÃO é checada no controller (canAccessWire).
        Route::middleware(['web', 'mad.auth'])
            ->post('/app/_mad-wire', [MadAppController::class, 'wire'])
            ->name('mad.wire');

        // ── Site público ────────────────────────────────────────────────────
        // Canal reativo das páginas públicas: SEM login (o visitante não tem
        // sessão) e com porteiro próprio no controller — só página de site e só
        // as ações que ela declarou como públicas. O limite de requisições é a
        // primeira contenção: é o único POST anônimo do sistema.
        Route::middleware(['web', 'throttle:60,1'])
            ->post('/public/_mad-wire', [\Mad\Site\MadSiteWireController::class, 'handle'])
            ->name('mad.site.wire');

        // Mapa do site e instruções para buscadores. Vêm como ROTA (e não como
        // arquivo em public/) porque precisam refletir as páginas publicadas —
        // um arquivo estático é servido antes de o sistema ser consultado e
        // ficaria sempre desatualizado.
        Route::middleware(['web'])
            ->get('/sitemap.xml', \Mad\Site\SiteSitemapController::class)
            ->name('mad.site.sitemap');

        Route::middleware(['web'])
            ->get('/robots.txt', \Mad\Site\SiteRobotsController::class)
            ->name('mad.site.robots');

        // Download de uploads (substitui o download.php legado) — os campos
        // de arquivo geram URLs via mad_download_url(). Validação de path no controller.
        // 'signed:relative' torna a URL infalsificável e não-enumerável: o
        // usuário só recebe URLs assinadas dos arquivos que a página que ele pode
        // ver renderiza — não consegue forjar URL pro path de outro usuário/tenant
        // (fecha o IDOR cross-tenant). Assinatura relativa = host-agnóstica.
        Route::middleware(['web', 'mad.auth', 'signed:relative'])
            ->get('/app/_mad-download', MadDownloadController::class)
            ->name('mad.download');

        // Download de BLOB base64 do banco (campos storage="db"). model+col
        // validados contra config('mad.blob_downloads') no controller.
        Route::middleware(['web', 'mad.auth'])
            ->get('/app/_mad-blob', MadBlobDownloadController::class)
            ->name('mad.blob');

        // Arquivo PÚBLICO de upload (campo com `public`, #244): sem login e
        // fora do `web` (sem sessão/cookie — cacheável). Só `_public/…` com
        // HMAC válido; o controller recusa (404) todo o resto.
        Route::get('/arquivos/{path}', \Mad\Http\Controllers\MadPublicFileController::class)
            ->where('path', '_public/.+')
            ->name('mad.public-file');

        // Download dos exports da grade (CSV/XLSX/PDF). Arquivo fora do público,
        // servido só logado; 410 Gone quando já foi purgado (mad:grid:purge-exports).
        Route::middleware(['web', 'mad.auth'])
            ->get('/app/_mad-grid-export', GridExportDownloadController::class)
            ->name('mad.grid.export');
    }

    /**
     * Wiring Laravel do MadTrace: boot real, exceptions (reportable), nome
     * de rota, bridge DB::listen (Eloquent/DB facade) e eventos de fila e
     * scheduler. Tudo no-op quando o install não rodou (trace.enabled=false).
     * Os corpos não precisam de try/catch: a API do MadTrace nunca lança.
     */
    private function bootTrace(): void
    {
        if (! class_exists('MadTrace') || empty(config('mad.trace.enabled'))) {
            return;
        }

        // boot_ms = bootstrap real do Laravel (re-stampa o fim do boot)
        $this->app->booted(fn () => \MadTrace::markBootEnd());

        // Exceptions: HandleExceptions já reporta tudo (inclusive FatalError)
        // pelo ExceptionHandler — pendura o capture lá. Dedup de fatal contra
        // o shutdown handler acontece dentro do MadTrace (file:line).
        $handler = $this->app->make(ExceptionHandler::class);
        if (method_exists($handler, 'reportable')) {
            $handler->reportable(fn (\Throwable $e) => \MadTrace::captureException($e));
        }

        // Nome da transação APM pra rotas Laravel. Requests do MadWire são
        // refinadas DEPOIS pelo MadComponentHandler ("Classe::ação") — last
        // write wins, ordem correta.
        Event::listen(RouteMatched::class, function (RouteMatched $e) {
            \MadTrace::setTransactionName($e->route->getName() ?: $e->route->getActionName());
            \MadTrace::setTransactionType($e->request->is('api/*') ? 'rest' : 'web');
        });

        // Bridge Eloquent/DB facade → APM + coleta p/ modal de debug + log SQL
        // opcional. TODAS as conexões são nativas, então QueryExecuted de qualquer
        // conexão (inclusive as MAD) chega aqui. Dispara pós-exec → tempo real.
        if (config('mad.trace.db_listen', true)) {
            DB::listen(function (QueryExecuted $q) {
                // banco 'log' fora do bridge — não auto-instrumenta o próprio log.
                if (($q->connectionName ?? '') === 'log') {
                    return;
                }
                if (\MadTrace::apmActive()) {
                    \MadTrace::recordQuery($q->sql, $q->connectionName, (float) $q->time);
                }
                MadSqlCollector::recordTimed($q->sql, $q->connectionName, (float) $q->time);

                // Log SQL persistente (ex-slog), opt-in via mad.trace.sql_log.
                if (config('mad.trace.sql_log', false)) {
                    self::writeSqlLog($q);
                }
            });
        }

        // Fila (port do MadQueueWorker legado — aqui o worker é o artisan
        // queue:work nativo, os eventos são os mesmos).
        Event::listen(JobProcessing::class, function (JobProcessing $e) {
            if (! \MadTrace::performanceEnabled()) {
                return;
            }
            \MadTrace::beginJob($e->job->resolveName(), [
                'queue' => $e->job->getQueue() ?: 'default',
                'attempts' => $e->job->attempts(),
            ]);
        });
        Event::listen(JobProcessed::class, fn () => \MadTrace::endJob('ok'));
        Event::listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $e) => \MadTrace::endJob('failed', $e->exception));

        // Scheduler — trace por task (tipo job, queue=scheduler). Sem tabela
        // mad_sys_schedule_log: decisão de escopo, paridade fica pro Command Center.
        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $e) {
            if (! \MadTrace::performanceEnabled()) {
                return;
            }
            \MadTrace::beginJob($e->task->command ?: ($e->task->description ?: 'scheduled-task'), [
                'queue' => 'scheduler',
            ]);
        });
        Event::listen(ScheduledTaskFinished::class, fn () => \MadTrace::endJob('ok'));
        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $e) => \MadTrace::endJob('failed', $e->exception));
    }

    /**
     * Log SQL persistente opt-in (mad.trace.sql_log). Substitui o antigo slog
     * (SqlLogService/DebugLogger plugados em Transaction) por um writer nativo
     * no DB::listen global. Grava só DML (INSERT/UPDATE/DELETE) em
     * App\Models\Log\Sql (conexão 'log'), com SQL literal e id de correlação
     * por transação. Best-effort: nunca derruba a query que está sendo logada.
     *
     * Segredos ({@see \Mad\Security\SqlLogMasker}): instrução sobre tabela de
     * credencial/privada (ProtectedData) não é gravada; nas demais, o valor de
     * coluna de senha/token/segredo sai mascarado. Até o 5.97.0 trocar a senha
     * gravava o hash e ligar o 2FA gravava o segredo em texto.
     */
    private static function writeSqlLog(QueryExecuted $q): void
    {
        try {
            $type = strtoupper(substr(ltrim($q->sql), 0, 6));
            if (! in_array($type, ['INSERT', 'UPDATE', 'DELETE'], true)) {
                return;
            }

            $sqlCommand = \Mad\Security\SqlLogMasker::rawSqlFor($q);
            if ($sqlCommand === null) {
                return;
            }

            $driver = $q->connection->getDriverName();
            $date_mask = in_array($driver, ['sqlsrv', 'dblib'], true) ? 'Ymd H:i:s' : 'Y-m-d H:i:s';

            Sql::create([
                'logdate' => date($date_mask),
                'log_year' => date('Y'),
                'log_month' => date('m'),
                'log_day' => date('d'),
                'login' => session('login'),
                'database_name' => $q->connectionName,
                'sql_command' => $sqlCommand,
                'statement_type' => $type,
                'access_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'transaction_id' => TransactionId::get(),
                'log_trace' => (new \Exception)->getTraceAsString(),
                'session_id' => session()->getId(),
                'class_name' => $_REQUEST['class'] ?? '',
                'php_sapi' => php_sapi_name(),
                'request_id' => RequestId::get(),
            ]);
        } catch (\Throwable $e) {
            // log nunca pode derrubar a query
        }
    }

    private function bootPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // Mapa fonte→servido (mesmo do composer mad:sync). vendor:publish publica
        // SÓ o grupo 'package' (fonte em packages/); 'served_copies' é app-level
        // (fonte na raiz app/lib) e fica a cargo exclusivo do mad:sync.
        // O mapa mora no próprio pacote: vale instalado por path repo
        // (packages/mad-framework) ou pelo Packagist (vendor/madbuilder/framework).
        $root = base_path();
        $packageRoot = dirname(__DIR__, 2);
        $map = require $packageRoot.'/assets/asset-map.php';

        $publish = [];
        foreach (($map['package'] ?? []) as $srcRel => $dstRel) {
            // Chaves do mapa são relativas à raiz de um app com path repo
            // ("packages/mad-framework/assets/…"); vendor:publish quer absolutos.
            $src = str_starts_with($srcRel, 'packages/mad-framework/')
                ? $packageRoot.'/'.substr($srcRel, strlen('packages/mad-framework/'))
                : $root.'/'.$srcRel;
            $publish[$src] = $root.'/'.$dstRel;
        }

        $this->publishes($publish, 'mad-assets');
    }
}
