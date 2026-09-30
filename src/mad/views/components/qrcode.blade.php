{{-- QR code para tela via BaconQrCode (composer-provided).
     Versao screen-friendly do <mad-doc-qrcode> (que e mm-based, para PDF).
     Usage: <mad-qrcode data="https://..." :size="200" ec-level="M" align="center" />
     PNG via Imagick quando disponivel; fallback SVG. Embedado como data URI. --}}
@props([
    'data' => '',
    'size' => 200,
    'ecLevel' => 'M',
    'align' => 'left',
    'alt' => 'QR',
    'class' => '',
])

@php
    use BaconQrCode\Renderer\ImageRenderer;
    use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
    use BaconQrCode\Renderer\Image\SvgImageBackEnd;
    use BaconQrCode\Renderer\RendererStyle\RendererStyle;
    use BaconQrCode\Writer;
    use BaconQrCode\Common\ErrorCorrectionLevel;

    $alignSafe = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    $sizePx = max(32, min(800, (int) $size));
    $text = (string) $data;

    $dataUri = '';
    if ($text !== '') {
        try {
            $ec = match ((string) $ecLevel) {
                'L' => ErrorCorrectionLevel::L(),
                'Q' => ErrorCorrectionLevel::Q(),
                'H' => ErrorCorrectionLevel::H(),
                default => ErrorCorrectionLevel::M(),
            };
            $backEnd = extension_loaded('imagick')
                ? new ImagickImageBackEnd()
                : new SvgImageBackEnd();
            $renderer = new ImageRenderer(new RendererStyle(400), $backEnd);
            $writer = new Writer($renderer);
            $out = $writer->writeString($text, 'UTF-8', $ec);
            $mime = extension_loaded('imagick') ? 'image/png' : 'image/svg+xml';
            $dataUri = 'data:' . $mime . ';base64,' . base64_encode($out);
        } catch (\Throwable $e) {
            $dataUri = '';
        }
    }
@endphp

@if($dataUri !== '')
<div class="mad-qrcode {{ $class }}" style="text-align:{{ $alignSafe }};">
    <img src="{{ $dataUri }}"
         style="display:inline-block;width:{{ $sizePx }}px;height:{{ $sizePx }}px;"
         alt="{{ $alt }}" />
</div>
@endif
