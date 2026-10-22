<?php

namespace Mad\Support;

/**
 * Chaves do Modo Vibe: só existem no `.env` do Teste Online, escritas pela
 * plataforma. Fora dele (download, SSH deploy, MadCloud) ficam ausentes e o
 * app se comporta como sempre.
 *
 * Lê `config('mad.vibe.*')` primeiro (sobrevive a `config:cache`); sem a chave
 * no config do app — esqueleto antigo —, cai na variável de ambiente.
 */
final class VibePreview
{
    /** MAD_SOURCE_MARKERS=1: comentários de origem em volta de cada <mad-*>. */
    public static function sourceMarkers(): bool
    {
        return self::truthy(self::read('source_markers', 'MAD_SOURCE_MARKERS'));
    }

    /**
     * MAD_AGENT_BRIDGE=1 + MAD_AGENT_BRIDGE_ORIGINS: as tags do Agent Bridge
     * em toda resposta HTML. Sem origem autorizada o bridge seria inerte —
     * conta como desligado.
     */
    public static function agentBridge(): bool
    {
        return self::truthy(self::read('agent_bridge', 'MAD_AGENT_BRIDGE'))
            && self::agentBridgeOrigins() !== [];
    }

    /** @return list<string> origens do Studio autorizadas a comandar o bridge */
    public static function agentBridgeOrigins(): array
    {
        $raw = self::read('agent_bridge_origins', 'MAD_AGENT_BRIDGE_ORIGINS');
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);
        return array_values(array_filter(array_map(static fn ($o) => trim((string) $o), $list), 'strlen'));
    }

    /**
     * MAD_PREVIEW_LOGIN_SECRET: segredo (por projeto) que assina o token do
     * `/__mad/preview-login`. Vazio = a rota não existe.
     */
    public static function previewLoginSecret(): string
    {
        return trim((string) self::read('preview_login_secret', 'MAD_PREVIEW_LOGIN_SECRET'));
    }

    private static function read(string $key, string $env): mixed
    {
        if (function_exists('config') && function_exists('app') && app()->bound('config')) {
            $v = config('mad.vibe.' . $key);
            if ($v !== null) {
                return $v;
            }
        }
        $v = getenv($env);
        if ($v === false) {
            $v = $_ENV[$env] ?? $_SERVER[$env] ?? null;
        }
        return $v;
    }

    private static function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);
    }
}
