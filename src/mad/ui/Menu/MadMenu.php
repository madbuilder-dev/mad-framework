<?php

declare(strict_types=1);

namespace Mad\Ui\Menu;

/**
 * Registro dos provedores de contador/visibilidade do menu (MenuItemProvider).
 *
 * O app registra sem editar o framework, de um destes jeitos:
 *
 *   // 1) config/mad.php → 'menu' => ['providers' => [App\Services\MeuMenu::class]]
 *   //    (ou MAD_MENU_PROVIDERS=App\Services\MeuMenu no .env)
 *   // 2) em qualquer código que rode antes do casco (listener, service provider):
 *   MadMenu::provider(App\Services\MeuMenu::class);
 *   // 3) só com funções:
 *   MadMenu::using(
 *       badge:   fn (MenuItem $i) => $i->is('AprovacaoList') ? Aprovacao::minhas()->count() : null,
 *       visible: fn (MenuItem $i) => ! $i->inModule('Administração') || Perfil::admin(),
 *   );
 *
 * Com vários provedores: o item aparece só se TODOS deixarem; o contador é o
 * do primeiro que devolver um valor. Sem provedor nenhum o menu não muda (nem
 * um atributo a mais no HTML).
 *
 * Cada item é perguntado uma vez por requisição: o resultado fica guardado nos
 * atributos da requisição (seguro com Octane, que reaproveita o processo).
 * Erro dentro do provedor não derruba o menu: o item fica visível e sem
 * contador, e o erro vai para o log.
 */
final class MadMenu
{
    private const REQUEST_KEY = 'mad.menu.state';

    /** @var array<string, MenuItemProvider|string> registrados por código (sobrevivem entre requisições no Octane) */
    private static array $registered = [];

    /** Estado fora de uma requisição HTTP (CLI/testes sem request). */
    private static ?array $fallbackState = null;

    /** Registra um provedor (classe ou instância). Registrar de novo a mesma classe não duplica. */
    public static function provider(MenuItemProvider|string $provider): void
    {
        if (is_string($provider) && !is_subclass_of($provider, MenuItemProvider::class)) {
            throw new \InvalidArgumentException("{$provider} precisa implementar " . MenuItemProvider::class . '.');
        }

        $id = is_string($provider) ? ltrim($provider, '\\') : 'obj#' . spl_object_id($provider);
        self::$registered[$id] = $provider;
        self::resetRequestState();
    }

    /** Provedor a partir de funções: `badge(MenuItem): int|string|null`, `visible(MenuItem): bool`. */
    public static function using(?callable $badge = null, ?callable $visible = null): void
    {
        self::provider(new class ($badge, $visible) implements MenuItemProvider {
            /** @var callable|null */
            private $badgeFn;
            /** @var callable|null */
            private $visibleFn;

            public function __construct(?callable $badge, ?callable $visible)
            {
                $this->badgeFn = $badge;
                $this->visibleFn = $visible;
            }

            public function badge(MenuItem $item): int|string|null
            {
                return $this->badgeFn ? ($this->badgeFn)($item) : null;
            }

            public function visible(MenuItem $item): bool
            {
                return $this->visibleFn ? (bool) ($this->visibleFn)($item) : true;
            }
        });
    }

    /** Há algum provedor (registrado ou na config)? */
    public static function active(): bool
    {
        return self::instances() !== [];
    }

    /** O item fica no menu? (true sem provedor.) */
    public static function visible(MenuItem $item): bool
    {
        $providers = self::instances();
        if ($providers === []) {
            return true;
        }

        return self::remember('visible', $item, static function () use ($providers, $item): bool {
            foreach ($providers as $p) {
                try {
                    if (!$p->visible($item)) {
                        return false;
                    }
                } catch (\Throwable $e) {
                    self::report($p, 'visible', $item, $e);
                }
            }

            return true;
        });
    }

    /** Valor do contador já normalizado: int > 0, texto curto, ou null. */
    public static function badgeValue(MenuItem $item): int|string|null
    {
        $providers = self::instances();
        if ($providers === []) {
            return null;
        }

        return self::remember('badge', $item, static function () use ($providers, $item): int|string|null {
            foreach ($providers as $p) {
                try {
                    $v = self::normalize($p->badge($item));
                } catch (\Throwable $e) {
                    self::report($p, 'badge', $item, $e);
                    continue;
                }
                if ($v !== null) {
                    return $v;
                }
            }

            return null;
        });
    }

    /** Texto exibido no contador ("99+" acima de 99), ou null. */
    public static function badgeText(MenuItem $item): ?string
    {
        return self::format(self::badgeValue($item));
    }

    public static function format(int|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_int($value) ? ($value > 99 ? '99+' : (string) $value) : $value;
    }

    /**
     * HTML do contador para dentro de um link do menu. Vazio quando não há
     * valor (o JS cria o nó se o número aparecer depois).
     */
    public static function badgeHtml(string $key, int|string|null $value, string $extraClass = ''): string
    {
        $text = self::format($value);
        if ($text === null) {
            return '';
        }

        $cls = 'mad-menu-badge' . ($extraClass !== '' ? ' ' . $extraClass : '');

        return '<span class="' . $cls . '" data-mad-menu-badge="' . self::e($key) . '"'
            . ' data-value="' . self::e((string) $value) . '">' . self::e($text) . '</span>';
    }

    /** Limpa provedores e cache (testes). */
    public static function flush(): void
    {
        self::$registered = [];
        self::resetRequestState();
    }

    // ── internos ─────────────────────────────────────────────────────────

    private static function normalize(mixed $v): int|string|null
    {
        if ($v === null || $v === false || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\s*-?\d+\s*$/', $v))) {
            $n = (int) $v;

            return $n > 0 ? $n : null;
        }

        $s = trim((string) $v);

        return $s === '' ? null : mb_substr($s, 0, 12);
    }

    /** @return MenuItemProvider[] */
    private static function instances(): array
    {
        $state = &self::state();
        if (!isset($state['providers'])) {
            $list = [];
            $config = function_exists('config') ? config('mad.menu.providers', []) : [];
            if (is_string($config)) {
                $config = array_filter(array_map('trim', explode(',', $config)));
            }
            foreach ((array) $config as $cls) {
                if (is_string($cls) && $cls !== '') {
                    $list[ltrim($cls, '\\')] = $cls;
                }
            }
            $list = array_merge($list, self::$registered);

            $out = [];
            foreach ($list as $p) {
                if ($p instanceof MenuItemProvider) {
                    $out[] = $p;
                    continue;
                }
                try {
                    $obj = function_exists('app') ? app($p) : new $p();
                } catch (\Throwable $e) {
                    error_log('[MadMenu] provedor ' . $p . ' não carregou: ' . $e->getMessage());
                    continue;
                }
                if ($obj instanceof MenuItemProvider) {
                    $out[] = $obj;
                } else {
                    error_log('[MadMenu] ' . $p . ' não implementa ' . MenuItemProvider::class);
                }
            }
            $state['providers'] = $out;
        }

        return $state['providers'];
    }

    private static function remember(string $kind, MenuItem $item, callable $compute): mixed
    {
        $state = &self::state();
        // Contador é da TELA: a mesma tela no menu lateral, nos atalhos do topo
        // e na busca é perguntada uma vez só. Visibilidade é da POSIÇÃO (a
        // regra pode depender do módulo, `inModule()`), então leva o trilho.
        $k = $kind === 'badge' && $item->class !== ''
            ? "badge\x1d" . $item->class . "\x1f" . $item->method
            : $kind . "\x1d" . $item->cacheKey();
        if (!array_key_exists($k, $state['cache'] ?? [])) {
            $state['cache'][$k] = $compute();
        }

        return $state['cache'][$k];
    }

    /** Estado da requisição corrente (provedores instanciados + respostas). */
    private static function &state(): array
    {
        $req = self::request();
        if ($req === null) {
            self::$fallbackState ??= [];

            return self::$fallbackState;
        }

        $holder = $req->attributes->get(self::REQUEST_KEY);
        if (!$holder instanceof \stdClass) {
            $holder = new \stdClass();
            $holder->state = [];
            $req->attributes->set(self::REQUEST_KEY, $holder);
        }

        return $holder->state;
    }

    private static function resetRequestState(): void
    {
        self::$fallbackState = null;
        $req = self::request();
        if ($req !== null) {
            $req->attributes->remove(self::REQUEST_KEY);
        }
    }

    private static function request(): ?\Illuminate\Http\Request
    {
        if (!function_exists('app')) {
            return null;
        }
        try {
            $app = app();
            if (!$app->bound('request')) {
                return null;
            }
            $req = $app->make('request');

            return $req instanceof \Illuminate\Http\Request ? $req : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function report(MenuItemProvider $p, string $method, MenuItem $item, \Throwable $e): void
    {
        error_log('[MadMenu] ' . get_class($p) . "::{$method}({$item->key()}) falhou: " . $e->getMessage());
        if (function_exists('report')) {
            try {
                report($e);
            } catch (\Throwable) {
            }
        }
    }

    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
