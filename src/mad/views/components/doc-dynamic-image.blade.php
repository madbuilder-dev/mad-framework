{{-- Dynamic image bound to a model attribute.
     Usage: mad-doc-dynamic-image(:src="$record->foto", width-mm=40, fit=contain, alt="Foto").
     `src` aceita o que um app grava numa coluna de imagem: chave do disco de
     uploads (campo Upload/Imagem gravando em disco), base64 com ou sem o
     prefixo `data:` (gravação no banco), URL http(s) e caminho absoluto. O
     Dompdf não busca nada sozinho (isRemoteEnabled desligado): o valor vira
     data URI em Mad\Doc\MadDocImage, com as travas de SSRF para a URL (a URL
     que o app não busca segue crua, como antes). Valor que não vira imagem
     mostra o texto alternativo; coluna vazia não desenha nada (o layout fecha
     o buraco). --}}
@props([
    'src' => null,
    'widthMm' => 40,
    'align' => 'left',
    'fit' => 'contain',
    'alt' => '',
])

@php
    $alignSafe = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    $fitSafe = in_array($fit, ['contain', 'cover'], true) ? $fit : 'contain';
    $width = max(1, min(500, (int) $widthMm));
    $hasValue = is_array($src) ? $src !== [] : (is_scalar($src) && trim((string) $src) !== '');
    $imgSrc = $hasValue ? \Mad\Doc\MadDocImage::src($src) : '';
@endphp

@if($imgSrc !== '')
<div style="text-align:{{ $alignSafe }};margin:2pt 0;">
    <img src="{{ $imgSrc }}" alt="{{ $alt }}"
         style="width:{{ $width }}mm;max-width:100%;object-fit:{{ $fitSafe }};" />
</div>
@elseif($hasValue && trim((string) $alt) !== '')
<div style="text-align:{{ $alignSafe }};margin:2pt 0;font-size:9pt;color:#6b7280;">{{ $alt }}</div>
@endif
