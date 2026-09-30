<?php

namespace Mad\Rest;

/**
 * Assinatura HMAC do Driver REST — núcleo de segurança.
 *
 * Contrato (DEVE casar 1:1 com o lado do builder, RestDriverTransport):
 *
 *   requestCanonical  = "madrest-req\n" . keyId . "\n" . action . "\n" . ts . "\n" . nonce . "\n" . sha256hex(body)
 *   responseCanonical = "madrest-res\n" . keyId . "\n" . ts . "\n" . nonce . "\n" . sha256hex(body)
 *   signature         = hash_hmac('sha256', canonical, secret)   // hex
 *
 * Prefixos rotulados ("madrest-req"/"madrest-res") evitam reuso cruzado de uma
 * assinatura de request como response. `action` é o ÚLTIMO segmento do path
 * (run/introspect/plan/ping) — independente de prefixo/proxy de URL. Comparação
 * sempre por hash_equals (timing-safe).
 */
class RestDriverSigner
{
    public const HEADER_KEY = 'X-Mad-Key';
    public const HEADER_TS = 'X-Mad-Timestamp';
    public const HEADER_NONCE = 'X-Mad-Nonce';
    public const HEADER_SIGNATURE = 'X-Mad-Signature';

    public function requestSignature(string $secret, string $keyId, string $action, int $ts, string $nonce, string $body): string
    {
        return $this->sign($secret, "madrest-req\n{$keyId}\n{$action}\n{$ts}\n{$nonce}\n" . hash('sha256', $body));
    }

    public function responseSignature(string $secret, string $keyId, int $ts, string $nonce, string $body): string
    {
        return $this->sign($secret, "madrest-res\n{$keyId}\n{$ts}\n{$nonce}\n" . hash('sha256', $body));
    }

    public function verifyRequest(string $secret, string $keyId, string $action, int $ts, string $nonce, string $body, string $signature): bool
    {
        return hash_equals($this->requestSignature($secret, $keyId, $action, $ts, $nonce, $body), $signature);
    }

    public function verifyResponse(string $secret, string $keyId, int $ts, string $nonce, string $body, string $signature): bool
    {
        return hash_equals($this->responseSignature($secret, $keyId, $ts, $nonce, $body), $signature);
    }

    private function sign(string $secret, string $canonical): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }
}
