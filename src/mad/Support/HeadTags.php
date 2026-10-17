<?php

namespace Mad\Support;

/**
 * HeadTags — o que o "Header Tags" do projeto já declara no `<head>`.
 *
 * Os cascos do app escrevem o próprio manifest (`manifest.json`, gerado com o
 * nome do projeto) e o favicon (`app/images/favicon.png`) ANTES do trecho do
 * dono do app. O navegador usa o PRIMEIRO `rel="manifest"` da página: um app
 * que declarava o seu no Header Tags tinha o manifest ignorado, e o ícone
 * dele concorria com o do framework. Agora o casco consulta esta classe e só
 * emite o seu quando o projeto não declarou um.
 *
 * Lê apenas `<link …>` com `rel` (tokens separados por espaço, sem
 * diferenciar maiúsculas); comentários HTML são ignorados.
 */
class HeadTags
{
    /** Tokens de `rel` que fazem o navegador escolher o ícone do app. */
    private const ICON_RELS = ['icon', 'apple-touch-icon', 'apple-touch-icon-precomposed'];

    /** O trecho declara um manifest (`<link rel="manifest">`)? */
    public static function declaresManifest(?string $html): bool
    {
        return self::hasRel($html, ['manifest']);
    }

    /** O trecho declara um ícone (`icon`, `shortcut icon`, `apple-touch-icon`)? */
    public static function declaresIcon(?string $html): bool
    {
        return self::hasRel($html, self::ICON_RELS);
    }

    /** @param list<string> $wanted */
    private static function hasRel(?string $html, array $wanted): bool
    {
        $html = (string) $html;
        if ($html === '' || stripos($html, '<link') === false) {
            return false;
        }
        $html = (string) preg_replace('/<!--.*?-->/s', '', $html);

        // Quote-aware: valor de atributo pode ter `>` (ex.: `media="(min-width>…)"`).
        if (! preg_match_all('/<link\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i', $html, $tags)) {
            return false;
        }
        foreach ($tags[1] as $attrs) {
            if (! preg_match('/(?:^|\s)rel\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $attrs, $m)) {
                continue;
            }
            $rel = strtolower(trim(($m[1] ?? '').($m[2] ?? '').($m[3] ?? '')));
            $tokens = preg_split('/\s+/', $rel, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (array_intersect($tokens, $wanted) !== []) {
                return true;
            }
        }

        return false;
    }
}
