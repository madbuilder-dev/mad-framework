<?php

namespace Mad\I18n;

/**
 * Texto escrito na tela pelo MadBuilder (rótulo de selo, título…) no idioma de
 * quem usa o app.
 *
 * O MadBuilder exporta `lang/{idioma}.json` com o texto do idioma principal →
 * a tradução de cada chave cadastrada em Traduções; o `__()` do Laravel lê
 * esse catálogo ANTES dos arquivos de grupo. Então basta passar o texto por
 * `__()`: com tradução sai traduzido, sem tradução sai igual. `grupo.chave`
 * também funciona.
 *
 * Resultado que não é texto volta o original: um rótulo que coincide com um
 * arquivo de `lang/` ("Admin" → `admin.php` num disco que ignora maiúsculas)
 * faria o `__()` devolver o ARRAY do grupo inteiro.
 */
final class AppText
{
    public static function translate(string $text): string
    {
        if (trim($text) === '' || !function_exists('__')) {
            return $text;
        }

        try {
            $translated = __($text);
        } catch (\Throwable) {
            return $text;
        }

        return is_string($translated) && $translated !== '' ? $translated : $text;
    }
}
