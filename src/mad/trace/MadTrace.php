<?php
/**
 * MadTrace — captura de erros/exceptions/fatais (schema 1) + APM de
 * performance (schema 2) estilo Sentry/APM. Port Laravel do cliente legado
 * (lib/mad/trace/MadTrace.php do mad-framework legado).
 *
 * Classe GLOBAL (sem namespace) de propósito: os call-sites do port
 * (Mad\Util\MadSqlCollector, MadComponentHandler, McpAccessAudit) já chamam
 * \MadTrace guardados por class_exists('MadTrace').
 * Carregada via autoload.files do composer (disponível em HTTP, artisan e
 * queue:work). Install acontece no MadServiceProvider::register() lendo
 * config('mad.trace').
 *
 *     MadTrace::install([
 *         'dsn'         => 'https://PUBLIC_KEY@host/api/trace/ingest',
 *         'app'         => 'M4d ERP',
 *         'env'         => 'production',
 *         'performance' => true,           // liga o APM (schema 2)
 *     ]);
 *
 * --- ERROS (schema 1) ---
 * No Laravel, exceptions entram via ExceptionHandler->reportable() (wired no
 * provider) — NÃO registramos set_error_handler/set_exception_handler aqui:
 * o bootstrapper HandleExceptions do Laravel é dono deles e roda ANTES dos
 * providers (re-registrar deslocaria a conversão erro→ErrorException).
 * Fatais (parse/OOM/timeout) continuam cobertos pelo shutdown handler
 * próprio, com dedup contra o caminho reportable (FatalError). Manual:
 *
 *     MadTrace::captureException($e, ['extra' => 'algo']);
 *     MadTrace::captureMessage('fraude mercadopago', 'warning', ['tx' => 999]);
 *
 * --- PERFORMANCE / APM (schema 2) ---
 * Abre um trace de transação no install e fecha no shutdown (ou em
 * finishRequest()), reportando route, status, duração, breakdown
 * (boot/php/db/view/ext), memória, lista de SQL com tempo real por query,
 * waterfall de spans, N+1 e jobs/filas.
 *
 *     $span = MadTrace::startSpan('ext', 'SEFAZ webservice');
 *     // ... chamada externa ...
 *     $span->finish();
 *
 *     MadTrace::beginJob('NotaFiscalEnvioJob', ['queue' => 'nfe']);
 *     // ... execução do job ...
 *     MadTrace::endJob('ok');
 *
 * Garantias:
 *  - NUNCA quebra a aplicação (todo o caminho é try/catch + @).
 *  - Trabalho caro (backtrace por query, spans, timing) só roda em requisições
 *    AMOSTRADAS (apmActive()); requisições não amostradas pagam ~zero.
 *  - Envio non-blocking (fastcgi_finish_request quando existe; sob artisan
 *    serve não existe — fail-soft) + timeout curto, fail-open.
 *  - Fallback error_log() sempre, mesmo se o endpoint cair.
 *  - Scrubbing de segredos, reserva de memória p/ reportar fatais de OOM.
 *
 * @version 2.0-laravel
 */
class MadTrace
{
    /** @var array config resolvida */
    private static $config = [];

    /** @var bool já instalado? (evita registro duplo) */
    private static $installed = false;

    /** @var bool shutdown já processou erros? */
    private static $sent = false;

    /** @var array|null resultado do último POST (diagnóstico): endpoint, schema, status, error, bytes */
    private static $lastSend = null;

    /** @var bool resposta já liberada ao cliente (fastcgi_finish_request) */
    private static $flushed = false;

    /** @var array<int,array> eventos de erro (schema 1) enfileirados p/ envio no shutdown */
    private static $pending = [];

    /** @var array fingerprints já enviados nesta request (dedup) */
    private static $seen = [];

    /** @var int erros (error|fatal) registrados nesta request — força keep do APM */
    private static $errorCount = 0;

    /** @var array contexto extra acumulado (user, tags) */
    private static $context = ['user' => [], 'tags' => [], 'extra' => []];

    /**
     * "file:line" do último \Error capturado via captureException() — o
     * reportable do Laravel entrega o FatalError ANTES do shutdown; o
     * handleShutdown() usa isso p/ não reportar o mesmo fatal duas vezes.
     *
     * @var string|null
     */
    private static $fatalNoted = null;

    /**
     * Buffer de memória reservado no install e liberado no início do shutdown,
     * para que um fatal de "Allowed memory size exhausted" ainda tenha folga
     * para montar + serializar + enviar o evento.
     *
     * @var string|null
     */
    private static $memReserve;

    // ───────────────────────────── APM (schema 2) ─────────────────────────────

    /** @var float T0 da request (REQUEST_TIME_FLOAT) */
    private static $t0 = 0.0;

    /** @var float microtime no fim do boot */
    private static $bootEndWall = 0.0;

    /** @var int hrtime no fim do boot */
    private static $bootEndHr = 0;

    /** @var bool markBootEnd() já re-stampou o fim do boot? (idempotência) */
    private static $bootMarked = false;

    /** @var bool decisão de amostragem (estável por request) */
    private static $sampledIn = false;

    /** @var bool transação APM já enviada (idempotência) */
    private static $txSent = false;

    /** @var string|null nome da transação (route) setado por hook */
    private static $txName = null;

    /** @var string|null tipo da transação (web|rest|job|cli) */
    private static $txType = null;

    /** @var array<int,array> spans explícitos (boot/app/view/ext) */
    private static $spans = [];

    /** @var int sequência de spans */
    private static $spanSeq = 0;

    /** @var array<int,int> pilha de ids de span (parent tracking) */
    private static $spanStack = [];

    /** @var array<int,array> queries de precisão (com src/time real) */
    private static $queries = [];

    /** @var int sequência de queries */
    private static $querySeq = 0;

    /** @var int|null índice da última query pendente de tempo */
    private static $lastQ = null;

    /** @var bool a lista de queries foi truncada (cap) */
    private static $queriesTruncated = false;

    /** @var bool processando um job (filas) */
    private static $jobMode = false;

    /** @var bool job atual já finalizado (evita envio duplo retry/failed) */
    private static $jobEnded = false;

    /** @var array contexto do job atual */
    private static $job = [];

    /** @var float wall-clock do início do job */
    private static $jobStartWall = 0.0;

    /** @var array|null último job finalizado (lido pelo agente infra) */
    private static $lastJob = null;

    /** Teto de spans por request (anti-explosão). */
    const MAX_SPANS = 1000;

    /** Limite de bytes do payload (o receptor rejeita > 256 KB). Margem de segurança. */
    const MAX_PAYLOAD_BYTES = 240000;

    /** flags de json_encode — substitui UTF-8 inválido em vez de falhar o envelope inteiro */
    const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

    /** mapa de severidade PHP -> nível MadTrace */
    private static $levelMap = [
        E_ERROR             => 'fatal',
        E_PARSE             => 'fatal',
        E_CORE_ERROR        => 'fatal',
        E_COMPILE_ERROR     => 'fatal',
        E_USER_ERROR        => 'error',
        E_RECOVERABLE_ERROR => 'error',
        E_WARNING           => 'warning',
        E_CORE_WARNING      => 'warning',
        E_COMPILE_WARNING   => 'warning',
        E_USER_WARNING      => 'warning',
        E_NOTICE            => 'info',
        E_USER_NOTICE       => 'info',
        E_DEPRECATED        => 'info',
        E_USER_DEPRECATED   => 'info',
        2048                => 'info', // E_STRICT (constante deprecada no PHP 8.4)
    ];

    /**
     * Instala os handlers. Idempotente.
     *
     * @param array $config dsn, app, env, release, performance, sampling, etc.
     */
    public static function install(array $config)
    {
        if (self::$installed) {
            return;
        }

        self::$config = array_merge([
            // ── erros (schema 1) ──
            'dsn'           => '',
            'app'           => 'app',
            'env'           => 'production',
            'release'       => null,
            'min_level'     => 'warning',   // info < warning < error < fatal
            'sample_rate'   => 1.0,         // 0..1 (amostragem de eventos de erro)
            'timeout_ms'    => 1500,        // tempo máx de envio (erros)
            'max_per_request' => 25,        // teto de eventos de erro por request
            'scrub_keys'    => ['password','passwd','pass','secret','token','authorization',
                                'api_key','apikey','access_token','client_secret','card',
                                'card_number','cvv','cvc','senha','credit_card','cpf','cnpj','pin'],
            // Laravel: HandleExceptions é dono de set_error_handler /
            // set_exception_handler. true = registra os nativos mesmo assim
            // (uso fora do Laravel — escape hatch de paridade com o legado).
            'native_handlers' => false,

            // ── performance (schema 2) ──
            'performance'        => false,  // liga o APM
            'traces_sample_rate' => 0.2,    // fração das requests "ok" amostradas
            'slow_threshold_ms'  => 800,    // acima disso captura 100%
            'capture_sql'        => true,
            'sql_slow_ms'        => 100,    // marca query lenta
            'max_queries'        => 500,    // teto de queries detalhadas por request
            'capture_bindings'   => false,  // logar binds (cuidado PII)
            'capture_sql_src'    => true,   // backtrace src frame (só quando amostrado)
            'send'               => 'shutdown', // shutdown | async | sync
            'spool_dir'          => 'tmp/madtrace-spool', // async: diretório do spool NDJSON
            'spool_max_bytes'    => 8 * 1024 * 1024,      // teto do spool ativo (drop acima)
            // lock do spool: auto (flock em disco local, mkdir em FS de rede) | flock | mkdir
            'spool_lock_mode'    => 'auto',
            // budget do POST de performance. Como o envio é pós-fastcgi (não
            // bloqueia o usuário), 1500ms p/ não dropar o trace.
            'perf_timeout_ms'    => 1500,
            'before_send'        => null,   // callable(array $payload): ?array — use setBeforeSend() (config:cache não aceita closure)
            'ignore_routes'      => [],     // rotas a não rastrear
            // endpoint dedicado p/ APM (schema 2). null = mesmo endpoint do DSN.
            'apm_endpoint'       => null,
            // jobs: o backend só aceita kind:infra (snapshot agregado via agente
            // cron), NÃO type:job por execução. Mantém OFF até o agente existir.
            'job_ingest'         => false,
        ], $config);

        if (empty(self::$config['dsn'])) {
            // sem DSN: opera em modo "só error_log" (ainda útil em dev)
            @error_log('[MadTrace] instalado SEM dsn — eventos vão só para error_log');
        }

        // release NÃO é resolvido aqui: resolveRelease() lê .git/HEAD (I/O).
        // Resolução é lazy (self::release()), só quando um payload vai ser
        // montado/enviado — fora do caminho crítico do bootstrap.

        // Reserva ~256 KB. Liberado no shutdown ANTES de processar o fatal.
        self::$memReserve = str_repeat('x', 1024 * 256);

        // ── base de tempo do APM ──
        self::$t0          = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
        self::$bootEndWall = microtime(true);
        self::$bootEndHr   = self::hr();

        // decisão de amostragem (1x, estável por request)
        if (!empty(self::$config['performance'])) {
            $rate = (float) self::$config['traces_sample_rate'];
            self::$sampledIn = ($rate >= 1.0) ? true
                : (($rate > 0.0) && ((mt_rand() / mt_getrandmax()) < $rate));
        }

        // Handlers nativos só fora do Laravel (ver docblock da config).
        if (!empty(self::$config['native_handlers'])) {
            set_error_handler([__CLASS__, 'handleError']);
            set_exception_handler([__CLASS__, 'handleException']);
        }

        // shutdown único: erros FATAIS + fechamento da transação APM
        register_shutdown_function([__CLASS__, '_shutdown']);

        self::$installed = true;
    }

    /**
     * Re-stampa o fim do boot (chamado de $app->booted() no provider) —
     * boot_ms passa a medir o bootstrap REAL do Laravel, não "até o
     * register() do provider". Idempotente: só o 1º chamado vale.
     */
    public static function markBootEnd(): void
    {
        try {
            if (!self::$installed || self::$bootMarked) {
                return;
            }
            self::$bootMarked  = true;
            self::$bootEndWall = microtime(true);
            self::$bootEndHr   = self::hr();
        } catch (\Throwable $e) {}
    }

    /**
     * Define/limpa o callback before_send em runtime. Necessário no Laravel:
     * config:cache serializa a config — closure lá quebraria o cache.
     */
    public static function setBeforeSend(?callable $cb): void
    {
        self::$config['before_send'] = $cb;
    }

    /** Define o usuário corrente (aparece em todo evento). */
    public static function setUser(array $user)
    {
        self::$context['user'] = array_merge(self::$context['user'], $user);
    }

    /** Adiciona uma tag global. */
    public static function setTag($key, $value)
    {
        self::$context['tags'][$key] = $value;
    }

    // ---------------------------------------------------------------- handlers

    public static function handleError($severity, $message, $file = '', $line = 0)
    {
        try {
            // respeita @ e error_reporting() do ambiente
            if (!(error_reporting() & $severity)) {
                return false;
            }
            $level = self::$levelMap[$severity] ?? 'error';
            self::report($level, $message, $file, $line, null, 'php_error', $severity);
        } catch (\Throwable $e) {
            // nunca propaga
        }
        // false = deixa o handler padrão do PHP rodar também
        return false;
    }

    public static function handleException($exception)
    {
        try {
            self::captureException($exception);
        } catch (\Throwable $e) {
            // nunca propaga
        }
    }

    /**
     * Shutdown único — registra os fatais (schema 1) e depois fecha a
     * transação de performance (schema 2). Ambos fail-open e idempotentes.
     */
    public static function _shutdown()
    {
        // Libera a reserva ANTES de qualquer alocação, p/ sobreviver a um OOM.
        self::$memReserve = null;

        // Libera a resposta ao cliente ANTES de qualquer telemetria. Tudo
        // abaixo (coleta de queries, N+1, serialização, curl) roda FORA do
        // caminho da request — não afeta a latência percebida pelo usuário.
        self::flushResponse();

        try {
            self::handleShutdown();      // captura fatal → enfileira
        } catch (\Throwable $e) {
            // nunca propaga
        }

        try {
            self::_finishTransaction();  // monta + envia a transação APM
        } catch (\Throwable $e) {
            // nunca propaga
        }

        try {
            self::flushPending();        // envia os erros enfileirados (schema 1)
        } catch (\Throwable $e) {
            // nunca propaga
        }
    }

    /**
     * Fecha a request explicitamente (transação APM + erros pendentes).
     * Idempotente — o shutdown depois vira no-op. Usos: testes (PHPUnit não
     * roda shutdown functions entre testes) e runtimes long-lived (Octane).
     */
    public static function finishRequest(): void
    {
        try {
            self::_finishTransaction();
        } catch (\Throwable $e) {}
        try {
            self::flushPending();
        } catch (\Throwable $e) {}
    }

    /** Libera a resposta ao cliente (FPM). Idempotente; só no 1º chamado. */
    private static function flushResponse(): void
    {
        if (self::$flushed) {
            return;
        }
        self::$flushed = true;
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
    }

    /** Envia os eventos de erro enfileirados (chamado no shutdown, pós-flush). */
    private static function flushPending(): void
    {
        if (empty(self::$pending)) {
            return;
        }
        $batch = self::$pending;
        self::$pending = [];
        $cb = self::$config['before_send'] ?? null;
        foreach ($batch as $event) {
            if (is_callable($cb)) {
                $event = $cb($event);
                if (!is_array($event)) {
                    continue; // callback descartou
                }
            }
            self::send($event, 1);
        }
    }

    public static function handleShutdown()
    {
        if (self::$sent) {
            return;
        }
        self::$sent = true;

        try {
            $err = error_get_last();
            if (!$err) {
                return;
            }

            $fatais = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
            if (in_array($err['type'], $fatais, true)) {
                // Dedup contra o reportable do Laravel: o HandleExceptions
                // converte o fatal em FatalError e o reporta ANTES deste
                // shutdown — se captureException já viu este file:line, pula.
                $key = ($err['file'] ?? '') . ':' . ($err['line'] ?? 0);
                if (self::$fatalNoted === $key) {
                    return;
                }
                self::report('fatal', $err['message'], $err['file'] ?? '', $err['line'] ?? 0, null, 'php_fatal', $err['type']);
            }

            // NÃO reportar não-fatais aqui: error_get_last() inclui erros
            // @-SUPRIMIDOS (ex.: @mkdir de contenção de lock, @file_get_contents)
            // — reportá-los geraria FALSOS eventos. Warnings/notices legítimos
            // chegam via reportable() do Laravel (ErrorException) ou pelo
            // handleError() quando native_handlers está ligado.
        } catch (\Throwable $e) {
            // nunca propaga
        }
    }

    // ---------------------------------------------------------------- API manual (erros)

    /**
     * @param \Throwable $exception
     * @param array $extra contexto adicional
     */
    public static function captureException($exception, array $extra = [])
    {
        try {
            $trace = self::formatTrace($exception->getTrace());

            // \Error (fatais catcháveis + FatalError do Symfony) são mais
            // graves que \Exception. \ErrorException carrega a severidade
            // PHP original (warning promovido pelo Laravel continua warning).
            if ($exception instanceof \ErrorException) {
                $level = self::$levelMap[$exception->getSeverity()] ?? 'error';
            } elseif ($exception instanceof \Error) {
                $level = 'fatal';
                // anti-duplicação com o handleShutdown (mesmo fatal)
                self::$fatalNoted = $exception->getFile() . ':' . $exception->getLine();
            } else {
                $level = 'error';
            }

            self::report(
                $level,
                get_class($exception) . ': ' . $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $trace,
                'exception',
                null,
                $extra
            );
        } catch (\Throwable $e) {
            // nunca propaga
        }
    }

    /**
     * @param string $message
     * @param string $level  info|warning|error|fatal
     * @param array  $extra
     */
    public static function captureMessage($message, $level = 'info', array $extra = [])
    {
        try {
            self::report($level, $message, '', 0, null, 'message', null, $extra);
        } catch (\Throwable $e) {
            // nunca propaga
        }
    }

    /**
     * Drop-in para error_log(): captura no MadTrace E mantém o log local.
     *
     * @param string      $message
     * @param int         $type
     * @param string|null $level
     * @param array       $extra
     */
    public static function captureFromErrorLog($message, $type = 0, $level = null, array $extra = [])
    {
        $message = (string) $message;

        // 1) preserva o comportamento do error_log nativo (log local SEMPRE)
        try {
            if ($type === 0) {
                @\error_log($message);
            } else {
                $args = func_get_args();
                @\call_user_func_array('error_log', array_slice($args, 0, 4));
            }
        } catch (\Throwable $e) {
            // nunca propaga
        }

        // 2) captura no MadTrace
        try {
            if ($level === null) {
                $level = self::levelFromTag($message);
            }
            self::report($level, $message, '', 0, null, 'message', null, $extra);
        } catch (\Throwable $e) {
            // nunca propaga
        }
    }

    /** Infere nível a partir do prefixo [TAG] da mensagem. */
    private static function levelFromTag($message)
    {
        if (preg_match('/\[(FATAL|CRITICAL)\]/i', $message))               { return 'fatal'; }
        if (preg_match('/\[(FRAUD|SECURITY|DISPATCH-DENY|DISPATCH-WOULD-DENY)\]/i', $message)) { return 'warning'; }
        if (preg_match('/\[(ERROR|EXCEPTION)\]/i', $message))              { return 'error'; }
        return 'info';
    }

    // ---------------------------------------------------------------- núcleo (erros)

    private static function report($level, $message, $file, $line, $trace, $kind, $severity = null, array $extra = [])
    {
        // teto anti-flood
        if (count(self::$seen) >= self::$config['max_per_request']) {
            return;
        }

        // filtro de nível mínimo
        $order = ['info' => 0, 'warning' => 1, 'error' => 2, 'fatal' => 3];
        $min = $order[self::$config['min_level']] ?? 1;
        if (($order[$level] ?? 2) < $min) {
            return;
        }

        // fingerprint: agrupa eventos iguais
        $fingerprint = md5($kind . '|' . $level . '|' . self::normalize($message) . '|' . basename((string) $file) . '|' . $line);

        // dedup dentro da mesma request
        if (isset(self::$seen[$fingerprint])) {
            return;
        }
        self::$seen[$fingerprint] = true;

        // conta erros (liga schema 1 ↔ keep do schema 2)
        if ($level === 'error' || $level === 'fatal') {
            self::$errorCount++;
        }

        // amostragem (determinística por fingerprint)
        if (self::$config['sample_rate'] < 1.0) {
            $h = hexdec(substr($fingerprint, 0, 8)) / 0xffffffff;
            if ($h > self::$config['sample_rate']) {
                return;
            }
        }

        $event = self::buildEvent($level, $message, $file, $line, $trace, $kind, $severity, $fingerprint, $extra);

        // fallback SEMPRE: error_log local (resiliência se o endpoint cair)
        @error_log('[MadTrace][' . $level . '] ' . $message . ' @ ' . $file . ':' . $line . ' fp=' . substr($fingerprint, 0, 8));

        // NÃO envia agora — enfileira p/ envio no shutdown (após o flush da
        // resposta). Evita curl no meio da request e flush prematuro da página.
        self::$pending[] = $event;
    }

    private static function buildEvent($level, $message, $file, $line, $trace, $kind, $severity, $fingerprint, $extra)
    {
        $cfg = self::$config;

        return [
            'schema'      => 1,
            'event_id'    => self::uuid4(),
            'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
            'app'         => $cfg['app'],
            'environment' => $cfg['env'],
            'release'     => self::release(),
            'level'       => $level,
            'kind'        => $kind,          // php_error|php_fatal|exception|message
            'severity'    => $severity,      // código E_* original (se houver)
            'message'     => self::truncate((string) $message, 4000),
            'culprit'     => self::truncate(basename((string) $file) . ($line ? ':' . $line : ''), 255),
            'fingerprint' => $fingerprint,
            'exception'   => [
                'file'  => (string) $file,
                'line'  => (int) $line,
                'trace' => $trace,           // null em php_error/message
            ],
            'request'     => self::scrub(self::collectRequest()),
            'user'        => self::scrub(self::collectUser()),
            'tags'        => self::$context['tags'],
            'extra'       => self::scrub($extra),
            'sdk'         => ['name' => 'madtrace-php', 'version' => '2.0'],
            'php_version' => PHP_VERSION,
        ];
    }

    private static function collectRequest()
    {
        $s = $_SERVER;
        $ip = $s['HTTP_CF_CONNECTING_IP']
            ?? $s['HTTP_X_FORWARDED_FOR']
            ?? ($s['REMOTE_ADDR'] ?? '');
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        $scheme = (!empty($s['HTTPS']) && $s['HTTPS'] !== 'off') ? 'https' : 'http';
        $url = isset($s['HTTP_HOST'], $s['REQUEST_URI'])
            ? $scheme . '://' . $s['HTTP_HOST'] . $s['REQUEST_URI']
            : ($s['SCRIPT_NAME'] ?? 'cli');

        // Segredos fora do evento que sai da máquina (SecretMasker): o `token=`
        // da URL e o JSON do MadWire (`mad_params`) — o scrub() por nome de
        // chave não abre string com JSON dentro nem olha a URL.
        return [
            'url'        => \Mad\Security\SecretMasker::maskUri($url),
            'method'     => $s['REQUEST_METHOD'] ?? 'CLI',
            'ip'         => $ip,
            'user_agent' => $s['HTTP_USER_AGENT'] ?? '',
            'referer'    => \Mad\Security\SecretMasker::maskUri((string) ($s['HTTP_REFERER'] ?? '')),
            'query'      => \Mad\Security\SecretMasker::maskArray($_GET ?? []),
            'body'       => self::limitArray(\Mad\Security\SecretMasker::maskArray($_POST ?? []), 50),
        ];
    }

    private static function collectUser()
    {
        $user = self::$context['user'];

        // auto-detecta a sessão Laravel (chaves do Mad auth) se não setado
        // manualmente. Guardado: fora de request HTTP a sessão não existe.
        if (empty($user) && function_exists('app')) {
            try {
                $app = @app();
                if ($app && $app->bound('session') && $app['session']->isStarted()) {
                    $session = $app['session'];
                    $id    = $session->get('userid');
                    $email = $session->get('usermail') ?: $session->get('login');
                    if ($id)    { $user['id'] = $id; }
                    if ($email) { $user['email'] = $email; }
                }
            } catch (\Throwable $e) {
                // ignora
            }
        }
        if (!empty($_SERVER['REMOTE_ADDR']) && empty($user['ip'])) {
            $user['ip'] = $_SERVER['REMOTE_ADDR'];
        }
        return $user;
    }

    // ═══════════════════════════════ APM (schema 2) ═══════════════════════════

    /**
     * Gate lido pelos hooks (MadSqlCollector, Transaction, bridge DB::listen).
     * Quando false, nenhum trabalho caro acontece (backtrace, timing, spans).
     */
    public static function apmActive(): bool
    {
        // No modo job, só coleta SQL/spans se o envio per-execução estiver
        // ligado (job_ingest) — senão evita backtrace inútil no worker.
        $jobCollect = self::$jobMode && !empty(self::$config['job_ingest']);

        return self::$installed
            && !empty(self::$config['performance'])
            && (self::$sampledIn || $jobCollect)
            && !self::$txSent;
    }

    /** Só a flag performance (usado pelos listeners de job antes de begin/end). */
    public static function performanceEnabled(): bool
    {
        return self::$installed && !empty(self::$config['performance']);
    }

    /** Teto de queries detalhadas (lido pelo MadSqlCollector). */
    public static function maxQueries(): int
    {
        return (int) (self::$config['max_queries'] ?? 500);
    }

    /** Último job finalizado neste processo (p/ o agente infra agregar). */
    public static function _lastJob(): ?array
    {
        return self::$lastJob;
    }

    /** Resultado do último POST ao receptor (diagnóstico/teste de entrega). */
    public static function _lastSend(): ?array
    {
        return self::$lastSend;
    }

    /**
     * Ingestão de INFRA (schema 2, kind:infra) — filas / jobs / servidores.
     * Snapshot agregado enviado por agente cron, fora do caminho da request.
     * Upsert no backend por name (filas/jobs) e host (servidores). Arrays
     * queues/jobs/hosts são todos opcionais.
     *
     * @param array $data ['queues'=>[], 'jobs'=>[], 'hosts'=>[]]
     */
    public static function ingestInfra(array $data): void
    {
        try {
            if (!self::$installed || empty(self::$config['performance'])) {
                return;
            }

            $payload = [
                'schema'      => 2,
                'kind'        => 'infra',
                'event_id'    => self::uuid4(),
                'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
                'app'         => self::$config['app'],
                'env'         => self::$config['env'],
                'environment' => self::$config['env'],
                'release'     => self::release(),
                'sdk'         => ['name' => 'madtrace-php-apm', 'version' => '2.0'],
            ];

            foreach (['queues', 'jobs', 'hosts'] as $k) {
                if (!empty($data[$k]) && is_array($data[$k])) {
                    $payload[$k] = self::scrub($data[$k]); // scrub payloads de falhas
                }
            }

            $cb = self::$config['before_send'] ?? null;
            if (is_callable($cb)) {
                $payload = $cb($payload);
                if (!is_array($payload)) {
                    return;
                }
            }

            self::send($payload, 2);
        } catch (\Throwable $e) {}
    }

    /** Define o nome (route) da transação — ex.: "Classe::ação". */
    public static function setTransactionName(string $route): void
    {
        try { self::$txName = $route; } catch (\Throwable $e) {}
    }

    /** Define o tipo da transação: web|rest|job|cli. */
    public static function setTransactionType(string $type): void
    {
        try { self::$txType = $type; } catch (\Throwable $e) {}
    }

    /**
     * Abre um span do waterfall. Retorna um handle com ->finish().
     * No-op (handle id=-1) quando a request não está sendo coletada.
     */
    public static function startSpan(string $type, string $label, array $meta = []): MadTraceSpan
    {
        try {
            if (!self::apmActive() || self::$spanSeq >= self::MAX_SPANS) {
                return new MadTraceSpan(-1);
            }
            $id     = self::$spanSeq++;
            $parent = empty(self::$spanStack) ? -1 : end(self::$spanStack);
            self::$spans[$id] = [
                'id'       => $id,
                'parent'   => $parent,
                'type'     => $type,
                'label'    => self::truncate($label, 200),
                'start_ms' => self::relMs(self::hr()),
                'dur_ms'   => null,
                'meta'     => $meta,
                '_hr'      => self::hr(),
            ];
            self::$spanStack[] = $id;
            return new MadTraceSpan($id);
        } catch (\Throwable $e) {
            return new MadTraceSpan(-1);
        }
    }

    /** Açúcar try/finally: roda $fn dentro de um span e retorna o resultado. */
    public static function span(string $type, string $label, callable $fn)
    {
        $s = self::startSpan($type, $label);
        try {
            return $fn();
        } finally {
            $s->finish();
        }
    }

    /** @internal usado por MadTraceSpan::finish() */
    public static function _finishSpan(int $id): void
    {
        try {
            if ($id < 0 || !isset(self::$spans[$id])) {
                return;
            }
            self::$spans[$id]['dur_ms'] = round((self::hr() - self::$spans[$id]['_hr']) / 1e6, 3);
            // pop da pilha (até o id, defensivo)
            while (!empty(self::$spanStack)) {
                $top = array_pop(self::$spanStack);
                if ($top === $id) {
                    break;
                }
            }
        } catch (\Throwable $e) {}
    }

    /** @internal usado por MadTraceSpan::annotate() */
    public static function _annotateSpan(int $id, string $k, $v): void
    {
        try {
            if ($id >= 0 && isset(self::$spans[$id])) {
                self::$spans[$id]['meta'][$k] = $v;
            }
        } catch (\Throwable $e) {}
    }

    /**
     * Registra uma query. Dois modos:
     *
     *  - $timeMs === null (caminho legado: MadSqlCollector::record() ANTES do
     *    exec): captura src barato; o tempo real é preenchido depois por
     *    recordQueryTime() (via o coletor de SQL legado).
     *  - $timeMs !== null (bridge DB::listen: QueryExecuted dispara DEPOIS do
     *    exec): linha já nasce completa — start_ms retrocede a duração, slow
     *    é decidido na hora e $lastQ NÃO é setado (um recordQueryTime() do
     *    caminho legado nunca pode parear com uma query da bridge).
     */
    public static function recordQuery(string $sql, ?string $db = null, ?float $timeMs = null, ?int $rows = null): void
    {
        try {
            if (!self::apmActive() || empty(self::$config['capture_sql'])) {
                return;
            }
            if (count(self::$queries) >= self::maxQueries()) {
                self::$queriesTruncated = true;
                self::$lastQ = null;
                return;
            }

            $src = null;
            if (!empty(self::$config['capture_sql_src'])) {
                $src = self::callerSrc();
            }

            $timed   = ($timeMs !== null);
            $nowMs   = self::relMs(self::hr());
            $startMs = $timed ? max(0.0, $nowMs - $timeMs) : $nowMs;

            $seq = self::$querySeq++;
            self::$queries[] = [
                'seq'         => $seq,
                'sql'         => self::truncate($sql, 4000),
                'db'          => $db ?? '',
                'kind'        => self::sqlKind($sql),
                'table'       => self::sqlTable($sql),
                'fingerprint' => self::sqlFingerprint($sql),
                'src'         => $src,
                'start_ms'    => round($startMs, 2),
                'time_ms'     => $timed ? round($timeMs, 3) : null,
                'rows'        => $rows,
                'slow'        => $timed && ($timeMs >= (float) self::$config['sql_slow_ms']),
            ];
            self::$lastQ = $timed ? null : array_key_last(self::$queries);
        } catch (\Throwable $e) {}
    }

    /**
     * Preenche o tempo real da última query registrada (chamado por
     * Transaction::logExecutionTime()). Pareamento seguro: só aplica se a
     * última query ainda não tem tempo (queries sem push não são afetadas).
     */
    public static function recordQueryTime(float $timeMs, ?int $rows = null): void
    {
        try {
            if (self::$lastQ === null || !isset(self::$queries[self::$lastQ])) {
                return;
            }
            if (self::$queries[self::$lastQ]['time_ms'] !== null) {
                return; // já preenchida — fire órfão, ignora
            }
            self::$queries[self::$lastQ]['time_ms'] = round($timeMs, 3);
            if ($rows !== null) {
                self::$queries[self::$lastQ]['rows'] = $rows;
            }
            $slowMs = (float) self::$config['sql_slow_ms'];
            self::$queries[self::$lastQ]['slow'] = ($timeMs >= $slowMs);
        } catch (\Throwable $e) {}
    }

    // ─────────────────────────────── jobs / filas ───────────────────────────

    /** Inicia o trace de um job (reseta o estado por job no daemon). */
    public static function beginJob(string $name, array $meta = []): void
    {
        try {
            if (!self::performanceEnabled()) {
                return;
            }
            // reset de estado por job (no daemon, acumularia entre jobs).
            // $seen/$pending também: senão o dedup de erros e o flush ficam
            // presos ao ciclo de vida do PROCESSO, não do job.
            self::$queries          = [];
            self::$querySeq         = 0;
            self::$lastQ            = null;
            self::$queriesTruncated = false;
            self::$spans            = [];
            self::$spanSeq          = 0;
            self::$spanStack        = [];
            self::$errorCount       = 0;
            self::$txSent           = false;
            self::$seen             = [];
            self::$pending          = [];

            self::$jobMode      = true;
            self::$jobEnded     = false;
            self::$jobStartWall = microtime(true);
            self::$bootEndHr    = self::hr();
            self::$bootEndWall  = self::$jobStartWall;
            self::$t0           = self::$jobStartWall;
            self::$job          = [
                'name'  => $name,
                'queue' => $meta['queue'] ?? 'default',
                'meta'  => $meta,
            ];

            if (class_exists('\\Mad\\Util\\MadSqlCollector')) {
                @\Mad\Util\MadSqlCollector::clear();
            }
        } catch (\Throwable $e) {}
    }

    /** Fecha e envia o trace de um job (envio imediato — não via shutdown). */
    public static function endJob(string $status = 'ok', ?\Throwable $e = null): void
    {
        try {
            if (!self::$jobMode || self::$jobEnded) {
                return;
            }
            self::$jobEnded = true;

            $durationMs = (microtime(true) - self::$jobStartWall) * 1000;
            $queries    = self::collectQueries();

            $payload = [
                'schema'      => 2,
                'type'        => 'job',
                'event_id'    => self::uuid4(),
                'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
                'app'         => self::$config['app'],
                'environment' => self::$config['env'],
                'release'     => self::release(),
                'job'         => self::$job['name'] ?? 'unknown',
                'queue'       => self::$job['queue'] ?? 'default',
                'status'      => $status,
                'attempts'    => (int) (self::$job['meta']['attempts'] ?? 1),
                'duration_ms' => round($durationMs, 2),
                'memory_mb'   => round(memory_get_peak_usage(true) / 1048576, 2),
                'query_count' => count($queries),
                'queries'     => self::capQueries($queries),
                'n_plus_one'  => self::detectNPlusOne($queries),
                'payload'     => self::scrub(self::$job['meta']['payload'] ?? []),
                'php_version' => PHP_VERSION,
                'sdk'         => ['name' => 'madtrace-php-apm', 'version' => '2.0'],
            ];

            if ($e !== null) {
                $payload['error'] = self::truncate(get_class($e) . ': ' . $e->getMessage(), 2000);
            }

            // O backend NÃO aceita type:job por execução — jobs entram via
            // kind:infra (snapshot agregado do agente cron). Por padrão não
            // envia; mantém capturado para o agente/diagnóstico. Liga com
            // 'job_ingest' => true apenas se o receptor passar a aceitar.
            self::$lastJob = $payload;
            if (!empty(self::$config['job_ingest'])) {
                self::dispatchPerf($payload);
            }

            // Workers daemon: erros (schema 1) capturados durante o job saem
            // AGORA — esperar o shutdown do processo atrasaria horas.
            self::flushPending();

            // mantém jobMode true p/ o shutdown não emitir uma transação HTTP
            self::$txSent = true;
        } catch (\Throwable $ex) {}
    }

    // ─────────────────────────── fechamento da transação ────────────────────

    private static function _finishTransaction(): void
    {
        if (self::$txSent || empty(self::$config['performance']) || self::$jobMode) {
            return;
        }

        $sapi = php_sapi_name();
        $route = self::resolveRoute();

        // CLI sem route nomeada → não é uma transação HTTP
        if (($sapi === 'cli') && ($route === null) && (self::$txName === null)) {
            return;
        }
        if ($route === null) {
            $route = self::$txName ?? ($_SERVER['REQUEST_URI'] ?? 'unknown');
        }

        // ignore_routes
        foreach ((array) self::$config['ignore_routes'] as $pat) {
            if ($route === $pat || (function_exists('fnmatch') && @fnmatch($pat, $route))) {
                return;
            }
        }

        $durationMs = (microtime(true) - self::$t0) * 1000;
        $status     = function_exists('http_response_code') ? (int) http_response_code() : 0;
        if ($status <= 0) {
            $status = 200;
        }

        // sinais BARATOS de keep primeiro — sem varrer/copiar queries.
        $cheapKeep = self::$sampledIn
            || $status >= 500
            || $durationMs >= (float) self::$config['slow_threshold_ms']
            || self::$errorCount > 0;

        // quantidade de queries SEM copiar o buffer
        $qcount = !empty(self::$queries)
            ? count(self::$queries)
            : (class_exists('\\Mad\\Util\\MadSqlCollector') ? (int) @\Mad\Util\MadSqlCollector::count() : 0);

        // Só coleta + detecta N+1 se vai manter, OU se há queries suficientes
        // p/ existir N+1 (>=3). Página pequena não-amostrada → custo ~zero.
        $queries = [];
        $n1      = [];
        if ($cheapKeep || $qcount >= 3) {
            $queries = self::collectQueries();
            $n1      = ($qcount >= 3) ? self::detectNPlusOne($queries) : [];
        }

        if (!$cheapKeep && empty($n1)) {
            return; // descarta — nada de interesse nesta request
        }

        self::$txSent = true;

        $payload = self::buildTransaction($route, $status, $durationMs, $queries, $n1, $sapi);
        self::dispatchPerf($payload);
    }

    private static function buildTransaction($route, $status, $durationMs, array $queries, array $n1, $sapi)
    {
        $cfg = self::$config;

        // breakdown
        $bootMs = max(0.0, (self::$bootEndWall - self::$t0) * 1000);
        $dbMs   = 0.0;
        foreach ($queries as $q) {
            $dbMs += (float) ($q['time_ms'] ?? 0);
        }
        $viewMs = 0.0;
        $extMs  = 0.0;
        foreach (self::$spans as $sp) {
            if ($sp['type'] === 'view') { $viewMs += (float) ($sp['dur_ms'] ?? 0); }
            if ($sp['type'] === 'ext')  { $extMs  += (float) ($sp['dur_ms'] ?? 0); }
        }
        $phpMs = max(0.0, $durationMs - $bootMs - $dbMs - $viewMs - $extMs);

        $class  = isset($_REQUEST['class'])  ? (string) $_REQUEST['class']  : null;
        $method = isset($_REQUEST['method']) ? (string) $_REQUEST['method'] : null;
        $user   = self::scrub(self::collectUser());

        $payload = [
            'schema'           => 2,
            'kind'             => 'transaction',   // discriminador do backend (transaction|infra)
            'event_id'         => self::uuid4(),
            'trace_id'         => self::requestId(),
            'request_id'       => self::requestId(),
            'timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
            'app'              => $cfg['app'],
            'env'              => $cfg['env'],      // backend lê env (aceita environment também)
            'environment'      => $cfg['env'],
            'release'          => self::release(),
            'method'           => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'status'           => $status,
            'route'            => $route,
            'url'              => self::currentUrl(),
            'controller'       => $class,
            'action'           => $method,
            'duration_ms'      => round($durationMs, 2),
            'boot_ms'          => round($bootMs, 2),
            'php_ms'           => round($phpMs, 2),
            'db_ms'            => round($dbMs, 2),
            'view_ms'          => round($viewMs, 2),
            'ext_ms'           => round($extMs, 2),
            'query_count'      => count($queries),
            'memory_mb'        => round(memory_get_peak_usage(true) / 1048576, 2),
            'user'             => $user,
            'ip'               => $user['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            'php_version'      => PHP_VERSION,
            'queries'          => self::capQueries($queries),
            'n_plus_one'       => $n1,
            'context'          => [
                'txn_type'          => self::$txType ?? (($sapi === 'cli') ? 'cli' : 'web'),
                'queries_truncated' => self::$queriesTruncated,
                'spans'             => self::buildSpans($bootMs, $queries),
            ],
            'sdk'              => ['name' => 'madtrace-php-apm', 'version' => '2.0'],
        ];

        return $payload;
    }

    /** Monta o waterfall: span boot + spans explícitos + 1 span db por query. */
    private static function buildSpans($bootMs, array $queries)
    {
        $spans = [];
        $spans[] = ['label' => 'Bootstrap', 'type' => 'boot', 'start_ms' => 0.0, 'dur_ms' => round($bootMs, 2)];

        foreach (self::$spans as $sp) {
            $spans[] = [
                'label'    => $sp['label'],
                'type'     => $sp['type'],
                'start_ms' => round((float) $sp['start_ms'], 2),
                'dur_ms'   => round((float) ($sp['dur_ms'] ?? 0), 2),
            ];
        }

        foreach ($queries as $q) {
            $spans[] = [
                'label'     => strtoupper((string) $q['kind']) . ' ' . ($q['table'] ?? ''),
                'type'      => 'db',
                'start_ms'  => round((float) ($q['start_ms'] ?? 0), 2),
                'dur_ms'    => round((float) ($q['time_ms'] ?? 0), 2),
                'query_seq' => $q['seq'] ?? null,
            ];
        }

        usort($spans, function ($a, $b) {
            return $a['start_ms'] <=> $b['start_ms'];
        });

        if (count($spans) > self::MAX_SPANS) {
            $spans = array_slice($spans, 0, self::MAX_SPANS);
        }

        return $spans;
    }

    /**
     * Lista de queries: prefere o buffer de precisão; senão usa o
     * MadSqlCollector (gap-timing, sem src) — garante N+1 e contagem mesmo em
     * requests não amostradas que precisam ser mantidas (lento/erro).
     */
    private static function collectQueries(): array
    {
        if (!empty(self::$queries)) {
            return self::$queries;
        }

        $out = [];
        if (empty(self::$config['capture_sql']) || !class_exists('\\Mad\\Util\\MadSqlCollector')) {
            return $out;
        }

        try {
            $rows = (array) \Mad\Util\MadSqlCollector::all();
        } catch (\Throwable $e) {
            return $out;
        }

        $cursor = $bootMs = max(0.0, (self::$bootEndWall - self::$t0) * 1000);
        $seq = 0;
        foreach ($rows as $r) {
            $sql = (string) ($r['sql'] ?? '');
            $dur = isset($r['duration_ms']) ? (float) $r['duration_ms'] : 0.0;
            $out[] = [
                'seq'         => $seq++,
                'sql'         => self::truncate($sql, 4000),
                'db'          => (string) ($r['db'] ?? ''),
                'kind'        => self::sqlKind($sql),
                'table'       => self::sqlTable($sql),
                'fingerprint' => self::sqlFingerprint($sql),
                'src'         => null,
                'start_ms'    => round($cursor, 2),
                'time_ms'     => round($dur, 3),
                'rows'        => null,
                'slow'        => ($dur >= (float) self::$config['sql_slow_ms']),
            ];
            $cursor += $dur;
            if (count($out) >= self::maxQueries()) {
                self::$queriesTruncated = true;
                break;
            }
        }
        return $out;
    }

    private static function capQueries(array $queries): array
    {
        $max = self::maxQueries();
        if (count($queries) > $max) {
            self::$queriesTruncated = true;
            $queries = array_slice($queries, 0, $max);
        }
        // remove campos internos
        return array_map(function ($q) {
            unset($q['_hr']);
            return $q;
        }, $queries);
    }

    /** Detecção de N+1: agrupa por fingerprint; ≥3 com mesma table → issue. */
    private static function detectNPlusOne(array $queries): array
    {
        $groups = [];
        foreach ($queries as $q) {
            $fp = $q['fingerprint'] ?? '';
            if ($fp === '') {
                continue;
            }
            if (!isset($groups[$fp])) {
                $groups[$fp] = ['count' => 0, 'total_ms' => 0.0, 'table' => $q['table'] ?? null, 'sql' => $q['sql'] ?? '', 'src' => $q['src'] ?? null];
            }
            $groups[$fp]['count']++;
            $groups[$fp]['total_ms'] += (float) ($q['time_ms'] ?? 0);
        }

        $issues = [];
        foreach ($groups as $fp => $g) {
            if ($g['count'] >= 3 && !empty($g['table'])) {
                $issues[] = [
                    'fingerprint' => $fp,
                    'table'       => $g['table'],
                    'count'       => $g['count'],
                    'total_ms'    => round($g['total_ms'], 2),
                    'src'         => $g['src'],
                    'sql'         => self::truncate((string) $g['sql'], 500),
                ];
            }
        }
        return $issues;
    }

    // ─────────────────────────────── SQL helpers ────────────────────────────

    private static function sqlKind(string $sql): string
    {
        return strtolower((string) strtok(ltrim($sql), " \t\n("));
    }

    private static function sqlTable(string $sql): ?string
    {
        if (preg_match('/\b(?:from|into|update|join)\s+[`"\[]?([a-z0-9_\.]+)/i', $sql, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function sqlFingerprint(string $sql): string
    {
        $s = strtolower($sql);
        $s = preg_replace('/\s+/', ' ', $s);
        $s = preg_replace("/'[^']*'/", '?', $s);
        // Aspas duplas: no SQL do Illuminate são IDENTIFICADORES ("tabela".
        // "coluna") — apagar o conteúdo (como o legado fazia) colapsava
        // queries de TABELAS DIFERENTES no mesmo fingerprint e fundia grupos
        // de N+1. Mantém o conteúdo quando parece identificador; literal de
        // string double-quoted (MySQL sem ANSI_QUOTES) ainda vira '?'.
        $s = preg_replace_callback('/"([^"]*)"/', function ($m) {
            return preg_match('/^[a-z0-9_.]+$/i', $m[1]) ? $m[1] : '?';
        }, $s);
        $s = preg_replace('/\b\d+\b/', '?', $s);
        $s = preg_replace('/\bin\s*\([^)]*\)/i', 'in (?)', $s);
        $s = trim((string) $s);
        return substr(hash('crc32b', $s), 0, 8);
    }

    /**
     * 1º frame fora do package e do vendor — aponta p/ código da app.
     * Profundidade 48: a pilha Eloquent (listener QueryExecuted → Dispatcher
     * → Connection → Builder → Model) come ~20 frames de vendor antes do
     * call-site real; os 18 do legado (pilha rasa) devolviam null.
     */
    private static function callerSrc(): ?string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 48);
        foreach ($frames as $f) {
            if (empty($f['file'])) {
                continue;
            }
            $file = str_replace('\\', '/', $f['file']);
            if (strpos($file, '/packages/mad-framework/') !== false) { continue; }
            if (strpos($file, '/vendor/') !== false)                 { continue; }
            return basename($f['file']) . ':' . ($f['line'] ?? 0);
        }
        return null;
    }

    // ───────────────────────────── route / contexto ─────────────────────────

    private static function resolveRoute(): ?string
    {
        if (self::$txName !== null) {
            return self::$txName;
        }
        // compat: URLs estilo engine (?class=...&method=...) ainda circulam
        $class = isset($_REQUEST['class']) ? trim((string) $_REQUEST['class']) : '';
        if ($class !== '') {
            $method = isset($_REQUEST['method']) ? trim((string) $_REQUEST['method']) : '';
            return $method !== '' ? "{$class}::{$method}" : $class;
        }
        return null;
    }

    private static function currentUrl(): string
    {
        $s = $_SERVER;
        if (!isset($s['HTTP_HOST'], $s['REQUEST_URI'])) {
            return $s['SCRIPT_NAME'] ?? 'cli';
        }
        $scheme = (!empty($s['HTTPS']) && $s['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $s['HTTP_HOST'] . $s['REQUEST_URI'];
    }

    private static function requestId(): string
    {
        static $rid = null;
        if (defined('REQUEST_ID')) {
            return (string) REQUEST_ID;
        }
        // cacheado por processo — sob Octane/worker reusado isso vira id por
        // worker, não por request (aceitável: trace_id só correlaciona spans)
        if ($rid === null) {
            $rid = self::uuid4();
        }
        return $rid;
    }

    // ─────────────────────────────── timing utils ───────────────────────────

    private static function hr(): int
    {
        return function_exists('hrtime') ? hrtime(true) : (int) (microtime(true) * 1e9);
    }

    /** Converte um instante hrtime em ms relativo a T0. */
    private static function relMs(int $hr): float
    {
        $bootMs = (self::$bootEndWall - self::$t0) * 1000;
        return max(0.0, $bootMs + ($hr - self::$bootEndHr) / 1e6);
    }

    // ─────────────────────────────── release ────────────────────────────────

    /** Release resolvido de forma lazy + cacheado (só na 1ª montagem de payload). */
    private static function release()
    {
        static $resolved = false;
        static $value = null;
        if (!$resolved) {
            $resolved = true;
            $value = self::resolveRelease(self::$config['release'] ?? null);
        }
        return $value;
    }

    /** Resolve o release SEM shell_exec por request (cacheado em static). */
    private static function resolveRelease($explicit)
    {
        static $cached = false;
        static $value  = null;

        if (!empty($explicit)) {
            return $explicit;
        }
        if ($cached) {
            return $value;
        }
        $cached = true;

        // raiz do projeto (PATH é definido pelo MadServiceProvider::bootPaths)
        $base = defined('PATH') ? PATH : getcwd();

        try {
            // 1) APCu
            if (function_exists('apcu_fetch')) {
                $ok = false;
                $v = @apcu_fetch('madtrace.release', $ok);
                if ($ok && $v) { return $value = $v; }
            }

            // 2) .git/HEAD direto (sem processo)
            $head = @file_get_contents($base . '/.git/HEAD');
            if ($head !== false) {
                $head = trim($head);
                if (strpos($head, 'ref:') === 0) {
                    $ref = trim(substr($head, 4));
                    $hash = @file_get_contents($base . '/.git/' . $ref);
                    if ($hash !== false) {
                        $value = substr(trim($hash), 0, 7);
                    }
                } elseif (preg_match('/^[0-9a-f]{7,40}$/i', $head)) {
                    $value = substr($head, 0, 7);
                }
            }

            // 3) build-stamp
            if (!$value) {
                $stamp = @file_get_contents($base . '/tmp/build.txt');
                if ($stamp !== false && trim($stamp) !== '') {
                    $value = trim($stamp);
                }
            }

            // 4) último recurso: git (1x por processo, cacheado)
            if (!$value && function_exists('shell_exec')) {
                $out = @shell_exec('git rev-parse --short HEAD 2>/dev/null');
                if ($out) { $value = trim($out); }
            }

            if ($value && function_exists('apcu_store')) {
                @apcu_store('madtrace.release', $value, 86400);
            }
        } catch (\Throwable $e) {
            $value = null;
        }

        return $value;
    }

    // ───────────────────────────── envio (perf) ─────────────────────────────

    /** Envia um payload de performance (schema 2), aplicando before_send. */
    private static function dispatchPerf(array $payload): void
    {
        try {
            $cb = self::$config['before_send'] ?? null;
            if (is_callable($cb)) {
                $payload = $cb($payload);
                if (!is_array($payload)) {
                    return; // callback descartou
                }
            }
            self::send($payload, 2);
        } catch (\Throwable $e) {}
    }

    // ---------------------------------------------------------------- envio

    private static function send(array $event, int $schema = 1)
    {
        $dsn = self::$config['dsn'];
        if (empty($dsn)) {
            return; // modo só-error_log
        }

        // DSN: https://PUBLIC_KEY@host/path  -> separa key + endpoint
        $key = '';
        $endpoint = $dsn;
        if (preg_match('#^(https?://)([^@/]+)@(.+)$#', $dsn, $m)) {
            $key = $m[2];
            $endpoint = $m[1] . $m[3];
        }

        // APM (schema 2) vai p/ /api/trace/ingest-apm (rota dedicada do backend,
        // derivada do path do DSN). Override total via config 'apm_endpoint'.
        if ($schema === 2) {
            if (!empty(self::$config['apm_endpoint'])) {
                $endpoint = self::$config['apm_endpoint'];
            } elseif (strpos($endpoint, '/api/trace/ingest-apm') === false) {
                if (strpos($endpoint, '/api/trace/ingest') !== false) {
                    $endpoint = str_replace('/api/trace/ingest', '/api/trace/ingest-apm', $endpoint);
                } elseif (substr($endpoint, -7) === '/ingest') {
                    $endpoint .= '-apm';
                } else {
                    $endpoint = rtrim($endpoint, '/') . '/api/trace/ingest-apm';
                }
            }
        }

        $payload = self::encode($event);
        if ($payload === false) {
            return;
        }

        // Guard de tamanho. Trim depende do schema.
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            $payload = self::trimPayload($event, $schema);
            if ($payload === false) {
                return;
            }
        }

        // budget de tempo por schema
        $timeout = ($schema === 2)
            ? (int) self::$config['perf_timeout_ms']
            : (int) self::$config['timeout_ms'];

        // garante resposta liberada antes do curl (no shutdown já ocorreu)
        self::flushResponse();

        // ASYNC: enfileira no spool (append rápido sob flock) em vez de curl
        // in-process. Worker é liberado em ~µs; o mad:trace-flush (cron) envia.
        // Se o spool falhar (não enfileirou), cai pro envio in-process (fallback).
        if (self::$config['send'] === 'async') {
            if (self::spool($endpoint, $key, $schema, $payload)) {
                self::$lastSend = ['endpoint' => $endpoint, 'schema' => $schema,
                    'status' => 0, 'error' => 'spooled', 'bytes' => strlen($payload)];
                return;
            }
            // fallthrough → fallback in-process
        }

        self::$lastSend = self::postRaw($endpoint, $key, $schema, $payload, $timeout);
    }

    /**
     * POST de uma linha já serializada ao receptor. Retorna o diagnóstico
     * {endpoint, schema, status, error, bytes}. Usado pelo envio in-process
     * e pelo flushSpool().
     */
    private static function postRaw(string $endpoint, string $key, int $schema, string $payload, int $timeoutMs): array
    {
        $result = ['endpoint' => $endpoint, 'schema' => $schema, 'status' => 0, 'error' => '', 'bytes' => strlen($payload)];

        if (!function_exists('curl_init')) {
            $result['error'] = 'curl indisponível';
            return $result;
        }

        try {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS     => $timeoutMs,
                CURLOPT_CONNECTTIMEOUT_MS => 800,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-MadTrace-Key: ' . $key,
                    'X-MadTrace-Schema: ' . $schema,
                    'User-Agent: madtrace-php/2.0',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            @curl_exec($ch);
            $result['status'] = (int) @curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $result['error']  = (string) @curl_error($ch);
            @curl_close($ch);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Enfileira uma linha NDJSON no spool (append sob flock). Cada linha é
     * autossuficiente {u:endpoint, k:key, s:schema, n:attempts, b:body} — o
     * mad:trace-flush (cron) envia em lote. Retorna:
     *   true  = enfileirado (ou dropado por spool cheio) → NÃO faz fallback
     *   false = falha de I/O → caller cai pro envio in-process (fallback)
     */
    private static function spool(string $endpoint, string $key, int $schema, string $payload): bool
    {
        try {
            $dir = (string) (self::$config['spool_dir'] ?: 'tmp/madtrace-spool');
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return false; // não criou o dir → fallback
            }
            $file = $dir . '/spool.ndjson';

            $line = @json_encode(
                ['u' => $endpoint, 'k' => $key, 's' => $schema, 'n' => 0, 'b' => $payload],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            if ($line === false) {
                return false;
            }
            $line .= "\n";

            // Lock NFS-safe (auto: flock em disco local, mkdir em FS de rede).
            $lock = self::lockAcquire($file, self::lockMode($dir), 1000, 5);
            if (!$lock) {
                return false; // não conseguiu lock → fallback in-process
            }
            try {
                clearstatcache(true, $file);
                $size = is_file($file) ? (int) @filesize($file) : 0;
                if ($size + strlen($line) > (int) self::$config['spool_max_bytes']) {
                    // spool cheio (flusher parado?) → dropa p/ não estourar disco
                    // nem segurar o worker. Best-effort: APM não bloqueia o app.
                    @error_log('[MadTrace] spool cheio — evento descartado (rode mad:trace-flush)');
                    return true;
                }
                // append protegido pelo lock externo (mutual exclusion garantida)
                return @file_put_contents($file, $line, FILE_APPEND) !== false;
            } finally {
                self::lockRelease($lock);
            }
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Drena o spool NDJSON: rotaciona o arquivo sob lock (rename atômico),
     * POSTa cada linha ao endpoint gravado nela e re-enfileira falhas com
     * n+1 (dropa quando n >= $maxAttempts). Chamado pelo comando
     * mad:trace-flush (cron a cada minuto).
     *
     * @return array{sent:int,failed:int,requeued:int,dropped:int}
     */
    public static function flushSpool(?string $dir = null, int $maxAttempts = 5): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'requeued' => 0, 'dropped' => 0];

        try {
            $dir = (string) ($dir ?: (self::$config['spool_dir'] ?? '') ?: 'tmp/madtrace-spool');
            $file = $dir . '/spool.ndjson';
            if (!is_file($file)) {
                return $out;
            }

            // Rotaciona sob lock: o rename é atômico e libera os writers em
            // seguida — o POST (lento) roda fora da seção crítica.
            $sending = $dir . '/spool.sending.' . getmypid() . '.ndjson';
            $lock = self::lockAcquire($file, self::lockMode($dir), 2000, 5);
            if (!$lock) {
                return $out;
            }
            try {
                if (!is_file($file) || !@rename($file, $sending)) {
                    return $out;
                }
            } finally {
                self::lockRelease($lock);
            }

            $fh = @fopen($sending, 'r');
            if (!$fh) {
                return $out;
            }
            $requeue = [];
            while (($raw = fgets($fh)) !== false) {
                $raw = trim($raw);
                if ($raw === '') {
                    continue;
                }
                $row = @json_decode($raw, true);
                if (!is_array($row) || !isset($row['u'], $row['b'])) {
                    $out['dropped']++; // linha corrompida — sem como reenviar
                    continue;
                }

                $res = self::postRaw(
                    (string) $row['u'],
                    (string) ($row['k'] ?? ''),
                    (int) ($row['s'] ?? 1),
                    (string) $row['b'],
                    (int) (self::$config['perf_timeout_ms'] ?? 1500)
                );

                if ($res['status'] >= 200 && $res['status'] < 300) {
                    $out['sent']++;
                    continue;
                }

                $out['failed']++;
                $row['n'] = (int) ($row['n'] ?? 0) + 1;
                if ($row['n'] >= $maxAttempts) {
                    $out['dropped']++;
                    @error_log('[MadTrace] flush: evento dropado após ' . $row['n'] . ' tentativas (' . $row['u'] . ')');
                } else {
                    $requeue[] = $row;
                }
            }
            @fclose($fh);
            @unlink($sending);

            // re-enfileira as falhas no spool ativo (append sob lock)
            if ($requeue) {
                $lock = self::lockAcquire($file, self::lockMode($dir), 2000, 5);
                if ($lock) {
                    try {
                        $lines = '';
                        foreach ($requeue as $row) {
                            $enc = @json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                            if ($enc !== false) {
                                $lines .= $enc . "\n";
                            }
                        }
                        if ($lines !== '' && @file_put_contents($file, $lines, FILE_APPEND) !== false) {
                            $out['requeued'] = count($requeue);
                        }
                    } finally {
                        self::lockRelease($lock);
                    }
                }
            }
        } catch (\Throwable $e) {
            // nunca propaga
        }

        return $out;
    }

    /** Resolve o modo de lock: 'flock' | 'mkdir' (auto → detecta FS de rede). */
    private static function lockMode(string $dir): string
    {
        $m = strtolower((string) (self::$config['spool_lock_mode'] ?? 'auto'));
        if ($m === 'flock' || $m === 'mkdir') {
            return $m;
        }
        return self::isNetworkFs($dir) ? 'mkdir' : 'flock'; // auto
    }

    /** Detecta FS de rede (NFS/CIFS/…) via /proc/mounts. Cacheado. flock não é confiável nesses. */
    private static function isNetworkFs(string $dir): bool
    {
        static $cache = [];
        $real = @realpath($dir) ?: $dir;
        if (isset($cache[$real])) {
            return $cache[$real];
        }
        $net = false;
        try {
            $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($mounts) {
                $best = ''; $bestType = '';
                foreach ($mounts as $ln) {
                    $p = explode(' ', $ln);
                    if (count($p) < 3) { continue; }
                    if (strpos($real, $p[1]) === 0 && strlen($p[1]) >= strlen($best)) {
                        $best = $p[1]; $bestType = $p[2];
                    }
                }
                $net = in_array(strtolower($bestType), [
                    'nfs', 'nfs4', 'cifs', 'smbfs', 'smb3', 'fuse.sshfs', 'afpfs',
                    'ncpfs', 'glusterfs', 'fuse.glusterfs', '9p', 'fuse.s3fs', 'lustre',
                ], true);
            }
        } catch (\Throwable $e) {
            $net = false;
        }
        return $cache[$real] = $net;
    }

    /**
     * Adquire um lock. flock = LOCK_EX num .lock file; mkdir = mkdir atômico
     * (NFS-safe) com stale-recovery por mtime. Retorna handle ou false.
     */
    private static function lockAcquire(string $base, string $mode, int $waitMs, int $staleSec, bool $yieldIfAlive = false)
    {
        $end = microtime(true) + $waitMs / 1000;

        // flock (disco local): o kernel libera o lock na morte do processo →
        // sem stale/owner/heartbeat. É o caminho recomendado (disco local).
        if ($mode !== 'mkdir') {
            $fh = @fopen($base . '.lock', 'c');
            if (!$fh) { return false; }
            do {
                if (@flock($fh, LOCK_EX | LOCK_NB)) {
                    return ['mode' => 'flock', 'fh' => $fh];
                }
                usleep(2000);
            } while (microtime(true) < $end);
            @fclose($fh);
            return false;
        }

        // mkdir (NFS-safe): stale-recovery imune a clock-skew —
        //  - mesmo host: rouba só se o PID do dono não existe mais (definitivo);
        //  - cross-host: rouba só se o seq do owner ficou CONGELADO por staleSec
        //    medido pelo relógio LOCAL (nunca compara mtime-do-servidor × time()).
        $d = $base . '.lock.d';
        $ownerFile = $d . '/owner';
        $obsSeq = null; $obsAt = 0.0;
        do {
            // is_dir antes do mkdir evita o warning "File exists" na contenção
            // (comum). mkdir continua sendo o passo atômico que decide o lock.
            if (!is_dir($d) && @mkdir($d, 0775)) {
                $start = self::pidStart(getmypid()) ?? '';
                @file_put_contents($ownerFile, gethostname() . "\t" . getmypid() . "\t" . $start . "\t1");
                return ['mode' => 'mkdir', 'dir' => $d, 'owner' => $ownerFile, 'start' => $start, 'seq' => 1, 'beat' => microtime(true)];
            }
            $info  = self::readOwner($ownerFile);
            $steal = false;
            if ($info === null) {
                if ($obsSeq !== '∅') { $obsSeq = '∅'; $obsAt = microtime(true); }
                elseif (microtime(true) - $obsAt > $staleSec) { $steal = true; }
            } elseif ($info['host'] === gethostname()) {
                // vivo SÓ se PID existe E o start-time bate (anti PID-reuse)
                if (!self::pidLiveMatch($info['pid'], $info['start'] ?? '')) { $steal = true; }
                elseif ($yieldIfAlive) { return false; }
                // senão: lock breve → continua girando até soltar
            } else {
                if ($obsSeq !== $info['seq']) {                              // seq mudou → dono vivo
                    if ($yieldIfAlive && $obsSeq !== null) { return false; }
                    $obsSeq = $info['seq']; $obsAt = microtime(true);
                } elseif (microtime(true) - $obsAt > $staleSec) {           // congelado (relógio LOCAL)
                    $steal = true;
                }
            }
            if ($steal) { @unlink($ownerFile); @rmdir($d); continue; }
            usleep(50000);
        } while (microtime(true) < $end);
        return false;
    }

    private static function lockRelease($lock): void
    {
        if (!$lock) { return; }
        if (($lock['mode'] ?? '') === 'mkdir') {
            @unlink($lock['owner'] ?? ($lock['dir'] . '/owner'));
            @rmdir($lock['dir']);
        } else {
            @flock($lock['fh'], LOCK_UN);
            @fclose($lock['fh']);
        }
    }

    /** Lê o owner do lock.d → [host,pid,start,seq] ou null (compat 3-campos). */
    private static function readOwner(string $file): ?array
    {
        $s = @file_get_contents($file);
        if ($s === false || $s === '') { return null; }
        $p = explode("\t", trim($s));
        if (count($p) >= 4) {
            return ['host' => $p[0], 'pid' => (int) $p[1], 'start' => $p[2], 'seq' => $p[3]];
        }
        if (count($p) === 3) { // formato antigo host\tpid\tseq
            return ['host' => $p[0], 'pid' => (int) $p[1], 'start' => '', 'seq' => $p[2]];
        }
        return null;
    }

    /** PID vivo NESTE host? Não-determinável → assume vivo (seq-frozen é o backstop). */
    private static function pidAlive(int $pid): bool
    {
        if ($pid <= 0) { return false; }
        if (function_exists('posix_kill')) {
            if (@posix_kill($pid, 0)) { return true; }
            return function_exists('posix_get_last_error') && posix_get_last_error() === 1; // EPERM = vivo
        }
        if (@is_dir('/proc')) { return @is_dir('/proc/' . $pid); }
        return true;
    }

    /** Vivo E mesmo processo? Anti PID-reuse: exige pid vivo + start-time igual. */
    private static function pidLiveMatch(int $pid, string $start): bool
    {
        if (!self::pidAlive($pid)) { return false; }
        if ($start === '') { return true; } // sem start gravado → degrada p/ pid-only
        $cur = self::pidStart($pid);
        if ($cur === null) { return true; }  // não-determinável agora → não rouba à toa
        return $cur === $start;               // diferente → PID reciclado → tratar como morto
    }

    /** Start-time do processo (estável; muda se o PID for reciclado). Linux /proc, senão ps. */
    private static function pidStart(int $pid): ?string
    {
        if ($pid <= 0) { return null; }
        // Linux: /proc/<pid>/stat campo 22 (starttime). comm tem parens/espaços → corta após o último ')'.
        // Guard is_dir('/proc'): não tenta ler em SO sem /proc (macOS/BSD) → sem warning.
        if (@is_dir('/proc')) {
            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            if ($stat !== false && $stat !== '') {
                $rp = strrpos($stat, ')');
                if ($rp !== false) {
                    $rest = preg_split('/\s+/', trim(substr($stat, $rp + 1)));
                    if (isset($rest[19])) { return 'lt:' . $rest[19]; } // campo 22 = índice 19 após o ')'
                }
            }
        }
        // macOS/BSD: ps -o lstart= (string de início estável por processo)
        if (function_exists('shell_exec')) {
            $o = @shell_exec('ps -o lstart= -p ' . ((int) $pid) . ' 2>/dev/null');
            if ($o !== null && trim($o) !== '') { return 'ps:' . trim($o); }
        }
        return null;
    }

    /** Reduz o payload acima do limite, preservando o núcleo. */
    private static function trimPayload(array $event, int $schema)
    {
        if ($schema === 2) {
            // corta cauda de queries → spans; preserva resumo + N+1
            if (isset($event['queries'])) {
                $event['queries'] = array_slice($event['queries'], 0, 50);
                if (isset($event['context'])) { $event['context']['queries_truncated'] = true; }
            }
            $payload = self::encode($event);
            if ($payload !== false && strlen($payload) <= self::MAX_PAYLOAD_BYTES) {
                return $payload;
            }
            if (isset($event['context']['spans'])) {
                $event['context']['spans'] = array_slice($event['context']['spans'], 0, 50);
            }
            unset($event['queries']);
            $payload = self::encode($event);
            return ($payload !== false && strlen($payload) <= self::MAX_PAYLOAD_BYTES) ? $payload : self::encode([
                'schema' => 2, 'type' => $event['type'] ?? 'transaction',
                'event_id' => $event['event_id'] ?? self::uuid4(),
                'app' => $event['app'] ?? '', 'environment' => $event['environment'] ?? '',
                'route' => $event['route'] ?? null, 'status' => $event['status'] ?? null,
                'duration_ms' => $event['duration_ms'] ?? null,
                'n_plus_one' => $event['n_plus_one'] ?? [],
                '_trimmed' => true,
            ]);
        }

        // schema 1 (erros): descarta partes variáveis
        $event['request']['body']  = ['_dropped' => 'payload too large'];
        $event['request']['query'] = ['_dropped' => 'payload too large'];
        $event['extra']            = ['_dropped' => 'payload too large'];
        $payload = self::encode($event);
        if ($payload === false || strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            $event['exception']['trace'] = array_slice($event['exception']['trace'] ?? [], 0, 5);
            $payload = self::encode($event);
        }
        return $payload;
    }

    /** json_encode resiliente (substitui UTF-8 inválido em vez de falhar tudo). */
    private static function encode(array $event)
    {
        return @json_encode($event, self::JSON_FLAGS);
    }

    // ---------------------------------------------------------------- utils

    /** Remove valores de chaves sensíveis recursivamente. */
    private static function scrub($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        $keys = self::$config['scrub_keys'];
        $out = [];
        foreach ($data as $k => $v) {
            $lk = strtolower((string) $k);
            $hit = false;
            foreach ($keys as $bad) {
                if (strpos($lk, $bad) !== false) { $hit = true; break; }
            }
            if ($hit) {
                $out[$k] = '[scrubbed]';
            } elseif (is_array($v)) {
                $out[$k] = self::scrub($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** Stacktrace compacto e seguro (sem args, que podem vazar segredos). */
    private static function formatTrace(array $trace)
    {
        $frames = [];
        foreach (array_slice($trace, 0, 30) as $f) {
            $frames[] = [
                'file'     => $f['file'] ?? '',
                'line'     => $f['line'] ?? 0,
                'function' => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''),
            ];
        }
        return $frames;
    }

    /** Normaliza mensagem para fingerprint (tira números/hashes/paths variáveis). */
    private static function normalize($message)
    {
        $m = (string) $message;
        $m = preg_replace('/0x[0-9a-f]+/i', '0xX', $m);
        $m = preg_replace('/\d+/', 'N', $m);
        $m = preg_replace('/[a-f0-9]{16,}/i', 'HASH', $m);
        return $m;
    }

    /** Trunca preservando caracteres multibyte (não corta UTF-8 no meio). */
    private static function truncate($s, $len)
    {
        $s = (string) $s;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return (mb_strlen($s, 'UTF-8') > $len) ? mb_substr($s, 0, $len, 'UTF-8') . '…' : $s;
        }
        return (strlen($s) > $len) ? substr($s, 0, $len) . '…' : $s;
    }

    private static function limitArray(array $a, $max)
    {
        return (count($a) > $max) ? array_slice($a, 0, $max, true) : $a;
    }

    private static function uuid4()
    {
        $b = @random_bytes(16);
        if ($b === false || strlen($b) < 16) {
            $b = md5(uniqid('', true), true);
        }
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * Limpa TODO o estado static. @internal — SÓ para testes (a classe é
     * static por design; sem isso, estado vaza entre test cases).
     */
    public static function _reset(): void
    {
        self::$config           = [];
        self::$installed        = false;
        self::$sent             = false;
        self::$lastSend         = null;
        self::$flushed          = false;
        self::$pending          = [];
        self::$seen             = [];
        self::$errorCount       = 0;
        self::$context          = ['user' => [], 'tags' => [], 'extra' => []];
        self::$fatalNoted       = null;
        self::$memReserve       = null;
        self::$t0               = 0.0;
        self::$bootEndWall      = 0.0;
        self::$bootEndHr        = 0;
        self::$bootMarked       = false;
        self::$sampledIn        = false;
        self::$txSent           = false;
        self::$txName           = null;
        self::$txType           = null;
        self::$spans            = [];
        self::$spanSeq          = 0;
        self::$spanStack        = [];
        self::$queries          = [];
        self::$querySeq         = 0;
        self::$lastQ            = null;
        self::$queriesTruncated = false;
        self::$jobMode          = false;
        self::$jobEnded         = false;
        self::$job              = [];
        self::$jobStartWall     = 0.0;
        self::$lastJob          = null;
    }
}

/**
 * Handle de span retornado por MadTrace::startSpan(). id=-1 é no-op (request
 * não amostrada) — finish()/annotate() são seguros e custam ~zero.
 */
final class MadTraceSpan
{
    /** @var int */
    private $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function finish(): void
    {
        MadTrace::_finishSpan($this->id);
    }

    public function annotate(string $key, $value): self
    {
        MadTrace::_annotateSpan($this->id, $key, $value);
        return $this;
    }
}

/**
 * Drop-in global para error_log().
 *
 *     error_log('[FRAUD] ...')  ->  mad_trace_error_log('[FRAUD] ...')
 *
 * @param string      $message
 * @param int         $type
 * @param string|null $level
 * @param array       $extra
 * @return bool
 */
if (!function_exists('mad_trace_error_log')) {
    function mad_trace_error_log($message, $type = 0, $level = null, array $extra = [])
    {
        if (class_exists('MadTrace')) {
            MadTrace::captureFromErrorLog($message, $type, $level, $extra);
            return true;
        }
        return @error_log($message, $type);
    }
}
