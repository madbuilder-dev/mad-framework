<?php
namespace Mad\Grid\Pdf;

use Dompdf\Adapter\CPDF;

/**
 * O canvas CPDF do próprio Dompdf, com três ganchos que o motor direto usa:
 *
 *  - captura da sentinela de {PAGE_NUM}: a banda custom é renderizada UMA vez
 *    pelo Dompdf; o texto do número de página (marcado pelo callback
 *    begin_frame) não é desenhado — a posição, fonte e cor são guardadas e o
 *    número de cada página é escrito ali depois;
 *  - "congelar" a página pronta: o Cpdf guarda todo content stream em memória
 *    até o output(); comprimir cada página assim que ela termina corta a
 *    memória do PDF em ~6× (100 mil linhas ficariam em centenas de MB);
 *  - esquecer o estado gráfico ao trocar de stream: o Cpdf só emite `rg`/`w`/
 *    `gs` quando o valor muda, e o estado rastreado pertence ao stream que
 *    estava aberto — sem o reset, a banda (outro stream) herdaria uma cor que
 *    nunca foi emitida nela.
 */
class GridPdfCanvas extends CPDF
{
    public bool $captureNext = false;
    /** @var array<int, array> textos capturados (x, y, font, size, color, word_space, char_space, angle, op) */
    public array $captured = [];

    public function text($x, $y, $text, $font, $size, $color = [0, 0, 0], $word_space = 0.0, $char_space = 0.0, $angle = 0.0)
    {
        if ($this->captureNext) {
            $this->captureNext = false;
            $this->captured[] = [
                'x' => $x, 'y' => $y, 'font' => $font, 'size' => $size, 'color' => $color,
                'word_space' => $word_space, 'char_space' => $char_space, 'angle' => $angle,
                'op' => $this->_current_opacity,
            ];
            return;
        }
        parent::text($x, $y, $text, $font, $size, $color, $word_space, $char_space, $angle);
    }

    /** @return int[] id do content stream de cada página, na ordem. */
    public function pageContentIds(): array
    {
        return $this->_pages;
    }

    public function resetGraphicState(): void
    {
        $c = $this->_pdf;
        $c->currentColor = null;
        $c->currentStrokeColor = null;
        $c->currentLineStyle = '';
        $c->currentLineTransparency = ['mode' => '', 'opacity' => -1.0];
        $c->currentFillTransparency = ['mode' => '', 'opacity' => -1.0];
        $this->_current_opacity = 1.0;
    }

    /**
     * Comprime o content stream de uma página que não recebe mais desenho e o
     * marca como objeto pronto ('raw' — o Cpdf imprime como está no output).
     */
    public function freeze(int $contentsId): void
    {
        $c = $this->_pdf;
        if (!function_exists('gzcompress') || empty($c->options['compression'])) {
            return;
        }
        $o = &$c->objects[$contentsId];
        if (($o['t'] ?? '') !== 'contents' || isset($o['raw']) || !empty($o['info'])) {
            return;
        }
        $z = gzcompress($o['c'], 6);
        $o['c'] = "<< /Filter /FlateDecode\n/Length " . strlen($z) . " >>\nstream\n" . $z . "\nendstream";
        $o['raw'] = 1;
    }
}
