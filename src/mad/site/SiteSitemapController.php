<?php

namespace Mad\Site;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * SiteSitemapController — o mapa do site (`GET /sitemap.xml`).
 *
 * Lista os endereços públicos que devem aparecer nos buscadores: as páginas
 * registradas por `MadRoutes::exposeSite()` que NÃO pediram "não indexar", mais
 * os endereços dinâmicos que algum provedor tenha registrado em
 * {@see SiteRegistry::extraUrls()} (os posts do blog, por exemplo).
 *
 * Caminho com parâmetro (`blog/{slug}`) nunca entra: não é um endereço, é um
 * molde — quem conhece os endereços reais é o provedor.
 *
 * Sem nenhuma página de site o mapa sai vazio (e válido), em vez de 404: um
 * buscador que já conhece o endereço recebe "nada a indexar" em vez de erro.
 */
class SiteSitemapController
{
    public function __invoke(Request $request): Response
    {
        $base = rtrim($request->getSchemeAndHttpHost() . $request->getBaseUrl(), '/');

        $urls = [];
        foreach (SiteRegistry::all() as $page) {
            if ($page['noindex'] || str_contains($page['path'], '{')) {
                continue;
            }
            $urls[] = $this->absolute($base, $page['path']);
        }

        foreach (SiteRegistry::collectExtraUrls() as $extra) {
            $urls[] = str_starts_with($extra, 'http')
                ? $extra
                : $this->absolute($base, $extra);
        }

        $urls = array_values(array_unique($urls));

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";

        return new Response($xml, 200, [
            'Content-Type'  => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function absolute(string $base, string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? $base . '/' : $base . '/' . $path;
    }
}
