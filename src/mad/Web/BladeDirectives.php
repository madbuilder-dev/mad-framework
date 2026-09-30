<?php

namespace Mad\Web;

use Mad\View\MadBlade;

/**
 * Mad\Web\BladeDirectives
 *
 * Registra no BladeOne (via MadBlade::directive()) as diretivas especificas
 * do contexto web: @csrf, @method, @error/@enderror, @auth/@endauth,
 * @guest/@endguest.
 *
 * Deve ser chamado UMA VEZ no boot do MadPublicServer, antes de qualquer
 * render de template publico.
 */
class BladeDirectives
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // @csrf → gera <input type="hidden" name="_token" value="...">
        MadBlade::directive('csrf', function (string $expr): string {
            return '<?php echo csrf_field(); ?>';
        });

        // @method('PUT') → <input type="hidden" name="_method" value="PUT">
        MadBlade::directive('method', function (string $expr): string {
            return '<?php echo method_field(' . $expr . '); ?>';
        });

        // @error('campo') ... @enderror — usa $errors injetado pelo ViewResponse
        MadBlade::directive('error', function (string $expr): string {
            return '<?php if (isset($errors) && $errors instanceof \Mad\Web\ErrorBag && $errors->has(' . $expr . ')): $message = $errors->first(' . $expr . '); ?>';
        });

        MadBlade::directive('enderror', function (string $expr): string {
            return '<?php unset($message); endif; ?>';
        });

        // @auth ... @endauth — renderiza conteudo SOMENTE se usuario publico esta logado
        MadBlade::directive('auth', function (string $expr): string {
            return '<?php if (\Mad\Web\Session::has("user_public_id")): ?>';
        });

        MadBlade::directive('endauth', function (string $expr): string {
            return '<?php endif; ?>';
        });

        // @guest ... @endguest — renderiza conteudo SOMENTE se nao ha usuario publico logado
        MadBlade::directive('guest', function (string $expr): string {
            return '<?php if (!\Mad\Web\Session::has("user_public_id")): ?>';
        });

        MadBlade::directive('endguest', function (string $expr): string {
            return '<?php endif; ?>';
        });
    }

    public static function isRegistered(): bool
    {
        return self::$registered;
    }
}
