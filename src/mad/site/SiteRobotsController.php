<?php

namespace Mad\Site;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * SiteRobotsController — as instruções para os buscadores (`GET /robots.txt`).
 *
 * Com site publicado: libera o conteúdo público, fecha a área interna (o painel,
 * os endereços de serviço e as telas de cobrança) e aponta o mapa do site.
 *
 * Sem site publicado: mantém o arquivo genérico de antes, para não mudar o
 * comportamento de quem só usa o sistema por dentro.
 *
 * Vem como ROTA, não como arquivo em public/: um arquivo físico é servido pelo
 * servidor web antes de o sistema ser consultado, e aí o conteúdo nunca
 * acompanharia o site (o arquivo estático foi removido junto com esta rota).
 */
class SiteRobotsController
{
    /** Prefixos que nunca devem ser rastreados. */
    private const DISALLOW = ['/app', '/public', '/billing'];

    public function __invoke(Request $request): Response
    {
        $linhas = ['User-agent: *'];

        if (SiteRegistry::hasHome()) {
            foreach (self::DISALLOW as $prefixo) {
                $linhas[] = 'Disallow: ' . $prefixo;
            }
            $linhas[] = '';
            $base = rtrim($request->getSchemeAndHttpHost() . $request->getBaseUrl(), '/');
            $linhas[] = 'Sitemap: ' . $base . '/sitemap.xml';
        } else {
            $linhas[] = 'Disallow:';
        }

        return new Response(implode("\n", $linhas) . "\n", 200, [
            'Content-Type'  => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
