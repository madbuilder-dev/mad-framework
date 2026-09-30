{{--
    <mad-site-blog-list> — o índice do blog.

    `posts` aceita o que o controller tiver em mãos: o paginador do Eloquent,
    uma coleção, um array de modelos ou um array de arrays. Sem nenhum post
    publicado, a página diz isso em uma frase em vez de mostrar uma faixa
    vazia — blog "vazio por engano" e blog "ainda sem artigos" parecem a mesma
    coisa para quem visita.

    A paginação sai como dois links simples (mais recentes / mais antigos):
    é o suficiente para o visitante e para o robô de busca, e não depende de
    nenhuma view de paginação do app.
--}}
@props([
    'id'       => '',
    'posts'    => null,
    'title'    => '',
    'lead'     => '',
    'baseHref' => '',
    'tone'     => 'plain',
    'class'    => '',
])

@php
    $__base = trim((string) $baseHref);
    if ($__base === '') { $__base = site_url('/blog'); }
    $__base = rtrim($__base, '/');

    // Normaliza a fonte para uma lista iterável, sem assumir Eloquent.
    $__items = $posts;
    if ($__items instanceof \Illuminate\Contracts\Support\Arrayable && ! is_array($__items)) {
        try { $__items = $__items->all(); } catch (\Throwable $e) { $__items = []; }
    }
    if (! is_array($__items) && $__items instanceof \Traversable) {
        $__items = iterator_to_array($__items);
    }
    $__items = is_array($__items) ? $__items : [];

    /** Lê um campo tanto de model quanto de array — o kit não escolhe por você. */
    $__field = function ($post, string $key, $default = '') {
        if (is_array($post)) {
            return $post[$key] ?? $default;
        }
        if (is_object($post)) {
            try { return $post->{$key} ?? $default; } catch (\Throwable $e) { return $default; }
        }
        return $default;
    };

    $__date = function ($value): array {
        $raw = trim((string) $value);
        if ($raw === '') {
            return ['iso' => '', 'label' => ''];
        }
        try {
            $dt = new \DateTimeImmutable($raw);
            return ['iso' => $dt->format('Y-m-d'), 'label' => $dt->format('d/m/Y')];
        } catch (\Throwable $e) {
            return ['iso' => '', 'label' => $raw];
        }
    };

    $__image = function ($value): string {
        $src = trim((string) $value);
        if ($src === ''
            || str_starts_with($src, 'data:')
            || str_starts_with($src, '//')
            || preg_match('#^https?://#i', $src)) {
            return $src;
        }
        return asset(ltrim($src, '/'));
    };

    // Paginador? Só perguntamos pelos dois links que usamos.
    $__prevUrl = '';
    $__nextUrl = '';
    if (is_object($posts) && method_exists($posts, 'previousPageUrl') && method_exists($posts, 'nextPageUrl')) {
        try {
            $__prevUrl = (string) ($posts->previousPageUrl() ?? '');
            $__nextUrl = (string) ($posts->nextPageUrl() ?? '');
        } catch (\Throwable $e) {
            $__prevUrl = $__nextUrl = '';
        }
    }
@endphp

<section class="site-section {{ trim(($tone === 'tint' ? 'site-section--tint ' : '') . $class) }}"
         @if($id) id="{{ $id }}" @endif>
    <div class="site-container">
        <h2>{{ $title !== '' ? $title : mad_t('mad.site_blog_title') }}</h2>

        @if($lead !== '')
            <p>{{ $lead }}</p>
        @endif

        <div class="site-blog-list site-grid-3">
            @forelse($__items as $__post)
                @php
                    $__slug  = (string) $__field($__post, 'slug');
                    $__pDate = $__date($__field($__post, 'published_at'));
                    $__cover = $__image($__field($__post, 'cover_image'));
                @endphp
                <article class="site-blog-card">
                    @if($__cover !== '')
                        <a href="{{ $__base }}/{{ $__slug }}">
                            <img src="{{ $__cover }}" alt="" loading="lazy">
                        </a>
                    @endif

                    <h3><a href="{{ $__base }}/{{ $__slug }}">{{ $__field($__post, 'title') }}</a></h3>

                    @if($__pDate['label'] !== '')
                        <p class="site-blog-post__meta">
                            <time @if($__pDate['iso'] !== '') datetime="{{ $__pDate['iso'] }}" @endif>{{ $__pDate['label'] }}</time>
                        </p>
                    @endif

                    @if((string) $__field($__post, 'excerpt') !== '')
                        <p>{{ $__field($__post, 'excerpt') }}</p>
                    @endif

                    <a href="{{ $__base }}/{{ $__slug }}">{{ mad_t('mad.site_blog_read') }}</a>
                </article>
            @empty
                <p>{{ mad_t('mad.site_blog_empty') }}</p>
            @endforelse
        </div>

        @if($__prevUrl !== '' || $__nextUrl !== '')
            <nav>
                @if($__prevUrl !== '')
                    <a class="site-btn site-btn--ghost" href="{{ $__prevUrl }}">{{ mad_t('mad.site_blog_newer') }}</a>
                @endif
                @if($__nextUrl !== '')
                    <a class="site-btn site-btn--ghost" href="{{ $__nextUrl }}">{{ mad_t('mad.site_blog_older') }}</a>
                @endif
            </nav>
        @endif

        {{ $slot ?? '' }}
    </div>
</section>
