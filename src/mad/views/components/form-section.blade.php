@php
    $title    = $title    ?? '';
    $subtitle = $subtitle ?? null;
    $icon     = $icon     ?? null;
    $class    = $class    ?? '';
    $style    = $style    ?? '';
    $attrs    = $attrs    ?? '';
    // `no-header`: some a linha com ícone, título e divisor mesmo com título
    // preenchido. Caso típico: formulário aberto em gaveta, que já mostra o
    // título da tela na própria barra (fórum #84).
    $noHeader = filter_var($noHeader ?? false, FILTER_VALIDATE_BOOLEAN);
    // Seção sem título, subtítulo nem ícone é só agrupamento (condição,
    // tabela vinculada): sem header, senão sobra uma faixa vazia com o
    // divisor no topo do formulário.
    $hasHeader = !$noHeader && (trim((string) $title) !== '' || filled($subtitle) || filled($icon));
    // Título escondido continua sendo o nome da seção para leitor de tela.
    $ariaLabel = $noHeader && trim((string) $title) !== '' ? trim((string) $title) : '';
    // Aparência (fórum #321): cor, tamanho, peso, itálico e fonte do título e
    // do subtítulo viram --mad-section-* no HEADER — uma seção dentro desta não
    // herda — e o fundo vira --mad-section-bg na própria seção. Sem nenhuma
    // delas, o HTML sai igual ao de antes.
    $_secHeadStyle = \Mad\Support\CssUnits::sectionTextVars('title', $titleColor ?? null, $titleSize ?? null, $titleWeight ?? null, $titleItalic ?? null, $titleFont ?? null)
        . \Mad\Support\CssUnits::sectionTextVars('subtitle', $subtitleColor ?? null, $subtitleSize ?? null, $subtitleWeight ?? null, $subtitleItalic ?? null, $subtitleFont ?? null);
    $_secBg = \Mad\Support\CssUnits::color($bg ?? null);
    if ($_secBg !== '') {
        $style = trim((string) $style);
        $style = ($style === '' ? '' : rtrim($style, '; ') . ';') . '--mad-section-bg:' . $_secBg . ';';
    }
@endphp

<section class="mad-form-section {{ $class }}" @if($style) style="{{ $style }}" @endif @if($ariaLabel !== '') aria-label="{{ $ariaLabel }}" @endif {!! $attrs !!}>
    @if($hasHeader)
    <div class="mad-form-section-header"@if($_secHeadStyle !== '') style="{{ $_secHeadStyle }}"@endif>
        <div class="mad-form-section-meta">
            @if($icon)
            <div class="mad-form-section-icon">
                <i data-lucide="{{ $icon }}"></i>
            </div>
            @endif
            <div class="mad-form-section-text">
                <div class="mad-form-section-title">{{ $title }}</div>
                @if($subtitle)
                <div class="mad-form-section-subtitle">{{ $subtitle }}</div>
                @endif
            </div>
        </div>
        <div class="mad-form-section-divider"></div>
    </div>
    @endif

    {!! $slot !!}
</section>
