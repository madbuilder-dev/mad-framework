{{--
    <mad-site-nav> — barra de navegação do site público.

    Sem props, ela se monta sozinha: a marca vem do tema do app
    (SiteBranding) e os links vêm das páginas de site que pediram lugar no
    menu (SiteRegistry). Quem quiser outro conjunto passa `:items` com
    [['label' => ..., 'href' => ...], ...] ou escreve os <a> no slot.

    O botão do menu é um <button> de verdade com aria-expanded — `site.js`
    só alterna a classe. Sem JS o menu continua na página (o CSS mostra os
    links empilhados), porque um site que não abre o menu sem JavaScript
    perde o visitante antes da primeira rolagem.
--}}
@props([
    'brand'     => '',
    'logo'      => '',
    'homeHref'  => '',
    'items'     => null,
    'ctaLabel'  => '',
    'ctaHref'   => '',
    'class'     => '',
    'id'        => '',
])

@php
    // Marca: prop > tema do app > vazio. `class_exists` porque o site pode
    // rodar num app sem a camada de branding (framework antigo).
    $__navBrand = trim((string) $brand);
    $__navLogo  = trim((string) $logo);
    if (class_exists(\App\Support\Site\SiteBranding::class)) {
        try {
            if ($__navBrand === '') { $__navBrand = (string) \App\Support\Site\SiteBranding::name(); }
            if ($__navLogo === '')  { $__navLogo  = (string) (\App\Support\Site\SiteBranding::logoUrl() ?? ''); }
        } catch (\Throwable $e) {
            // branding indisponível — a barra continua com o texto que houver
        }
    }

    $__navHome = trim((string) $homeHref);
    if ($__navHome === '') { $__navHome = site_url('/'); }

    // Links: prop > registro das páginas de site > nenhum.
    $__navItems = is_array($items) ? $items : null;
    if ($__navItems === null && class_exists(\Mad\Site\SiteRegistry::class)) {
        try {
            $__navItems = \Mad\Site\SiteRegistry::navItems();
        } catch (\Throwable $e) {
            $__navItems = [];
        }
    }
    $__navItems = is_array($__navItems) ? $__navItems : [];

    // Logo relativo ("images/logo.png") vira URL pública; absoluto passa direto.
    $__navLogoSrc = $__navLogo;
    if ($__navLogoSrc !== ''
        && ! str_starts_with($__navLogoSrc, 'data:')
        && ! str_starts_with($__navLogoSrc, '//')
        && ! preg_match('#^https?://#i', $__navLogoSrc)) {
        $__navLogoSrc = asset(ltrim($__navLogoSrc, '/'));
    }
@endphp

<header class="site-nav {{ $class }}" @if($id) id="{{ $id }}" @endif>
    <div class="site-container">
        <a class="site-nav__brand" href="{{ $__navHome }}">
            @if($__navLogoSrc !== '')
                <img src="{{ $__navLogoSrc }}" alt="{{ $__navBrand }}">
            @endif
            @if($__navBrand !== '')
                <span>{{ $__navBrand }}</span>
            @endif
        </a>

        <button type="button" class="site-nav__toggle" data-site-nav-toggle
                aria-expanded="false" aria-controls="site-nav-links"
                aria-label="{{ mad_t('mad.site_menu') }}">
            @include('site.partials.icon', ['name' => 'menu'])
        </button>

        <nav class="site-nav__links" id="site-nav-links" aria-label="{{ mad_t('mad.site_nav_label') }}">
            @foreach($__navItems as $__item)
                @php
                    $__label = trim((string) ($__item['label'] ?? ''));
                    $__href  = (string) ($__item['href'] ?? '');
                @endphp
                @if($__label !== '')
                    <a href="{{ $__href }}">{{ $__label }}</a>
                @endif
            @endforeach

            {{ $slot ?? '' }}

            @if($ctaLabel !== '')
                <a class="site-btn site-btn--primary" href="{{ $ctaHref }}">{{ $ctaLabel }}</a>
            @endif
        </nav>
    </div>
</header>
