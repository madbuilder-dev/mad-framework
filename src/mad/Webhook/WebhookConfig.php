<?php

namespace Mad\Webhook;

/**
 * Leitura de `config('mad.webhook.*')`, SEMPRE com o padrão em linha.
 *
 * O merge de padrões do pacote (`mergeConfigFrom`) é pulado quando a config do
 * app está em cache, e um app que só atualizou o vendor ainda não tem o bloco
 * `webhook` no `config/mad.php` dele. Por isso nada aqui depende de a chave
 * existir.
 */
final class WebhookConfig
{
    /** Conexão das duas tabelas do recurso (control-plane: nunca é repontada por empresa). */
    public static function connection(): string
    {
        return (string) (config('mad.webhook.connection') ?: 'iam');
    }

    /** Caminho do arquivo de definições gerado pela plataforma. */
    public static function definitionsPath(): string
    {
        $path = (string) (config('mad.webhook.definitions_path') ?: '');

        return $path !== '' ? $path : app_path('config/webhooks.json');
    }

    /** Tentativas de processamento antes de desistir da entrega. */
    public static function maxAttempts(): int
    {
        return max(1, (int) (config('mad.webhook.max_attempts') ?: 5));
    }

    /** Segundos até uma entrega `received` ser considerada parada. */
    public static function staleSeconds(): int
    {
        return max(10, (int) (config('mad.webhook.stale_seconds') ?: 120));
    }

    /** Idade máxima (s) do batimento do worker para a fila ser usada no modo `auto`. */
    public static function heartbeatTtl(): int
    {
        return max(30, (int) (config('mad.webhook.heartbeat_ttl') ?: 90));
    }

    /** Quantas linhas `rejected` ficam guardadas por endpoint. */
    public static function rejectedSample(): int
    {
        $value = config('mad.webhook.rejected_sample');

        return $value === null || $value === '' ? 20 : max(0, (int) $value);
    }
}
