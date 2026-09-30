<?php

namespace Mad\Support;

/**
 * Identidade do pacote mad-framework em runtime.
 *
 * A versão vive em packages/mad-framework/VERSION (texto puro, semver) — NUNCA
 * no campo `version` do composer.json: o require dos apps é `mad/framework: @dev`
 * (path repo) e uma version explícita mudaria a stability pra stable, quebrando
 * o `composer install` de todo app já gerado. Ver
 * .claude/rules/framework-versioning.md.
 *
 * O arquivo viaja no export (vendor-mirror copia o pacote inteiro) e no canal
 * de update da Central de Comando (card "Mad Framework") — apps antigos sem
 * VERSION reportam '0.0.0-unknown' até o primeiro sync.
 */
final class MadFramework
{
    private static ?string $version = null;

    /** Versão semver corrente do pacote (lida de VERSION, cache por request). */
    public static function version(): string
    {
        if (self::$version !== null) {
            return self::$version;
        }
        // src/mad/Support → raiz do pacote (onde mora o VERSION).
        $file = dirname(__DIR__, 3) . '/VERSION';
        $raw  = is_file($file) ? trim((string) @file_get_contents($file)) : '';

        return self::$version = (preg_match('/^\d+\.\d+\.\d+/', $raw) === 1 ? $raw : '0.0.0-unknown');
    }

    /**
     * URL de um asset do framework com cache-bust pela VERSÃO do pacote.
     *
     *   {!! \Mad\Support\MadFramework::asset('lib/mad/mad.js') !!}
     *   → lib/mad/mad.js?fwver=5.69.0
     *
     * Antes cada `<script>` carregava um `?appver=` DATADO À MÃO. Quem editava
     * o JS do framework tinha que lembrar de bumpar a data em outro arquivo —
     * e não lembrava: `mad-livewire.js` ficou anos sem parâmetro nenhum e
     * `mad-ui.js?appver=20260718a` seguiu servindo a cópia de julho depois de
     * várias releases. Resultado: o fix ia pro servidor e o navegador do
     * usuário continuava com o arquivo velho, com o bug que "já foi corrigido".
     * Amarrar no VERSION faz o bump obrigatório da release invalidar sozinho
     * todo asset MAD.
     */
    public static function asset(string $path): string
    {
        $sep = str_contains($path, '?') ? '&' : '?';

        return $path . $sep . 'fwver=' . rawurlencode(self::version());
    }
}
