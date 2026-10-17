<?php

namespace Mad\Support;

use Dompdf\Css\Stylesheet;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;

/**
 * FontMetrics do Dompdf sem o cache de PROCESSO na fonte de reserva — e a fonte
 * padrão de cada PDF fixada na raiz.
 *
 * `FontMetrics::getFont(null, $peso)` — o que o Dompdf chama quando a família
 * pedida não tem aquele peso (o 600 do cabeçalho das tabelas, 300, 500…) ou não
 * está instalada — escolhe pela fonte padrão das opções e guarda o resultado
 * numa variável `static` chaveada só pelo peso. Num processo que fica de pé
 * (Octane, fila), o primeiro PDF decidia para todos os seguintes: depois de uma
 * listagem em PDF (Helvetica) o documento (DejaVu Sans) saía em Helvetica e
 * "→", "≥", "▸" viravam "?"; depois de um documento, a listagem saía em DejaVu
 * e o motor direto da grade desistia.
 *
 * Aqui a reserva é resolvida com a fonte padrão DESTE PDF (mesma escolha do
 * Dompdf), com cache da instância. A busca por família nomeada continua no
 * `getFont` do Dompdf: ela não depende das opções.
 *
 * O segundo vazamento é o valor inicial de `font-family` (`Style::$_defaults`,
 * também `static`): ele é lido da fonte padrão do PRIMEIRO Dompdf do processo.
 * Texto sem `font-family` declarada herdava a fonte do PDF anterior. O
 * `install()` fixa a fonte padrão deste PDF na raiz com uma regra de agente do
 * usuário — a mais fraca da cascata: qualquer `font-family` do HTML vence.
 */
final class PdfFontMetrics extends FontMetrics
{
    /** @var array<string, string|null> fonte padrão|peso → arquivo de fonte */
    private array $fallback = [];

    /**
     * Prepara um Dompdf recém-criado (antes do loadHtml): troca o FontMetrics
     * (o Stylesheet guarda a própria referência no construtor do Dompdf: troca
     * nos dois) e fixa a fonte padrão na raiz.
     */
    public static function install(Dompdf $dompdf): Dompdf
    {
        $fm = new self($dompdf->getCanvas(), $dompdf->getOptions());
        $dompdf->setFontMetrics($fm);
        $dompdf->getCss()->setFontMetrics($fm);

        $font = str_replace(['"', '\\', '<', '>', '{', '}', ';'], '', (string) $dompdf->getOptions()->getDefaultFont());
        if ($font !== '') {
            $css = 'html{font-family:"' . $font . '"}';
            $dompdf->getCss()->load_css($css, Stylesheet::ORIG_UA);
        }

        return $dompdf;
    }

    public function getFont($familyRaw, $subtypeRaw = 'normal')
    {
        if ($familyRaw !== null) {
            return parent::getFont($familyRaw, $subtypeRaw);
        }

        $default = strtolower((string) $this->getOptions()->getDefaultFont());
        $subtype = strtolower((string) ($subtypeRaw ?: 'normal'));
        $key = $default . '|' . $subtype;
        if (!array_key_exists($key, $this->fallback)) {
            $this->fallback[$key] = $this->resolveFallback([$default, 'serif'], $subtype);
        }

        return $this->fallback[$key];
    }

    /** A escolha do `FontMetrics::getFont(null, …)` do Dompdf 3, passo a passo. */
    private function resolveFallback(array $names, string $subtype): ?string
    {
        $families = $this->getFontFamilies();
        foreach ($names as $name) {
            if (isset($families[$name][$subtype])) {
                return $families[$name][$subtype];
            }
            if (!isset($families[$name])) {
                continue;
            }
            $family = $families[$name];
            foreach ($family as $sub => $font) {
                if (strpos($subtype, $sub) !== false) {
                    return $font;
                }
            }
            if ($subtype !== 'normal') {
                foreach ($family as $sub => $font) {
                    if ($sub !== 'normal') {
                        return $font;
                    }
                }
            }
            $subtype = 'normal';
            if (isset($family[$subtype])) {
                return $family[$subtype];
            }
        }

        return null;
    }
}
