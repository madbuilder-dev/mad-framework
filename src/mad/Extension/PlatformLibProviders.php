<?php

namespace Mad\Extension;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Bibliotecas da plataforma com provider próprio (fórum #284, ex.: Google
 * Agenda). A plataforma entrega os arquivos da lib ao app e, junto, o
 * `app/config/platform-libs.json` com os providers das libs CONCEDIDAS:
 *
 *     {"providers": ["App\\Services\\GoogleCalendar\\GoogleCalendarServiceProvider"]}
 *
 * Revogada a lib, o arquivo volta com a lista sem ela: num servidor que só
 * sobrepõe arquivos (Deploy SSH) o código velho pode ficar no disco, mas não
 * sobe mais. Sem o arquivo nada acontece. Só classe `App\…` que existe e é
 * ServiceProvider; erro numa lib vai para o log e não derruba o app.
 */
final class PlatformLibProviders
{
    public const MANIFEST = 'app/config/platform-libs.json';

    /** @return list<class-string<ServiceProvider>> */
    public static function providers(string $basePath): array
    {
        $file = rtrim($basePath, '/').'/'.self::MANIFEST;
        if (! is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        $out = [];
        foreach ((array) (is_array($data) ? ($data['providers'] ?? []) : []) as $class) {
            if (! is_string($class) || preg_match('/^App\\\\[A-Za-z0-9_\\\\]+$/', $class) !== 1) {
                continue;
            }
            try {
                if (class_exists($class) && is_subclass_of($class, ServiceProvider::class)) {
                    $out[] = $class;
                }
            } catch (Throwable $e) {
                Log::warning("Biblioteca da plataforma: provider {$class} não carregou: ".$e->getMessage());
            }
        }

        return array_values(array_unique($out));
    }

    public static function register(Application $app): void
    {
        foreach (self::providers($app->basePath()) as $class) {
            try {
                $app->register($class);
            } catch (Throwable $e) {
                Log::warning("Biblioteca da plataforma: provider {$class} não registrou: ".$e->getMessage());
            }
        }
    }
}
