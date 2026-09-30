{{-- QR code rendered inline via BaconQrCode + Imagick (both present in composer).
     Usage: mad-doc-qrcode(data="https://app/order/123", size-mm=25, ec-level=M, align=left).
     The PNG is base64-embedded as a data URI, so DOMPDF doesn't need
     filesystem access. --}}
@props([
    'data' => '',
    'sizeMm' => 25,
    'ecLevel' => 'M',
    'align' => 'left',
])

@php
    use BaconQrCode\Renderer\ImageRenderer;
    use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
    use BaconQrCode\Renderer\Image\SvgImageBackEnd;
    use BaconQrCode\Renderer\RendererStyle\RendererStyle;
    use BaconQrCode\Writer;
    use BaconQrCode\Common\ErrorCorrectionLevel;

    $alignSafe = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    $size = max(10, min(80, (int) $sizeMm));
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
            // Usa Imagick quando disponivel (PNG mais compacto);
            // caso contrario cai para SVG puro (zero dependencia).
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
<div style="text-align:{{ $alignSafe }};margin:2pt 0;">
    <img src="{{ $dataUri }}"
         style="display:inline-block;width:{{ $size }}mm;height:{{ $size }}mm;" alt="QR" />
</div>
@endif
