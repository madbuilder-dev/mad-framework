<?php
namespace Mad\Util;

/**
 * MadHtmlSanitizer
 *
 * Sanitizador de HTML server-side baseado em DOMDocument com whitelist
 * de tags e atributos. Defesa contra Stored XSS quando se aceita HTML
 * de editores ricos (Quill / TinyMCE / etc) e armazena no banco para
 * renderizar via {!! !!} mais tarde.
 *
 * Whitelist conservadora — adequada para conteudo editorial. Bloqueia:
 *   - Tags executaveis (script, iframe, object, embed, link, meta, base, frame)
 *   - Atributos `on*` (event handlers JS)
 *   - URLs `javascript:`/`data:` em href/src (com exceções para data:image)
 *   - Atributo `style` (vetor comum de XSS via expression(), background:url())
 *
 * Uso:
 *   $clean = \Mad\Util\MadHtmlSanitizer::sanitize($dirtyHtml);
 *
 * Para quem precisa de sanitizacao mais robusta (ex: confiar em apenas
 * HTMLPurifier), substituir esta classe por wrapper de HTMLPurifier.
 */
class MadHtmlSanitizer
{
    /** Tags permitidas no body do conteudo. */
    private const ALLOWED_TAGS = [
        // Texto
        'p', 'br', 'span', 'div', 'hr',
        'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup', 'small', 'mark',
        // Headings
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        // Listas
        'ul', 'ol', 'li',
        // Blocos
        'blockquote', 'pre', 'code',
        // Tabelas
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
        // Links / midia
        'a', 'img', 'figure', 'figcaption',
    ];

    /** Atributos por tag. '*' aplica-se a todas as tags permitidas. */
    private const ALLOWED_ATTRS = [
        '*'   => ['class', 'id', 'title', 'lang', 'dir'],
        'a'   => ['href', 'target', 'rel', 'name'],
        'img' => ['src', 'alt', 'width', 'height'],
        'td'  => ['colspan', 'rowspan', 'align'],
        'th'  => ['colspan', 'rowspan', 'align', 'scope'],
        'ol'  => ['start', 'type'],
        'col' => ['span'],
        'colgroup' => ['span'],
    ];

    /** Protocolos seguros em href. */
    private const ALLOWED_HREF_PROTOCOLS = ['http', 'https', 'mailto', 'tel', 'ftp'];

    /** Protocolos seguros em src de img. */
    private const ALLOWED_IMG_PROTOCOLS = ['http', 'https'];

    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // Loadhtml com hint UTF-8. Wrap em container para localizar nos.
        $wrapper = '<?xml encoding="UTF-8"?><div id="__mad_sanitize_root">' . $html . '</div>';

        $doc = new \DOMDocument('1.0', 'UTF-8');
        // Suprime warnings de tags HTML5 nao reconhecidas (DOMDocument nao tem
        // suporte total a HTML5; apenas o subconjunto da whitelist nos importa).
        libxml_use_internal_errors(true);
        $doc->loadHTML($wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $doc->getElementById('__mad_sanitize_root')
              ?? $doc->documentElement;

        if ($root) {
            self::cleanNode($root);
        }

        // Re-serializa, removendo o wrapper artificial.
        $output = '';
        if ($root) {
            foreach ($root->childNodes as $child) {
                $output .= $doc->saveHTML($child);
            }
        }

        return trim($output);
    }

    /**
     * Sanitiza recursivamente um node DOM:
     *   - Remove tags fora da whitelist (mas mantem texto interno via mover children).
     *   - Remove atributos fora da whitelist.
     *   - Valida URLs em href/src (rejeita javascript:, data: nao-imagem, etc).
     */
    private static function cleanNode(\DOMNode $node): void
    {
        if (!$node->hasChildNodes()) {
            // Mesmo sem filhos, ainda pode ter atributos (img, br) — sanitiza
            if ($node instanceof \DOMElement) {
                self::sanitizeElement($node);
            }
            return;
        }

        // Itera sobre snapshot (modificacao destruiria iteracao)
        $children = iterator_to_array($node->childNodes);
        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);

                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    // Tag nao permitida -> remove a tag mas mantem children (texto)
                    // EXCETO para tags inerentemente perigosas, onde removemos tudo.
                    // svg/math sao incluidas: podem conter <script> aninhado
                    // (XSS via mutated XML).
                    if (in_array($tag, [
                        'script', 'style', 'iframe', 'object', 'embed',
                        'frame', 'frameset', 'link', 'meta', 'base',
                        'form', 'input', 'button', 'textarea', 'select',
                        'svg', 'math', 'foreignobject', 'animate',
                    ], true)) {
                        $node->removeChild($child);
                        continue;
                    }
                    // Tag desconhecida: clean recursivamente os filhos PRIMEIRO
                    // (para remover scripts dentro), depois move-os para o pai.
                    self::cleanNode($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                // Tag permitida: sanitiza atributos e desce recursivamente
                self::sanitizeElement($child);
                self::cleanNode($child);
            } elseif ($child instanceof \DOMComment) {
                // Comentarios podem conter conteudo malicioso (IE conditional)
                $node->removeChild($child);
            }
            // DOMText: mantem (eh apenas texto, escapado pelo DOM ao serializar)
        }
    }

    private static function sanitizeElement(\DOMElement $el): void
    {
        $tag = strtolower($el->tagName);

        // Lista de atributos permitidos para esta tag
        $allowed = array_merge(
            self::ALLOWED_ATTRS['*'] ?? [],
            self::ALLOWED_ATTRS[$tag] ?? []
        );

        // Coleta nomes dos atributos antes de modificar
        $attrNames = [];
        foreach ($el->attributes as $a) {
            $attrNames[] = $a->name;
        }

        foreach ($attrNames as $name) {
            $lower = strtolower($name);

            // Bloqueio absoluto: event handlers (onclick, onload, on*)
            if (str_starts_with($lower, 'on')) {
                $el->removeAttribute($name);
                continue;
            }
            // Bloqueio: style (vetor de XSS via expression(), url(javascript:))
            if ($lower === 'style') {
                $el->removeAttribute($name);
                continue;
            }
            if (!in_array($lower, $allowed, true)) {
                $el->removeAttribute($name);
                continue;
            }

            // Validacao especifica para URLs
            if ($lower === 'href' || $lower === 'src') {
                $val = $el->getAttribute($name);
                $isImg = ($tag === 'img');
                if (!self::isSafeUrl($val, $isImg)) {
                    $el->removeAttribute($name);
                }
            }
        }

        // Forca rel="noopener noreferrer" em links externos com target=_blank
        if ($tag === 'a' && strtolower($el->getAttribute('target')) === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function isSafeUrl(string $url, bool $isImg): bool
    {
        $url = trim($url);
        if ($url === '') return false;

        // URLs relativas / fragmentos / paths absolutos sao seguros
        if ($url[0] === '/' || $url[0] === '#' || $url[0] === '?') {
            return true;
        }

        // Detecta esquema (case-insensitive). preg para tolerar espacos/encoding.
        if (preg_match('#^([a-z][a-z0-9+\-.]*)\s*:#i', $url, $m)) {
            $scheme = strtolower($m[1]);

            // data:image/...;base64,... e ok para img (NAO para href, evita XSS)
            if ($scheme === 'data') {
                if ($isImg && preg_match('#^data:image/(png|jpe?g|gif|webp|svg\+xml);base64,#i', $url)) {
                    // SVG via data:URI ainda pode conter script — bloqueia
                    if (stripos($url, 'svg') !== false) {
                        return false;
                    }
                    return true;
                }
                return false;
            }

            $allowed = $isImg ? self::ALLOWED_IMG_PROTOCOLS : self::ALLOWED_HREF_PROTOCOLS;
            return in_array($scheme, $allowed, true);
        }

        // Sem esquema -> URL relativa, seguro
        return true;
    }
}
