<?php

namespace Mad\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mad\Support\VibePreview;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Agent Bridge em TODA página HTML do Teste Online (Modo Vibe, item 2).
 *
 * A plataforma injeta o bridge editando três views (shell/layout, shell/login,
 * site/layout — AgentBridgeInjector). Página de erro (403, 404, 419, 500) não
 * passa por nenhuma delas: o agente via `page_without_bridge` e ficava cego
 * justo onde mais precisava olhar. Este middleware GLOBAL anexa o mesmo bloco
 * antes do `</body>` de qualquer resposta HTML que ainda não o tenha —
 * inclusive as que o handler de exceção renderizou.
 *
 * Só é registrado com MAD_AGENT_BRIDGE=1 e MAD_AGENT_BRIDGE_ORIGINS
 * preenchido (MadServiceProvider). Os assets em public/ continuam vindo da
 * plataforma; aqui só a tag.
 */
class InjectAgentBridge
{
    /** Mesmo marker do AgentBridgeInjector: um só para o bloco inteiro. */
    public const MARKER = 'data-mad-agent-bridge';

    /** Na ordem: core antes da camada MAD (tags clássicas, sem defer). */
    public const SCRIPTS = ['agent-bridge.js', 'agent-bridge-mad.js'];

    /**
     * O `?v=` é o MAIOR mtime entre todos os assets copiados, nas duas tags —
     * a mesma regra do injector: uma edição só na camada MAD não pode seguir
     * servindo o JS velho do cache do navegador.
     */
    private const VERSION_FILES = [
        'agent-revision.php', 'agent-bridge.js', 'agent-bridge-mad.js', 'agent-bridge-html2canvas.js',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if ($response instanceof Response) {
            self::inject($response);
        }

        return $response;
    }

    public static function inject(Response $response): void
    {
        if ($response instanceof BinaryFileResponse
            || $response instanceof StreamedResponse
            || $response instanceof JsonResponse) {
            return;
        }

        $type = (string) $response->headers->get('Content-Type', '');
        if ($type !== '' && stripos($type, 'text/html') === false) {
            return;
        }
        if (stripos((string) $response->headers->get('Content-Disposition', ''), 'attachment') !== false) {
            return;
        }

        $html = $response->getContent();
        if (!is_string($html) || $html === '' || str_contains($html, self::MARKER)) {
            return;
        }
        $pos = strripos($html, '</body>');
        if ($pos === false) {
            return;
        }

        $origins = VibePreview::agentBridgeOrigins();
        if ($origins === []) {
            return;
        }

        $response->setContent(substr($html, 0, $pos) . self::tags($origins) . substr($html, $pos));
        $response->headers->remove('Content-Length');
    }

    /** @param list<string> $origins */
    public static function tags(array $origins): string
    {
        $version = self::version();
        $originsAttr = htmlspecialchars(implode(',', $origins), ENT_QUOTES);
        $auth = self::authenticated() ? 1 : 0;

        $tag = '';
        foreach (self::SCRIPTS as $script) {
            $tag .= '    <script ' . self::MARKER . ' src="/' . $script . '?v=' . $version . '" data-allowed-origins="'
                . $originsAttr . '" data-mad-auth="' . $auth . '"></script>' . "\n";
        }
        return $tag;
    }

    private static function version(): int
    {
        $public = function_exists('public_path') ? public_path() : getcwd() . '/public';
        $mtimes = [];
        foreach (self::VERSION_FILES as $file) {
            $m = @filemtime($public . '/' . $file);
            if ($m !== false) {
                $mtimes[] = (int) $m;
            }
        }
        return $mtimes ? max($mtimes) : 0;
    }

    /**
     * Sessão autenticada? O shell perguntava ao `Auth::check()`, que só
     * enxerga o usuário nas rotas que passaram pelo MadAuthenticate (ele faz a
     * ponte sessão → guard); a página de erro não passa. A sessão do app
     * (`logged`, gravada no login) responde nos dois casos.
     */
    private static function authenticated(): bool
    {
        try {
            if (\Illuminate\Support\Facades\Auth::check()) {
                return true;
            }
        } catch (\Throwable) {
            // sem guard configurado: cai na sessão
        }
        try {
            return \Mad\Security\PermissionGate::isLogged();
        } catch (\Throwable) {
            return false;
        }
    }
}
