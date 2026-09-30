{{--
    <mad-site-blog-post> — o artigo aberto.

    Duas coisas vivem aqui e em nenhum outro lugar:

     • **O corpo sai como HTML** (`{!! !!}`). É a ÚNICA saída não escapada do
       kit inteiro, e ela só é segura porque o texto já foi limpo na gravação
       (`<script>`, `on*=` e `javascript:` caem no save do post). Não aponte
       esta prop para conteúdo de origem desconhecida.
     • **O JSON-LD `BlogPosting` vai para o <head>** pelo mesmo canal da
       diretiva `@sitehead` — é o que faz o artigo aparecer com data e autor
       no resultado de busca. Como o corpo da página é renderizado antes do
       layout, o bloco chega ao <head> a tempo.
--}}
@props([
    'post'      => null,
    'title'     => '',
    'excerpt'   => '',
    'bodyHtml'  => '',
    'cover'     => '',
    'author'    => '',
    'published' => '',
    'backHref'  => '',
    'class'     => '',
    'id'        => '',
])

@php
    $__field = function ($source, string $key, $default = '') {
        if (is_array($source)) {
            return $source[$key] ?? $default;
        }
        if (is_object($source)) {
            try { return $source->{$key} ?? $default; } catch (\Throwable $e) { return $default; }
        }
        return $default;
    };

    $__title     = trim((string) $title)    !== '' ? (string) $title    : (string) $__field($post, 'title');
    $__excerpt   = trim((string) $excerpt)  !== '' ? (string) $excerpt  : (string) $__field($post, 'excerpt');
    $__body      = trim((string) $bodyHtml) !== '' ? (string) $bodyHtml : (string) $__field($post, 'body_html');
    $__cover     = trim((string) $cover)    !== '' ? (string) $cover    : (string) $__field($post, 'cover_image');
    $__published = trim((string) $published) !== '' ? (string) $published : (string) $__field($post, 'published_at');
    $__author    = trim((string) $author);

    if ($__cover !== ''
        && ! str_starts_with($__cover, 'data:')
        && ! str_starts_with($__cover, '//')
        && ! preg_match('#^https?://#i', $__cover)) {
        $__cover = asset(ltrim($__cover, '/'));
    }

    $__iso = '';
    $__dateLabel = '';
    if ($__published !== '') {
        try {
            $__dt        = new \DateTimeImmutable($__published);
            $__iso       = $__dt->format('c');
            $__dateLabel = $__dt->format('d/m/Y');
        } catch (\Throwable $e) {
            $__dateLabel = $__published;
        }
    }

    $__back = trim((string) $backHref);
    if ($__back === '') { $__back = site_url('/blog'); }

    // JSON-LD do artigo. Só o que temos de verdade entra: campo inventado em
    // dado estruturado é penalizado pelo buscador.
    $__jsonLd = array_filter([
        '@context'      => 'https://schema.org',
        '@type'         => 'BlogPosting',
        'headline'      => $__title,
        'description'   => $__excerpt,
        'datePublished' => $__iso,
        'image'         => $__cover,
        'author'        => $__author !== '' ? ['@type' => 'Person', 'name' => $__author] : null,
    ], static fn ($v) => $v !== null && $v !== '' && $v !== []);

    $__jsonLdTag = '';
    if (($__jsonLd['headline'] ?? '') !== '') {
        $__jsonLdTag = '<script type="application/ld+json">'
            . json_encode($__jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
            . '</script>';
    }

    // O bloco vai para o <head> pelo MESMO caminho da diretiva @sitehead
    // (MadSiteAssets::pushHead), mas chamado direto: a diretiva só existe no
    // compilador enquanto ele não é reconstruído, e o registro dela tem um
    // guard estático que não volta depois de um `MadBlade::configure()` — num
    // processo de vida longa (Octane, suíte de testes) o `@sitehead` sai como
    // texto literal e o dado estruturado do artigo some sem aviso.
    //
    // Sem a camada de assets do site, o bloco fica no corpo: JSON-LD dentro do
    // <body> é válido e indexado; o que não pode é ele não existir.
    $__jsonLdInline = $__jsonLdTag;
    if ($__jsonLdTag !== '' && class_exists(\Mad\Site\MadSiteAssets::class)) {
        try {
            \Mad\Site\MadSiteAssets::pushHead($__jsonLdTag);
            $__jsonLdInline = '';
        } catch (\Throwable $e) {
            $__jsonLdInline = $__jsonLdTag;
        }
    }
@endphp

<article class="site-blog-post {{ $class }}" @if($id) id="{{ $id }}" @endif>
    <div class="site-container">
        {!! $__jsonLdInline !!}

        <header>
            @if($__title !== '')
                <h1>{{ $__title }}</h1>
            @endif

            @if($__dateLabel !== '' || $__author !== '')
                <p class="site-blog-post__meta">
                    @if($__dateLabel !== '')
                        <time @if($__iso !== '') datetime="{{ $__iso }}" @endif>{{ mad_t('mad.site_published_on', ['date' => $__dateLabel]) }}</time>
                    @endif
                    @if($__author !== '')
                        <span>{{ $__author }}</span>
                    @endif
                </p>
            @endif
        </header>

        @if($__cover !== '')
            <img src="{{ $__cover }}" alt="" loading="lazy">
        @endif

        {{-- Único {!! !!} do kit: o corpo já foi sanitizado na gravação. --}}
        <div class="site-prose">{!! $__body !!}</div>

        {{ $slot ?? '' }}

        <p><a href="{{ $__back }}">{{ mad_t('mad.site_blog_title') }}</a></p>
    </div>
</article>
