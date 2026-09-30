<?php

namespace Mad\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mad\Rest\RestDriver;
use Mad\Rest\RestDriverKeyStore;
use Mad\Rest\RestDriverSigner;

/**
 * Autenticação HMAC do Driver REST (serviço-a-serviço, SEM sessão). Verifica,
 * em ordem fail-closed:
 *   1. driver habilitado;
 *   2. headers presentes (key/timestamp/nonce/signature);
 *   3. timestamp dentro da janela (anti-replay temporal);
 *   4. nonce inédito na janela (anti-replay por nonce, via cache);
 *   5. keyId conhecido no keystore;
 *   6. assinatura HMAC válida (timing-safe).
 *
 * Qualquer falha → 401 genérico (sem revelar qual checagem caiu). A entrada da
 * chave resolvida é anexada ao request (`mad_rest_key`) p/ o controller usar
 * connection/read_only.
 */
class RestDriverHmac
{
    public function __construct(
        private readonly RestDriverSigner $signer = new RestDriverSigner(),
        private readonly RestDriverKeyStore $keys = new RestDriverKeyStore(),
    ) {}

    public function handle(Request $request, Closure $next)
    {
        if (! RestDriver::enabled()) {
            return $this->deny('disabled', 404);
        }

        $keyId = (string) $request->header(RestDriverSigner::HEADER_KEY, '');
        $ts = (int) $request->header(RestDriverSigner::HEADER_TS, '0');
        $nonce = (string) $request->header(RestDriverSigner::HEADER_NONCE, '');
        $signature = (string) $request->header(RestDriverSigner::HEADER_SIGNATURE, '');

        if ($keyId === '' || $ts === 0 || $nonce === '' || $signature === '') {
            return $this->deny();
        }

        // Janela temporal (clock skew tolerado nos dois sentidos).
        $window = RestDriver::windowSeconds();
        if (abs(time() - $ts) > $window) {
            return $this->deny();
        }

        // Nonce inédito (replay). Cache::add é atômico: false = já visto.
        $nonceKey = 'mad-rd-nonce:' . $keyId . ':' . $nonce;
        if (! Cache::add($nonceKey, 1, $window * 2)) {
            return $this->deny();
        }

        $entry = $this->keys->find($keyId);
        if (! $entry || empty($entry['secret'])) {
            return $this->deny();
        }

        $action = $this->action($request);
        $body = $request->getContent();

        if (! $this->signer->verifyRequest((string) $entry['secret'], $keyId, $action, $ts, $nonce, $body, $signature)) {
            return $this->deny();
        }

        // Disponível p/ o controller (connection alvo + read_only + assinatura da resposta).
        $request->attributes->set('mad_rest_key', ['key_id' => $keyId, 'action' => $action] + $entry);

        return $next($request);
    }

    private function action(Request $request): string
    {
        $parts = explode('/', trim($request->path(), '/'));
        return (string) end($parts);
    }

    private function deny(string $reason = 'unauthorized', int $status = 401): JsonResponse
    {
        return new JsonResponse(['error' => $reason], $status);
    }
}
