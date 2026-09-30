<?php

namespace Mad\Http;

/**
 * ErrorRenderer
 *
 * Politica de erro de um entry point HTTP (MadHttpKernel). Cada entry
 * (REST/MCP/Public/Embed/Web) configura sua politica: formato (JSON/HTML),
 * vazamento de detalhes e logging.
 */
interface ErrorRenderer
{
    /**
     * Renderiza 404 (NotFoundHttpException) ou 405 (MethodNotAllowedHttpException)
     * lancados pelo dispatch do Router.
     */
    public function render(int $code, \Throwable $e): \Illuminate\Http\Response;

    /**
     * Renderiza erro fatal (Throwable fora do dispatch — boot, rotas, emissao).
     * Responsavel por setar http_response_code + headers e retornar o body.
     */
    public function renderFatal(\Throwable $e): string;
}
