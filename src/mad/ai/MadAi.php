<?php

namespace Mad\Ai;

use Laravel\Ai\AiManager;

/**
 * MadAi — configuração do laravel/ai a partir do config MAD.
 *
 * No framework antigo esta classe era um bootstrap inteiro do pacote SEM
 * illuminate/foundation (container/facades/eventos a mão). Aqui o app é
 * Laravel completo — o AiServiceProvider do pacote já registra tudo; sobra:
 *
 *  (a) montar config('ai') com a precedência MAD: preferência do sistema
 *      (tela Preferências, chaves ai_*) > config/mad.php [ai] > env > default;
 *  (b) ligar o rastreio de custo do OpenRouter (usage.cost → AiCallCost) via
 *      o seam público useTextGateway — sem patch de vendor.
 *
 * Idempotente; chamar antes de construir o EmbedAgent.
 */
final class MadAi
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        config(['ai' => array_replace(config('ai', []), self::buildAiConfig())]);

        // Custo por chamada (OpenRouter): gateway estendido tapeia extractUsage
        // e acumula usage.cost em AiCallCost (o registro de uso consome).
        try {
            if (self::providerRaw() === 'openrouter') {
                app(AiManager::class)
                    ->textProvider('openrouter')
                    ->useTextGateway(new CostTrackingOpenRouterGateway(app('events')));
            }
        } catch (\Throwable $e) {
            error_log('[mad-ai] cost tracking wiring failed (segue sem custo): ' . $e->getMessage());
        }

        self::$booted = true;
    }

    /** Reset p/ testes (flag + config são por processo). */
    public static function reset(): void
    {
        self::$booted = false;
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

    public static function model(): string
    {
        self::boot();
        $provider = self::provider();

        $model = config("ai.providers.{$provider}.models.text.default");
        if (is_string($model) && $model !== '') {
            return $model;
        }

        return $provider === 'openrouter' ? 'anthropic/claude-haiku-4.5' : 'claude-haiku-4-5';
    }

    public static function maxSteps(): int
    {
        self::boot();

        return (int) (config('ai.max_steps') ?: 8);
    }

    /**
     * Config 'ai' com precedência: preferência do sistema > config mad [ai] >
     * env > default. Providers anthropic e openrouter; `provider` escolhe.
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

        $provider = strtolower($pref('ai_provider') ?? (string) ($ai['provider'] ?? 'anthropic'));
        if (! in_array($provider, ['anthropic', 'openrouter'], true)) {
            $provider = 'anthropic';
        }
        $maxSteps  = (int) ($pref('ai_max_steps') ?? $ai['max_steps'] ?? 8);
        $maxTokens = (int) ($pref('ai_max_tokens') ?? $ai['max_tokens'] ?? 0); // 0 = default do provider

        $anthropicModel = $pref('ai_anthropic_model') ?? (string) ($ai['anthropic_model'] ?? $ai['model'] ?? 'claude-haiku-4-5');
        $anthropicKey   = $pref('ai_anthropic_api_key') ?? (string) ($ai['anthropic_api_key'] ?? $ai['api_key'] ?? (getenv('ANTHROPIC_API_KEY') ?: ''));
        $anthropicUrl   = (string) ($ai['anthropic_base_url'] ?? $ai['base_url'] ?? (getenv('ANTHROPIC_URL') ?: 'https://api.anthropic.com/v1'));

        $openrouterModel = $pref('ai_openrouter_model') ?? (string) ($ai['openrouter_model'] ?? 'anthropic/claude-haiku-4.5');
        $openrouterKey   = $pref('ai_openrouter_api_key') ?? (string) ($ai['openrouter_api_key'] ?? (getenv('OPENROUTER_API_KEY') ?: ''));
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
            ],
        ];
    }
}
