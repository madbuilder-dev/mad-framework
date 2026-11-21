<?php

namespace Mad\Ai\Gateways;

/**
 * Tira chave de API de um texto que vai para a tela ou para o log (motivo de
 * erro devolvido por um provedor de IA). Os provedores não costumam repetir a
 * chave na mensagem, mas a regra é não depender disso: some qualquer chave
 * configurada em config('ai.providers.*.key') e os formatos conhecidos
 * (Google `AIza…`, OpenAI/Anthropic/OpenRouter `sk-…`, xAI `xai-…`).
 * O texto também é cortado em 400 caracteres.
 */
final class SecretScrubber
{
    private const PATTERNS = [
        '/AIza[0-9A-Za-z_\-]{20,}/',
        '/\bsk-[A-Za-z0-9_\-*]{8,}/',
        '/\bxai-[A-Za-z0-9_\-*]{8,}/',
        '/\b(Bearer)\s+[A-Za-z0-9._\-]{16,}/i',
    ];

    public static function clean(string $text): string
    {
        if ($text === '') {
            return '';
        }

        foreach ((array) config('ai.providers', []) as $provider) {
            $key = is_array($provider) ? trim((string) ($provider['key'] ?? '')) : '';
            if (strlen($key) >= 8) {
                $text = str_replace($key, '[chave omitida]', $text);
            }
        }
        foreach (self::PATTERNS as $re) {
            $text = (string) preg_replace_callback($re, static fn (array $m): string => isset($m[1]) ? $m[1] . ' [chave omitida]' : '[chave omitida]', $text);
        }

        return mb_strlen($text) > 400 ? mb_substr($text, 0, 400) . '…' : $text;
    }
}
