<?php

namespace Mad\Ai;

/**
 * Provedores de IA com chave direta além de OpenRouter e Anthropic (fórum #376,
 * 21/11/2026): Google (Gemini), OpenAI e xAI (Grok).
 *
 * Cada um é um provider do config('ai') com o driver do laravel/ai de mesmo
 * nome. A chave vem, nesta ordem, das Preferências › IA (cifrada no banco do
 * app), do config/mad.php [ai] e do .env — e nunca sai do servidor do app.
 * O modelo vem das Preferências, do config ou, sem nenhum, do padrão do
 * próprio driver do laravel/ai (MadAi::model()).
 *
 * OpenRouter e Anthropic seguem montados à parte em MadAi::buildAiConfig()
 * (precedências antigas: `api_key`, `model`, ANTHROPIC_URL), para os apps que
 * já existem não mudarem de comportamento.
 */
final class MadAiProviders
{
    /**
     * id do provider => metadados. `pref_key`/`pref_model` são as chaves em
     * Preferências; `config_key`/`config_model`/`config_url` as do [ai]; `env`
     * a variável do .env (os nomes que o laravel/ai também usa).
     *
     * @var array<string, array{label: string, driver: string, pref_key: string, pref_model: string,
     *   config_key: string, config_model: string, config_url: string, env: string, env_url: string}>
     */
    public const DIRECT = [
        'google' => [
            'label'         => 'Google (Gemini)',
            'driver'        => 'gemini',
            'pref_key'      => 'ai_google_api_key',
            'pref_model'    => 'ai_google_model',
            'config_key'    => 'google_api_key',
            'config_model'  => 'google_model',
            'config_url'    => 'google_base_url',
            'env'           => 'GEMINI_API_KEY',
            'env_url'       => 'GEMINI_URL',
        ],
        'openai' => [
            'label'         => 'OpenAI',
            'driver'        => 'openai',
            'pref_key'      => 'ai_openai_api_key',
            'pref_model'    => 'ai_openai_model',
            'config_key'    => 'openai_api_key',
            'config_model'  => 'openai_model',
            'config_url'    => 'openai_base_url',
            'env'           => 'OPENAI_API_KEY',
            'env_url'       => 'OPENAI_URL',
        ],
        'xai' => [
            'label'         => 'xAI (Grok)',
            'driver'        => 'xai',
            'pref_key'      => 'ai_xai_api_key',
            'pref_model'    => 'ai_xai_model',
            'config_key'    => 'xai_api_key',
            'config_model'  => 'xai_model',
            'config_url'    => 'xai_base_url',
            'env'           => 'XAI_API_KEY',
            'env_url'       => 'XAI_URL',
        ],
    ];

    /** Todo provider que a preferência `ai_provider` aceita. @return list<string> */
    public static function ids(): array
    {
        return ['openrouter', 'anthropic', ...array_keys(self::DIRECT)];
    }

    public static function isDirect(string $id): bool
    {
        return isset(self::DIRECT[$id]);
    }

    /** Onde a chave do provider é informada — para o log, nunca o valor. */
    public static function secretLabel(string $id): string
    {
        $label = self::DIRECT[$id]['label'] ?? $id;

        return "Preferências › IA › API key ({$label})";
    }
}
