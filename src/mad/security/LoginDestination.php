<?php

namespace Mad\Security;

use Illuminate\Http\Request;
use Mad\Component\MadComponent;
use Mad\Registry\ControlRegistry;
use Mad\Routing\MadRoutes;

/**
 * Para onde voltar depois do login.
 *
 * Quem abre `/app/<rota>?a=1` sem sessão vai ao login; o endereço do login leva
 * a tela pedida em `previous_class` / `previous_method` e os parâmetros da
 * query (o mesmo contrato que o `LoginForm` já lia), e depois de entrar a pessoa
 * cai nela, não no Início. Quem JÁ tem sessão e abre
 * `/app/login?previous_class=X&…` vai direto para X.
 *
 * Só tela do próprio app: classe com nome de classe, carregável e filha de
 * `MadComponent` — nunca URL livre (sem open redirect). A permissão de abrir a
 * tela continua com quem a abre: aqui só se escolhe o destino.
 */
final class LoginDestination
{
    /** Chaves que não viajam como parâmetro da tela. */
    private const RESERVED = ['class', 'method', 'static', 'previous_class', 'previous_method', 'previous_parameters', 'mad_state', '_token'];

    /** Teto do endereço do login: parâmetro demais não vira URL gigante. */
    private const MAX_QUERY = 1500;

    /**
     * Endereço do login que volta para a tela pedida. Pedido que não é
     * navegação (POST, chamada do wire) ou tela que não serve de destino: o
     * login puro, como antes.
     */
    public static function loginUrl(Request $request, string $class, ?string $method = null): string
    {
        $login = MadRoutes::loginUrl();
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return $login;
        }
        // Só o NOME é conferido aqui: este caminho atende o visitante sem login,
        // e carregar a tela (class_exists) faria uma tela com erro de
        // compilação devolver 500 em vez do login. O LoginForm confere a classe
        // de verdade antes de usar o destino.
        $class = ControlRegistry::idFor($class);
        if (!self::isClassName($class)) {
            return $login;
        }

        $query = ['previous_class' => $class];
        $method = is_string($method) ? trim($method) : '';
        if ($method !== '' && $method !== 'show' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) {
            $query['previous_method'] = $method;
        }
        // Parâmetros da rota (o {id} de /pedidos/{id}/editar) e da query.
        $route = $request->route();
        $params = array_merge(
            $route && method_exists($route, 'parameters') ? (array) $route->parameters() : [],
            $request->query()
        );
        foreach ($params as $k => $v) {
            if (!is_string($k) || in_array($k, self::RESERVED, true) || !is_scalar($v)) {
                continue;
            }
            $query[$k] = (string) $v;
        }

        $qs = http_build_query($query);
        if (strlen($qs) > self::MAX_QUERY) {
            $qs = http_build_query(array_intersect_key($query, array_flip(['previous_class', 'previous_method'])));
        }

        return $login . '?' . $qs;
    }

    /**
     * Endereço da tela de destino lido da query do login
     * (`previous_class`/`previous_method` + parâmetros), ou null quando não há
     * destino válido.
     *
     * @param array<string, mixed> $query
     */
    public static function urlFromQuery(array $query): ?string
    {
        $class = is_string($query['previous_class'] ?? null) ? trim($query['previous_class']) : '';
        if (!self::isAllowedClass($class)) {
            return null;
        }
        $method = is_string($query['previous_method'] ?? null) ? trim($query['previous_method']) : '';
        if ($method !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) {
            $method = '';
        }
        $params = [];
        foreach ($query as $k => $v) {
            if (is_string($k) && !in_array($k, self::RESERVED, true) && is_scalar($v)) {
                $params[$k] = (string) $v;
            }
        }

        return MadRoutes::urlFor($class, $method !== '' ? $method : null, $params);
    }

    /** Parece nome de classe (sem URL, sem espaço) e não é o próprio login. */
    public static function isClassName(string $class): bool
    {
        $class = trim($class);

        return $class !== '' && $class !== 'LoginForm' && preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $class) === 1;
    }

    /**
     * Tela do próprio app que pode ser destino: nome de classe (sem URL, sem
     * espaço), carregável, filha de `MadComponent`, e não o próprio login.
     */
    public static function isAllowedClass(string $class): bool
    {
        $class = trim($class);
        if (!self::isClassName($class)) {
            return false;
        }
        try {
            return class_exists($class) && is_subclass_of($class, MadComponent::class)
                && ControlRegistry::idFor($class) !== 'LoginForm';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
