{{--
    <mad-site-hero> — a primeira dobra: o que o produto faz, para quem, e o
    que fazer agora.

    Dois botões no máximo, e o segundo é sempre o caminho de quem ainda não
    está pronto para comprar ("Falar com vendas"). Tudo é opcional: sem
    título o bloco não aparece com um "Lorem ipsum" no ar.

    Texto livre (listas, selos, formulário curto) vai no slot.
--}}
@props([
    'eyebrow'        => '',
    'title'          => '',
    'subtitle'       => '',
    'primaryLabel'   => '',
    'primaryHref'    => '',
    'secondaryLabel' => '',
    'secondaryHref'  => '',
    'image'          => '',
    'imageAlt'       => '',
    'class'          => '',
    'id'             => '',
])

@php
    // Caminho relativo ("images/print.png") vira URL pública; absoluto e
    // data: passam verbatim — mesma regra do <mad-image>.
    $__heroImg = trim((string) $image);
    if ($__heroImg !== ''
        && ! str_starts_with($__heroImg, 'data:')
        && ! str_starts_with($__heroImg, '//')
        && ! preg_match('#^https?://#i', $__heroImg)) {
        $__heroImg = asset(ltrim($__heroImg, '/'));
    }
@endphp

<section class="site-hero {{ $class }}" @if($id) id="{{ $id }}" @endif>
    <div class="site-container">
        <div>
            @if($eyebrow !== '')
                <p class="site-hero__eyebrow">{{ $eyebrow }}</p>
            @endif

            @if($title !== '')
                <h1 class="site-hero__title">{{ $title }}</h1>
            @endif

            @if($subtitle !== '')
                <p class="site-hero__subtitle">{{ $subtitle }}</p>
            @endif

            {{ $slot ?? '' }}

            @if($primaryLabel !== '' || $secondaryLabel !== '')
                <div class="site-hero__actions">
                    @if($primaryLabel !== '')
                        <a class="site-btn site-btn--primary" href="{{ $primaryHref }}">{{ $primaryLabel }}</a>
                    @endif
                    @if($secondaryLabel !== '')
                        <a class="site-btn site-btn--ghost" href="{{ $secondaryHref }}">{{ $secondaryLabel }}</a>
                    @endif
                </div>
            @endif
        </div>

        @if($__heroImg !== '')
            <div class="site-hero__media">
                <img src="{{ $__heroImg }}" alt="{{ $imageAlt }}" loading="eager">
            </div>
        @endif
    </div>
</section>
