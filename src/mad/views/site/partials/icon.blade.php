{{--
    Ícone inline do kit de site.

    SVG escrito no HTML, não fonte de ícone nem <script> de terceiro: uma
    landing page que espera o Lucide carregar mostra quadrados vazios no
    primeiro paint — justo na dobra que decide se o visitante fica.

    Uso:
        @include('site.partials.icon', ['name' => 'check'])
        @include('site.partials.icon', ['name' => 'arrow', 'size' => 16])

    Nome desconhecido não desenha nada (e não quebra a página).
--}}
@php
    $__iconName  = (string) ($name ?? '');
    $__iconSize  = (string) ($size ?? 20);
    $__iconSize  = is_numeric($__iconSize) ? $__iconSize : '20';
    $__iconClass = trim((string) ($class ?? ''));

    // Traçados do Lucide (ISC), copiados para não depender do CDN em runtime.
    $__iconPaths = [
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'arrow' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'menu'  => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
        'x'     => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'star'  => '<path d="M11.5 3.2a.6.6 0 0 1 1 0l2.2 4.5 4.9.7c.5.1.7.7.3 1l-3.5 3.4.8 4.9c.1.5-.4.9-.9.7L12 16l-4.4 2.3c-.4.2-.9-.2-.8-.7l.8-4.9L4 9.4c-.4-.3-.2-.9.3-1l4.9-.7z"/>',
    ];
@endphp
@if(isset($__iconPaths[$__iconName]))
<svg class="{{ $__iconClass }}" width="{{ $__iconSize }}" height="{{ $__iconSize }}" viewBox="0 0 24 24"
     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true" focusable="false">{!! $__iconPaths[$__iconName] !!}</svg>
@endif
