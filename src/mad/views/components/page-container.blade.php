@php
    $class = $class ?? '';
    $style = $style ?? '';
    // Tela embutida num <mad-transporter> (X-Mad-Embed): a página de fora já é
    // o cartão. Sem `mad-page-container` — borda, sombra, padding e a altura
    // mínima de tela cheia (e as variantes .mad-layout-*) não se repetem dentro
    // dela. `header="full"` no transporter mantém o casco completo.
    $_embed = \Mad\Ui\MadTransporter::embedHeader();
    $_shell = in_array($_embed, ['compact', 'title'], true) ? 'mad-page-embed' : 'mad-page-container';
@endphp
<div class="mad-ui {{ $_shell }} {{ $class }}" @if($style) style="{{ $style }}" @endif>
    {!! $slot !!}
</div>
