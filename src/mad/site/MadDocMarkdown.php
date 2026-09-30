<?php

namespace Mad\Site;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\MarkdownConverter;

/**
 * MadDocMarkdown — wrapper league/commonmark para o portal de docs do MAD.
 *
 * Renderiza markdown para HTML com:
 *   - GFM (tabelas, strikethrough, task list, autolink)
 *   - Heading permalinks (h2, h3 ganham id + <a> ancorada)
 *   - FrontMatter YAML (extraído em parseFrontMatter)
 *   - Atributos customizados {.class #id}
 *
 * Code blocks fenced (```php, ```blade) saem com `<pre><code class="language-php">`,
 * compatível com Prism.js carregado pelo layout da doc.
 *
 * Também extrai os headings (h2/h3/h4) para alimentar o TOC right sidebar.
 */
class MadDocMarkdown
{
    private static ?MarkdownConverter $converter = null;

    private static function converter(): MarkdownConverter
    {
        if (self::$converter !== null) {
            return self::$converter;
        }

        $config = [
            'html_input'         => 'allow',     // permite HTML inline (callouts custom etc.)
            'allow_unsafe_links' => false,
            'heading_permalink'  => [
                'html_class'        => 'mad-doc-anchor',
                'id_prefix'         => '',
                'fragment_prefix'   => '',
                'insert'            => 'after',
                'min_heading_level' => 2,
                'max_heading_level' => 4,
                'title'             => 'Link para esta seção',
                'symbol'            => '#',
            ],
            'renderer' => [
                'soft_break' => "\n",
            ],
        ];

        $env = new Environment($config);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new GithubFlavoredMarkdownExtension());
        $env->addExtension(new HeadingPermalinkExtension());
        $env->addExtension(new FrontMatterExtension());
        $env->addExtension(new AttributesExtension());

        self::$converter = new MarkdownConverter($env);
        return self::$converter;
    }

    /**
     * Caminho absoluto para arquivo markdown da pasta `app/resources/docs/markdown/`.
     */
    public static function fullPath(string $relativePath): string
    {
        return base_path('app/resources/docs/markdown/' . ltrim($relativePath, '/'));
    }

    /**
     * Renderiza arquivo markdown para HTML.
     *
     * Retorna array com:
     *   - html (string): HTML renderizado
     *   - headings (array): lista de headings extraídos para TOC
     *   - frontMatter (array): YAML front-matter parseado
     *   - error (?string): mensagem de erro se algo falhou
     */
    public static function renderFile(string $relativePath): array
    {
        $full = self::fullPath($relativePath);
        if (!is_file($full)) {
            return [
                'html'        => '<div class="mad-doc-empty"><p><strong>Página em construção.</strong> O conteúdo desta página ainda não foi escrito.</p></div>',
                'headings'    => [],
                'frontMatter' => [],
                'error'       => 'file_not_found',
            ];
        }

        $content = file_get_contents($full);
        if ($content === false) {
            return [
                'html'        => '<div class="mad-doc-empty"><p><strong>Erro ao ler arquivo.</strong></p></div>',
                'headings'    => [],
                'frontMatter' => [],
                'error'       => 'read_failed',
            ];
        }

        return self::renderString($content);
    }

    /**
     * Renderiza string markdown para HTML.
     */
    public static function renderString(string $content): array
    {
        try {
            $converter = self::converter();
            $result    = $converter->convert($content);

            $html        = (string) $result;
            $frontMatter = [];
            if (method_exists($result, 'getFrontMatter')) {
                $frontMatter = (array) ($result->getFrontMatter() ?? []);
            }

            // Pós-processa: copia id do <a class="mad-doc-anchor"> pro próprio h2/h3/h4 —
            // permite anchor links nativos, scrollspy e CSS scroll-margin-top.
            $html = self::promoteHeadingIds($html);

            $headings = self::extractHeadings($html);

            return [
                'html'        => $html,
                'headings'    => $headings,
                'frontMatter' => $frontMatter,
                'error'       => null,
            ];
        } catch (\Throwable $e) {
            return [
                'html'        => '<div class="mad-doc-empty"><p><strong>Erro ao renderizar markdown:</strong> ' . htmlspecialchars($e->getMessage()) . '</p></div>',
                'headings'    => [],
                'frontMatter' => [],
                'error'       => 'render_failed',
            ];
        }
    }

    /**
     * Pós-processa HTML do commonmark: copia id do anchor permalink (que vai dentro
     * do h2 via HeadingPermalinkExtension) para o atributo id do próprio h2/h3/h4.
     *
     * Antes: <h2><a id="props" href="#props" class="mad-doc-anchor">¶</a>Props</h2>
     * Depois: <h2 id="props"><a href="#props" class="mad-doc-anchor">¶</a>Props</h2>
     */
    public static function promoteHeadingIds(string $html): string
    {
        return preg_replace_callback(
            '#<h([2-4])\b([^>]*)>(.*?)</h\1>#is',
            function ($m) {
                $level = $m[1];
                $attrs = $m[2];
                $inner = $m[3];

                if (preg_match('#\bid\s*=\s*"#i', $attrs)) {
                    return $m[0];
                }

                if (preg_match('#<a\b[^>]*\bid\s*=\s*"([^"]+)"[^>]*>#i', $inner, $idMatch)) {
                    $id = $idMatch[1];
                    return '<h' . $level . ' id="' . $id . '"' . $attrs . '>' . $inner . '</h' . $level . '>';
                }

                return $m[0];
            },
            $html
        );
    }

    /**
     * Extrai headings h2/h3/h4 do HTML renderizado para alimentar o TOC.
     *
     * Aceita id em qualquer posição dos atributos. Funciona tanto com:
     *   - <h2 id="X">  (heading-permalink extension)
     *   - <h2 class="..." id="X">  (componentes Blade)
     *
     * @return array<int, array{level:int, id:string, text:string}>
     */
    public static function extractHeadings(string $html): array
    {
        $headings = [];
        if (preg_match_all('#<h([2-4])\b([^>]*)>(.+?)</h\1>#is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $level = (int) $match[1];
                $attrs = (string) $match[2];

                if (!preg_match('#\bid\s*=\s*"([^"]+)"#i', $attrs, $idMatch)) {
                    continue; // sem id, não entra no TOC
                }
                $id = $idMatch[1];

                $text = preg_replace('#<a[^>]*class="(mad-doc-anchor|mad-docs-anchor)[^"]*"[^>]*>.*?</a>#is', '', $match[3]);
                $text = strip_tags($text);
                $text = trim(preg_replace('/\s+/', ' ', $text));

                if ($text !== '') {
                    $headings[] = [
                        'level' => $level,
                        'id'    => $id,
                        'text'  => $text,
                    ];
                }
            }
        }
        return $headings;
    }

    /**
     * Slugify simples (para ids manuais quando precisar).
     */
    public static function slug(string $text): string
    {
        $text = preg_replace('/[^\pL\d\s-]/u', '', $text);
        $text = preg_replace('/\s+/', '-', trim($text));
        $text = mb_strtolower($text);
        $map = [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c','ñ'=>'n',
        ];
        $text = strtr($text, $map);
        return $text;
    }
}
