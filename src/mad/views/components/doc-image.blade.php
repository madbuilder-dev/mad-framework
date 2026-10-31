{{-- <mad-doc-image src="images/logo.png" width-mm="40" align="left" fit="contain" />
     `src` aceita: data URI; arquivo do próprio app em `public/`
     ("images/logo.png", "/images/logo.png" ou o caminho completo dentro de
     `public/`); `app:logo` — a logo do sistema, a mesma do cabeçalho/login do
     app; e a chave do disco de uploads. O Dompdf roda com `chroot` em
     `storage/app` e não lê `public/`: o valor vira data URI em
     Mad\Doc\MadDocImage (só imagem raster pelos bytes, com teto de tamanho).
     URL http(s) continua recusada: a imagem estática não busca nada na rede
     (imagem de dado com URL: <mad-doc-dynamic-image>). Caminho absoluto fora
     de `public/` segue cru para o Dompdf, como antes. --}}
@props([
    'src' => '',
    'widthMm' => 40,
    'align' => 'left',
    'fit' => 'contain',
    'alt' => '',
])

@php
    $srcStr = trim((string) $src);
    $widthSafe = max(5, min(250, (int) $widthMm));
    $alignSafe = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    $fitSafe = $fit === 'cover' ? 'cover' : 'contain';

    // Reject remote URLs — DOMPDF's isRemoteEnabled is off by default and the
    // static image never fetches anything itself.
    $isSafe = true;
    if ($srcStr !== '' && ! str_starts_with($srcStr, 'data:image/')) {
        $scheme = strtolower(parse_url($srcStr, PHP_URL_SCHEME) ?? '');
        if (in_array($scheme, ['http', 'https'], true)) {
            $isSafe = false;
        }
    }
    $imgSrc = $srcStr !== '' && $isSafe ? \Mad\Doc\MadDocImage::src($srcStr) : '';
@endphp

@if($imgSrc !== '')
<div style="text-align:{{ $alignSafe }};margin:2pt 0;">
    <img src="{{ $imgSrc }}" alt="{{ $alt }}"
         style="width:{{ $widthSafe }}mm;max-width:100%;object-fit:{{ $fitSafe }};">
</div>
@elseif($srcStr !== '' && $isSafe && trim((string) $alt) !== '')
<div style="text-align:{{ $alignSafe }};margin:2pt 0;font-size:9pt;color:#6b7280;">{{ $alt }}</div>
@else
<div style="font-size:9pt;color:#9ca3af;font-style:italic;">{{ mad_t('mad.ui.image_no_source') }}</div>
@endif
