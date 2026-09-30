{{--
    <mad-site-footer> — o rodapé.

    As colunas (links, contato, redes) vêm do slot. O que o componente
    garante sozinho é a linha legal: o © com o ano corrente e os links de
    Termos e Privacidade — mas só quando essas páginas EXISTEM. Link para
    /termos numa 404 é pior do que rodapé sem link: sinaliza descuido logo no
    lugar em que o visitante procura seriedade.
--}}
@props([
    'brand'       => '',
    'note'        => '',
    'termsHref'   => '',
    'privacyHref' => '',
    'year'        => '',
    'class'       => '',
    'id'          => '',
])

@php
    $__brand = trim((string) $brand);
    if ($__brand === '' && class_exists(\App\Support\Site\SiteBranding::class)) {
        try {
            $__brand = (string) \App\Support\Site\SiteBranding::name();
        } catch (\Throwable $e) {
            $__brand = '';
        }
    }

    $__year = trim((string) $year);
    if ($__year === '') { $__year = date('Y'); }

    // Rota registrada? `Route::has` é a única pergunta honesta: as páginas de
    // site nascem com o nome `site.<slug>` (MadRoutes::exposeSite).
    $__routeHas = function (string $name): bool {
        try {
            return \Illuminate\Support\Facades\Route::has($name);
        } catch (\Throwable $e) {
            return false;
        }
    };

    $__terms = trim((string) $termsHref);
    if ($__terms === '' && $__routeHas('site.termos')) { $__terms = site_url('/termos'); }

    $__privacy = trim((string) $privacyHref);
    if ($__privacy === '' && $__routeHas('site.privacidade')) { $__privacy = site_url('/privacidade'); }
@endphp

<footer class="site-footer {{ $class }}" @if($id) id="{{ $id }}" @endif>
    <div class="site-container">
        <div class="site-footer__cols">
            {{ $slot ?? '' }}
        </div>

        <div class="site-footer__legal">
            <span>© {{ $__year }} {{ $__brand }} — {{ mad_t('mad.site_all_rights') }}</span>

            @if($__terms !== '')
                <a href="{{ $__terms }}">{{ mad_t('mad.site_terms') }}</a>
            @endif

            @if($__privacy !== '')
                <a href="{{ $__privacy }}">{{ mad_t('mad.site_privacy') }}</a>
            @endif

            @if($note !== '')
                <span>{{ $note }}</span>
            @endif
        </div>
    </div>
</footer>
