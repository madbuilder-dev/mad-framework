<?php

namespace Mad\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mad\I18n\MadLang;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dicionário dos textos de interface do JavaScript do framework.
 *
 * O mad-ui.js, o mad.js e o mad-livewire.js montam combos, avisos de upload,
 * confirmações e diálogos de erro no navegador; cada texto passa por
 * `madT(chave, textoPtBr)`. Fora do pt-BR, este middleware GLOBAL põe logo
 * depois do `<head>` de toda resposta HTML o grupo `mad.js` do catálogo do
 * framework (`Mad\I18n\MadLang`) no idioma ativo:
 *
 *     <script data-mad-i18n>window.MAD_I18N = {...};</script>
 *
 * Em pt-BR nada é injetado: o próprio JS já traz o texto pt-BR, e a página
 * sai byte a byte como antes. Página sem `<head>` (fragmento) fica como está.
 * Roda no fim da resposta, depois do SetUserLocale do app: vale o idioma do
 * usuário.
 */
class InjectMadI18n
{
    public const MARKER = 'data-mad-i18n';

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

        $tag = self::scriptTag();
        if ($tag === '') {
            return;
        }

        $html = $response->getContent();
        if (!is_string($html) || $html === '' || str_contains($html, self::MARKER)) {
            return;
        }
        if (!preg_match('/<head\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return;
        }
        $at = $m[0][1] + strlen($m[0][0]);

        $response->setContent(substr($html, 0, $at) . "\n" . $tag . substr($html, $at));
        $response->headers->remove('Content-Length');
    }

    /**
     * A tag do dicionário no idioma ativo, ou '' em pt-BR (sem nada a traduzir).
     * Pública para quem monta uma página HTML fora do ciclo HTTP.
     */
    public static function scriptTag(): string
    {
        if (MadLang::getLocale() === 'pt') {
            return '';
        }

        $dict = MadLang::group('mad.js');
        if ($dict === []) {
            return '';
        }

        $json = json_encode(
            $dict,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($json)) {
            return '';
        }

        return '<script ' . self::MARKER . '>window.MAD_I18N=Object.assign(window.MAD_I18N||{},' . $json . ');</script>' . "\n";
    }
}
