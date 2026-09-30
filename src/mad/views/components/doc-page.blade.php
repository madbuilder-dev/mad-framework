{{-- MadBuilder <mad-doc-page> — root wrapper for a generated document.
     Emits a full <!DOCTYPE html> so the inner slot can be piped straight
     into DOMPDF (Mad\Doc\MadDocPdf::fromView()). All other <mad-doc-*>
     blocks assume they live inside this component — they inherit the
     page's font and colors via CSS cascade.

     Paper size: a named preset (A4, A5, Letter, Legal) combined with
     `orientation`, or `size="custom"` with `width-mm` + `height-mm` — the
     page is then exactly width × height (orientation is ignored; swap the
     two numbers to turn the page). Custom dimensions outside 10–2000 mm
     fall back to A4.

     Margins accept two forms:
       margins="20,15,20,15"   → CSS `top right bottom left` in mm
       margins="20"            → uniform
     Font size is in pt. --}}

@props([
    'size' => 'A4',
    'orientation' => 'portrait',
    'widthMm' => null,
    'heightMm' => null,
    'margins' => '20,15,20,15',
    'font' => 'DejaVu Sans',
    'fontSize' => 11,
    'customCss' => '',
    'watermarkText' => '',
    'watermarkOpacity' => 0.1,
    'watermarkAngle' => -30,
    'watermarkColor' => '#94a3b8',
])

@php
    // Normalize margins → array of 4 ints (mm).
    $parts = array_map('trim', explode(',', (string) $margins));
    if (count($parts) === 1) {
        $m = [(int) $parts[0], (int) $parts[0], (int) $parts[0], (int) $parts[0]];
    } else {
        $m = [
            (int) ($parts[0] ?? 20),
            (int) ($parts[1] ?? 15),
            (int) ($parts[2] ?? 20),
            (int) ($parts[3] ?? 15),
        ];
    }

    // Sanitize size / orientation → the CSS `@page { size }` value.
    $orient = $orientation === 'landscape' ? 'landscape' : 'portrait';
    $customMm = static function ($v): ?float {
        if (! is_numeric($v)) return null;
        $n = round((float) $v, 1);
        return ($n >= 10 && $n <= 2000) ? $n : null;
    };
    $customW = $customMm($widthMm);
    $customH = $customMm($heightMm);
    if (strtolower((string) $size) === 'custom' && $customW !== null && $customH !== null) {
        $pageSize = "{$customW}mm {$customH}mm";
    } else {
        $paper = in_array($size, ['A4', 'A5', 'Letter', 'Legal'], true) ? $size : 'A4';
        $pageSize = "{$paper} {$orient}";
    }

    // Quote the font family safely (allow letters, numbers, space, hyphen).
    $fontSafe = preg_match('/^[A-Za-z][A-Za-z0-9 \-]{0,40}$/', $font) ? $font : 'DejaVu Sans';
    $fontPt = max(6, min(36, (int) $fontSize));

    // Cabeçalho/rodapé repetidos: a margem da página RESERVA a altura de cada
    // banda (margem + banda, como a prévia do editor). As bandas já foram
    // desenhadas no slot e anunciam a própria altura em data-mad-doc-band-mm;
    // elas sobem/descem essa medida para a faixa reservada. Sem isto o
    // cabeçalho (position:fixed; top:0 — o Dompdf ancora o fixed na área de
    // conteúdo) caía em cima do corpo.
    $bandMm = ['header' => 0.0, 'footer' => 0.0];
    if (preg_match_all('/data-mad-doc-band="(header|footer)"\s+data-mad-doc-band-mm="([0-9]+(?:\.[0-9]+)?)"/', (string) ($slot ?? ''), $bandHits, PREG_SET_ORDER)) {
        foreach ($bandHits as $hit) {
            $bandMm[$hit[1]] = max($bandMm[$hit[1]], (float) $hit[2]);
        }
    }
    $mm = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    $marginTop    = $mm($m[0] + $bandMm['header']);
    $marginBottom = $mm($m[2] + $bandMm['footer']);

    $wmEnabled = trim((string) $watermarkText) !== '';
    $wmColor = preg_match('/^#[0-9a-fA-F]{3,6}$/', $watermarkColor) ? $watermarkColor : '#94a3b8';
    $wmOpacity = max(0.0, min(1.0, (float) $watermarkOpacity));
    $wmAngle = max(-90, min(90, (int) $watermarkAngle));
@endphp

<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page {
        size: {{ $pageSize }};
        margin: {{ $marginTop }}mm {{ $m[1] }}mm {{ $marginBottom }}mm {{ $m[3] }}mm;
    }
    body {
        font-family: "{{ $fontSafe }}", sans-serif;
        font-size: {{ $fontPt }}pt;
        color: #111;
        margin: 0;
        line-height: 1.4;
    }
    main { position: relative; }
    p { margin: 0 0 0.3em 0; }
    h1, h2, h3, h4 { margin: 0 0 0.2em 0; font-weight: 700; }
    h1 { font-size: 1.6em; } h2 { font-size: 1.35em; }
    h3 { font-size: 1.15em; } h4 { font-size: 1em; }

    /* Lists — DOMPDF needs explicit list-style to render markers. */
    ul, ol { margin: 0 0 0.3em 0; padding-left: 1.5em; }
    ul { list-style: disc outside; } ol { list-style: decimal outside; }
    li { display: list-item; margin: 0 0 2px 0; }

    /* CSS counters for <mad-doc-page-number>. */
    .mad-page:after  { content: counter(page); }
    .mad-pages:after { content: counter(pages); }

    hr { border: none; border-top: 1px solid #d1d5db; margin: 0.5em 0; }
    a { color: #2563eb; text-decoration: underline; }
    strong { font-weight: 700; } em { font-style: italic; }

    /* User custom CSS (appended last so it can override anything). */
    {!! $customCss !!}
</style>
</head>
<body>
@if($wmEnabled)
<div class="mad-doc-watermark"
     style="position:fixed;top:40%;left:0;right:0;text-align:center;
            font-size:64pt;color:{{ $wmColor }};opacity:{{ $wmOpacity }};
            transform:rotate({{ $wmAngle }}deg);z-index:-1;font-weight:700;
            letter-spacing:8px;pointer-events:none;user-select:none;">
    {{ $watermarkText }}
</div>
@endif
<main>
{!! $slot !!}
</main>
</body>
</html>
