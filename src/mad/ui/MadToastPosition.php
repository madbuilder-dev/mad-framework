<?php

namespace Mad\Ui;

/**
 * Posição dos avisos (toast) do app inteiro (fórum #400).
 *
 * Ordem: a preferência "Posição dos avisos" (Sistema › Preferências, linha
 * `toast_position` de `mad_sys_preference`) › `mad.toast_position`
 * (`MAD_TOAST_POSITION`, que o Studio grava pelas Propriedades do projeto) ›
 * nada. Sem nenhuma das duas, cada aviso fica onde sempre ficou: o do servidor
 * no alto à direita, o do navegador embaixo à direita.
 *
 * Posição passada por código numa chamada (`->toast($msg, 'info', '', 'top-center')`,
 * `madToast({position})`) continua valendo só para ela.
 */
final class MadToastPosition
{
    public const PREFERENCE = 'toast_position';

    public const CONFIG = 'mad.toast_position';

    /** As seis posições que o toaster desenha (mad-ui.css). */
    public const POSITIONS = ['top-left', 'top-center', 'top-right', 'bottom-left', 'bottom-center', 'bottom-right'];

    /** Memo por pedido: [id do pedido, posição|''] — nada de consulta por aviso. */
    private static ?array $memo = null;

    /** Posição escolhida para o app, ou '' quando ninguém escolheu (fica a de cada aviso). */
    public static function configured(): string
    {
        $key = self::requestKey();
        if (self::$memo !== null && self::$memo[0] === $key) {
            return self::$memo[1];
        }
        $position = self::normalize(self::readPreference()) ?? self::normalize(self::readConfig()) ?? '';
        self::$memo = [$key, $position];

        return $position;
    }

    /**
     * Posição de UM aviso: a que a chamada passou; senão a do app; senão a de
     * sempre daquele aviso (`$builtin`). Posição inválida passada por código
     * segue como veio — é a de quem escreveu a tela.
     */
    public static function for(string $explicit, string $builtin): string
    {
        if ($explicit !== '') {
            return $explicit;
        }

        return self::configured() ?: $builtin;
    }

    public static function isValid(mixed $raw): bool
    {
        return self::normalize($raw) !== null;
    }

    /** Opções para a tela de Preferências: valor => rótulo no idioma do app. */
    public static function options(): array
    {
        $out = [];
        foreach (self::POSITIONS as $p) {
            $out[$p] = self::label($p);
        }

        return $out;
    }

    public static function label(string $position): string
    {
        $fallback = [
            'top-left' => 'No alto, à esquerda', 'top-center' => 'No alto, no centro', 'top-right' => 'No alto, à direita',
            'bottom-left' => 'Embaixo, à esquerda', 'bottom-center' => 'Embaixo, no centro', 'bottom-right' => 'Embaixo, à direita',
        ][$position] ?? $position;
        try {
            $key = 'admin.pref_toast_pos_' . str_replace('-', '_', $position);
            $text = function_exists('__') ? __($key) : $key;

            return is_string($text) && $text !== $key ? $text : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /** Esquece o valor lido (testes; depois de gravar a preferência). */
    public static function reset(): void
    {
        self::$memo = null;
    }

    private static function normalize(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $p = strtolower(trim($raw));

        return in_array($p, self::POSITIONS, true) ? $p : null;
    }

    private static function readPreference(): mixed
    {
        $model = '\\App\\Models\\Sys\\Preference';
        if (! class_exists($model)) {
            return null;
        }
        try {
            return $model::getPreference(self::PREFERENCE);
        } catch (\Throwable) {
            // Sem banco (instalação, console sem conexão): fica a do .env.
            return null;
        }
    }

    private static function readConfig(): mixed
    {
        try {
            return function_exists('config') && function_exists('app') && app()->bound('config')
                ? config(self::CONFIG)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Identidade do pedido atual: o memo não atravessa pedidos (Octane, filas). */
    private static function requestKey(): int
    {
        try {
            if (function_exists('app') && app()->bound('request')) {
                return spl_object_id(app('request'));
            }
        } catch (\Throwable) {
        }

        return 0;
    }
}
