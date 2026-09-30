<?php

namespace Mad\Http;

/**
 * JsonErrorRenderer
 *
 * Politica de erro JSON dos entries de API (REST/MCP/Embed).
 *
 * Paridade com os entries originais:
 *   - MCP:   leakMessage=false, logPrefix='[MadMcpServer]' → 500 generico + error_log
 *   - Embed: leakMessage=true                              → 500 com $e->getMessage()
 *   - REST:  leakMessage=true, useExceptionCode=true       → 500 com getCode() ?: 500
 */
class JsonErrorRenderer implements ErrorRenderer
{
    public function __construct(
        private bool $leakMessage = false,
        private ?string $logPrefix = null,
        private bool $useExceptionCode = false
    ) {
    }

    public function render(int $code, \Throwable $e): \Illuminate\Http\Response
    {
        $message = $code === 404 ? 'Route not found' : 'Method not allowed';

        return new \Illuminate\Http\Response(
            json_encode(['error' => $message]),
            $code,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public function renderFatal(\Throwable $e): string
    {
        if ($this->logPrefix !== null) {
            \error_log($this->logPrefix . ' ' . $e->getMessage());
        }

        // Paridade com MadRestServer: getCode() so e usado em Exception
        // (catch Error fixava 500), e 0 cai pra 500 via ?:.
        $code = 500;
        if ($this->useExceptionCode && $e instanceof \Exception) {
            $code = (int) $e->getCode() ?: 500;
        }

        // Guard identico ao JSONResponse legado: com headers ja enviados
        // (ex: SSE que morreu no meio do stream), nao tenta setar status.
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }

        $message = $this->leakMessage ? $e->getMessage() : 'Internal server error';

        return json_encode(['error' => $message]);
    }
}
