<?php

namespace Mad\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * Cache de rotas do Laravel (`bootstrap/cache/routes-v7.php`) que ficou para trás.
 *
 * `php artisan route:cache` (ou `optimize`, que hospedagens e instaladores
 * rodam) congela as rotas daquele momento. Depois disso, quem atualiza as telas
 * do app — `routes/web.php`, os apelidos de endereço (o `routes.php` de cada idioma em `lang/`) ou o
 * próprio framework — não muda nada para o navegador: a rota nova não existe e
 * o menu aponta para um "404 Not Found" sem erro em lugar nenhum (fórum #270, o
 * "Perfil" do menu do usuário numa hospedagem cPanel).
 *
 * O cache é só uma otimização. Se algum arquivo que ele resume é mais novo que
 * ele, o arquivo é apagado (o mesmo que `route:clear`) e o Laravel monta as
 * rotas do jeito normal. Quem quiser o cache de volta roda `route:cache` de novo.
 *
 * FAIL-SAFE: nunca lança. Sem permissão para apagar, só registra o aviso.
 */
final class MadRouteCacheGuard
{
    /**
     * @return bool true se o cache estava velho (apagado ou não)
     */
    public static function forgetStale(Application $app): bool
    {
        try {
            $cache = $app->getCachedRoutesPath();
            if (!is_file($cache)) {
                return false;
            }

            $builtAt = (int) @filemtime($cache);
            $newer   = null;
            foreach (self::sources($app) as $file) {
                if ((int) @filemtime($file) > $builtAt) {
                    $newer = $file;
                    break;
                }
            }
            if ($newer === null) {
                return false;
            }

            $removed = @unlink($cache);
            try {
                logger()->warning(
                    $removed
                        ? '[mad] cache de rotas desatualizado removido: ' . basename($newer) . ' é mais novo que o cache.'
                        : '[mad] cache de rotas desatualizado e sem permissão para remover: apague bootstrap/cache/' . basename($cache) . ' (ou rode "php artisan route:clear").',
                    ['newer' => str_replace($app->basePath() . DIRECTORY_SEPARATOR, '', $newer)]
                );
            } catch (\Throwable) {
                // sem log, sem problema
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Os arquivos que definem as rotas: o que o cache resume.
     *
     * @return list<string>
     */
    private static function sources(Application $app): array
    {
        $files = glob($app->basePath('routes') . '/*.php') ?: [];
        foreach ([$app->langPath(), $app->basePath('app/lang')] as $lang) {
            $files = array_merge($files, glob($lang . '/*/routes.php') ?: []);
        }

        // O framework instalado: rota nova ou slug novo chega com uma versão nova.
        $version = dirname(__DIR__, 3) . '/VERSION';
        if (is_file($version)) {
            $files[] = $version;
        }

        return $files;
    }
}
