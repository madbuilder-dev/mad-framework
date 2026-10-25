@php
    $class = $class ?? '';
    $style = $style ?? '';
    // Tela embutida num <mad-transporter> (X-Mad-Embed): a página de fora já é
    // o cartão. Sem `mad-page-container` — borda, sombra, padding e a altura
    // mínima de tela cheia (e as variantes .mad-layout-*) não se repetem dentro
    // dela. `header="full"` no transporter mantém o casco completo; `row` (tela
    // aberta na linha da listagem, <mad-row-expand>) é o compact.
    $_shell = \Mad\Ui\MadTransporter::compactShell() ? 'mad-page-embed' : 'mad-page-container';
    // `frameless` (builder#396): tela de parede — cozinha, painel de senhas,
    // TV. Enquanto ela é a tela visível, o casco esconde topo, menu e abas
    // (mad.js lê a marca; o botão "Menu" do casco traz a moldura de volta).
    // Embutida noutra tela, não: quem manda na moldura é a tela de fora.
    $_frameless = !empty($frameless ?? false) && $frameless !== 'false' && $_shell === 'mad-page-container';
@endphp
<div class="mad-ui {{ $_shell }} {{ $class }}" @if($style) style="{{ $style }}" @endif @if($_frameless) data-mad-frameless="1" @endif>
    {!! $slot !!}
</div>
