<?php

namespace Mad\Webhook;

use Illuminate\Support\Facades\Log;

/**
 * Definições de webhooks recebidos — o conteúdo de `app/config/webhooks.json`.
 *
 * Lido DIRETO do disco (não passa por `config()`), memoizado por caminho +
 * mtime + tamanho: um worker de vida longa (Octane, `queue:work`) sobrevive à
 * sincronização que troca o arquivo e tem que enxergar a versão nova na
 * requisição seguinte, sem reinício.
 *
 * Arquivo ausente, ilegível ou com JSON quebrado = nenhuma definição (todo
 * endereço responde 404). Nunca lança.
 */
final class WebhookRegistry
{
    /** @var array{path: string, stamp: string, definitions: array<string, WebhookDefinition>}|null */
    private static ?array $memo = null;

    /** @return array<string, WebhookDefinition> slug => definição (inclui as desligadas) */
    public static function all(): array
    {
        $path = WebhookConfig::definitionsPath();

        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return [];
        }
        $stamp = $mtime . ':' . (int) @filesize($path);

        if (self::$memo !== null && self::$memo['path'] === $path && self::$memo['stamp'] === $stamp) {
            return self::$memo['definitions'];
        }

        $definitions = self::parse($path);
        self::$memo = ['path' => $path, 'stamp' => $stamp, 'definitions' => $definitions];

        return $definitions;
    }

    public static function find(string $slug): ?WebhookDefinition
    {
        return self::all()[$slug] ?? null;
    }

    /** Há ao menos uma definição no arquivo (ligada ou não)? */
    public static function hasDefinitions(): bool
    {
        return self::all() !== [];
    }

    /** Esquece o que foi lido (testes). */
    public static function flush(): void
    {
        self::$memo = null;
    }

    /** @return array<string, WebhookDefinition> */
    private static function parse(string $path): array
    {
        $json = @file_get_contents($path);
        if ($json === false || $json === '') {
            return [];
        }

        $data = json_decode($json, true);
        if (! is_array($data) || ! is_array($data['webhooks'] ?? null)) {
            self::warn('o arquivo de definições não é um JSON válido', $path);

            return [];
        }

        $definitions = [];
        foreach ($data['webhooks'] as $raw) {
            $definition = WebhookDefinition::fromArray($raw);
            if ($definition === null) {
                self::warn('definição ignorada (slug, verificação ou classe inválidos)', $path);

                continue;
            }
            // Slug repetido: vale a primeira; a plataforma garante unicidade por projeto.
            $definitions[$definition->slug] ??= $definition;
        }

        return $definitions;
    }

    private static function warn(string $message, string $path): void
    {
        try {
            Log::warning('[webhook] ' . $message, ['file' => $path]);
        } catch (\Throwable $e) {
            // log indisponível não derruba a leitura das definições
        }
    }
}
