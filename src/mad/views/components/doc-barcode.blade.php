{{-- Barcode via picqer/php-barcode-generator (composer-provided).
     Usage: mad-doc-barcode(data="PT0046.012.345", format=C128, width-mm=60, height-mm=15, show-text=true).
     PNG is base64-inlined as a data URI so DOMPDF doesn't hit the
     filesystem. Unknown formats fall back to Code 128. --}}
@props([
    'data' => '',
    'format' => 'C128',
    'widthMm' => 60,
    'heightMm' => 15,
    'showText' => true,
    'align' => 'left',
])

@php
    use Picqer\Barcode\BarcodeGeneratorPNG;

    $alignSafe = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    $w = max(10, min(250, (int) $widthMm));
    $h = max(5, min(60, (int) $heightMm));
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
            // width-factor 2, height ~40px — re-sized by CSS below.
            $png = $gen->getBarcode($text, $type, 2, 40);
            $dataUri = 'data:image/png;base64,' . base64_encode($png);
        } catch (\Throwable $e) {
            $dataUri = '';
        }
    }
@endphp

@if($dataUri !== '')
<div style="text-align:{{ $alignSafe }};margin:2pt 0;">
    <img src="{{ $dataUri }}"
         style="display:inline-block;width:{{ $w }}mm;height:{{ $h }}mm;" alt="Barcode" />
    @if($showText)
        <div style="font-family:monospace;font-size:9pt;color:#111;
                    text-align:{{ $alignSafe }};margin-top:1pt;">
            {{ $text }}
        </div>
    @endif
</div>
@endif
