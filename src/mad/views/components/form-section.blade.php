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
@endphp

<section class="mad-form-section {{ $class }}" @if($style) style="{{ $style }}" @endif @if($ariaLabel !== '') aria-label="{{ $ariaLabel }}" @endif {!! $attrs !!}>
    @if($hasHeader)
    <div class="mad-form-section-header">
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
