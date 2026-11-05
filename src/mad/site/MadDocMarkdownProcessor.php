<?php

namespace Mad\Site;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;

/**
 * Ajustes da árvore do Markdown antes do HTML, para as instâncias de
 * {@see MadDocMarkdown} (o manual do sistema):
 *
 *   - Callout: citação cuja 1ª linha começa com **Dica:**, **Atenção:** ou
 *     **Nota:** vira bloco com `data-callout="tip|warning|note"` e a classe
 *     `mad-help-callout mad-help-callout-{tipo}` (não `.mad-callout`, que é o componente `<mad-callout>` do mad-ui). O Studio (react-markdown) marca o MESMO
 *     atributo — é o contrato de paridade da prévia.
 *   - Imagem `prints/x.webp` (ou `./prints/x.webp`) aponta para a rota logada
 *     dos prints; nome fora do padrão seguro fica como está (e não carrega).
 *   - Link `/app/...` ganha o prefixo do app instalado em subpasta.
 */
final class MadDocMarkdownProcessor
{
    /** Rótulo do callout (sem acento, minúsculo) → tipo. */
    private const CALLOUTS = [
        'dica'    => 'tip',
        'atencao' => 'warning',
        'nota'    => 'note',
    ];

    public function __construct(
        private readonly bool $callouts = true,
        private readonly ?string $imageBase = null,
        private readonly ?string $linkBase = null,
    ) {
    }

    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $walker = $event->getDocument()->walker();
        while ($ev = $walker->next()) {
            if (!$ev->isEntering()) {
                continue;
            }
            $node = $ev->getNode();
            if ($this->callouts && $node instanceof BlockQuote) {
                $this->markCallout($node);
            } elseif ($node instanceof Image && $this->imageBase !== null) {
                $this->rewriteImage($node);
            } elseif ($node instanceof Link && $this->linkBase !== null) {
                $url = $node->getUrl();
                if (!str_starts_with($url, '/app/')) {
                    continue;
                }
                if ($this->linkBase !== '' && !str_starts_with($url, $this->linkBase . '/')) {
                    $node->setUrl($this->linkBase . $url);
                }
                // Link para outra tela do app: o casco navega sem recarregar.
                $node->data->set('attributes/generator', 'mad');
            }
        }
    }

    /** Tipo do callout pela 1ª linha da citação; null = citação comum. */
    public static function calloutType(BlockQuote $quote): ?string
    {
        $para = $quote->firstChild();
        if (!$para instanceof Paragraph) {
            return null;
        }
        $strong = $para->firstChild();
        if (!$strong instanceof Strong) {
            return null;
        }

        $label = self::plain($strong);
        // `**Dica**: texto` — os dois pontos ficaram fora do negrito.
        if (!str_ends_with($label, ':')) {
            $next = $strong->next();
            if (!$next instanceof Text || !str_starts_with(ltrim($next->getLiteral()), ':')) {
                return null;
            }
            $label .= ':';
        }

        $key = self::fold(rtrim(trim($label), ':'));

        return self::CALLOUTS[trim($key)] ?? null;
    }

    private function markCallout(BlockQuote $quote): void
    {
        $type = self::calloutType($quote);
        if ($type === null) {
            return;
        }
        $quote->data->set('attributes/data-callout', $type);
        $quote->data->append('attributes/class', 'mad-help-callout');
        $quote->data->append('attributes/class', 'mad-help-callout-' . $type);
        $quote->data->set('attributes/role', 'note');
    }

    private function rewriteImage(Image $image): void
    {
        $url = $image->getUrl();
        if (!preg_match('#^(?:\./)?prints/([^/?\#]+)$#', $url, $m)) {
            return;
        }
        $name = rawurldecode($m[1]);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.webp$/', $name) || str_contains($name, '..')) {
            return;
        }
        $image->setUrl($this->imageBase . '/' . rawurlencode($name));
        $image->data->set('attributes/loading', 'lazy');
    }

    private static function plain(Node $node): string
    {
        $out = '';
        foreach ($node->children() as $child) {
            $out .= $child instanceof Text ? $child->getLiteral() : self::plain($child);
        }

        return $out;
    }

    /** Minúsculo e sem acento (Atenção → atencao). */
    private static function fold(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');

        return strtr($s, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e',
            'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c',
        ]);
    }
}
