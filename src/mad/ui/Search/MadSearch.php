<?php

declare(strict_types=1);

namespace Mad\Ui\Search;

use Mad\Component\MadComponent;
use Mad\Routing\MadRoutes;
use Mad\Security\PermissionGate;

/**
 * Registro dos provedores de REGISTROS da busca ⌘K (RecordSearchProvider).
 * Mesmo jeito de registrar do provedor do menu ({@see \Mad\Ui\Menu\MadMenu}):
 *
 *   // 1) config/mad.php → 'search' => ['providers' => [App\Services\AtividadeBusca::class]]
 *   //    (ou MAD_SEARCH_PROVIDERS=App\Services\AtividadeBusca,App\Services\RiscoBusca no .env)
 *   // 2) em qualquer código que rode antes da busca (listener, service provider):
 *   MadSearch::provider(App\Services\AtividadeBusca::class);
 *   // 3) só com uma função:
 *   MadSearch::using('decisao', 'Decisões', fn (string $termo, int $limite) => [...], icon: 'lucide:gavel');
 *
 * Cada provedor vira um grupo na busca, na ordem de registro, com no máximo
 * `mad.search.per_group` itens (padrão 5). Erro dentro de um provedor não
 * derruba os outros: o grupo dele mostra um aviso e o erro vai para o log.
 * Sem provedor nenhum a busca não muda.
 */
final class MadSearch
{
    /** @var array<string, RecordSearchProvider|string> registrados por código (sobrevivem entre requisições no Octane) */
    private static array $registered = [];

    /** Registra um provedor (classe ou instância). Registrar de novo a mesma classe não duplica. */
    public static function provider(RecordSearchProvider|string $provider): void
    {
        if (is_string($provider) && !is_subclass_of($provider, RecordSearchProvider::class)) {
            throw new \InvalidArgumentException("{$provider} precisa implementar " . RecordSearchProvider::class . '.');
        }

        $id = is_string($provider) ? ltrim($provider, '\\') : 'obj#' . spl_object_id($provider);
        self::$registered[$id] = $provider;
    }

    /**
     * Provedor a partir de uma função `fn (string $termo, int $limite): iterable`.
     * Registrar de novo com a mesma `$key` troca o anterior.
     */
    public static function using(string $key, string $label, callable $search, string $icon = '', ?string $program = null): void
    {
        self::$registered['fn#' . $key] = new class ($key, $label, $search, $icon, $program) implements RecordSearchProvider {
            /** @var callable */
            private $fn;

            public function __construct(
                private readonly string $k,
                private readonly string $l,
                callable $fn,
                private readonly string $i,
                private readonly ?string $p,
            ) {
                $this->fn = $fn;
            }

            public function key(): string { return $this->k; }
            public function label(): string { return $this->l; }
            public function icon(): string { return $this->i !== '' ? $this->i : 'lucide:file-text'; }
            public function program(): ?string { return $this->p; }

            public function search(string $term, int $limit): iterable
            {
                return ($this->fn)($term, $limit);
            }
        };
    }

    /** Há algum provedor (registrado ou na config)? */
    public static function active(): bool
    {
        return self::configured() !== [] || self::$registered !== [];
    }

    /** Menor termo que dispara a busca de registros (`mad.search.min_chars`, padrão 2). */
    public static function minChars(): int
    {
        return max(1, (int) self::config('mad.search.min_chars', 2));
    }

    /** Itens por grupo (`mad.search.per_group`, padrão 5, de 1 a 50). */
    public static function perGroup(): int
    {
        return min(50, max(1, (int) self::config('mad.search.per_group', 5)));
    }

    /**
     * Roda os provedores que o usuário pode ver e devolve os grupos com itens
     * (grupo sem resultado fica fora; grupo com erro vem com `error: true`).
     *
     * @return list<array{key:string,label:string,icon:string,items:list<array<string,mixed>>,more:bool,error:bool,ms:float}>
     */
    public static function search(string $term): array
    {
        $term = mb_substr(trim($term), 0, 100);
        if (mb_strlen($term) < self::minChars()) {
            return [];
        }

        $limit = self::perGroup();
        $groups = [];
        $seen = [];

        foreach (self::instances() as $p) {
            $t0 = hrtime(true);
            try {
                $key = $p->key();
                $label = $p->label();
                $icon = $p->icon();
                $program = $p->program();
            } catch (\Throwable $e) {
                self::report($p, 'key/label', $e);
                continue;
            }

            $key = $key !== '' ? $key : 'grupo';
            $base = $key;
            for ($n = 2; isset($seen[$key]); $n++) {
                $key = $base . '_' . $n;
            }
            $seen[$key] = true;

            if ($program !== null && $program !== '' && !PermissionGate::canAccess($program)) {
                continue;
            }

            $items = [];
            $more = false;
            $error = false;
            try {
                foreach ($p->search($term, $limit + 1) as $row) {
                    if (count($items) >= $limit) {
                        $more = true;
                        break;
                    }
                    try {
                        if (!$row instanceof SearchResult && !is_array($row)) {
                            throw new \InvalidArgumentException('item da busca precisa ser SearchResult ou array, veio ' . get_debug_type($row));
                        }
                        $item = self::item(SearchResult::from($row), $icon);
                    } catch (\InvalidArgumentException $e) {
                        self::report($p, 'search', $e);
                        continue;
                    }
                    if ($item !== null) {
                        $items[] = $item;
                    }
                }
            } catch (\Throwable $e) {
                self::report($p, 'search', $e);
                $items = [];
                $more = false;
                $error = true;
            }

            if ($items === [] && !$error) {
                continue;
            }

            $groups[] = [
                'key'   => $key,
                'label' => $label,
                'icon'  => $icon,
                'items' => $items,
                'more'  => $more,
                'error' => $error,
                'ms'    => round((hrtime(true) - $t0) / 1e6, 1),
            ];
        }

        return $groups;
    }

    /** Limpa os provedores registrados (testes). */
    public static function flush(): void
    {
        self::$registered = [];
    }

    // ── internos ─────────────────────────────────────────────────────────

    /**
     * Item pronto para a busca, ou null quando o usuário não pode abrir a
     * tela de destino (o que a rota não abre, a busca não mostra).
     *
     * @return array<string, mixed>|null
     */
    private static function item(SearchResult $r, string $groupIcon): ?array
    {
        $screen = $r->screen;
        $method = $r->targetMethod();
        $params = $r->targetParams();
        $layer = 'page';

        if ($screen !== '') {
            if (!PermissionGate::canAccess($screen)) {
                return null;
            }
            if (class_exists($screen) && is_subclass_of($screen, MadComponent::class)) {
                $w = $screen::getWrapper();
                $layer = $w === MadComponent::DRAWER || $w === MadComponent::MODAL ? $w : 'page';
            }
        }

        $url = $r->url !== '' ? $r->url : ($screen !== '' ? MadRoutes::urlFor($screen, $method, $params) : '');
        if ($url === '') {
            return null;
        }

        return [
            'label'    => $r->title,
            'subtitle' => $r->subtitle,
            'icon'     => $r->icon !== '' ? $r->icon : $groupIcon,
            'url'      => $url,
            'cls'      => $r->url !== '' ? '' : $screen,
            'method'   => $method,
            'params'   => $params,
            'layer'    => $r->url !== '' ? 'page' : $layer,
        ];
    }

    /** @return list<string> */
    private static function configured(): array
    {
        $config = self::config('mad.search.providers', null);
        // App publicado antes desta versão: o config/mad.php dele não tem a
        // chave, então a variável de ambiente é lida direto.
        if ($config === null) {
            $config = function_exists('env') ? env('MAD_SEARCH_PROVIDERS', []) : (getenv('MAD_SEARCH_PROVIDERS') ?: []);
        }
        if (is_string($config)) {
            $config = explode(',', $config);
        }

        return array_values(array_filter(array_map(static fn ($c) => is_string($c) ? ltrim(trim($c), '\\') : '', (array) $config)));
    }

    /** @return list<RecordSearchProvider> */
    private static function instances(): array
    {
        $list = [];
        foreach (self::configured() as $cls) {
            $list[$cls] = $cls;
        }
        $list = array_merge($list, self::$registered);

        $out = [];
        foreach ($list as $p) {
            if ($p instanceof RecordSearchProvider) {
                $out[] = $p;
                continue;
            }
            try {
                $obj = function_exists('app') ? app($p) : new $p();
            } catch (\Throwable $e) {
                error_log('[MadSearch] provedor ' . $p . ' não carregou: ' . $e->getMessage());
                continue;
            }
            if ($obj instanceof RecordSearchProvider) {
                $out[] = $obj;
            } else {
                error_log('[MadSearch] ' . $p . ' não implementa ' . RecordSearchProvider::class);
            }
        }

        return $out;
    }

    private static function config(string $key, mixed $default): mixed
    {
        if (!function_exists('config')) {
            return $default;
        }
        try {
            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    private static function report(RecordSearchProvider $p, string $method, \Throwable $e): void
    {
        $msg = class_exists(\Mad\Ui\MadErrorRedactor::class) ? \Mad\Ui\MadErrorRedactor::message($e) : $e->getMessage();
        error_log('[MadSearch] ' . get_class($p) . "::{$method}() falhou: " . $msg);
        if (function_exists('report')) {
            try {
                report($e);
            } catch (\Throwable) {
            }
        }
    }
}
