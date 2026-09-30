<?php
namespace Mad\View;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;

/**
 * MadTranslator — i18n para a camada MAD usando Illuminate\Translation.
 *
 * Estrutura de arquivos (ordem de precedência via FileLoader array of paths):
 *   1. {project_root}/app/lang/{locale}/{group}.php   → overrides do projeto (deltas only)
 *   2. lib/mad/lang/{locale}/{group}.php              → core vendored (read-only)
 *
 * Illuminate\Translation\FileLoader::loadPaths() faz `array_replace_recursive`
 * dos paths em ordem — paths posteriores sobrescrevem anteriores. Aqui o
 * override é o ÚLTIMO da lista, então deltas em `app/lang/` ganham sobre o
 * core, mas keys só existentes no core continuam visíveis (merge nested).
 *
 * Uso:
 *   __('mad.save')                              → "Salvar" (pt-BR) / "Save" (en)
 *   __('doc.recipe')                            → "Receita" / "Recipe"
 *   __('mad.saved', ['name' => 'Receita'])      → "Receita salvo com sucesso!"
 *   trans('mad.cancel')                         → alias de __()
 */
class MadTranslator
{
    private static ?Translator $instance = null;

    public static function getInstance(): Translator
    {
        if (self::$instance === null) {
            $core     = dirname(__DIR__) . '/lang';
            $override = self::resolveOverrideLangPath();

            // Override é o último — array_replace_recursive faz override ganhar
            // sobre core, mas core preenche keys ausentes no override.
            $paths = [$core];
            if ($override !== null) {
                $paths[] = $override;
            }
            $loader = new FileLoader(new Filesystem(), $paths);

            // Locale ativo (nativo Laravel)
            $lang = app()->getLocale() ?: 'pt';

            $locale = match ($lang) {
                'en'    => 'en',
                'es'    => 'es',
                'pt-PT' => 'pt-PT',
                default => 'pt-BR',
            };

            self::$instance = new Translator($loader, $locale);
            self::$instance->setFallback('en');
        }

        return self::$instance;
    }

    /**
     * Resolve `app/lang` no diretório do projeto cliente.
     * Retorna null quando não dá para localizar — FileLoader continua só
     * com o core e o sistema funciona normalmente.
     */
    private static function resolveOverrideLangPath(): ?string
    {
        // Constante explícita ganha (caso o projeto a defina no bootstrap)
        if (defined('APP_PATH')) {
            $p = rtrim((string) constant('APP_PATH'), '/\\') . '/app/lang';
            return is_dir($p) ? $p : $p; // retorna mesmo se não existe — FileLoader skipa graciosamente
        }
        // Fallback: lib/mad/view → 3 níveis acima = project root.
        // Defended via PATH constant — symlinks de lib/mad fariam __DIR__
        // resolver pro projeto fonte em vez do projeto consumidor.
        $root = defined('PATH') ? PATH : dirname(__DIR__, 3);
        $p = $root . '/app/lang';
        return $p;
    }

    /**
     * Traduz uma chave: __('grupo.chave', ['param' => 'valor'])
     */
    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        return static::getInstance()->get($key, $replace, $locale);
    }

    /**
     * Muda o locale em runtime.
     */
    public static function setLocale(string $locale): void
    {
        static::getInstance()->setLocale($locale);
    }

    /**
     * Reseta a instância (útil ao mudar idioma em runtime).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}