<?php

namespace Mad\Ai\Http;

use Closure;
use Illuminate\Http\Request;

/**
 * EmbedCors — CORS dos endpoints /embed/v1/* (o iframe do copilot é
 * cross-origin: ai.embed_frontend_url). Origin liberal de propósito: a
 * autorização real é o Bearer (token MCP) — sem ele tudo é 401.
 */
class EmbedCors
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('OPTIONS')) {
            return response('', 204, self::headers());
        }

        $response = $next($request);

        foreach (self::headers() as $k => $v) {
            $response->headers->set($k, $v);
        }

        return $response;
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return [
            'Access-Control-Allow-Origin'  => '*',
            // PATCH/PUT/DELETE: widgets/dashboards do micro-BI (rename, layout,
            // share, exclusão) — sem eles o preflight barra o autosave da tela.
            'Access-Control-Allow-Methods' => 'GET, POST, PATCH, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
            'Access-Control-Max-Age'       => '600',
        ];
    }
}
