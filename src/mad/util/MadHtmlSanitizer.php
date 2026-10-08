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
 *   - No atributo `style`, tudo o que nao e formatacao: so ficam as
 *     propriedades de ALLOWED_STYLES com valor simples (cor, alinhamento,
 *     fonte, margem, borda, largura). `url()`, `expression()`, `position` e
 *     afins saem — ver cleanStyle().
 *
 * O que o editor (TinyMCE) grava para negrito, italico, sublinhado, cor,
 * alinhamento, tamanho de fonte, recuo, lista, link, imagem e tabela passa
 * inteiro. Midia embutida (iframe/video) e removida. A lista completa esta em
 * docs/editor-html.md.
 *
 * A limpeza e IDEMPOTENTE: limpar o que ja foi limpo devolve o mesmo texto —
 * ela roda na entrada do formulario, de novo no Salvar e na exibicao.
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
        'del', 'ins', 'abbr', 'cite', 'q', 'kbd', 'samp', 'var',
        // Headings
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        // Listas
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        // Blocos
        'blockquote', 'pre', 'code',
        // Tabelas
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
        // Links / midia
        'a', 'img', 'figure', 'figcaption',
    ];

    /** Atributos por tag. '*' aplica-se a todas as tags permitidas. */
    private const ALLOWED_ATTRS = [
        '*'   => ['class', 'id', 'title', 'lang', 'dir', 'style', 'align'],
        'a'   => ['href', 'target', 'rel', 'name'],
        'img' => ['src', 'alt', 'width', 'height', 'border'],
        // Apresentacao de tabela: e o que o editor (e o modelo de e-mail)
        // grava para borda, largura e espacamento das celulas.
        'table' => ['border', 'cellpadding', 'cellspacing', 'width', 'height', 'bgcolor', 'summary'],
        'tr'  => ['valign', 'bgcolor', 'height'],
        'td'  => ['colspan', 'rowspan', 'valign', 'width', 'height', 'bgcolor', 'nowrap'],
        'th'  => ['colspan', 'rowspan', 'valign', 'width', 'height', 'bgcolor', 'nowrap', 'scope'],
        'ol'  => ['start', 'type', 'reversed'],
        'ul'  => ['type'],
        'li'  => ['value'],
        'col' => ['span', 'width'],
        'colgroup' => ['span', 'width'],
        'blockquote' => ['cite'],
        'q'   => ['cite'],
        'del' => ['datetime'],
        'ins' => ['datetime'],
    ];

    /** Atributos de apresentacao (largura, cor, alinhamento): so valor simples. */
    private const PRESENTATION_ATTRS = [
        'align', 'valign', 'bgcolor', 'border', 'cellpadding', 'cellspacing', 'width', 'height',
    ];

    /**
     * Propriedades CSS aceitas no atributo `style` — formatacao de texto e de
     * caixa. Ficam de FORA de proposito: `position`, `top`/`left`, `z-index`,
     * `opacity`, `transform`, `content`, `cursor` e tudo o que tira o conteudo
     * do lugar ou o poe por cima da tela (um link invisivel cobrindo um botao).
     */
    private const ALLOWED_STYLES = [
        'color', 'background', 'background-color',
        'font', 'font-family', 'font-size', 'font-style', 'font-variant', 'font-weight',
        'line-height', 'letter-spacing', 'word-spacing',
        'text-align', 'text-decoration', 'text-decoration-line', 'text-decoration-color',
        'text-decoration-style', 'text-indent', 'text-transform', 'vertical-align',
        'white-space', 'word-break', 'word-wrap', 'overflow-wrap',
        'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-color', 'border-style', 'border-width', 'border-radius',
        'border-collapse', 'border-spacing',
        'border-top-color', 'border-top-style', 'border-top-width',
        'border-right-color', 'border-right-style', 'border-right-width',
        'border-bottom-color', 'border-bottom-style', 'border-bottom-width',
        'border-left-color', 'border-left-style', 'border-left-width',
        'float', 'clear', 'display', 'list-style', 'list-style-type',
        'table-layout', 'caption-side', 'box-sizing', 'direction',
    ];

    /** Valores aceitos em `display` (nada que mude o fluxo da pagina em volta). */
    private const ALLOWED_DISPLAY = [
        'block', 'inline', 'inline-block', 'none', 'table', 'table-row', 'table-cell', 'list-item',
    ];

    /** Maximo de declaracoes lidas de um `style` e tamanho maximo de um valor. */
    private const MAX_STYLE_DECLARATIONS = 40;
    private const MAX_STYLE_VALUE        = 200;

    /** Protocolos seguros em href. */
    private const ALLOWED_HREF_PROTOCOLS = ['http', 'https', 'mailto', 'tel', 'ftp'];

    /** Protocolos seguros em src de img. */
    private const ALLOWED_IMG_PROTOCOLS = ['http', 'https'];

    /** Quantas coisas a limpeza em curso tirou (tag, atributo, declaracao de estilo, comentario). */
    private static int $removed = 0;

    /**
     * Limpa e diz se algo foi TIRADO — para quem precisa separar o conteudo
     * que tinha o que remover do que so foi reescrito pelo serializador
     * (`<br />` vira `<br>`, `&nbsp;` vira o caractere). E o que o comando
     * `mad:html-clean` usa para so regravar os registros que precisam.
     *
     * @return array{html: string, removed: int}
     */
    public static function inspect(string $html): array
    {
        $before = self::$removed;
        self::$removed = 0;
        try {
            $clean = self::sanitize($html);

            return ['html' => $clean, 'removed' => self::$removed];
        } finally {
            self::$removed = $before;
        }
    }

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

        // O serializador do libxml codifica a URL de href/src: o marcador
        // `{$link}` de um modelo de e-mail saia como `%7B%24link%7D` e deixava
        // de ser substituido. Volta ao que o autor escreveu — `{`, `$` e `}`
        // nao fecham atributo nem abrem tag.
        $output = preg_replace('/%7B%24([A-Za-z_][A-Za-z0-9_]*)%7D/i', '{\$$1}', $output) ?? $output;

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
                        self::$removed++;
                        continue;
                    }
                    // Tag desconhecida: clean recursivamente os filhos PRIMEIRO
                    // (para remover scripts dentro), depois move-os para o pai.
                    self::cleanNode($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    self::$removed++;
                    continue;
                }

                // Tag permitida: sanitiza atributos e desce recursivamente
                self::sanitizeElement($child);
                self::cleanNode($child);
            } elseif ($child instanceof \DOMComment) {
                // Comentarios podem conter conteudo malicioso (IE conditional)
                $node->removeChild($child);
                self::$removed++;
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
                self::$removed++;
                continue;
            }
            if (!in_array($lower, $allowed, true)) {
                $el->removeAttribute($name);
                self::$removed++;
                continue;
            }
            // style: fica so a formatacao (cleanStyle); o resto — url(),
            // expression(), position — sai. Nada sobrou: sai o atributo.
            if ($lower === 'style') {
                $css = self::cleanStyle($el->getAttribute($name));
                if ($css === '') {
                    $el->removeAttribute($name);
                } else {
                    $el->setAttribute($name, $css);
                }
                continue;
            }
            // Largura, cor, alinhamento: valor simples (numero, %, nome, #hex).
            if (in_array($lower, self::PRESENTATION_ATTRS, true)
                && !preg_match('/^[#A-Za-z0-9%.]{1,40}$/', trim($el->getAttribute($name)))) {
                $el->removeAttribute($name);
                self::$removed++;
                continue;
            }

            // Validacao especifica para URLs
            if ($lower === 'href' || $lower === 'src') {
                $val = $el->getAttribute($name);
                $isImg = ($tag === 'img');
                if (!self::isSafeUrl($val, $isImg)) {
                    $el->removeAttribute($name);
                    self::$removed++;
                }
            }
        }

        // Forca rel="noopener noreferrer" em links externos com target=_blank
        if ($tag === 'a' && strtolower($el->getAttribute('target')) === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * `style` so com formatacao: cada declaracao precisa ser de uma propriedade
     * de ALLOWED_STYLES e ter valor simples — letras, numeros, `#`, `%`,
     * virgula, parenteses (`rgb(...)`), aspas (nome de fonte). Sai a declaracao
     * com `url(`, `expression(`, `@import`, barra invertida (escape CSS),
     * comentario, numero negativo ou valor comprido demais.
     *
     * Devolve `prop: valor; prop: valor;` (ou '' quando nada passa).
     */
    public static function cleanStyle(string $style): string
    {
        $kept  = [];
        $given = 0;
        foreach (explode(';', $style) as $i => $declaration) {
            if (trim($declaration) === '') {
                continue;
            }
            $given++;
            if ($i >= self::MAX_STYLE_DECLARATIONS) {
                continue;
            }
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                continue;
            }
            $property = strtolower(trim(substr($declaration, 0, $colon)));
            $value    = trim(substr($declaration, $colon + 1));

            if ($value === '' || !in_array($property, self::ALLOWED_STYLES, true)) {
                continue;
            }
            if (strlen($value) > self::MAX_STYLE_VALUE
                || !preg_match('/^[A-Za-z0-9\s#%.,()\-+\'"!\/]+$/', $value)) {
                continue;
            }
            $compact = strtolower((string) preg_replace('/\s+/', '', $value));
            foreach (['url(', 'expression(', 'javascript', 'vbscript', 'import', 'behavior', 'binding', '/*', '*/', 'var(', 'attr(', 'env('] as $bad) {
                if (str_contains($compact, $bad)) {
                    continue 2;
                }
            }
            // Numero negativo tira o conteudo do lugar (margem, recuo).
            if (preg_match('/(^|[\s,(])-\s*[\d.]/', $value)) {
                continue;
            }
            if ($property === 'display' && !in_array($compact, self::ALLOWED_DISPLAY, true)) {
                continue;
            }

            $kept[$property] = $property . ': ' . $value . ';';
        }
        self::$removed += max(0, $given - count($kept));

        return implode(' ', $kept);
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
