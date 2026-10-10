{{-- Casco das páginas PÚBLICAS (site do produto).

     Recebe de MadSitePage::showPage():
       $seo  — title, description, og_image, noindex, canonical, jsonld
       $body — o HTML da página já renderizado
       $nav  — itens de menu do SiteRegistry (usados pelos componentes do site)

     Monta o cabeçalho que os buscadores e as redes sociais leem, e carrega SÓ
     o CSS/JS do site (o do painel entra apenas quando a página pede).

     ⚠️ Nada de `@media`/`@keyframes` no <style> daqui: `@` seguido de letra é
     diretiva do compilador Blade. Regra de tela mora no mad-site.css. --}}
@php
    use App\Support\Site\SiteBranding;
    use Mad\Site\MadSiteAssets;

    $seo = is_array($seo ?? null) ? $seo : [];

    $brandName = class_exists(SiteBranding::class) ? SiteBranding::name() : 'Sistema';
    $brandCor  = class_exists(SiteBranding::class) ? SiteBranding::brandColor() : '#1E55E8';
    $brandFg   = class_exists(SiteBranding::class) ? SiteBranding::brandFg() : '#ffffff';
    $logoUrl   = class_exists(SiteBranding::class) ? SiteBranding::logoUrl() : null;
    $favicon   = class_exists(SiteBranding::class) ? SiteBranding::faviconUrl() : null;

    // Trechos do dono do app (Header & Footer Tags, app/config/app.json via
    // config/mad.php do esqueleto). App sem a chave não imprime nada.
    $headerTags = (string) config('mad.general.header_tags', '');
    $footerTags = (string) config('mad.general.footer_tags', '');

    $tituloPagina = trim((string) ($seo['title'] ?? ''));
    $titulo       = $tituloPagina !== '' && $tituloPagina !== $brandName
        ? $tituloPagina . ' · ' . $brandName
        : ($tituloPagina !== '' ? $tituloPagina : $brandName);

    $descricao = trim((string) ($seo['description'] ?? ''));
    $noindex   = (bool) ($seo['noindex'] ?? false);
    $ogImagem  = trim((string) ($seo['og_image'] ?? ''));

    // Endereço canônico SEM query: `?utm_source=...` não cria página nova, e
    // sem isto cada campanha virava uma URL diferente aos olhos do buscador.
    $canonical = trim((string) ($seo['canonical'] ?? ''));
    if ($canonical === '') {
        $canonical = url()->current();
        // A inicial é "https://dominio/" — sem a barra, o buscador trata o
        // endereço como diferente do que aparece em todo link do site.
        if (parse_url($canonical, PHP_URL_PATH) === null) {
            $canonical .= '/';
        }
    }

    if ($ogImagem !== '' && ! str_starts_with($ogImagem, 'http')) {
        $ogImagem = url($ogImagem);
    } elseif ($ogImagem === '' && $logoUrl !== null) {
        $ogImagem = url($logoUrl);
    }

    $dados = [
        [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $brandName,
            'url'      => url('/'),
        ] + ($logoUrl !== null ? ['logo' => url($logoUrl)] : []),
        [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $brandName,
            'url'      => url('/'),
        ],
    ];
    foreach ((array) ($seo['jsonld'] ?? []) as $extra) {
        if (is_array($extra) && $extra !== []) {
            $dados[] = $extra;
        }
    }

    $vars = ':root{--site-brand:' . $brandCor . ';--site-brand-fg:' . $brandFg . ';}';

    // Token unificado (MadCsrf::token() === csrf_token()); o mad-livewire.js e
    // os formulários públicos leem daqui.
    try {
        $csrfToken = \Mad\Security\MadCsrf::token();
    } catch (\Throwable) {
        $csrfToken = '';
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- href vem do REQUEST, nunca "/" fixo: sob `Alias /projeto` o app roda em
         subpath e todo asset relativo desta página pende deste base. --}}
    <base href="{{ rtrim(url('/'), '/') }}/">
    <title>{{ $titulo }}</title>
@if($descricao !== '')
    <meta name="description" content="{{ $descricao }}">
@endif
@if($noindex)
    <meta name="robots" content="noindex,nofollow">
@else
    <meta name="robots" content="index,follow">
@endif
    <link rel="canonical" href="{{ $canonical }}">
@if($favicon !== null)
    <link rel="icon" href="{{ $favicon }}">
@endif

    {{-- Tipo do conteúdo: 'website' na maioria das páginas, 'article' num
         texto do blog (é o que faz o link virar card de artigo ao ser
         compartilhado). A página escolhe pelo seoArticle()/pageSeo. --}}
    <meta property="og:type" content="{{ trim((string) ($seo['og_type'] ?? '')) !== '' ? $seo['og_type'] : 'website' }}">
    <meta property="og:site_name" content="{{ $brandName }}">
    <meta property="og:title" content="{{ $tituloPagina !== '' ? $tituloPagina : $brandName }}">
@if($descricao !== '')
    <meta property="og:description" content="{{ $descricao }}">
@endif
    <meta property="og:url" content="{{ $canonical }}">
@if($ogImagem !== '')
    <meta property="og:image" content="{{ $ogImagem }}">
@endif
    <meta name="twitter:card" content="{{ $ogImagem !== '' ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $tituloPagina !== '' ? $tituloPagina : $brandName }}">
@if($descricao !== '')
    <meta name="twitter:description" content="{{ $descricao }}">
@endif
@if($ogImagem !== '')
    <meta name="twitter:image" content="{{ $ogImagem }}">
@endif

    <meta name="csrf-token" content="{{ $csrfToken }}">

    {{-- ORDEM IMPORTA: a folha do site também declara `:root{--site-brand:…}`
         com o azul padrão. Declarada DEPOIS, ela venceria a cor do tema e todo
         site sairia azul. Por isso a cor da marca vem por último. --}}
    {!! MadSiteAssets::renderHead() !!}
    <style>{!! $vars !!}</style>
    {{-- "Header & Footer Tags" das Propriedades do projeto (analytics, fonte,
         verificação do domínio): sem escape, no fim do <head>. --}}
    {!! $headerTags !!}
</head>
<body class="site">
{!! $body ?? ($componentHtml ?? '') !!}
@foreach($dados as $bloco)
    <script type="application/ld+json">{!! json_encode($bloco, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endforeach
{!! MadSiteAssets::renderBody() !!}
{!! $footerTags !!}
</body>
</html>
