<?php

/**
 * Mad\Web helpers — funcoes globais pra rotas publicas (estilo Laravel web.php).
 *
 * Carregadas via composer autoload "files" (ver composer.json). Todas as funcoes
 * sao idempotentes com function_exists() pra permitir reload sem fatal.
 */

use Mad\Web\AuthHelper;
use Mad\Web\Csrf;
use Mad\Web\RedirectResponse;
use Mad\Web\Session;
use Mad\Web\SessionHelper;
use Mad\Web\ViewResponse;

// ─── View / render ──────────────────────────────────────────────────────────

if (!function_exists('view')) {
    /**
     * Renderiza uma view Blade e retorna um ViewResponse.
     *
     * @param string $template Path com notacao ponto (ex: 'public.home')
     * @param array $data Variaveis a passar pra view
     * @param int $code Status HTTP (default 200)
     */
    function view(string $template, array $data = [], int $code = 200): ViewResponse
    {
        return (new ViewResponse($template, $code))->with($data);
    }
}

// ─── Redirects ──────────────────────────────────────────────────────────────

if (!function_exists('redirect')) {
    /**
     * Retorna um RedirectResponse chainable.
     *
     * Exemplos:
     *   return redirect('/public/home');
     *   return redirect('/public/login')->with('error', 'Senha invalida')->withInput();
     */
    function redirect(?string $url = null, int $code = 302): RedirectResponse
    {
        return new RedirectResponse($url ?? '/', $code);
    }
}

if (!function_exists('back')) {
    /**
     * Redireciona pra URL anterior (HTTP_REFERER) ou pra raiz se nao houver.
     */
    function back(int $code = 302): RedirectResponse
    {
        $url = $_SERVER['HTTP_REFERER'] ?? '/public/';
        return new RedirectResponse($url, $code);
    }
}

// ─── Session ────────────────────────────────────────────────────────────────

if (!function_exists('session')) {
    /**
     * Helper polimorfico pra session:
     *   session()                    → retorna SessionHelper (chainable)
     *   session('chave')              → retorna o valor da chave (ou null)
     *   session('chave', 'default')   → retorna o valor ou default
     *   session(['k' => 'v', ...])    → grava varias chaves de uma vez
     *
     * @param mixed $key
     * @param mixed $default
     * @return mixed
     */
    function session($key = null, $default = null)
    {
        if ($key === null) {
            return new SessionHelper();
        }
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                Session::put($k, $v);
            }
            return null;
        }
        return Session::get($key, $default);
    }
}

if (!function_exists('old')) {
    /**
     * Retorna um valor do "old input" (gravado via redirect()->withInput()).
     * Usado em forms pra preservar valores apos validation falhar.
     *
     * Exemplo em view Blade:
     *   <input name="email" value="{{ old('email') }}">
     */
    function old(string $key, $default = null)
    {
        return Session::getOldInput($key, $default);
    }
}

// ─── CSRF ───────────────────────────────────────────────────────────────────

if (!function_exists('csrf_token')) {
    /**
     * Retorna o token CSRF atual (gera se nao existir).
     * Usado na diretiva @csrf do Blade ou em meta tag.
     */
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Retorna o HTML de um input hidden com o CSRF token.
     * Equivalente ao @csrf do Blade.
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('method_field')) {
    /**
     * Retorna o HTML de um input hidden pra method spoofing (_method=PUT etc).
     * Equivalente ao @method('PUT') do Blade.
     */
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . htmlspecialchars(strtoupper($method), ENT_QUOTES, 'UTF-8') . '">';
    }
}

// ─── Auth ───────────────────────────────────────────────────────────────────

if (!function_exists('auth')) {
    /**
     * Retorna o AuthHelper para checar/logar/logar o usuario publico.
     */
    function auth(): AuthHelper
    {
        return new AuthHelper();
    }
}

// ─── URL helpers ────────────────────────────────────────────────────────────

if (!function_exists('url')) {
    /**
     * Retorna uma URL absoluta a partir de um path relativo.
     *
     * Respeita deploy em subdiretorio via SCRIPT_NAME (ex: /meu-app):
     *   - Dev subdir:  url('/public/login') → https://host/meu-app/public/login
     *   - Prod root:   url('/public/login') → https://host/public/login
     *
     * Exemplo: url('/public/login') → 'https://dominio.com/public/login'
     */
    function url(string $path = '/'): string
    {
        $scheme = (($_SERVER['HTTPS'] ?? 'off') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            ? 'https'
            : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // basePath a partir de SCRIPT_NAME (equivale a MadSiteAssets::basePath)
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = str_replace('\\', '/', dirname($script));
        if ($dir === '/' || $dir === '.' || $dir === '\\') {
            $dir = '';
        }
        $base = rtrim($dir, '/');

        return $scheme . '://' . $host . $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('site_url')) {
    /**
     * Endereço de um caminho do app, respeitando o deploy em subdiretório.
     *
     * Por padrão devolve o endereço *relativo* (só o caminho), ideal para href
     * de links e action de forms dentro do próprio app:
     *
     *   - Dev subdir:  site_url('/public/login') → /meu-app/public/login
     *   - Prod root:   site_url('/public/login') → /public/login
     *
     * Com `absolute: true` devolve o endereço COMPLETO, para tudo que sai do
     * app (link enviado por e-mail ou WhatsApp, QR Code, texto copiado):
     *
     *   site_url('/loja/x', absolute: true) → https://dominio.com/loja/x
     *
     * Fora de uma requisição HTTP (comando, job de fila, agendador, script) a
     * base vem do endereço configurado do app (APP_URL): ali o SCRIPT_NAME é o
     * caminho do script em execução, não a base do app.
     */
    function site_url(string $path = '/', bool $absolute = false): string
    {
        $suffix = '/' . ltrim($path, '/');

        $app = function_exists('app') ? app() : null;
        $console = is_object($app) && method_exists($app, 'runningInConsole')
            ? (bool) $app->runningInConsole()
            : in_array(PHP_SAPI, ['cli', 'phpdbg'], true);

        if ($console) {
            $root = '';
            try {
                $root = is_object($app) && $app->bound('config')
                    ? rtrim(trim((string) $app->make('config')->get('app.url', '')), '/')
                    : '';
            } catch (\Throwable) {
                $root = '';
            }

            if ($absolute) {
                return ($root !== '' ? $root : 'http://localhost') . $suffix;
            }

            return rtrim((string) parse_url($root, PHP_URL_PATH), '/') . $suffix;
        }

        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = str_replace('\\', '/', dirname($script));
        if ($dir === '/' || $dir === '.' || $dir === '\\') {
            $dir = '';
        }
        $base = rtrim($dir, '/');

        if (! $absolute) {
            return $base . $suffix;
        }

        // Esquema e domínio da requisição em curso (o Laravel já resolve o
        // proxy confiável); sem ele, o que o servidor web informou.
        try {
            if (is_object($app) && $app->bound('request')) {
                return $app->make('request')->getSchemeAndHttpHost() . $base . $suffix;
            }
        } catch (\Throwable) {
            // cai no $_SERVER
        }

        $scheme = (($_SERVER['HTTPS'] ?? 'off') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            ? 'https'
            : 'http';

        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . $suffix;
    }
}

if (!function_exists('asset')) {
    /**
     * Retorna a URL absoluta de um asset (CSS, JS, imagem).
     * No MVP e identico a url() — futuramente pode adicionar cache busting.
     */
    function asset(string $path): string
    {
        return url($path);
    }
}

if (!function_exists('route')) {
    /**
     * MVP: retorna o nome como-e (nao resolve rotas nomeadas ainda).
     * Phase 2 pode implementar resolucao de rotas com nome via Router::getRoutes().
     *
     * Uso futuro: route('home') → '/public/home'
     */
    function route(string $name, array $params = []): string
    {
        // Por enquanto so retorna o nome — phase 2 implementa
        return $name;
    }
}
