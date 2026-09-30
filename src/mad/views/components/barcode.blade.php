{{-- Barcode para tela via picqer/php-barcode-generator (composer-provided).
     Versao screen-friendly do <mad-doc-barcode> (que e mm-based, para PDF).
     Usage: <mad-barcode data="PT0046.012.345" format="C128" :width="280" :height="60" />
     Formatos: C128 (default), C39, EAN13, EAN8, UPCA. Fallback para C128 se desconhecido.
     PNG embedado como data URI. --}}
@props([
    'data' => '',
    'format' => 'C128',
    'width' => 240,
    'height' => 60,
    'showText' => true,
    'align' => 'left',
    'alt' => 'Barcode',
    'class' => '',
])

@php
    use Picqer\Barcode\BarcodeGeneratorPNG;

    $alignSafe = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    $widthPx  = max(40, min(900, (int) $width));
    $heightPx = max(20, min(300, (int) $height));
    $text = (string) $data;

    $typeMap = [
        'C128'  => BarcodeGeneratorPNG::TYPE_CODE_128,
        'C39'   => BarcodeGeneratorPNG::TYPE_CODE_39,
        'EAN13' => BarcodeGeneratorPNG::TYPE_EAN_13,
        'EAN8'  => BarcodeGeneratorPNG::TYPE_EAN_8,
        'UPCA'  => BarcodeGeneratorPNG::TYPE_UPC_A,
    ];
    $type = $typeMap[(string) $format] ?? BarcodeGeneratorPNG::TYPE_CODE_128;

    $dataUri = '';
    if ($text !== '') {
        try {
            $gen = new BarcodeGeneratorPNG();
            // width-factor 2, height ~40px — re-sized via CSS para o tamanho final.
            $png = $gen->getBarcode($text, $type, 2, 40);
            $dataUri = 'data:image/png;base64,' . base64_encode($png);
        } catch (\Throwable $e) {
            $dataUri = '';
        }
    }
@endphp

@if($dataUri !== '')
<div class="mad-barcode {{ $class }}" style="text-align:{{ $alignSafe }};">
    <img src="{{ $dataUri }}"
         style="display:inline-block;width:{{ $widthPx }}px;height:{{ $heightPx }}px;"
         alt="{{ $alt }}" />
    @if($showText)
        <div style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
                    font-size:12px;color:var(--mad-text,#111);
                    text-align:{{ $alignSafe }};margin-top:4px;letter-spacing:0.02em;">
            {{ $text }}
        </div>
    @endif
</div>
@endif
