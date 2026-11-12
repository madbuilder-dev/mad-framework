<?php

namespace Mad\Ai;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Mad\Security\StoredSecret;

/**
 * MadAi — configuração do laravel/ai a partir do config MAD.
 *
 * No framework antigo esta classe era um bootstrap inteiro do pacote SEM
 * illuminate/foundation (container/facades/eventos a mão). Aqui o app é
 * Laravel completo — o AiServiceProvider do pacote já registra tudo; sobra:
 *
 *  (a) montar config('ai') com a precedência MAD (doc: docs/ia-do-app.md):
 *        1. chave própria (Preferências › IA, chaves ai_*; senão config/mad.php
 *           [ai] / env) do provider escolhido → anthropic | openrouter;
 *        2. Mad Coding Plan do DONO do projeto → provider `coding_plan`
 *           (MAD_AI_CODING_PLAN ligado no MadBuilder e a opção "Usar o Mad
 *           Coding Plan" ligada em Preferências › IA);
 *        3. nada → a chamada falha com o motivo (MadAiException);
 *  (b) ligar o rastreio de custo do OpenRouter (usage.cost → AiCallCost) via
 *      o seam público useTextGateway — sem patch de vendor;
 *  (c) no `coding_plan`, o CodingPlanGateway: driver anthropic do laravel/ai
 *      apontado para a Messages API do MadBuilder, com a credencial curta que
 *      `codingPlanSession()` minta com o MAD_PROJECT_TOKEN. O modelo é o da
 *      plataforma (MiniMax), a cota debitada é a semanal do dono, e a chave
 *      da pool nunca chega ao app. Sem plano ativo, não funciona.
 *
 * `provider()`/`model()` são baratos (sem rede): também servem para o log de
 * uso. Para a tela saber se há IA e por quê: `disponivel()`, `motivo()`,
 * `estado()`. Para transformar qualquer falha de chamada em texto para o
 * usuário: `mensagemDeErro($e)`.
 *
 * Idempotente; chamar antes de construir o agente.
 */
final class MadAi
{
    /** Provider do config('ai') quando a IA vem do Mad Coding Plan do dono. */
    public const CODING_PLAN = 'coding_plan';

    public const MODO_CHAVE_PROPRIA = 'chave_propria';

    public const MODO_CODING_PLAN = 'coding_plan';

    public const MODO_NENHUM = 'nenhum';

    /** Padrões de chave própria (Haiku atual). */
    public const DEFAULT_ANTHROPIC_MODEL = 'claude-haiku-5-5';

    public const DEFAULT_OPENROUTER_MODEL = 'anthropic/claude-haiku-5.5';

    /** Rótulo do modelo antes da 1ª sessão (quem decide é a plataforma). */
    private const CODING_PLAN_MODEL_LABEL = 'MiniMax-M3';

    private const SESSION_CACHE = 'mad_ai:coding_plan:session';

    private const REFUSAL_CACHE = 'mad_ai:coding_plan:refusal';

    /** Recusa da plataforma lembrada por este tempo (não martelar o MadBuilder a cada tela). */
    private const REFUSAL_TTL = 60;

    private const REQUIRED_MESSAGE =
        'A IA deste sistema usa o Mad Coding Plan de quem mantém o sistema. Ative o plano no MadBuilder ou informe uma chave própria em Preferências › IA.';

    private static bool $booted = false;

    private static string $modo = self::MODO_NENHUM;

    /** Por que não há IA quando o modo é `nenhum`. */
    private static string $motivoNenhum = '';

    /** @var array{token: string, base_url: string, model: string, exp: int, quota: array|null}|null */
    private static ?array $session = null;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        config(['ai' => array_replace(config('ai', []), self::buildAiConfig())]);

        try {
            $manager  = app(AiManager::class);
            $provider = self::providerRaw();
            // Instâncias de um boot anterior (troca de preferência, testes)
            // carregariam o gateway antigo.
            $manager->forgetInstance(['anthropic', 'openrouter', self::CODING_PLAN]);

            if (self::$modo === self::MODO_CHAVE_PROPRIA) {
                // Custo por chamada (OpenRouter): gateway estendido tapeia
                // extractUsage e acumula usage.cost em AiCallCost.
                if ($provider === 'openrouter') {
                    $manager->textProvider('openrouter')
                        ->useTextGateway(new CostTrackingOpenRouterGateway(app('events')));
                }
            } else {
                // Coding Plan — e também "nada configurado": a chamada cai no
                // gateway, que falha com o motivo em vez do 401 cru do provedor.
                $manager->textProvider($provider)->useTextGateway(new CodingPlanGateway(app('events')));
            }
        } catch (\Throwable $e) {
            error_log('[mad-ai] gateway wiring failed: ' . $e->getMessage());
        }

        self::$booted = true;
    }

    /**
     * Relê preferências e config no próximo uso (testes; tela de Preferências
     * depois de salvar). `$esquecerSessao` também descarta a credencial e a
     * recusa guardadas no cache do app: a próxima pergunta vai ao MadBuilder.
     */
    public static function reset(bool $esquecerSessao = true): void
    {
        self::$booted       = false;
        self::$modo         = self::MODO_NENHUM;
        self::$motivoNenhum = '';
        self::$session      = null;
        if (! $esquecerSessao) {
            return;
        }
        try {
            Cache::forget(self::SESSION_CACHE);
            Cache::forget(self::REFUSAL_CACHE);
        } catch (\Throwable) {
            // sem cache: nada a esquecer
        }
    }

    public static function provider(): string
    {
        self::boot();

        return self::providerRaw();
    }

    private static function providerRaw(): string
    {
        return (string) (config('ai.default') ?: 'anthropic');
    }

    /** `chave_propria` | `coding_plan` | `nenhum`. */
    public static function modo(): string
    {
        self::boot();

        return self::$modo;
    }

    public static function model(): string
    {
        self::boot();
        $provider = self::provider();

        if ($provider === self::CODING_PLAN) {
            // O Node da plataforma troca pelo modelo dela de qualquer jeito;
            // aqui é só o rótulo (log de uso) — sem rede.
            $known = self::$session['model'] ?? null;

            return is_string($known) && $known !== '' ? $known : self::CODING_PLAN_MODEL_LABEL;
        }

        $model = config("ai.providers.{$provider}.models.text.default");
        if (is_string($model) && $model !== '') {
            return $model;
        }

        return $provider === 'openrouter' ? self::DEFAULT_OPENROUTER_MODEL : self::DEFAULT_ANTHROPIC_MODEL;
    }

    public static function maxSteps(): int
    {
        self::boot();

        return (int) (config('ai.max_steps') ?: 8);
    }

    // ── A tela pergunta: há IA? por quê não? ────────────────────────────────

    public static function disponivel(): bool
    {
        return self::estado()['disponivel'];
    }

    /** Motivo em pt-BR quando não há IA; null quando há. */
    public static function motivo(): ?string
    {
        return self::estado()['motivo'];
    }

    /**
     * Estado da IA do app, sem segredo nenhum. No `coding_plan` pergunta ao
     * MadBuilder (com cache da sessão e da recusa). Com `$consultar = false`
     * não sai rede: responde só o que já se sabe (`verificado` = false quando
     * ainda não se perguntou) — é o que a tela de Preferências usa no render.
     *
     * @return array{modo: string, disponivel: bool, verificado: bool, motivo: string|null, codigo: string|null,
     *   reset_at: string|null, quota: array{used: int, limit: int, pct: int, reset_at: string}|null}
     */
    public static function estado(bool $consultar = true): array
    {
        self::boot();
        $out = ['modo' => self::$modo, 'disponivel' => false, 'verificado' => true, 'motivo' => null, 'codigo' => null, 'reset_at' => null, 'quota' => null];

        if (self::$modo === self::MODO_CHAVE_PROPRIA) {
            $out['disponivel'] = true;

            return $out;
        }

        if (! $consultar && self::$modo === self::MODO_CODING_PLAN
            && ! self::sessionValid(self::$session)
            && ! self::sessionValid(self::cacheGet(self::SESSION_CACHE))
            && ! is_array(self::cacheGet(self::REFUSAL_CACHE))) {
            $out['verificado'] = false;

            return $out;
        }

        try {
            $session           = self::codingPlanSession();
            $out['disponivel'] = true;
            $weekly            = $session['quota']['weekly'] ?? null;
            if (is_array($weekly)) {
                $out['quota'] = [
                    'used'     => (int) ($weekly['used'] ?? 0),
                    'limit'    => (int) ($weekly['limit'] ?? 0),
                    'pct'      => (int) ($weekly['pct'] ?? 0),
                    'reset_at' => (string) ($weekly['reset_at'] ?? ''),
                ];
            }
        } catch (MadAiException $e) {
            $out['motivo']   = $e->getMessage();
            $out['codigo']   = $e->codigo();
            $out['reset_at'] = $e->resetAt();
        }

        return $out;
    }

    // ── Sessão do Mad Coding Plan ───────────────────────────────────────────

    /**
     * Credencial curta da IA do app no MadBuilder (POST /api/app-llm/session,
     * Bearer MAD_PROJECT_TOKEN). Reaproveitada até 60 s antes de vencer —
     * no processo e no cache do app (entre requisições do FPM). Recusa da
     * plataforma é lembrada por 60 s.
     *
     * @return array{token: string, base_url: string, model: string, exp: int, quota: array|null}
     *
     * @throws MadAiException sem IA disponível, com o motivo
     */
    public static function codingPlanSession(): array
    {
        self::boot();

        if (self::$modo !== self::MODO_CODING_PLAN) {
            throw new MadAiException(self::$motivoNenhum ?: self::REQUIRED_MESSAGE, 'ai_not_configured');
        }

        if (self::sessionValid(self::$session)) {
            return self::$session;
        }
        $cached = self::cacheGet(self::SESSION_CACHE);
        if (self::sessionValid($cached)) {
            return self::$session = $cached;
        }

        $refusal = self::cacheGet(self::REFUSAL_CACHE);
        if (is_array($refusal) && isset($refusal['message'])) {
            throw new MadAiException((string) $refusal['message'], (string) ($refusal['code'] ?? 'unavailable'), $refusal['reset_at'] ?? null);
        }

        $builderUrl   = rtrim((string) config('mad.builder.url'), '/');
        $projectToken = (string) config('mad.builder.token');
        if ($builderUrl === '' || $projectToken === '') {
            throw new MadAiException(
                'A IA deste sistema não está vinculada ao MadBuilder (MAD_BUILDER_URL / MAD_PROJECT_TOKEN ausentes no .env).',
                'not_linked',
            );
        }

        try {
            $resp = Http::withToken($projectToken)->acceptJson()->timeout(15)
                ->post($builderUrl . '/api/app-llm/session');
        } catch (\Throwable $e) {
            error_log('[mad-ai] coding plan session: ' . $e->getMessage());

            throw new MadAiException('A IA deste sistema não conseguiu falar com o MadBuilder. Tente de novo em instantes.', 'unavailable', null, $e);
        }

        if (! $resp->successful()) {
            $body    = (array) $resp->json();
            $code    = (string) ($body['code'] ?? '');
            $resetAt = is_string($body['reset_at'] ?? null) ? $body['reset_at'] : null;
            $message = self::refusalMessage($resp->status(), $code, $resetAt, $body);
            $code    = $code !== '' ? $code : ($resp->status() === 401 ? 'not_linked' : 'unavailable');
            error_log('[mad-ai] coding plan session HTTP ' . $resp->status() . ' ' . $code);
            if ($resp->status() < 500) {
                self::cachePut(self::REFUSAL_CACHE, ['message' => $message, 'code' => $code, 'reset_at' => $resetAt], self::REFUSAL_TTL);
            }

            throw new MadAiException($message, $code, $resetAt);
        }

        $data    = (array) $resp->json();
        $token   = (string) ($data['token'] ?? '');
        $baseUrl = rtrim((string) ($data['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            // Plataforma antiga sem `base_url`: deriva do front door do chat.
            $baseUrl = (string) preg_replace('#/chat$#', '', rtrim((string) config('mad.ai.embed_stream_url', ''), '/')) . '/v1';
        }
        if ($token === '' || $baseUrl === '/v1') {
            throw new MadAiException('A IA deste sistema não conseguiu falar com o MadBuilder. Tente de novo em instantes.', 'unavailable');
        }

        $ttl     = max(60, (int) ($data['expires_in'] ?? 900));
        $session = [
            'token'    => $token,
            'base_url' => $baseUrl,
            'model'    => (string) ($data['model'] ?? self::CODING_PLAN_MODEL_LABEL),
            'exp'      => time() + $ttl,
            'quota'    => is_array($data['quota'] ?? null) ? $data['quota'] : null,
        ];
        self::cachePut(self::SESSION_CACHE, $session, $ttl - 60);

        return self::$session = $session;
    }

    /** Sessão com token e endereço, valendo por mais de 60 s. */
    private static function sessionValid(mixed $s): bool
    {
        return is_array($s) && (int) ($s['exp'] ?? 0) - time() > 60
            && ($s['token'] ?? '') !== '' && ($s['base_url'] ?? '') !== '';
    }

    /** Esquece a sessão (credencial recusada): a próxima chamada minta outra. */
    public static function forgetCodingPlanSession(): void
    {
        self::$session = null;
        try {
            Cache::forget(self::SESSION_CACHE);
        } catch (\Throwable) {
            // sem cache
        }
    }

    // ── Falha → texto para o usuário ────────────────────────────────────────

    /** Mensagem pt-BR para qualquer falha de uma chamada de IA (nunca o corpo cru). */
    public static function mensagemDeErro(\Throwable $e): string
    {
        return self::asException($e)->getMessage();
    }

    /** A falha como MadAiException (motivo, código e hora de volta da franquia). */
    public static function asException(\Throwable $e): MadAiException
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($t instanceof MadAiException) {
                return $t;
            }
        }

        $status = self::httpStatusOf($e);
        $body   = [];
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($t instanceof RequestException && $t->response !== null) {
                $body = (array) $t->response->json();
                break;
            }
        }
        $code    = is_string($body['code'] ?? null) ? $body['code'] : '';
        $resetAt = is_string($body['reset_at'] ?? null) ? $body['reset_at'] : null;

        if ($status !== null) {
            return new MadAiException(self::refusalMessage($status, $code, $resetAt, $body, true), $code !== '' ? $code : 'unavailable', $resetAt, $e);
        }

        $class = get_class($e);
        if (str_contains($class, 'RateLimited')) {
            return new MadAiException('A IA está com muita demanda no momento. Tente de novo em instantes.', 'pool_exhausted', null, $e);
        }

        return new MadAiException('Não foi possível falar com a IA agora. Tente de novo em instantes.', 'unavailable', null, $e);
    }

    /** Status HTTP da resposta que originou a falha (cadeia de exceções), se houver. */
    public static function httpStatusOf(\Throwable $e): ?int
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($t instanceof RequestException && $t->response !== null) {
                return $t->response->status();
            }
        }

        return null;
    }

    /**
     * Texto da recusa pelo `code` do contrato da plataforma (builder#679).
     *
     * @param array<string, mixed> $body
     * @param bool $call true = falha de uma chamada (não do mint da sessão)
     */
    private static function refusalMessage(int $status, string $code, ?string $resetAt, array $body, bool $call = false): string
    {
        switch ($code) {
            case 'coding_plan_required':
                return self::REQUIRED_MESSAGE;
            case 'coding_plan_quota_exhausted':
                $when = self::localDateTime($resetAt);

                return 'A franquia semanal do Mad Coding Plan de quem mantém o sistema acabou. A IA volta a responder '
                    . ($when !== '' ? "em {$when}." : 'na próxima semana.');
            case 'app_ai_disabled':
                return 'A IA deste sistema pelo Mad Coding Plan está desligada no projeto. Quem mantém o sistema liga em Propriedades do projeto › Modelos de IA, no MadBuilder, ou informe uma chave própria em Preferências › IA.';
            case 'pool_exhausted':
                return 'A IA está com muita demanda no momento. Tente de novo em instantes.';
            case 'rate_limited':
                return 'Muitas chamadas de IA seguidas neste sistema. Aguarde um minuto e tente de novo.';
            case 'upstream_rejected':
                $msg = trim((string) ($body['error']['message'] ?? ''));

                return $msg !== '' ? $msg : 'O modelo recusou o pedido.';
        }

        if ($status === 401) {
            return $call
                ? 'A IA recusou a credencial deste sistema. Tente de novo; se continuar, confira a chave em Preferências › IA.'
                : 'O MadBuilder não reconheceu este sistema (token do projeto inválido ou revogado). Publique o projeto de novo.';
        }
        if ($status === 429) {
            return 'A IA está com muita demanda no momento. Tente de novo em instantes.';
        }
        if ($status === 402) {
            return 'A conta da chave de IA deste sistema está sem crédito. Confira a chave em Preferências › IA.';
        }
        if ($status === 400) {
            return 'O modelo recusou o pedido.';
        }

        return 'A IA está indisponível no momento. Tente de novo em instantes.';
    }

    /** 'd/m/Y H:i' no fuso do app; '' quando não dá para ler. */
    private static function localDateTime(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }
        try {
            return \Illuminate\Support\Carbon::parse($iso)
                ->setTimezone((string) config('app.timezone', 'UTC'))
                ->format('d/m/Y H:i');
        } catch (\Throwable) {
            return '';
        }
    }

    private static function cacheGet(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function cachePut(string $key, mixed $value, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        try {
            Cache::put($key, $value, $seconds);
        } catch (\Throwable) {
            // sem cache: fica só no processo
        }
    }

    // ── config('ai') ────────────────────────────────────────────────────────

    /**
     * Config 'ai' com precedência: chave própria (preferência do sistema >
     * config mad [ai] > env) > Mad Coding Plan do dono > nada. Providers
     * anthropic e openrouter com chave; `coding_plan` sem (credencial na hora).
     *
     * @return array<string, mixed>
     */
    private static function buildAiConfig(): array
    {
        $ai = (array) (\Mad\Core\AppConfig::get()['ai'] ?? []);

        $prefs = [];
        if (class_exists('SystemPreferenceService')) {
            try {
                $prefs = (array) (\SystemPreferenceService::getPreferences() ?? []);
            } catch (\Throwable) {
                $prefs = [];
            }
        }
        $pref = static function (string $key) use ($prefs): ?string {
            $v = isset($prefs[$key]) ? trim((string) $prefs[$key]) : '';

            return $v !== '' ? $v : null;
        };
        // As chaves de API ficam CIFRADAS nas preferências (StoredSecret). Texto
        // puro de antes da cifragem segue valendo; chave que não dá para ler
        // (APP_KEY trocada) conta como não gravada e cai no config/env — o log
        // diz o motivo. O valor nunca é logado.
        $secret = static function (string $key, string $label) use ($pref): ?string {
            $v = trim(StoredSecret::open($pref($key), $label));

            return $v !== '' ? $v : null;
        };

        $provider = strtolower($pref('ai_provider') ?? (string) ($ai['provider'] ?? 'anthropic'));
        if (! in_array($provider, ['anthropic', 'openrouter'], true)) {
            $provider = 'anthropic';
        }
        $maxSteps  = (int) ($pref('ai_max_steps') ?? $ai['max_steps'] ?? 8);
        $maxTokens = (int) ($pref('ai_max_tokens') ?? $ai['max_tokens'] ?? 0); // 0 = default do provider

        $anthropicModel = $pref('ai_anthropic_model') ?? (string) ($ai['anthropic_model'] ?? $ai['model'] ?? self::DEFAULT_ANTHROPIC_MODEL);
        $anthropicKey   = $secret('ai_anthropic_api_key', 'Preferências › IA › API key (Anthropic)') ?? (string) ($ai['anthropic_api_key'] ?? $ai['api_key'] ?? (getenv('ANTHROPIC_API_KEY') ?: ''));
        $anthropicUrl   = (string) ($ai['anthropic_base_url'] ?? $ai['base_url'] ?? (getenv('ANTHROPIC_URL') ?: 'https://api.anthropic.com/v1'));

        $openrouterModel = $pref('ai_openrouter_model') ?? (string) ($ai['openrouter_model'] ?? self::DEFAULT_OPENROUTER_MODEL);
        $openrouterKey   = $secret('ai_openrouter_api_key', 'Preferências › IA › API key (OpenRouter)') ?? (string) ($ai['openrouter_api_key'] ?? (getenv('OPENROUTER_API_KEY') ?: ''));
        $openrouterUrl   = (string) ($ai['openrouter_base_url'] ?? (getenv('OPENROUTER_URL') ?: ''));

        $openrouter = [
            'driver' => 'openrouter',
            'key'    => $openrouterKey,
            'models' => [
                'text' => [
                    'default'  => $openrouterModel,
                    'cheapest' => $openrouterModel,
                    'smartest' => $openrouterModel,
                ],
            ],
        ];
        if ($openrouterUrl !== '') {
            $openrouter['url'] = $openrouterUrl;
        }

        // 1. Chave própria do provider escolhido.
        $ownKey = trim($provider === 'openrouter' ? $openrouterKey : $anthropicKey) !== '';

        // 2. Mad Coding Plan: ligado no projeto (MAD_AI_CODING_PLAN, do
        //    MadBuilder) e não desligado aqui (Preferências › IA — a mesma
        //    opção do Copilot IA).
        $codingPlanOnProject = (bool) ($ai['coding_plan'] ?? config('mad.ai.coding_plan', false));
        $codingPlanPref      = strtoupper((string) ($pref('copilot_coding_plan') ?? 'T'));
        $codingPlanOn        = $codingPlanOnProject && ! in_array($codingPlanPref, ['F', '0'], true);

        if ($ownKey) {
            self::$modo = self::MODO_CHAVE_PROPRIA;
        } elseif ($codingPlanOn) {
            self::$modo = self::MODO_CODING_PLAN;
            $provider   = self::CODING_PLAN;
        } else {
            self::$modo         = self::MODO_NENHUM;
            self::$motivoNenhum = $codingPlanOnProject
                ? 'O uso do Mad Coding Plan está desligado em Preferências › IA. Ligue a opção ou informe uma chave própria.'
                : 'Nenhuma IA configurada neste sistema. Informe uma chave própria em Preferências › IA ou peça a quem mantém o sistema para ligar o Mad Coding Plan no MadBuilder.';
        }

        $codingPlanModel = (string) (self::$session['model'] ?? self::CODING_PLAN_MODEL_LABEL);

        return [
            'default'    => $provider,
            'max_steps'  => $maxSteps,
            'max_tokens' => $maxTokens,
            'providers'  => [
                'anthropic' => [
                    'driver' => 'anthropic',
                    'key'    => $anthropicKey,
                    'url'    => $anthropicUrl,
                    'models' => [
                        'text' => [
                            'default'  => $anthropicModel,
                            'cheapest' => $anthropicModel,
                            'smartest' => $anthropicModel,
                        ],
                    ],
                ],
                'openrouter' => $openrouter,
                // Credencial e URL vêm da sessão, na hora (CodingPlanGateway).
                // A `key` aqui é só um marcador — nunca vai a lugar nenhum.
                self::CODING_PLAN => [
                    'driver' => 'anthropic',
                    'key'    => 'coding-plan',
                    'url'    => '',
                    'models' => [
                        'text' => [
                            'default'  => $codingPlanModel,
                            'cheapest' => $codingPlanModel,
                            'smartest' => $codingPlanModel,
                        ],
                    ],
                ],
            ],
        ];
    }
}
