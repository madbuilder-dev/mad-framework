<?php

namespace Mad\Http;

use Illuminate\Http\Request as IlluminateRequest;
use Mad\Rest\Internal\RouterBridge;
use Mad\Rest\Request;
use Mad\Rest\ResponseInterface;
use Mad\Rest\RouteServiceProvider;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * MadHttpKernel
 *
 * Kernel HTTP unico dos entry points Mad*Server (mesmo desenho do
 * withRouting() do Laravel 11): cada entry vira config declarativa e o
 * esqueleto (boot → rotas → capture → bind → dispatch → emissao) vive aqui.
 *
 * O que NAO vive aqui (fica no entry stub, ANTES do require init.php,
 * porque esta classe so existe depois do autoloader):
 *   - error_reporting pre-init
 *   - headers CORS + resposta de preflight OPTIONS (ex: MadEmbedServer)
 *
 * Pipeline (ordem identica aos 5 entries originais):
 *   1. boot callbacks  (pacotes sem Foundation, sessao, diretivas Blade)
 *   2. rotas           ('provider' = RouteServiceProvider | arquivos isolados)
 *   3. capture + mutadores de request (ex: method spoofing)
 *   4. bind dos Requests no container (ActionResolver/middleware dependem)
 *   5. dispatch via Illuminate Router (404/405 → ErrorRenderer)
 *   6. emissao (ResponseInterface legado | Symfony Response | valor cru)
 */
class MadHttpKernel
{
    /**
     * @param array{
     *   boot?: callable[],            ordem preservada, roda antes das rotas
     *   routes?: 'provider'|string[], default 'provider'; arquivos relativos a app/routes/
     *   request?: callable[],         mutadores fn(IlluminateRequest): IlluminateRequest
     *   errors?: ErrorRenderer,       default JsonErrorRenderer (500 generico, sem leak)
     * } $config
     *
     * @return mixed string|null — contrato dos entries: `print Kernel::run([...]);`
     */
    public static function run(array $config)
    {
        // Reforca supressao apos init.php (que pode reabrir display_errors).
        ini_set('display_errors', '0');
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        $errors = $config['errors'] ?? new JsonErrorRenderer(false);

        try {
            // 1. Boot callbacks (idempotentes por contrato dos pacotes).
            foreach ($config['boot'] ?? [] as $boot) {
                $boot();
            }

            // 2. Rotas — provider completo (api+public+web) ou arquivos isolados.
            $routes = $config['routes'] ?? 'provider';
            if ($routes === 'provider') {
                (new RouteServiceProvider())->boot();
            } else {
                $root = defined('PATH') ? PATH : dirname(__DIR__, 3);
                foreach ((array) $routes as $file) {
                    $path = $root . '/app/routes/' . $file;
                    if (file_exists($path)) {
                        // ⚠️ O catch(\Throwable) lá embaixo NÃO cobre este require.
                        // Ele pega o que o arquivo de rotas EXECUTA (helper que
                        // lança, closure que explode no boot) — mas erro de
                        // COMPILAÇÃO (sintaxe, covariância, redeclaração) é fatal
                        // do PHP, não é Throwable: o renderFatal() nunca roda e o
                        // cliente recebe 500 cru do servidor. Não há isolamento
                        // possível aqui; um arquivo de rotas quebrado derruba o
                        // entry inteiro por design.
                        require_once $path;
                    }
                }
            }

            // 3. Captura o Illuminate Request + mutadores do entry.
            $illuminateRequest = IlluminateRequest::capture();
            foreach ($config['request'] ?? [] as $mutator) {
                $illuminateRequest = $mutator($illuminateRequest);
            }
            $madRequest = new Request($illuminateRequest);

            // 4. Bind dos dois Requests no container:
            //    - Illuminate Request no alias 'request' (closures que
            //      type-hintam Illuminate\Http\Request)
            //    - Mad\Rest\Request no FQCN (ActionResolver)
            $container = RouterBridge::getContainer();
            $container->instance('request', $illuminateRequest);
            $container->alias('request', IlluminateRequest::class);
            $container->instance(Request::class, $madRequest);

            // 5. Dispatch via Illuminate Router. O envelope do Mad\Rest\Router
            //    roda o middleware pipeline e normaliza a resposta.
            try {
                $response = RouterBridge::getRouter()->dispatch($illuminateRequest);
            } catch (NotFoundHttpException $e) {
                $response = $errors->render(404, $e);
            } catch (MethodNotAllowedHttpException $e) {
                $response = $errors->render(405, $e);
            }

            // 6. Emissao.
            if ($response instanceof ResponseInterface) {
                // Legado — parse() retorna o body; status/headers ja setados
                // no construtor do ResponseInterface.
                return $response->parse();
            }

            if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
                // Caminho principal: envia status + headers + body.
                $response->send();
                return null;
            }

            // Fallback: valor cru (string/primitivo de closure simples).
            return $response;
        } catch (\Throwable $e) {
            return $errors->renderFatal($e);
        }
    }
}
