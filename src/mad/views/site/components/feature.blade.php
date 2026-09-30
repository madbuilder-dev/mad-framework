{{--
    <mad-site-feature> — um recurso dentro de <mad-site-features>.

    `icon` aceita os nomes do partial do kit (check, arrow, menu, x, star);
    nome desconhecido simplesmente não desenha ícone. Texto mais longo vai no
    slot, abaixo do parágrafo.
--}}
@props([
    'icon'  => '',
    'title' => '',
    'text'  => '',
    'href'  => '',
    'class' => '',
])

<article class="site-feature {{ $class }}">
    @if($icon !== '')
        <div class="site-feature__icon">
            @include('site.partials.icon', ['name' => $icon, 'size' => 24])
        </div>
    @endif

    @if($title !== '')
        <h3>{{ $title }}</h3>
    @endif

    @if($text !== '')
        <p>{{ $text }}</p>
    @endif

    {{ $slot ?? '' }}

    @if($href !== '')
        <a href="{{ $href }}">{{ mad_t('mad.site_learn_more') }}</a>
    @endif
</article>
